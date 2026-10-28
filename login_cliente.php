<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

if (!empty($_SESSION['usuario_id'])) {
    $rol = $_SESSION['rol_en_empresa'] ?? ($_SESSION['role'] ?? '');
    if ($rol === 'developer') { header('Location: dashboard_developer.php'); exit; }
    if (in_array($rol, ['admin', 'superadmin', 'operador'], true)) { header('Location: dashboard_admin.php'); exit; }
    if ($rol === 'finanzas') { header('Location: dashboard_finanzas.php'); exit; }
    if ($rol === 'vigilante') { header('Location: dashboard_vigilante.php'); exit; }
    header('Location: dashboard_cliente.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Usuario y contraseña son obligatorios.';
        } else {
            try {
                // 1. Buscar usuario (Evitar duplicados)
                $stmt = $pdo->prepare("SELECT * FROM user WHERE (username = :u1 OR correo = :u2) LIMIT 1");
                $stmt->execute(['u1' => $username, 'u2' => $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    
                    // 2. VALIDACIÓN DE SEGURIDAD: Si no es cliente, pedirle que use su portal
                    // (Evita que admins entren sin 2FA por aquí)
                    if ($user['role'] !== 'cliente' && $user['role'] !== 'superadmin') {
                        $error = 'Esta cuenta es de tipo ' . strtoupper($user['role']) . '. Por favor, usa el portal de ' . ($user['role'] === 'developer' ? 'Developer' : 'Empresa') . ' para iniciar sesión.';
                    } else {
                        // 3. Buscar su empresa
                        $stmtEmp = $pdo->prepare("SELECT empresa_id, rol_en_empresa, parqueadero_id FROM usuarios_empresa WHERE user_id = :uid AND activo = 1 LIMIT 1");
                        $stmtEmp->execute(['uid' => $user['id']]);
                        $emp = $stmtEmp->fetch();

                        if (!$emp) {
                            $firstEmp = $pdo->query("SELECT id FROM empresas WHERE estado='activa' LIMIT 1")->fetch();
                            if ($firstEmp) {
                                $empresaId = (int)$firstEmp['id'];
                                $pdo->prepare("INSERT INTO usuarios_empresa (user_id, empresa_id, parqueadero_id, rol_en_empresa, activo) VALUES (:u, :e, 1, 'cliente', 1)")
                                    ->execute(['u' => $user['id'], 'e' => $empresaId]);
                                $emp = ['empresa_id' => $empresaId, 'rol_en_empresa' => 'cliente', 'parqueadero_id' => 1];
                            }
                        }

                        if ($emp) {
                            // 4. Login exitoso para CLIENTE (sin 2FA por ser cliente, pero blindado)
                            $empresaId = (int)$emp['empresa_id'];
                            $user['rol_en_empresa'] = 'cliente';
                            $user['parqueadero_id'] = $emp['parqueadero_id'];
                            establecer_sesion_completa($user, $empresaId);
                            header('Location: dashboard_cliente.php'); exit;
                        } else {
                            $error = 'No hay empresas activas en el sistema.';
                        }
                    }
                } else {
                    $error = 'Usuario o contraseña incorrectos.';
                }
            } catch (\Throwable $e) {
                error_log('login_cliente: ' . $e->getMessage());
                $error = 'Error interno. Intenta de nuevo.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Cliente - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=999">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<!-- QUITAMOS client-theme.css para que no rompa el diseño -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
<style>
    /* Forzar botón verde/cyan idéntico a la Imagen 4 */
    .cyber-btn-primary {
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%) !important;
        box-shadow: 0 4px 20px rgba(16, 185, 129, 0.25) !important;
        color: #030712 !important;
    }
    .cyber-btn-primary:hover {
        box-shadow: 0 15px 40px rgba(16, 185, 129, 0.5) !important;
    }
    .client-badge {
        display: inline-block; padding: 0.3rem 0.85rem; background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 100px; color: #10b981;
        font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; margin-bottom: 1rem;
    }

    /* FIX: Centrar el contenido de la columna izquierda */
    .split-left-content {
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .scene-header {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        text-align: center !important;
    }
    .scene-header img {
        margin: 0 auto 1.25rem auto !important;
        display: block !important;
    }
    .scene-header h2, .scene-header p {
        text-align: center !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }
</style>
</head>
<body class="cyber-body">

<canvas id="particles-canvas"></canvas>

<div class="split-login">
  <div class="split-left">
    <div class="split-left-content">
      <div class="scene-header">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" onerror="this.src='IMG/SMARTPARK.png'">
        <span class="client-badge">◈ PORTAL CLIENTE</span>
        <h2>Tu parqueadero<br>en la palma de tu mano</h2>
        <p>Consulta tu celda, paga desde el celular y guarda tu historial de parqueos.</p>
      </div>
      <div class="scene-middle">
        <div class="scene-parking">
          <div class="parking-grid">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <div class="park-cell <?= $i % 3 == 0 ? 'occupied' : 'free' ?>" data-cell="<?= $i ?>">
                <span class="cell-dot"></span>
                <?php if ($i % 3 == 0): ?>
                <svg class="car-svg" viewBox="0 0 40 80" xmlns="http://www.w3.org/2000/svg"><rect x="6" y="8" width="28" height="64" rx="10" fill="#06b6d4" opacity="0.85"/><rect x="12" y="16" width="16" height="10" rx="3" fill="#020617" opacity="0.35"/></svg>
                <?php endif; ?>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>
      <div class="scene-footer">
        <div class="scene-footer-item"><span class="sfi-icon">◎</span><div class="sfi-text">Pago sin filas<small>Desde tu celular</small></div></div>
        <div class="scene-footer-item"><span class="sfi-icon">⌁</span><div class="sfi-text">Tu historial<small>Siempre disponible</small></div></div>
      </div>
    </div>
  </div>

  <div class="split-right">
    <div class="split-form">
      <div style="margin-bottom: 1.75rem;">
        <h2 style="font-size: 1.75rem; font-weight: 800; margin-top: 1rem; letter-spacing: -0.02em; color: #f1f5f9;">Bienvenido de nuevo</h2>
        <p style="color: #64748b; font-size: 0.88rem; margin-top: 0.4rem;">Ingresa tus credenciales para gestionar tu parqueadero</p>
      </div>

      <?php if ($error): ?>
        <div class="cyber-alert" style="margin-bottom: 1rem;">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="cyber-form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="username">Usuario o correo</label>
          <input type="text" id="username" name="username" placeholder="tu_usuario" required autofocus autocomplete="username">
        </div>
        <div class="form-group">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
        </div>
        <button type="submit" class="cyber-btn-primary btn-block btn-lg" style="margin-top: 0.75rem;">Ingresar al portal</button>
      </form>

      <div style="text-align:center; margin-top:1.25rem;">
        <a href="olvidar_password.php" style="color:#64748b; font-size:.82rem; text-decoration:none;">¿Olvidaste tu contraseña?</a>
      </div>

      <div class="login-help" style="margin-top: 1.5rem;">
        <a href="registro_cliente.php">Crear cuenta nueva</a>
        <a href="index.php">← Volver al inicio</a>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>