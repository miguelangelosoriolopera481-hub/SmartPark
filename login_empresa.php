<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
send_security_headers();

// Si hay sesión activa, redirigir según rol
if (!empty($_SESSION['usuario_id'])) {
    $rolGlobal = $_SESSION['role'] ?? '';
    $rolEmpresa = $_SESSION['rol_en_empresa'] ?? '';

    // Developer: siempre puede acceder a cualquier lado
    if ($rolGlobal === 'developer') {
        // Si viene con ?cambiar=1, permitir ver el login
        if (($_GET['cambiar'] ?? '') !== '1') {
            header('Location: dashboard_developer.php');
            exit;
        }
    } else {
        // Otros roles: redirigir a su dashboard
        if (($_GET['cambiar'] ?? '') !== '1') {
            if (in_array($rolEmpresa, ['admin', 'superadmin', 'operador'], true)) {
                header('Location: dashboard_admin.php'); exit;
            }
            if ($rolEmpresa === 'finanzas')   { header('Location: dashboard_finanzas.php'); exit; }
            if ($rolEmpresa === 'vigilante')  { header('Location: dashboard_vigilante.php'); exit; }
            if (!empty($_SESSION['empresa_id'])) {
                header('Location: dashboard_cliente.php'); exit;
            }
        } else {
            // Vino a cambiar cuenta → mostrar aviso pero permitir login
            $avisoCambioCuenta = 'Estás a punto de cambiar de cuenta. Se cerrará tu sesión actual al ingresar con otra.';
        }
    }
}

$error = '';

// Stats globales
try {
    $totalEmpresas = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado='activa'")->fetchColumn();
    $totalCupos    = (int)$pdo->query("SELECT COUNT(*) FROM cupos")->fetchColumn();
    $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
    $totalActivos  = (int)$pdo->query("SELECT COUNT(*) FROM registros_parqueo WHERE estado='activo'")->fetchColumn();
} catch (\Throwable $e) {
    $totalEmpresas = $totalCupos = $totalUsuarios = $totalActivos = 0;
}

