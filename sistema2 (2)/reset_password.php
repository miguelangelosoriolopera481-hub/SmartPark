<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
send_security_headers();

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error=''; $exito=''; $valido=false; $user=null;

if ($token !== '') {
    $stmt = $pdo->prepare("SELECT pr.*, u.username, u.correo FROM password_resets pr JOIN user u ON u.id = pr.user_id WHERE pr.usado=0 AND pr.expira_en > NOW() ORDER BY pr.id DESC LIMIT 20");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($token, $row['token_hash'])) { $valido = true; $user = $row; break; }
    }
    if (!$valido) $error = 'El enlace es invalido o ya expiro.';
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $valido) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) { $error='Formulario expirado.'; }
    else {
        $nueva = (string)($_POST['password_nueva'] ?? '');
        $confirma = (string)($_POST['password_confirma'] ?? '');
        if (!validarPassword($nueva)) { $error = requisitosPassword(); }
        elseif ($nueva !== $confirma) { $error = 'Las contrasenas no coinciden.'; }
        else {
            $pdo->beginTransaction();
            try {
                $hash = hash_password_seguro($nueva);
                $pdo->prepare("UPDATE user SET password=:h, must_change_password=0 WHERE id=:id")->execute(['h'=>$hash,'id'=>$user['user_id']]);
                $pdo->prepare("UPDATE password_resets SET usado=1 WHERE user_id=:id")->execute(['id'=>$user['user_id']]);
                $pdo->commit();
                $exito = 'Contrasena actualizada. Ya puedes iniciar sesion.';
                $valido = false;
            } catch (\Throwable $e) { $pdo->rollBack(); $error = 'Error al actualizar.'; }
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><title>Restablecer Contrasena</title><link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<div class="cyber-login-wrapper">
  <div class="cyber-card cyber-entrance" style="max-width:440px;width:100%">
    <h2 style="color:var(--primary-cyan);text-align:center">Restablecer Contrasena</h2>
    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="cyber-success"><?= htmlspecialchars($exito) ?></div>
      <a href="login_empresa.php" class="cyber-btn-primary" style="display:block;text-align:center;margin-top:1rem">Ir al login</a>
    <?php endif; ?>
    <?php if ($valido && !$exito): ?>
    <form method="POST" class="cyber-form">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <div class="form-group"><label>Nueva contrasena</label><input type="password" name="password_nueva" required minlength="8" autofocus></div>
      <div class="form-group"><label>Confirmar contrasena</label><input type="password" name="password_confirma" required minlength="8"></div>
      <button type="submit" class="cyber-btn-primary" style="width:100%">Guardar</button>
    </form>
    <?php endif; ?>
  </div>
</div>
</body></html>
