<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

$error = '';
$empresas = $pdo->query("SELECT id, nombre FROM empresas WHERE estado='activa' ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesion expirada.';
    } else {
        $empresaId = (int)($_POST['empresa_id'] ?? 0);
        $res = autenticar_paso1($pdo, trim($_POST['username'] ?? ''), $_POST['password'] ?? '', $empresaId);
        if (!$res['exito']) { $error = $res['mensaje']; }
        elseif (!in_array($res['rol_empresa'], ['admin','superadmin'], true)) {
            $error = 'Esta cuenta no tiene rol de Administrador en la empresa seleccionada.';
        } else {
            $_SESSION['pre_auth_user_id'] = $res['user']['id'];
            $_SESSION['pre_auth_empresa'] = $empresaId;
            $_SESSION['pre_auth_user']    = $res['user'];
            $_SESSION['pre_auth_rol']     = 'admin';
            header('Location: verify_2fa.php'); exit;
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SmartPark - Portal Administrador</title>
<link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<nav class="cyber-navbar"><div class="nav-container">
  <a href="index.php" class="cyber-brand"><img src="IMG/SMARTPARK_TRANSPARENTE.png" class="nav-logo"><span class="brand-title">Admin Shield</span></a>
</div></nav>
<div class="cyber-login-wrapper">
  <div class="cyber-card cyber-entrance" style="max-width:460px;width:100%;border-color:var(--primary-green)">
    <h2 style="color:var(--primary-green);text-align:center;margin-bottom:.5rem">Portal Administrador</h2>
    <p style="color:var(--text-muted);text-align:center;font-size:.85rem;margin-bottom:1.5rem">Acceso exclusivo - requiere 2FA obligatorio.</p>
    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST" class="cyber-form">
      <?= csrf_field() ?>
      <div class="form-group"><label>Empresa</label>
        <select name="empresa_id" required>
          <option value="">-- Selecciona --</option>
          <?php foreach ($empresas as $e): ?><option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Usuario admin</label><input type="text" name="username" required></div>
      <div class="form-group"><label>Contrasena</label><input type="password" name="password" required></div>
      <button type="submit" class="cyber-btn-primary" style="width:100%;background:var(--primary-green);color:#05070d">Ingresar con 2FA</button>
    </form>
    <div style="margin-top:1.5rem;text-align:center;font-size:.85rem"><a href="login_empresa.php">Volver al portal general</a></div>
  </div>
</div>
</body></html>