try {
    $empresas = $pdo->query("SELECT id, nombre, estado_aprobacion FROM empresas WHERE estado = 'activa' AND estado_aprobacion = 'aprobada' ORDER BY nombre")->fetchAll();
} catch (\Throwable $e) {
    $empresas = $pdo->query("SELECT id, nombre FROM empresas WHERE estado = 'activa' AND estado_aprobacion = 'aprobada' ORDER BY nombre")->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $empresaId = (int)($_POST['empresa_id'] ?? 0);
        $username  = trim($_POST['username'] ?? '');
        $password  = (string)($_POST['password'] ?? '');

        if ($empresaId <= 0) {
            $error = 'Selecciona una empresa.';
        } else {
            $res = autenticar_paso1($pdo, $username, $password, $empresaId);
            if (!$res['exito']) {
                $error = $res['mensaje'];
            } else {
                $_SESSION['pre_auth_user_id'] = $res['user']['id'];
                $_SESSION['pre_auth_empresa'] = $empresaId;
                $_SESSION['pre_auth_user']    = $res['user'];
                $_SESSION['pre_auth_rol']     = $res['rol_empresa'];

                if (!empty($res['necesita_2fa'])) {
                    header('Location: verify_2fa.php'); exit;
                }

                establecer_sesion_completa($res['user'], $empresaId);
                unset($_SESSION['pre_auth_user_id'], $_SESSION['pre_auth_empresa'], $_SESSION['pre_auth_user'], $_SESSION['pre_auth_rol']);

                $rol = $_SESSION['rol_en_empresa'] ?? '';
                if (in_array($rol, ['admin', 'superadmin'], true)) { header('Location: dashboard_admin.php'); exit; }
                if ($rol === 'operador')   { header('Location: dashboard_admin.php'); exit; }
                if ($rol === 'finanzas')   { header('Location: dashboard_finanzas.php'); exit; }
                if ($rol === 'vigilante')  { header('Location: dashboard_vigilante.php'); exit; }
                header('Location: dashboard_cliente.php'); exit;
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
<title>Acceso Empresarial - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<div class="split-login">

  <!-- ============ LADO IZQUIERDO ============ -->
  <div class="split-left">
    <div class="split-left-content">

      <!-- Header -->
      <div class="scene-header">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark">
        <h2>Gestiona tu parqueadero<br>con inteligencia real</h2>
        <p>Celdas, tarifas, vehículos y pagos — todo bajo control desde un solo panel.</p>
      </div>

      <!-- Parqueadero en vivo -->
      <div class="scene-middle">
        <div class="scene-parking">
          <div class="parking-grid">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <div class="park-cell" data-cell="<?= $i ?>">
                <span class="cell-dot"></span>
                <svg class="car-svg" viewBox="0 0 40 80" xmlns="http://www.w3.org/2000/svg">
                  <rect x="6" y="8" width="28" height="64" rx="10" fill="#06b6d4" opacity="0.85"/>
                  <rect x="12" y="16" width="16" height="10" rx="3" fill="#020617" opacity="0.35"/>
                  <rect x="12" y="54" width="16" height="10" rx="3" fill="#020617" opacity="0.35"/>
                  <rect x="14" y="32" width="12" height="16" rx="2" fill="#020617" opacity="0.2"/>
                </svg>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <!-- 4 features mini -->
      <div class="scene-footer">
        <div class="scene-footer-item">
          <span class="sfi-icon">◎</span>
          <div class="sfi-text">Celdas en tiempo real<small>Ocupación viva</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">⌁</span>
          <div class="sfi-text">QR por parqueadero<small>Sin apps</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">✦</span>
          <div class="sfi-text">IA con Gemini<small>Predicción</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">▤</span>
          <div class="sfi-text">Facturación digital<small>Cero papel</small></div>
        </div>
      </div>

      <!-- NUEVO: Stats grandes -->
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

      <!-- NUEVO: Por qué elegirnos -->
      <div class="scene-why">
        <h3>Por qué elegir SmartPark</h3>
        <ul class="scene-why-list">
          <li><span class="chk">✓</span> Setup en menos de 10 minutos</li>
          <li><span class="chk">✓</span> Sin costo durante la fase demo</li>
          <li><span class="chk">✓</span> Migración de datos incluida</li>
          <li><span class="chk">✓</span> Soporte humano 24/7</li>
          <li><span class="chk">✓</span> Aislamiento multi-empresa</li>
          <li><span class="chk">✓</span> Reportes exportables a PDF/CSV</li>
        </ul>
      </div>

      <!-- NUEVO: Soporte -->
      <div class="scene-support">
        <span class="sup-icon">◈</span>
        <div class="sup-text">
          <strong>¿Necesitas ayuda?</strong>
          <span>Nuestro equipo está disponible 24/7</span>
        </div>
        <a href="mailto:soporte@smartpark.com" class="sup-link">Contactar →</a>
      </div>

    </div>
  </div>

  <!-- ============ LADO DERECHO: FORMULARIO ============ -->
  <div class="split-right">
    <div class="split-form">

      <div style="margin-bottom: 1.75rem;">
        <span class="cyber-badge">Portal Empresarial</span>
        <h2 style="font-size: 1.75rem; font-weight: 800; margin-top: 1rem; letter-spacing: -0.02em;">
          Bienvenido de nuevo
        </h2>
        <p style="color: #64748b; font-size: 0.88rem; margin-top: 0.4rem;">
          Ingresa tus credenciales para acceder a tu parqueadero
        </p>
      </div>

      <?php if ($error): ?>
        <div class="cyber-alert">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="cyber-form" autocomplete="off">
        <?= csrf_field() ?>

        <div class="form-group">
          <label for="empresa_id">Empresa</label>
          <select id="empresa_id" name="empresa_id" required>
            <option value="">— Selecciona tu empresa —</option>
            <?php foreach ($empresas as $e): ?>
              <option value="<?= (int)$e['id'] ?>">
                <?= htmlspecialchars($e['nombre']) ?>
                <?php if (isset($e['estado_aprobacion']) && $e['estado_aprobacion'] === 'pendiente'): ?>
                  · pendiente
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="username">Usuario o correo</label>
          <input type="text" id="username" name="username" placeholder="tu_usuario" required autocomplete="username">
        </div>

        <div class="form-group">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" placeholder="••••••••••" required autocomplete="current-password">
        </div>

        <button type="submit" class="cyber-btn-primary btn-block btn-lg" style="margin-top: 0.75rem;">
          Ingresar al panel
        </button>
      </form>

      <div style="text-align:center; margin-top:1.25rem;">
        <a href="olvidar_password.php" style="color:#64748b; font-size:.82rem; text-decoration:none;">¿Olvidaste tu contraseña?</a>
      </div>

      <div class="login-help" style="margin-top: 1.5rem;">
        <a href="olvidar_password.php">Olvidé mi contraseña</a>
        <a href="registro_empresa.php">Registrar nueva empresa</a>
        <div style="display: flex; gap: 0.75rem; justify-content: center; margin-top: 0.5rem; flex-wrap: wrap;">
          
          <a href="login_developer.php" style="font-size: 0.8rem;">Developer</a>
          <a href="index.php" style="font-size: 0.8rem;">Inicio</a>
        </div>
      </div>

    </div>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script>
/* ============ ANIMACIÓN DE AUTOS EN CELDAS ============ */
(function() {
    'use strict';
    function initParkingAnimation() {
        const cells = document.querySelectorAll('.park-cell');
        if (!cells.length) return;
        
        // Estado inicial aleatorio (40% ocupadas)
        cells.forEach(function(cell) {
            if (Math.random() < 0.4) {
                cell.classList.add('occupied');
            } else {
                cell.classList.add('free');
            }
        });
        
        // Cambiar una celda aleatoria cada 1.8 segundos
        setInterval(function() {
            const cell = cells[Math.floor(Math.random() * cells.length)];
            cell.classList.toggle('occupied');
            cell.classList.toggle('free');
        }, 1800);
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initParkingAnimation);
    } else {
        initParkingAnimation();
    }
})();
</script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
