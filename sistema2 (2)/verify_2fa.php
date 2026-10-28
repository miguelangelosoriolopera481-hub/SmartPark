<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'mailer.php';
require_once 'sms_service.php';
require_once 'csrf.php';
send_security_headers();

if (empty($_SESSION['pre_auth_user_id'])) { header('Location: login_empresa.php'); exit; }
$user = $_SESSION['pre_auth_user'];
$empresaId = (int)$_SESSION['pre_auth_empresa'];
$userId = (int)$user['id'];
$error = ''; $info = '';

if (($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action_enviar'])) || empty($_SESSION['2fa_enviado'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null) && isset($_POST['action_enviar'])) {
        $error = 'Sesion expirada.';
    } else {
        $metodo = $_POST['metodo'] ?? 'email';
        if (!in_array($metodo,['email','sms'],true)) $metodo='email';
        $destino = $metodo==='email' ? ($user['correo'] ?? '') : ($user['telefono'] ?? '');
        if ($destino === '') { $error = "No hay " . ($metodo==='email'?'correo':'telefono') . " registrado."; }
        else {
            $codigo = crear_otp_2fa($pdo, $userId, $empresaId, $metodo, $destino);
            if ($metodo === 'email') {
                enviar_correo($pdo, $destino, 'Codigo de verificacion SmartPark',
                    "<p>Tu codigo es: <b style='font-size:1.6rem;color:#00f2fe'>{$codigo}</b></p><p>Expira en 5 minutos.</p>");
            } else {
                enviar_sms($pdo, $destino, "SmartPark: tu codigo es {$codigo} (valido 5 min).");
            }
            $_SESSION['2fa_enviado'] = true;
            $info = "Codigo enviado a " . preg_replace('/(.{2}).+(.{2})/','$1***$2',$destino);
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action_verificar'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) { $error='Sesion expirada.'; }
    else {
        $codigo = preg_replace('/\D/','', $_POST['codigo'] ?? '');
        if (strlen($codigo) !== 6) { $error = 'El codigo debe tener 6 digitos.'; }
        else {
            $v = verificar_otp_2fa($pdo, $userId, $codigo);
            if ($v['ok']) {
                establecer_sesion_completa($user, $empresaId);
                unset($_SESSION['pre_auth_user_id'],$_SESSION['pre_auth_empresa'],$_SESSION['pre_auth_user'],$_SESSION['pre_auth_rol'],$_SESSION['2fa_enviado']);
                $dest = in_array($_SESSION['rol_en_empresa'],['admin','operador','superadmin','finanzas'],true) ? 'dashboard_admin.php' : 'dashboard_cliente.php';
                header("Location: $dest"); exit;
            }
            $error = $v['mensaje'];
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verificacion 2FA</title>
<link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<div class="cyber-login-wrapper">
  <div class="cyber-card cyber-entrance" style="max-width:420px;width:100%">
    <h2 style="color:var(--primary-cyan);text-align:center">Verificacion en 2 Pasos</h2>
    <p style="color:var(--text-muted);text-align:center;font-size:.85rem;margin-bottom:1rem">Ingresa el codigo de 6 digitos.</p>
    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="cyber-success"><?= htmlspecialchars($info) ?></div><?php endif; ?>
    <form method="POST" class="cyber-form">
      <?= csrf_field() ?>
      <div class="form-group"><label>Codigo OTP (6 digitos)</label>
        <input type="text" name="codigo" maxlength="6" minlength="6" pattern="[0-9]{6}" required autofocus
               style="text-align:center;font-size:1.6rem;letter-spacing:.5rem"></div>
      <input type="hidden" name="action_verificar" value="1">
      <button type="submit" class="cyber-btn-primary" style="width:100%">Verificar e Ingresar</button>
    </form>
    <form method="POST" style="margin-top:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action_enviar" value="1">
      <div style="display:flex;gap:.5rem">
        <button type="submit" name="metodo" value="email" class="cyber-btn-outline" style="flex:1">Reenviar por Email</button>
        <button type="submit" name="metodo" value="sms" class="cyber-btn-outline" style="flex:1">Reenviar por SMS</button>
      </div>
    </form>
    <div style="margin-top:1rem;text-align:center;font-size:.8rem"><a href="login_empresa.php">Cancelar</a></div>
  </div>
</div>
</body></html>
