<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'mailer.php';
send_security_headers();

require_role(['admin']);
require_password_change_guard();

$eid = current_empresa_id();
if (!$eid) { header('Location: login_empresa.php'); exit; }

$uid = (int)$_SESSION['usuario_id'];
$msg = ''; $error = '';

// Info de la empresa
$stmt = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$stmt->execute(['eid' => $eid]);
$empresa = $stmt->fetch() ?: ['nombre' => 'Mi Empresa', 'nit' => ''];

// Procesar invitacion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'invitar') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $rol = $_POST['rol'] ?? '';
        $rolesValidos = ['admin', 'operador', 'vigilante', 'finanzas'];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Correo electrónico inválido.';
        } elseif ($nombre === '' || mb_strlen($nombre) < 3) {
            $error = 'El nombre debe tener al menos 3 caracteres.';
        } elseif (!in_array($rol, $rolesValidos, true)) {
            $error = 'Rol inválido.';
        } else {

            // VALIDACIÓN GLOBAL DE EMAIL (multi-tenant seguro)
            // Un email solo puede pertenecer a UNA persona en TODO el sistema

            $stmt = $pdo->prepare("
                SELECT u.id, u.nombre, ue.empresa_id
                FROM user u
                LEFT JOIN usuarios_empresa ue ON ue.user_id = u.id
                WHERE u.correo = :email
                LIMIT 1
            ");
            $stmt->execute(['email' => $email]);
            $existenteGlobal = $stmt->fetch();

            if ($existenteGlobal) {
                if ((int)$existenteGlobal['empresa_id'] === $eid) {
                    $error = 'Ese correo ya pertenece a un miembro de tu empresa.';
                } else {
                    $error = 'Ese correo ya está registrado en el sistema. Si es un empleado que trabaja en varias empresas, debe usar OTRO correo o pedir al developer que lo vincule manualmente.';
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT id FROM invitaciones_equipo
                    WHERE empresa_id = :eid AND email = :email AND usado = 0 AND expira_en > NOW()
                    LIMIT 1
                ");
                $stmt->execute(['eid' => $eid, 'email' => $email]);
                if ($stmt->fetch()) {
                    $error = 'Ya hay una invitación pendiente para ese correo.';
                } else {
                    try {
                        $token = bin2hex(random_bytes(32));
                        $expira = date('Y-m-d H:i:s', strtotime('+48 hours'));

                        $stmt = $pdo->prepare("
                            INSERT INTO invitaciones_equipo 
                            (empresa_id, email, nombre, rol, token, invitado_por, expira_en)
                            VALUES (:eid, :email, :nombre, :rol, :token, :uid, :expira)
                        ");
                        $stmt->execute([
                            'eid' => $eid, 'email' => $email, 'nombre' => $nombre,
                            'rol' => $rol, 'token' => $token, 'uid' => $uid, 'expira' => $expira
                        ]);

                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $baseUrl = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME']);
                        $baseUrl = rtrim($baseUrl, '/');

                        $link = $baseUrl . '/aceptar_invitacion.php?token=' . urlencode($token);

                        $asunto = 'Invitación a ' . $empresa['nombre'] . ' - SmartPark Pro';
                        $cuerpo = "<div style='font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 30px; background: #f8fafc; border-radius: 14px;'>"
                            . "<h2 style='color: #10b981; margin: 0 0 8px;'>Hola " . htmlspecialchars($nombre) . "!</h2>"
                            . "<p style='color: #334155; font-size: 15px; line-height: 1.6;'>"
                            . "Has sido invitado a formar parte del equipo de <strong>" . htmlspecialchars($empresa['nombre']) . "</strong>"
                            . " en <strong>SmartPark Pro</strong> con el rol de <strong>" . htmlspecialchars(ucfirst($rol)) . "</strong>."
                            . "</p>"
                            . "<p style='color: #334155; font-size: 15px;'>Haz clic para crear tu contraseña:</p>"
                            . "<div style='text-align: center; margin: 30px 0;'>"
                            . "<a href='" . $link . "' style='display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #10b981, #06b6d4); color: white; text-decoration: none; border-radius: 10px; font-weight: 800; font-size: 15px;'>Crear mi contraseña</a>"
                            . "</div>"
                            . "<p style='color: #64748b; font-size: 13px;'>O copia este enlace:<br><span style='color: #10b981; word-break: break-all;'>" . $link . "</span></p>"
                            . "<p style='color: #94a3b8; font-size: 12px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0;'>Este enlace expira en 48 horas.</p>"
                            . "</div>";

                        if (enviar_correo($pdo, $email, $asunto, $cuerpo)) {
                            $msg = '✓ Invitación enviada a ' . $email;
                            registrarAuditoria($pdo, 'invitacion_enviada', "email=$email rol=$rol");
                        } else {
                            $error = 'La invitación se creó pero el correo no se pudo enviar. Verifica la config SMTP.';
                        }
                    } catch (\Throwable $e) {
                        error_log('invitar_miembro: ' . $e->getMessage());
                        $error = 'Error al crear la invitación.';
                    }
                }
            }
        }
    }
}

// Cancelar invitación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'cancelar') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $invId = (int)($_POST['inv_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE invitaciones_equipo SET usado = 1 WHERE id = :id AND empresa_id = :eid");
        $stmt->execute(['id' => $invId, 'eid' => $eid]);
        $msg = '✓ Invitación cancelada';
    }
}

