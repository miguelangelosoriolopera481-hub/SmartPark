<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

require_role(['admin']);
require_password_change_guard();

$eid = current_empresa_id();
if (!$eid) { header('Location: login_empresa.php'); exit; }

$uid_actual = (int)$_SESSION['usuario_id'];
$target_id = (int)($_GET['id'] ?? 0);

if ($target_id <= 0) { header('Location: equipo.php'); exit; }

$msg = ''; $error = '';

// Cargar el usuario objetivo (validar que pertenece a la empresa)
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.nombre, u.correo, u.role, u.fecha_creacion,
               ue.rol_en_empresa, ue.activo, ue.fecha_alta
        FROM user u
        JOIN usuarios_empresa ue ON ue.user_id = u.id
        WHERE u.id = :id AND ue.empresa_id = :eid
        LIMIT 1
    ");
    $stmt->execute(['id' => $target_id, 'eid' => $eid]);
    $usuario = $stmt->fetch();
} catch (\Throwable $e) { $usuario = null; }

if (!$usuario) {
    header('Location: equipo.php');
    exit;
}

// Procesar edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';
        
        if ($accion === 'guardar') {
            $nombre = trim($_POST['nombre'] ?? '');
            $correo = trim($_POST['correo'] ?? '');
            $rol = $_POST['rol'] ?? '';
            $activo = !empty($_POST['activo']) ? 1 : 0;
            
            $rolesValidos = ['admin', 'operador', 'vigilante', 'finanzas'];
            
            if ($nombre === '' || mb_strlen($nombre) < 3) {
                $error = 'El nombre debe tener al menos 3 caracteres.';
            } elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $error = 'El correo no es válido.';
            } elseif (!in_array($rol, $rolesValidos, true)) {
                $error = 'Rol inválido.';
            } elseif ($target_id === $uid_actual && !$activo) {
                $error = 'No puedes desactivar tu propia cuenta.';
            } elseif ($target_id === $uid_actual && $rol !== $usuario['rol_en_empresa']) {
                $error = 'No puedes cambiar tu propio rol.';
            } else {
                
                if ($correo !== '' && $correo !== ($usuario['correo'] ?? '')) {
                    $stmt = $pdo->prepare("SELECT id FROM user WHERE correo = :c AND id != :id LIMIT 1");
                    $stmt->execute(['c' => $correo, 'id' => $target_id]);
                    if ($stmt->fetch()) {
                        $error = 'Ese correo ya está registrado por otro usuario.';
                    }
                }
                
                if (!$error) {
                    try {
                        $pdo->beginTransaction();
                        
                        $pdo->prepare("UPDATE user SET nombre = :n, correo = :c WHERE id = :id")
                            ->execute(['n' => $nombre, 'c' => $correo ?: null, 'id' => $target_id]);
                        
                        $pdo->prepare("UPDATE usuarios_empresa SET rol_en_empresa = :r, activo = :a WHERE user_id = :id AND empresa_id = :eid")
                            ->execute(['r' => $rol, 'a' => $activo, 'id' => $target_id, 'eid' => $eid]);
                        
                        $pdo->commit();
                        
                        registrarAuditoria($pdo, 'editar_usuario', "user_id=$target_id nuevo_rol=$rol activo=$activo");
                        $msg = '✓ Usuario actualizado correctamente';
                        
                        // Recargar datos
                        $stmt = $pdo->prepare("
                            SELECT u.id, u.username, u.nombre, u.correo, u.role, u.fecha_creacion,
                                   ue.rol_en_empresa, ue.activo, ue.fecha_alta
                            FROM user u
                            JOIN usuarios_empresa ue ON ue.user_id = u.id
                            WHERE u.id = :id AND ue.empresa_id = :eid
                            LIMIT 1
                        ");
                        $stmt->execute(['id' => $target_id, 'eid' => $eid]);
                        $usuario = $stmt->fetch();
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('editar_usuario: ' . $e->getMessage());
                        $error = 'Error al guardar los cambios.';
                    }
                }
            }
        }
        
        // Resetear contraseña (envía link por correo)
        if ($accion === 'resetear_password') {
            if (empty($usuario['correo'])) {
                $error = 'Este usuario no tiene correo registrado.';
            } else {
                try {
                    $token = bin2hex(random_bytes(32));
                    $hash = password_hash($token, PASSWORD_BCRYPT, ['cost' => 10]);
                    
                    $pdo->prepare("
                        INSERT INTO password_resets (user_id, token_hash, metodo, destino, expira_en, ip)
                        VALUES (:u, :h, 'email', :d, (NOW() + INTERVAL 30 MINUTE), :ip)
                    ")->execute([
                        'u' => $target_id, 'h' => $hash, 'd' => $usuario['correo'],
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                    
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']);
                    $baseUrl = rtrim($baseUrl, '/');
                    $link = $baseUrl . '/reset_password.php?token=' . urlencode($token);
                    
                    require_once 'mailer.php';
                    $asunto = 'Restablecer contraseña - SmartPark Pro';
                    $cuerpo = "<h2 style='color:#10b981;'>Restablecer contraseña</h2>"
                        . "<p>Hola " . htmlspecialchars($usuario['nombre']) . ",</p>"
                        . "<p>El administrador ha solicitado que restablezcas tu contraseña.</p>"
                        . "<p><a href='" . $link . "' style='display:inline-block;padding:12px 24px;background:#10b981;color:white;text-decoration:none;border-radius:8px;font-weight:700;'>Restablecer ahora</a></p>"
                        . "<p style='color:#64748b;font-size:12px;'>Expira en 30 minutos.</p>";
                    
                    if (enviar_correo($pdo, $usuario['correo'], $asunto, $cuerpo)) {
                        $msg = '✓ Correo de restablecimiento enviado a ' . htmlspecialchars($usuario['correo']);
                    } else {
                        $error = 'No se pudo enviar el correo.';
                    }
                } catch (\Throwable $e) {
                    error_log('reset_password_admin: ' . $e->getMessage());
                    $error = 'Error al generar el reset.';
                }
            }
        }
    }
}

