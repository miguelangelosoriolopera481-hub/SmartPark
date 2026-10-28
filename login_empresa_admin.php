<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
send_security_headers();

if (!empty($_SESSION['usuario_id']) && !empty($_SESSION['empresa_id'])) {
    $rol = $_SESSION['rol_en_empresa'] ?? '';
    if (in_array($rol, ['admin', 'superadmin'], true)) {
        header('Location: dashboard_admin.php');
        exit;
    }
}

$error = '';
$nit_pre = trim($_GET['nit'] ?? '');

try {
    $totalEmpresas = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado='activa'")->fetchColumn();
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
        $nit      = trim($_POST['nit'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($nit === '' || $username === '' || $password === '') {
            $error = 'Todos los campos son obligatorios .';
        } else {
            // Buscar empresa por NIT
            $stmtEmp = $pdo->prepare("SELECT id, nombre FROM empresas WHERE nit = :nit AND estado = 'activa' LIMIT 1");
            $stmtEmp->execute(['nit' => $nit]);
            $empresa = $stmtEmp->fetch();

            if (!$empresa) {
                $error = 'No existe una empresa activa con ese NIT.';
            } else {
                $empresaId = (int)$empresa['id'];
                $res = autenticar_paso1($pdo, $username, $password, $empresaId);

                if (!$res['exito']) {
                    $error = $res['mensaje'];
                } elseif (!in_array($res['rol_empresa'], ['admin', 'superadmin'], true)) {
                    $error = 'Esta cuenta no tiene rol de Administrador en esa empresa.';
                } else {
                    $_SESSION['pre_auth_user_id'] = $res['user']['id'];
                    $_SESSION['pre_auth_empresa'] = $empresaId;
                    $_SESSION['pre_auth_user']    = $res['user'];
                    $_SESSION['pre_auth_rol']     = 'admin';
                    header('Location: verify_2fa.php');
                    exit;
                }
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
<title>Portal Administrador - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=19">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<div class="split-login">

  <!-- IZQUIERDA: Animación de SEGURIDAD mejorada -->
  <div class="split-left">
    <div class="split-left-content">

      <div class="scene-header">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark">
        <h2>Acceso seguro<br>para administradores</h2>
        <p>Verificación en dos pasos, cifrado Argon2id y auditoría completa de cada acción.</p>
      </div>

      <div class="scene-middle">
        <div class="scene-security">

          <!-- Anillos de radar expandiéndose -->
          <div class="sec-radar-ring"></div>
          <div class="sec-radar-ring"></div>
          <div class="sec-radar-ring"></div>

          <!-- Escudo central -->
          <div class="sec-shield-center">
            <div class="sec-shield-inner">✓</div>
          </div>

          <!-- Puntos orbitando alrededor del escudo -->
          <div class="sec-orbit">
            <span class="sec-orbit-dot"></span>
            <span class="sec-orbit-dot"></span>
            <span class="sec-orbit-dot"></span>
            <span class="sec-orbit-dot"></span>
          </div>

          <!-- Línea de escaneo horizontal -->
          <div class="sec-scanline-new"></div>

        </div>
      </div>

      <div class="scene-footer">
        <div class="scene-footer-item">
          <span class="sfi-icon">◆</span>
          <div class="sfi-text">Cifrado Argon2id<small>Contraseñas protegidas</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">✓</span>
          <div class="sfi-text">2FA obligatorio<small>Telegram + email</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">▤</span>
          <div class="sfi-text">Auditoría completa<small>Cada acción registrada</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">◈</span>
          <div class="sfi-text">Aislamiento total<small>Solo tu empresa</small></div>
        </div>
      </div>

      <div class="scene-stats">
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= $totalEmpresas ?>">0</div>
          <div class="ss-lbl">Empresas</div>
        </div>
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= $totalCupos ?>">0</div>
          <div class="ss-lbl">Celdas</div>
        </div>
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= $totalUsuarios ?>">0</div>
          <div class="ss-lbl">Usuarios</div>
        </div>
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= $totalActivos ?>">0</div>
          <div class="ss-lbl">Activos</div>
        </div>
      </div>

      <div class="scene-why">
        <h3>Zona restringida</h3>
        <ul class="scene-why-list">
          <li><span class="chk">✓</span> Solo admin de la empresa</li>
          <li><span class="chk">✓</span> Verificación 2FA obligatoria</li>
          <li><span class="chk">✓</span> Sesión auditada</li>
          <li><span class="chk">✓</span> Bloqueo tras 5 intentos fallidos</li>
          <li><span class="chk">✓</span> IP registrada en logs</li>
          <li><span class="chk">✓</span> Acceso a finanzas y personal</li>
        </ul>
      </div>

      <div class="scene-support">
        <span class="sup-icon">◈</span>
        <div class="sup-text">
          <strong>¿Problemas de acceso?</strong>
          <span>Contacta al soporte para desbloqueo</span>
        </div>
        <a href="mailto:soporte@smartpark.com" class="sup-link">Contactar →</a>
      </div>

    </div>
  </div>

  <!-- DERECHA: Formulario -->
  <div class="split-right">
    <div class="split-form">

      <div style="margin-bottom: 1.75rem;">
        <span class="cyber-badge" style="background: rgba(6, 182, 212, 0.15); border-color: rgba(6, 182, 212, 0.5); color: #06b6d4;">
          ◈ Portal Administrador
        </span>
        <h2 style="font-size: 1.75rem; font-weight: 800; margin-top: 1rem; letter-spacing: -0.02em;">
          Acceso con NIT
        </h2>
        <p style="color: #64748b; font-size: 0.88rem; margin-top: 0.4rem;">
          Ingresa con el NIT de tu empresa. Requiere verificación 2FA.
        </p>
      </div>

      <?php if ($error): ?>
        <div class="cyber-alert">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="cyber-form" autocomplete="off">
        <?= csrf_field() ?>

        <div class="form-group">
          <label for="nit">NIT de tu empresa</label>
          <input type="text" id="nit" name="nit" placeholder="Ej: 900123456-7" required autofocus value="<?= htmlspecialchars($nit_pre) ?>">
          <small style="color: #64748b; font-size: 0.72rem; margin-top: 0.3rem; display: block;">Lo encuentras en el registro o en tu factura</small>
        </div>

        <div class="form-group">
          <label for="username">Usuario administrador</label>
          <input type="text" id="username" name="username" placeholder="admin_empresa" required autocomplete="username">
        </div>

        <div class="form-group">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" placeholder="••••••••••" required autocomplete="current-password">
        </div>

        <button type="submit" class="cyber-btn-primary btn-block btn-lg" style="margin-top: 0.75rem;">
          Continuar con verificación 2FA
        </button>
      </form>

      <div class="login-help" style="margin-top: 1.5rem;">
        <a href="olvidar_password.php">Olvidé mi contraseña</a>
        <a href="login_empresa.php">← Portal general de empresas</a>
        <div style="display: flex; gap: 0.75rem; justify-content: center; margin-top: 0.5rem; flex-wrap: wrap;">
          <a href="login_developer.php" style="font-size: 0.8rem;">Developer</a>
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
