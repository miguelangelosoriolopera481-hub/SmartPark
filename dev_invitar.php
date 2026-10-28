<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'mailer.php';
send_security_headers();

if (empty($_SESSION['usuario_id']) || ($_SESSION['role'] ?? '') !== 'developer') {
    header('Location: login_developer.php');
    exit;
}

$uid = (int)$_SESSION['usuario_id'];
$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'invitar') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        
        // Límite de developers en el sistema
        $totalDevs = (int)$pdo->query("SELECT COUNT(*) FROM user WHERE role = 'developer'")->fetchColumn();
        
        if ($totalDevs >= 5) {
            $error = 'Límite alcanzado: máximo 5 developers en el sistema.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Correo electrónico inválido.';
        } elseif ($nombre === '' || mb_strlen($nombre) < 3) {
            $error = 'El nombre debe tener al menos 3 caracteres.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM user WHERE correo = :e LIMIT 1");
            $stmt->execute(['e' => $email]);
            if ($stmt->fetch()) {
                $error = 'Ya existe un usuario con ese correo.';
            } else {
                $stmt = $pdo->prepare("
                    SELECT id FROM developer_invitaciones
                    WHERE email = :e AND usado = 0 AND expira_en > NOW()
                    LIMIT 1
                ");
                $stmt->execute(['e' => $email]);
                if ($stmt->fetch()) {
                    $error = 'Ya hay una invitación pendiente para ese correo.';
                } else {
                    try {
                        $token = bin2hex(random_bytes(32));
                        $expira = date('Y-m-d H:i:s', strtotime('+48 hours'));
                        
                        $pdo->prepare("
                            INSERT INTO developer_invitaciones (email, nombre, token, invitado_por, expira_en)
                            VALUES (:e, :n, :t, :u, :x)
                        ")->execute([
                            'e' => $email, 'n' => $nombre, 't' => $token, 'u' => $uid, 'x' => $expira,
                        ]);
                        
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $baseUrl = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']);
                        $baseUrl = rtrim($baseUrl, '/');
                        $link = $baseUrl . '/aceptar_invitacion_dev.php?token=' . urlencode($token);
                        
                        $asunto = 'Invitación a Developer - SmartPark Pro';
                        $cuerpo = "<div style='font-family:Inter,Arial,sans-serif;max-width:560px;margin:0 auto;padding:30px;background:#f8fafc;border-radius:14px;'>"
                            . "<h2 style='color:#8b5cf6;margin:0 0 8px;'>Hola " . htmlspecialchars($nombre) . "!</h2>"
                            . "<p style='color:#334155;font-size:15px;line-height:1.6;'>Has sido invitado a formar parte del equipo de <strong>DEVELOPERS</strong> de SmartPark Pro.</p>"
                            . "<p style='color:#334155;font-size:15px;'>Este rol tiene <strong>acceso completo</strong> a todas las empresas, configuración global, backups e IA.</p>"
                            . "<p style='color:#334155;font-size:15px;'>Haz clic para activar tu cuenta:</p>"
                            . "<div style='text-align:center;margin:30px 0;'>"
                            . "<a href='" . $link . "' style='display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#8b5cf6,#06b6d4);color:white;text-decoration:none;border-radius:10px;font-weight:800;font-size:15px;'>Activar cuenta developer</a>"
                            . "</div>"
                            . "<p style='color:#64748b;font-size:13px;'>O copia este enlace:<br><span style='color:#8b5cf6;word-break:break-all;'>" . $link . "</span></p>"
                            . "<p style='color:#94a3b8;font-size:12px;margin-top:20px;padding-top:15px;border-top:1px solid #e2e8f0;'>Este enlace expira en 48 horas.</p>"
                            . "</div>";
                        
                        if (enviar_correo($pdo, $email, $asunto, $cuerpo)) {
                            $msg = '✓ Invitación enviada a ' . htmlspecialchars($email);
                            registrarAuditoria($pdo, 'invitacion_developer', "email=$email");
                        } else {
                            $error = 'La invitación se creó pero el correo no se pudo enviar.';
                        }
                    } catch (\Throwable $e) {
                        error_log('dev_invitar: ' . $e->getMessage());
                        $error = 'Error al crear la invitación.';
                    }
                }
            }
        }
    }
}

// Cancelar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'cancelar') {
    if (csrf_verificar($_POST['csrf_token'] ?? null)) {
        $invId = (int)($_POST['inv_id'] ?? 0);
        $pdo->prepare("UPDATE developer_invitaciones SET usado = 1 WHERE id = :id")->execute(['id' => $invId]);
        $msg = '✓ Invitación cancelada';
    }
}

