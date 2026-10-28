<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
send_security_headers();

if (!empty($_SESSION['usuario_id']) && ($_SESSION['role'] ?? '') === 'developer') {
    header('Location: dashboard_developer.php');
    exit;
}

$error = '';

try {
    $totalEmpresas = (int)$pdo->query("SELECT COUNT(*) FROM empresas")->fetchColumn();
    $totalCupos    = (int)$pdo->query("SELECT COUNT(*) FROM cupos")->fetchColumn();
    $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
    $totalActivos  = (int)$pdo->query("SELECT COUNT(*) FROM registros_parqueo WHERE estado='activo'")->fetchColumn();
} catch (\Throwable $e) {
    $totalEmpresas = $totalCupos = $totalUsuarios = $totalActivos = 0;
}

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
                $stmt = $pdo->prepare("SELECT * FROM user WHERE (username = :u1 OR correo = :u2) AND role = 'developer' LIMIT 1");
                $stmt->execute(['u1' => $username, 'u2' => $username]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'No existe un usuario developer con ese nombre.';
                } elseif (!password_verify($password, $user['password'])) {
                    $error = 'Contraseña incorrecta. Verifica mayúsculas y minúsculas.';
                } elseif (empty($user['correo'])) {
                    $error = 'Tu cuenta developer no tiene correo configurado. Contacta al soporte.';
                } else {
                    
                    $_SESSION['pre_auth_user_id']      = (int)$user['id'];
                    $_SESSION['pre_auth_empresa']      = 0;
                    $_SESSION['pre_auth_user']         = $user;
                    $_SESSION['pre_auth_rol']          = 'developer';
                    $_SESSION['pre_auth_es_developer'] = true;
                    $_SESSION['2fa_enviado']           = false;

                    header('Location: verify_2fa.php');
                    exit;
                }
            } catch (\Throwable $e) {
                $error = 'ERROR REAL: ' . $e->getMessage() . ' | Archivo: ' . $e->getFile() . ' línea ' . $e->getLine();
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
<title>Developer Portal - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=21">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<div class="split-login">

  <div class="split-left">
    <div class="split-left-content">
      <div class="scene-header">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark">
        <h2>Panel de control<br>para developers</h2>
        <p>Administra todas las empresas, configura el sistema y despliega nuevas versiones.</p>
      </div>

      <div class="scene-middle">
        <div class="scene-code">
          <span class="code-bracket b1">{</span>
          <span class="code-bracket b2">}</span>
          <div class="code-line l1"><span class="c">// Inicializando SmartPark Pro</span></div>
          <div class="code-line l2"><span class="k">const</span> <span class="n">app</span> = <span class="f">require</span>(<span class="s">'smartpark'</span>);</div>
          <div class="code-line l3">app.<span class="f">configure</span>({ empresas: <span class="n">true</span>, ia: <span class="s">'gemini'</span> });</div>
          <div class="code-line l4"><span class="k">await</span> app.<span class="f">start</span>(<span class="s">'v3.0'</span>);</div>
          <div class="code-line l5"><span class="c">// Sistema listo para developers</span></div>
          <div class="code-line l6"><span class="f">console</span>.<span class="n">log</span>(<span class="s">'Bienvenido'</span>)<span class="code-cursor"></span></div>
        </div>
      </div>

      <div class="scene-footer">
        <div class="scene-footer-item"><span class="sfi-icon">◇</span><div class="sfi-text">Acceso global<small>Todas las empresas</small></div></div>
        <div class="scene-footer-item"><span class="sfi-icon">▲</span><div class="sfi-text">Deploy de versiones<small>Sin downtime</small></div></div>
        <div class="scene-footer-item"><span class="sfi-icon">◐</span><div class="sfi-text">Logs y métricas<small>En tiempo real</small></div></div>
        <div class="scene-footer-item"><span class="sfi-icon">◇</span><div class="sfi-text">Configuración IA<small>API keys globales</small></div></div>
      </div>

      <div class="scene-stats">
        <div class="scene-stat"><div class="ss-num" data-counter="<?= $totalEmpresas ?>">0</div><div class="ss-lbl">Empresas</div></div>
        <div class="scene-stat"><div class="ss-num" data-counter="<?= $totalCupos ?>">0</div><div class="ss-lbl">Celdas</div></div>
        <div class="scene-stat"><div class="ss-num" data-counter="<?= $totalUsuarios ?>">0</div><div class="ss-lbl">Usuarios</div></div>
        <div class="scene-stat"><div class="ss-num" data-counter="<?= $totalActivos ?>">0</div><div class="ss-lbl">Activos</div></div>
      </div>

      <div class="scene-why">
        <h3>Herramientas de developer</h3>
        <ul class="scene-why-list">
          <li><span class="chk">✓</span> Aprobar/rechazar empresas</li>
          <li><span class="chk">✓</span> Configurar IA globalmente</li>
          <li><span class="chk">✓</span> Ver finanzas totales</li>
          <li><span class="chk">✓</span> Gestionar suscripciones</li>
          <li><span class="chk">✓</span> Monitoreo del sistema</li>
          <li><span class="chk">✓</span> Auditoría completa</li>
        </ul>
      </div>

      <div class="scene-support">
        <span class="sup-icon">◇</span>
        <div class="sup-text"><strong>¿Necesitas escalar el sistema?</strong><span>Documentación completa disponible</span></div>
        <a href="mailto:dev@smartpark.com" class="sup-link">Documentación →</a>
      </div>
    </div>
  </div>

  <div class="split-right">
    <div class="split-form">
      <div style="margin-bottom: 1.75rem;">
        <span class="cyber-badge" style="background: rgba(147, 51, 234, 0.15); border-color: rgba(147, 51, 234, 0.5); color: #a78bfa;">◇ Developer Portal</span>
        <h2 style="font-size: 1.75rem; font-weight: 800; margin-top: 1rem; letter-spacing: -0.02em;">Acceso developers</h2>
        <p style="color: #64748b; font-size: 0.88rem; margin-top: 0.4rem;">Solo cuentas con rol developer pueden ingresar aquí.</p>
      </div>

      <?php if ($error): ?>
        <div class="cyber-alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="cyber-form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="username">Usuario developer</label>
          <input type="text" id="username" name="username" placeholder="developer" required autofocus autocomplete="username">
        </div>
        <div class="form-group">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" placeholder="••••••••••" required autocomplete="current-password">
        </div>
        <button type="submit" class="cyber-btn-primary btn-block btn-lg" style="margin-top: 0.75rem;">Acceder al panel global</button>
      </form>

      <div class="login-help" style="margin-top: 1.5rem;">
        <a href="olvidar_password.php">Olvidé mi contraseña</a>
        <a href="login_empresa.php">← Portal de empresas</a>
        <div style="display: flex; gap: 0.75rem; justify-content: center; margin-top: 0.5rem; flex-wrap: wrap;">
          
          <a href="registro_empresa.php" style="font-size: 0.8rem;">Registro</a>
          <a href="index.php" style="font-size: 0.8rem;">Inicio</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="assets/js/app.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
