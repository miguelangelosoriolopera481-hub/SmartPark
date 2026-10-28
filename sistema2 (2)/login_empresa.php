<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
send_security_headers();

$error = '';
$empresas = $pdo->query("SELECT id, nombre FROM empresas WHERE estado='activa' ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesion expirada. Recarga la pagina.';
    } else {
        $empresaId = (int)($_POST['empresa_id'] ?? 0);
        $res = autenticar_paso1($pdo, trim($_POST['username'] ?? ''), $_POST['password'] ?? '', $empresaId);
        if (!$res['exito']) { $error = $res['mensaje']; }
        else {
            $_SESSION['pre_auth_user_id'] = $res['user']['id'];
            $_SESSION['pre_auth_empresa'] = $empresaId;
            $_SESSION['pre_auth_user']    = $res['user'];
            $_SESSION['pre_auth_rol']     = $res['rol_empresa'];
            if ($res['necesita_2fa']) { header('Location: verify_2fa.php'); exit; }
            establecer_sesion_completa($res['user'], $empresaId);
            unset($_SESSION['pre_auth_user_id'],$_SESSION['pre_auth_empresa'],$_SESSION['pre_auth_user'],$_SESSION['pre_auth_rol']);
            $dest = in_array($res['rol_empresa'],['admin','operador','superadmin'],true) ? 'dashboard_admin.php' : 'dashboard_cliente.php';
            header("Location: $dest"); exit;
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SmartPark - Acceso Empresarial</title>
<link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<nav class="cyber-navbar"><div class="nav-container">
  <a href="index.php" class="cyber-brand"><img src="IMG/SMARTPARK_TRANSPARENTE.png" class="nav-logo"><span class="brand-title">SmartPark Pro</span></a>
</div></nav>

<div class="cyber-login-wrapper">
  <div class="cyber-card cyber-entrance" style="max-width:460px;width:100%">
    <h2 style="color:var(--primary-cyan);text-align:center;margin-bottom:1.5rem">Acceso Multi-Empresa</h2>
    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST" class="cyber-form">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>Empresa / Parqueadero</label>
        <select name="empresa_id" required>
          <option value="">-- Selecciona tu empresa --</option>
          <?php foreach ($empresas as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Usuario o correo</label><input type="text" name="username" required autocomplete="username"></div>
      <div class="form-group"><label>Contrasena</label><input type="password" name="password" required autocomplete="current-password"></div>
      <button type="submit" class="cyber-btn-primary" style="width:100%">Ingresar</button>
    </form>
    <div style="margin-top:1.5rem;display:flex;flex-direction:column;gap:.5rem;font-size:.85rem;text-align:center">
      <a href="olvidar_password.php">Olvide mi contrasena</a>
      <a href="login_empresa_admin.php">Portal exclusivo Administrador</a>
      <a href="mailto:soporte@smartpark.local">Soporte tecnico directo</a>
    </div>
  </div>
</div>
</body></html>