// Developers actuales
$developers = [];
try {
    $developers = $pdo->query("
        SELECT id, username, nombre, correo, fecha_creacion 
        FROM user WHERE role = 'developer'
        ORDER BY fecha_creacion
    ")->fetchAll();
} catch (\Throwable $e) {}

// Invitaciones pendientes
$invitaciones = [];
try {
    $invitaciones = $pdo->query("
        SELECT di.*, u.nombre AS invitado_por_nombre
        FROM developer_invitaciones di
        LEFT JOIN user u ON u.id = di.invitado_por
        WHERE di.usado = 0 AND di.expira_en > NOW()
        ORDER BY di.creado_en DESC
    ")->fetchAll();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Developers - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=51">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1100px; margin: 0 auto; padding: 0 1.5rem; }
    .header-card {
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(6, 182, 212, 0.05));
        border: 1.5px solid rgba(139, 92, 246, 0.3);
        border-radius: 20px;
        padding: 1.75rem 2rem;
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
    }
    .header-card h1 {
        font-size: clamp(1.5rem, 3vw, 2rem);
        font-weight: 900;
        background: linear-gradient(135deg, #a78bfa, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0 0 0.3rem;
    }
    .header-card p { color: #94a3b8; font-size: 0.9rem; margin: 0; }
    .badge-modo {
        display: inline-block;
        padding: 0.3rem 0.85rem;
        background: rgba(139, 92, 246, 0.15);
        border: 1px solid rgba(139, 92, 246, 0.5);
        border-radius: 100px;
        color: #a78bfa;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        margin-bottom: 0.6rem;
    }
    .btn-invitar {
        padding: 0.9rem 1.75rem;
        background: linear-gradient(135deg, #8b5cf6, #06b6d4);
        color: #030712;
        border: none;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.92rem;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.3s ease;
    }
    .btn-invitar:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(139, 92, 246, 0.4); }
    .section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(139, 92, 246, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
    }
    .section h2 { font-size: 1.1rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.35rem; }
    .section .sub { color: #64748b; font-size: 0.85rem; margin-bottom: 1.25rem; }
    
    .dev-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.15rem;
        background: rgba(2, 6, 23, 0.5);
        border-radius: 12px;
        margin-bottom: 0.6rem;
        border-left: 3px solid #8b5cf6;
        flex-wrap: wrap;
    }
    .avatar {
        width: 44px; height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #8b5cf6, #06b6d4);
        display: flex; align-items: center; justify-content: center;
        color: #030712;
        font-weight: 900;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .dev-info { flex: 1; min-width: 180px; }
    .dev-info .nombre { font-weight: 700; color: #f1f5f9; font-size: 0.92rem; margin-bottom: 0.15rem; }
    .dev-info .email { color: #94a3b8; font-size: 0.78rem; }
    .badge-dev {
        padding: 0.25rem 0.75rem;
        background: rgba(139, 92, 246, 0.15);
        color: #c4b5fd;
        border-radius: 100px;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.04em;
    }
    .btn-mini {
        padding: 0.4rem 0.85rem;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        border: 1.5px solid #ef4444;
        background: transparent;
        color: #ef4444;
        cursor: pointer;
        font-family: inherit;
    }
    .btn-mini:hover { background: #ef4444; color: white; }
    .btn-volver {
        display: inline-block;
        padding: 0.65rem 1.15rem;
        background: transparent;
        border: 1.5px solid #a78bfa;
        color: #a78bfa;
        border-radius: 11px;
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        margin-bottom: 1rem;
        transition: all 0.25s ease;
    }
    .btn-volver:hover { background: #a78bfa; color: #030712; }
    
    .modal-overlay {
        position: fixed !important;
        top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important;
        width: 100vw !important; height: 100vh !important;
        background: rgba(0, 0, 0, 0.78);
        backdrop-filter: blur(8px);
        z-index: 999999 !important;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
    }
    .modal-overlay.active { display: flex !important; }
    .modal-content {
        background: linear-gradient(160deg, #0f172a, #0a0f1e);
        border: 1.5px solid rgba(139, 92, 246, 0.4);
        border-radius: 24px;
        padding: 2rem;
        width: 100%;
        max-width: 520px;
        position: relative !important;
        z-index: 1000000 !important;
    }
    .modal-content h2 { font-size: 1.35rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.5rem; }
    .modal-content .sub { color: #64748b; font-size: 0.85rem; margin-bottom: 1.5rem; }
    .form-group { margin-bottom: 1.1rem; }
    .form-group label { display: block; font-size: 0.82rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; }
    .form-group input {
        width: 100%; padding: 0.85rem 1rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 11px;
        color: #f1f5f9; font-family: inherit; font-size: 0.92rem; outline: none;
        box-sizing: border-box;
    }
    .form-group input:focus { border-color: #8b5cf6; box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15); }
    .modal-actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }
    .modal-actions button { flex: 1; padding: 0.9rem; border-radius: 11px; font-weight: 800; cursor: pointer; font-family: inherit; font-size: 0.92rem; }
    .btn-primary { background: linear-gradient(135deg, #8b5cf6, #06b6d4); color: #030712; border: none; }
    .btn-cancelar { background: transparent; color: #94a3b8; border: 1.5px solid rgba(148, 163, 184, 0.3); }
    .modal-cerrar {
        position: absolute; top: 1rem; right: 1rem;
        width: 36px; height: 36px; border-radius: 50%;
        background: rgba(239, 68, 68, 0.15);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #f87171; font-size: 1.1rem;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center;
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">
<canvas id="particles-canvas"></canvas>

<div class="container">
    
    <a href="dashboard_developer.php" class="btn-volver">← Volver al panel</a>
    
    <div class="header-card">
        <div>
            <span class="badge-modo">◇ DEVELOPER MODE</span>
            <h1>Equipo Developer</h1>
            <p>Gestiona quién tiene acceso completo al sistema</p>
        </div>
        <button type="button" class="btn-invitar" onclick="abrirModal()">➕ Invitar developer</button>
    </div>
    
    <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    
    <div class="section">
        <h2>👨‍💻 Developers actuales (<?= count($developers) ?>/5)</h2>
        <p class="sub">Acceso completo a todas las empresas y configuración</p>
        <?php foreach ($developers as $dev): ?>
            <div class="dev-item">
                <div class="avatar"><?= strtoupper(substr($dev['nombre'], 0, 1)) ?></div>
                <div class="dev-info">
                    <div class="nombre"><?= htmlspecialchars($dev['nombre']) ?><?= (int)$dev['id'] === $uid ? ' (tú)' : '' ?></div>
                    <div class="email">@<?= htmlspecialchars($dev['username']) ?><?= $dev['correo'] ? ' · ' . htmlspecialchars($dev['correo']) : '' ?></div>
                </div>
                <span class="badge-dev">DEVELOPER</span>
            </div>
        <?php endforeach; ?>
    </div>
    
    <?php if (!empty($invitaciones)): ?>
    <div class="section">
        <h2>⏳ Invitaciones pendientes (<?= count($invitaciones) ?>)</h2>
        <p class="sub">Esperando que acepten la invitación</p>
        <?php foreach ($invitaciones as $inv): ?>
            <div class="dev-item" style="border-left-color: #64748b;">
                <div class="avatar" style="background: rgba(100,116,139,0.3);">📧</div>
                <div class="dev-info">
                    <div class="nombre"><?= htmlspecialchars($inv['nombre']) ?></div>
                    <div class="email"><?= htmlspecialchars($inv['email']) ?> · Invitado por <?= htmlspecialchars($inv['invitado_por_nombre'] ?? '?') ?></div>
                </div>
                <span style="color: #94a3b8; font-size: 0.75rem;">Expira <?= htmlspecialchars(date('d/m H:i', strtotime($inv['expira_en']))) ?></span>
                <form method="POST" style="display: inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="cancelar">
                    <input type="hidden" name="inv_id" value="<?= (int)$inv['id'] ?>">
                    <button class="btn-mini" type="submit">Cancelar</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
</div>

<div class="modal-overlay" id="modalInv">
    <div class="modal-content">
        <button type="button" class="modal-cerrar" onclick="cerrarModal()">✕</button>
        <h2>➕ Invitar developer</h2>
        <p class="sub">Recibirá acceso completo al sistema</p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="invitar">
            <div class="form-group">
                <label>Nombre completo</label>
                <input type="text" name="nombre" placeholder="Ej: Juan Pérez" required maxlength="100" autofocus>
            </div>
            <div class="form-group">
                <label>Correo electrónico</label>
                <input type="email" name="email" placeholder="correo@ejemplo.com" required maxlength="150">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-primary">Enviar invitación</button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModal() { document.getElementById('modalInv').classList.add('active'); }
function cerrarModal() { document.getElementById('modalInv').classList.remove('active'); }
document.getElementById('modalInv').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});
</script>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>