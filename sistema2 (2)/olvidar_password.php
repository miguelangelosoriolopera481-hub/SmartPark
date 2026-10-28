<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'mailer.php';
require_once 'sms_service.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

$msg=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) { $error='Sesion expirada.'; }
    else {
        $ident = trim($_POST['identificador'] ?? '');
        $metodo = $_POST['metodo'] ?? 'email';
        if (!in_array($metodo,['email','sms'],true)) $metodo='email';
        $stmt = $pdo->prepare("SELECT id, nombre, correo, telefono FROM user WHERE username = :i OR correo = :i LIMIT 1");
        $stmt->execute(['i' => $ident]);
        $user = $stmt->fetch();
        $msg = 'Si los datos coinciden, enviaremos instrucciones de recuperacion.';
        if ($user) {
            $destino = $metodo==='email' ? ($user['correo'] ?? '') : ($user['telefono'] ?? '');
            if ($destino !== '') {
                $token = bin2hex(random_bytes(32));
                $hash = password_hash($token, PASSWORD_BCRYPT, ['cost'=>10]);
                $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, metodo, destino, expira_en, ip) VALUES (:u,:h,:m,:d,(NOW() + INTERVAL 30 MINUTE),:ip)")
                    ->execute(['u'=>$user['id'],'h'=>$hash,'m'=>$metodo,'d'=>$destino,'ip'=>$_SERVER['REMOTE_ADDR'] ?? null]);
                $link = rtrim(env('APP_URL',''),'/') . '/reset_password.php?token=' . urlencode($token);
                if ($metodo === 'email') {
                    enviar_correo($pdo, $destino, 'Recuperacion de contrasena SmartPark',
                        "<p>Hola {$user['nombre']},</p><p>Restablece tu contrasena (valido 30 min):</p><p><a href=\"{$link}\">{$link}</a></p>");
                } else {
                    enviar_sms($pdo, $destino, "SmartPark: restablece tu clave aqui: $link");
                }
            }
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recuperar Contrasena</title>
<link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<div class="cyber-login-wrapper">
  <div class="cyber-card cyber-entrance" style="max-width:420px;width:100%">
    <h2 style="color:var(--primary-cyan);text-align:center">Recuperar Contrasena</h2>
    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="cyber-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <form method="POST" class="cyber-form">
      <?= csrf_field() ?>
      <div class="form-group"><label>Usuario o correo</label><input type="text" name="identificador" required></div>
      <div class="form-group"><label>Metodo de entrega</label>
        <select name="metodo">
          <option value="email">Correo electronico</option>
          <option value="sms">SMS</option>
        </select>
      </div>
      <button type="submit" class="cyber-btn-primary" style="width:100%">Enviar instrucciones</button>
    </form>
    <div style="margin-top:1rem;text-align:center;font-size:.85rem"><a href="login_empresa.php">Volver</a></div>
  </div>
</div>
</body></html>