// Miembros actuales
$miembros = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.nombre, u.correo, ue.rol_en_empresa, ue.activo, ue.fecha_alta
        FROM usuarios_empresa ue
        JOIN user u ON u.id = ue.user_id
        WHERE ue.empresa_id = :eid
        ORDER BY ue.rol_en_empresa, u.nombre
    ");
    $stmt->execute(['eid' => $eid]);
    $miembros = $stmt->fetchAll();
} catch (\Throwable $e) {}

// Invitaciones pendientes
$invitaciones = [];
try {
    $stmt = $pdo->prepare("
        SELECT ie.*, u.nombre AS invitado_por_nombre
        FROM invitaciones_equipo ie
        LEFT JOIN user u ON u.id = ie.invitado_por
        WHERE ie.empresa_id = :eid AND ie.usado = 0 AND ie.expira_en > NOW()
        ORDER BY ie.creado_en DESC
    ");
    $stmt->execute(['eid' => $eid]);
    $invitaciones = $stmt->fetchAll();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi Equipo - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=50">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1100px; margin: 0 auto; padding: 0 1.5rem; }
    .empresa-header {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05));
        border: 1.5px solid rgba(16, 185, 129, 0.3);
        border-radius: 20px;
        padding: 1.75rem 2rem;
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
    }
    .empresa-header h1 {
        font-size: clamp(1.5rem, 3vw, 2rem);
        font-weight: 900;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0 0 0.3rem;
        letter-spacing: -0.02em;
    }
    .empresa-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }
    .btn-invitar {
        padding: 0.9rem 1.75rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        border: none;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.92rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-family: inherit;
    }
    .btn-invitar:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4); }
    .section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
    }
    .section h2 {
        font-size: 1.1rem;
        font-weight: 800;
        color: #f1f5f9;
        margin: 0 0 0.4rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .section .sub { color: #64748b; font-size: 0.85rem; margin-bottom: 1.25rem; }
    .miembro-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.15rem;
        background: rgba(2, 6, 23, 0.5);
        border-radius: 12px;
        margin-bottom: 0.6rem;
        border-left: 3px solid #10b981;
        transition: all 0.25s ease;
        flex-wrap: wrap;
    }
    .miembro-item:hover { background: rgba(2, 6, 23, 0.7); transform: translateX(3px); }
    .miembro-item.operador { border-left-color: #06b6d4; }
    .miembro-item.vigilante { border-left-color: #f59e0b; }
    .miembro-item.finanzas { border-left-color: #3b82f6; }
    .avatar {
        width: 44px; height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #030712;
        font-weight: 900;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .miembro-info { flex: 1; min-width: 180px; }
    .miembro-info .nombre { font-weight: 700; color: #f1f5f9; font-size: 0.92rem; margin-bottom: 0.15rem; }
    .miembro-info .email { color: #94a3b8; font-size: 0.78rem; }
    .badge-rol {
        padding: 0.25rem 0.75rem;
        border-radius: 100px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .badge-rol.admin { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
    .badge-rol.operador { background: rgba(6, 182, 212, 0.15); color: #67e8f9; }
    .badge-rol.vigilante { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
    .badge-rol.finanzas { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
    .estado-activo { color: #6ee7b7; font-size: 0.75rem; font-weight: 700; }
    .estado-inactivo { color: #fca5a5; font-size: 0.75rem; font-weight: 700; }
    .btn-mini {
        padding: 0.4rem 0.85rem;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        border: 1.5px solid;
        background: transparent;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.25s ease;
    }
    .btn-mini.editar { color: #10b981; border-color: #10b981; }
    .btn-mini.editar:hover { background: #10b981; color: #030712; }
    .btn-mini.peligro { color: #ef4444; border-color: #ef4444; }
    .btn-mini.peligro:hover { background: #ef4444; color: white; }
    .modal-overlay {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        background: rgba(0, 0, 0, 0.78);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 999999 !important;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        overflow-y: auto;
    }
    .modal-overlay.active { display: flex !important; }
    .modal-content {
        background: linear-gradient(160deg, #0f172a, #0a0f1e);
        border: 1.5px solid rgba(16, 185, 129, 0.3);
        border-radius: 24px;
        padding: 2rem;
        width: 100%;
        max-width: 520px;
        animation: modalIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative !important;
        z-index: 1000000 !important;
    }
    .modal-cerrar {
        position: absolute;
        top: 1rem;
        right: 1rem;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(239, 68, 68, 0.15);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #f87171;
        font-size: 1.1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.25s ease;
        font-family: inherit;
    }
    .modal-cerrar:hover {
        background: #ef4444;
        color: white;
        transform: rotate(90deg);
    }
    @keyframes modalIn {
        from { opacity: 0; transform: translateY(30px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .modal-content h2 { font-size: 1.35rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.5rem; }
    .modal-content .sub { color: #64748b; font-size: 0.85rem; margin-bottom: 1.5rem; }
    .form-group { margin-bottom: 1.1rem; }
    .form-group label { display: block; font-size: 0.82rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; }
    .form-group input, .form-group select {
        width: 100%;
        padding: 0.85rem 1rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 11px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.92rem;
        outline: none;
        transition: all 0.25s ease;
    }
    .form-group input:focus, .form-group select:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
    }
    .modal-actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }
    .modal-actions button { flex: 1; padding: 0.9rem; border-radius: 11px; font-weight: 800; cursor: pointer; font-family: inherit; font-size: 0.92rem; }
    .btn-primary { background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; border: none; }
    .btn-cancelar { background: transparent; color: #94a3b8; border: 1.5px solid rgba(148, 163, 184, 0.3); }
    .btn-cancelar:hover { color: #f1f5f9; border-color: #64748b; }
    .empty-mini { text-align: center; padding: 2rem; color: #64748b; }
    .empty-mini .ico { font-size: 2rem; opacity: 0.4; display: block; margin-bottom: 0.6rem; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">
<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="empresa-header">
    <div>
      <span style="display: inline-block; padding: 0.3rem 0.85rem; background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.4); border-radius: 100px; color: #10b981; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; margin-bottom: 0.6rem;">◉ <?= htmlspecialchars($empresa['nombre']) ?></span>
      <h1>Mi Equipo</h1>
      <p>Gestiona quién tiene acceso a tu parqueadero y con qué permisos</p>
    </div>
    <button type="button" class="btn-invitar" onclick="abrirModalInvitacion()">
      ➕ Invitar miembro
    </button>
  </div>

  <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="section">
    <h2>👥 Miembros actuales (<?= count($miembros) ?>)</h2>
    <p class="sub">Personas con acceso activo a tu parqueadero</p>
    <?php if (empty($miembros)): ?>
      <div class="empty-mini"><span class="ico">👥</span>No hay miembros aún</div>
    <?php else: foreach ($miembros as $m): ?>
      <div class="miembro-item <?= htmlspecialchars($m['rol_en_empresa']) ?>">
        <div class="avatar"><?= strtoupper(substr($m['nombre'], 0, 1)) ?></div>
        <div class="miembro-info">
          <div class="nombre"><?= htmlspecialchars($m['nombre']) ?></div>
          <div class="email">@<?= htmlspecialchars($m['username']) ?><?= $m['correo'] ? ' · ' . htmlspecialchars($m['correo']) : '' ?></div>
        </div>
        <span class="badge-rol <?= htmlspecialchars($m['rol_en_empresa']) ?>"><?= htmlspecialchars($m['rol_en_empresa']) ?></span>
        <span class="<?= $m['activo'] ? 'estado-activo' : 'estado-inactivo' ?>"><?= $m['activo'] ? '✓ Activo' : '✗ Inactivo' ?></span>
        <a href="editar_usuario.php?id=<?= (int)$m['id'] ?>" class="btn-mini editar">✏️ Editar</a>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if (!empty($invitaciones)): ?>
  <div class="section">
    <h2>⏳ Invitaciones pendientes (<?= count($invitaciones) ?>)</h2>
    <p class="sub">Personas invitadas que aún no han creado su contraseña</p>
    <?php foreach ($invitaciones as $inv): ?>
      <div class="miembro-item">
        <div class="avatar" style="background: rgba(100,116,139,0.3);">📧</div>
        <div class="miembro-info">
          <div class="nombre"><?= htmlspecialchars($inv['nombre']) ?></div>
          <div class="email"><?= htmlspecialchars($inv['email']) ?> · Invitado por <?= htmlspecialchars($inv['invitado_por_nombre'] ?? '?') ?></div>
        </div>
        <span class="badge-rol <?= htmlspecialchars($inv['rol']) ?>"><?= htmlspecialchars($inv['rol']) ?></span>
        <span style="color: #94a3b8; font-size: 0.75rem;">Expira <?= htmlspecialchars(date('d/m H:i', strtotime($inv['expira_en']))) ?></span>
        <form method="POST" style="display: inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="accion" value="cancelar">
          <input type="hidden" name="inv_id" value="<?= (int)$inv['id'] ?>">
          <button class="btn-mini peligro" type="submit">Cancelar</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<!-- Modal de invitación -->
<div class="modal-overlay" id="modalInvitacion">
  <div class="modal-content">
    <button type="button" class="modal-cerrar" onclick="cerrarModalInvitacion()" aria-label="Cerrar">✕</button>
    <h2>➕ Invitar miembro</h2>
    <p class="sub">Enviaremos un correo con el enlace de activación</p>
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
      <div class="form-group">
        <label>Rol en la empresa</label>
        <select name="rol" required>
          <option value="operador">Operador · Registra entradas y cobra</option>
          <option value="vigilante">Vigilante · Controla entrada/salida</option>
          <option value="finanzas">Finanzas · Solo reportes</option>
          <option value="admin">Admin · Control total</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn-cancelar" onclick="cerrarModalInvitacion()">Cancelar</button>
        <button type="submit" class="btn-primary">Enviar invitación</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirModalInvitacion() { document.getElementById('modalInvitacion').classList.add('active'); }
function cerrarModalInvitacion() { document.getElementById('modalInvitacion').classList.remove('active'); }
document.getElementById('modalInvitacion').addEventListener('click', function(e) {
  if (e.target === this) cerrarModalInvitacion();
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') cerrarModalInvitacion(); });
</script>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>