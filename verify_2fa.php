<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'mailer.php';
require_once 'csrf.php';
send_security_headers();

if (empty($_SESSION['pre_auth_user_id'])) {
    header('Location: login_empresa.php');
    exit;
}

$user = $_SESSION['pre_auth_user'];
$empresaId = (int)$_SESSION['pre_auth_empresa'];
$esDeveloper = !empty($_SESSION['pre_auth_es_developer']);
$userId = (int)$user['id'];
$error = '';
$info = '';

// Enviar OTP automáticamente la primera vez
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_enviar'])) || empty($_SESSION['2fa_enviado'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null) && isset($_POST['action_enviar'])) {
        $error = 'Sesión expirada.';
    } else {
        $metodo = 'email'; // Forzado solo email
        $destino = $user['correo'] ?? '';

        if ($destino === '') {
            $error = "No hay correo registrado en tu cuenta.";
        } else {
            try {
                $codigo = crear_otp_2fa($pdo, $userId, $esDeveloper ? 0 : $empresaId, $metodo, $destino);
                
                $asunto = 'Código de verificación SmartPark';
                $cuerpo = "<div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 24px; background: #f8fafc; border-radius: 12px;'>
                    <h2 style='color: #10b981; margin: 0 0 12px;'>SmartPark Pro</h2>
                    <p style='color: #334155; font-size: 15px;'>Tu código de verificación es:</p>
                    <div style='font-size: 34px; font-weight: 900; color: #10b981; letter-spacing: 6px; text-align: center; padding: 18px; background: #fff; border-radius: 10px; margin: 18px 0; border: 2px dashed #10b981;'>$codigo</div>
                    <p style='color: #64748b; font-size: 13px;'>Expira en 5 minutos. Si no solicitaste este código, ignora este mensaje.</p>
                    <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                    <p style='color: #94a3b8; font-size: 11px; text-align: center;'>SmartPark Pro · Sistema de gestión inteligente</p>
                </div>";
                enviar_correo($pdo, $destino, $asunto, $cuerpo);
                
                $_SESSION['2fa_enviado'] = true;
                $info = 'Código enviado a ' . preg_replace('/(.{2}).+(.{2})/', '$1***$2', $destino);
            } catch (\Throwable $e) {
                error_log('verify_2fa: ' . $e->getMessage());
                $error = 'No se pudo enviar el código. Intenta de nuevo.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verificar'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $codigo = preg_replace('/\D/', '', $_POST['codigo'] ?? '');
        if (strlen($codigo) !== 6) {
            $error = 'El código debe tener 6 dígitos.';
        } else {
            $v = verificar_otp_2fa($pdo, $userId, $codigo);
            if ($v['ok']) {
                if ($esDeveloper) {
                    session_regenerate_id(true);
                    $_SESSION['usuario_id'] = (int)$user['id'];
                    $_SESSION['username']   = $user['username'];
                    $_SESSION['nombre']     = $user['nombre'];
                    $_SESSION['correo']     = $user['correo'] ?? '';
                    $_SESSION['role']       = 'developer';
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    unset(
                        $_SESSION['pre_auth_user_id'],
                        $_SESSION['pre_auth_empresa'],
                        $_SESSION['pre_auth_user'],
                        $_SESSION['pre_auth_rol'],
                        $_SESSION['pre_auth_es_developer'],
                        $_SESSION['2fa_enviado']
                    );
                    header('Location: dashboard_developer.php');
                    exit;
                }
                establecer_sesion_completa($user, $empresaId);
                unset(
                    $_SESSION['pre_auth_user_id'],
                    $_SESSION['pre_auth_empresa'],
                    $_SESSION['pre_auth_user'],
                    $_SESSION['pre_auth_rol'],
                    $_SESSION['pre_auth_es_developer'],
                    $_SESSION['2fa_enviado']
                );
                $rolEmp = $_SESSION['rol_en_empresa'] ?? '';
                if (in_array($rolEmp, ['admin', 'superadmin', 'operador'], true)) $dest = 'dashboard_admin.php';
                elseif ($rolEmp === 'finanzas')  $dest = 'dashboard_finanzas.php';
                elseif ($rolEmp === 'vigilante') $dest = 'dashboard_vigilante.php';
                else                             $dest = 'dashboard_cliente.php';
                header("Location: $dest");
                exit;
            }
            $error = $v['mensaje'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verificación 2FA - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=29">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    .otp-input {
        text-align: center !important;
        font-size: 2rem !important;
        letter-spacing: 0.8rem !important;
        font-weight: 800 !important;
        font-family: 'JetBrains Mono', monospace !important;
        padding: 1.2rem 0.5rem 1.2rem 1.3rem !important;
    }
    .otp-input::placeholder {
        letter-spacing: 0.6rem;
        color: rgba(148, 163, 184, 0.3);
    }
    .shield-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(6, 182, 212, 0.12));
        border: 2px solid #10b981;
        font-size: 2.5rem;
        color: #10b981;
        margin-bottom: 1rem;
        box-shadow: 0 0 40px rgba(16, 185, 129, 0.4);
        animation: shieldPulse 3s ease-in-out infinite;
    }
    @keyframes shieldPulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 40px rgba(16, 185, 129, 0.4); }
        50% { transform: scale(1.05); box-shadow: 0 0 60px rgba(16, 185, 129, 0.6); }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<canvas id="particles-canvas"></canvas>

<div class="cyber-login-wrapper">
  <div class="cyber-login-card" style="max-width: 480px;">

    <div class="login-brand">
      <div class="shield-icon">◈</div>
      <h1 class="login-title">Verificación en 2 Pasos</h1>
      <p class="login-subtitle">Hemos enviado un código de 6 dígitos para proteger tu cuenta</p>
    </div>

    <?php if ($error): ?>
      <div class="cyber-alert">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
      <div class="cyber-success">✓ <?= htmlspecialchars($info) ?></div>
    <?php endif; ?>

    <form method="POST" class="cyber-form" autocomplete="off">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="codigo">Código de verificación</label>
        <input type="text" id="codigo" name="codigo" maxlength="6" pattern="[0-9]{6}"
               class="otp-input" placeholder="000000" required autofocus
               inputmode="numeric" autocomplete="one-time-code">
      </div>
      <input type="hidden" name="action_verificar" value="1">
      <button type="submit" class="cyber-btn-primary btn-block btn-lg" style="margin-top: 0.5rem;">
        Verificar e ingresar
      </button>
    </form>

    <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid rgba(255,255,255,0.06);">
      <p style="color: #64748b; font-size: 0.78rem; text-align: center; margin-bottom: 0.6rem;">
        ¿No recibiste el código? Puedes reenviarlo:
      </p>
      <form method="POST" style="display: flex; justify-content: center;">
        <?= csrf_field() ?>
        <input type="hidden" name="action_enviar" value="1">
        <button type="submit" name="metodo" value="email" class="cyber-btn-outline" style="width: 100%;">📧 Reenviar por Email</button>
      </form>
    </div>

    <div style="text-align: center; margin-top: 1.25rem;">
      <a href="login_empresa.php" style="color: #64748b; font-size: 0.82rem;">← Cancelar e ir al login</a>
    </div>

  </div>
</div>

<script src="assets/js/app.js?v=999"></script>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>