$rolesEtiquetas = [
    'admin' => 'Administrador · Control total',
    'operador' => 'Operador · Registra y cobra',
    'vigilante' => 'Vigilante · Entrada/salida',
    'finanzas' => 'Finanzas · Solo reportes',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Editar Usuario - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=51">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 780px; margin: 0 auto; padding: 0 1.5rem; }
    
    .header-card {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05));
        border: 1.5px solid rgba(16, 185, 129, 0.3);
        border-radius: 20px;
        padding: 1.75rem 2rem;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 1.25rem;
        flex-wrap: wrap;
    }
    .avatar-grande {
        width: 72px; height: 72px;
        border-radius: 20px;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        display: flex; align-items: center; justify-content: center;
        color: #030712;
        font-weight: 900;
        font-size: 2rem;
        flex-shrink: 0;
    }
    .header-info { flex: 1; min-width: 200px; }
    .header-info h1 {
        font-size: 1.5rem;
        font-weight: 900;
        color: #f1f5f9;
        margin: 0 0 0.25rem;
        letter-spacing: -0.02em;
    }
    .header-info .sub { color: #94a3b8; font-size: 0.85rem; }
    .header-info .badge-rol {
        display: inline-block;
        padding: 0.2rem 0.7rem;
        border-radius: 100px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-top: 0.4rem;
    }
    .badge-rol.admin { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
    .badge-rol.operador { background: rgba(6, 182, 212, 0.15); color: #67e8f9; }
    .badge-rol.vigilante { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
    .badge-rol.finanzas { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
    
    .btn-volver {
        padding: 0.65rem 1.15rem;
        background: transparent;
        border: 1.5px solid #10b981;
        color: #10b981;
        border-radius: 11px;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.25s ease;
        white-space: nowrap;
    }
    .btn-volver:hover { background: #10b981; color: #030712; }
    
    .section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
    }
    .section h2 {
        font-size: 1.05rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    
    .form-group { margin-bottom: 1.15rem; }
    .form-group label {
        display: block;
        font-size: 0.82rem;
        color: #cbd5e1;
        font-weight: 700;
        margin-bottom: 0.4rem;
    }
    .form-group label .opcional { color: #64748b; font-weight: 400; font-size: 0.75rem; }
    .form-group input, .form-group select {
        width: 100%;
        padding: 0.9rem 1rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 11px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.95rem;
        outline: none;
        box-sizing: border-box;
        transition: all 0.25s ease;
    }
    .form-group input:focus, .form-group select:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
    }
    .form-group input[disabled] {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .toggle-switch {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.85rem 1rem;
        background: rgba(2, 6, 23, 0.5);
        border-radius: 11px;
        cursor: pointer;
        transition: all 0.25s ease;
    }
    .toggle-switch:hover { background: rgba(2, 6, 23, 0.7); }
    .toggle-switch input { width: 20px; height: 20px; cursor: pointer; accent-color: #10b981; }
    .toggle-switch .toggle-text { font-size: 0.88rem; color: #cbd5e1; font-weight: 600; }
    
    .acciones {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-top: 1.5rem;
    }
    .btn-guardar {
        padding: 1rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        border: none;
        border-radius: 12px;
        font-weight: 900;
        font-size: 0.95rem;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.3s ease;
    }
    .btn-guardar:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4); }
    
    .btn-reset {
        padding: 1rem;
        background: rgba(245, 158, 11, 0.12);
        border: 1.5px solid rgba(245, 158, 11, 0.5);
        color: #fcd34d;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.9rem;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.3s ease;
    }
    .btn-reset:hover { background: rgba(245, 158, 11, 0.2); }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        font-size: 0.85rem;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .lbl { color: #64748b; }
    .info-row .val { color: #cbd5e1; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">
<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="header-card">
        <div class="avatar-grande"><?= strtoupper(substr($usuario['nombre'], 0, 1)) ?></div>
        <div class="header-info">
            <h1><?= htmlspecialchars($usuario['nombre']) ?></h1>
            <p class="sub">@<?= htmlspecialchars($usuario['username']) ?><?= $usuario['correo'] ? ' · ' . htmlspecialchars($usuario['correo']) : '' ?></p>
            <span class="badge-rol <?= htmlspecialchars($usuario['rol_en_empresa']) ?>"><?= htmlspecialchars($usuario['rol_en_empresa']) ?></span>
        </div>
        <a href="equipo.php" class="btn-volver">← Volver al equipo</a>
    </div>

    <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="section">
        <h2>📋 Información del usuario</h2>
        <div class="info-row">
            <span class="lbl">ID interno</span>
            <span class="val">#<?= (int)$usuario['id'] ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Miembro desde</span>
            <span class="val"><?= htmlspecialchars(substr($usuario['fecha_alta'], 0, 10)) ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Estado</span>
            <span class="val" style="color: <?= $usuario['activo'] ? '#6ee7b7' : '#fca5a5' ?>;"><?= $usuario['activo'] ? '● Activo' : '✗ Inactivo' ?></span>
        </div>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        
        <div class="section">
            <h2>✏️ Editar información</h2>
            
            <div class="form-group">
                <label>Nombre completo</label>
                <input type="text" name="nombre" value="<?= htmlspecialchars($usuario['nombre']) ?>" required maxlength="100">
            </div>
            
            <div class="form-group">
                <label>Usuario <span class="opcional">(no editable por seguridad)</span></label>
                <input type="text" value="<?= htmlspecialchars($usuario['username']) ?>" disabled>
            </div>
            
            <div class="form-group">
                <label>Correo electrónico <span class="opcional">(único en el sistema)</span></label>
                <input type="email" name="correo" value="<?= htmlspecialchars($usuario['correo'] ?? '') ?>" maxlength="150">
            </div>
            
            <div class="form-group">
                <label>Rol en la empresa</label>
                <select name="rol" required <?= $target_id === $uid_actual ? 'disabled' : '' ?>>
                    <?php foreach ($rolesEtiquetas as $rol_key => $rol_label): ?>
                        <option value="<?= $rol_key ?>" <?= $usuario['rol_en_empresa'] === $rol_key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rol_label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($target_id === $uid_actual): ?>
                    <input type="hidden" name="rol" value="<?= htmlspecialchars($usuario['rol_en_empresa']) ?>">
                    <p style="color: #64748b; font-size: 0.75rem; margin-top: 0.35rem;">No puedes cambiar tu propio rol.</p>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label>Acceso al sistema</label>
                <label class="toggle-switch">
                    <input type="checkbox" name="activo" value="1" <?= $usuario['activo'] ? 'checked' : '' ?> <?= $target_id === $uid_actual ? 'disabled' : '' ?>>
                    <span class="toggle-text">Usuario activo (puede iniciar sesión)</span>
                </label>
                <?php if ($target_id === $uid_actual): ?>
                    <input type="hidden" name="activo" value="1">
                <?php endif; ?>
            </div>
            
            <div class="acciones">
                <button type="submit" class="btn-guardar">💾 Guardar cambios</button>
                <button type="button" class="btn-reset" onclick="resetearPassword()">🔑 Enviar reset de contraseña</button>
            </div>
        </div>
    </form>
    
    <!-- Form separado para reset -->
    <form method="POST" id="formReset" style="display: none;">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="resetear_password">
    </form>

</div>

<script>
function resetearPassword() {
    if (!confirm('¿Enviar un correo para que el usuario restablezca su contraseña?')) return;
    document.getElementById('formReset').submit();
}
</script>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>