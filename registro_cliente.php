<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

if (!empty($_SESSION['usuario_id'])) {
    header('Location: dashboard_cliente.php'); exit;
}

$error = '';
$placa_escaner = trim($_GET['placa'] ?? '');
$empresa_id_qr = (int)($_GET['empresa_id'] ?? 0);

// FIX: Si no viene empresa_id pero sí la placa, buscar a qué empresa pertenece ese auto
if ($empresa_id_qr === 0 && $placa_escaner !== '') {
    $stmt_buscar_emp = $pdo->prepare("SELECT empresa_id FROM registros_parqueo WHERE placa = :p AND estado = 'activo' LIMIT 1");
    $stmt_buscar_emp->execute(['p' => $placa_escaner]);
    $empresa_id_qr = (int)$stmt_buscar_emp->fetchColumn();
}

// Si definitivamente no hay empresa (registro normal), usar la primera activa
if ($empresa_id_qr === 0) {
    $empresa_id_qr = (int)$pdo->query("SELECT id FROM empresas WHERE estado='activa' LIMIT 1")->fetchColumn();
}

// Si viene con placa desde el QR, buscamos a qué empresa pertenece
if ($placa_escaner !== '' && $empresa_id_qr === 0) {
    $stmtQ = $pdo->prepare("SELECT empresa_id FROM registros_parqueo WHERE placa = :p AND estado = 'activo' LIMIT 1");
    $stmtQ->execute(['p' => $placa_escaner]);
    $empresa_id_qr = (int)$stmtQ->fetchColumn();
}

// Empresa por defecto si no hay QR
if ($empresa_id_qr === 0) {
    $empresa_id_qr = (int)$pdo->query("SELECT id FROM empresas WHERE estado = 'activa' LIMIT 1")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $es_institucional = !empty($_POST['es_institucional']) ? 1 : 0;

        if ($nombre === '' || $username === '' || $correo === '' || $password === '') {
            $error = 'Todos los campos son obligatorios.';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'El correo no es válido.';
        } elseif ($es_institucional && !str_ends_with(strtolower($correo), '.edu.co')) {
            $error = 'Si eres institucional, el correo debe terminar en .edu.co';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            try {
                // Validar que no exista en user ni en usuarios_empresa
                $stmt = $pdo->prepare("SELECT id FROM user WHERE username = :u OR correo = :c LIMIT 1");
                $stmt->execute(['u' => $username, 'c' => $correo]);
                
                if ($stmt->fetch()) {
                    $error = 'El usuario o correo ya existen.';
                } else {
                    $pdo->beginTransaction();
                    $hash = hash_password_seguro($password);
                    
                    $pdo->prepare("INSERT INTO user (username, password, nombre, correo, role, es_institucional, must_change_password) VALUES (:u, :p, :n, :c, 'cliente', :inst, 0)")
                        ->execute(['u' => $username, 'p' => $hash, 'n' => $nombre, 'c' => $correo, 'inst' => $es_institucional]);
                    
                    $nuevo_id = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO usuarios_empresa (user_id, empresa_id, parqueadero_id, rol_en_empresa, activo) VALUES (:u, :e, 1, 'cliente', 1)")
                        ->execute(['u' => $nuevo_id, 'e' => $empresa_id_qr]);

                    // Si viene de un QR escaneado, le asignamos el auto a su cuenta
                    if ($placa_escaner !== '') {
                        $pdo->prepare("UPDATE registros_parqueo SET user_id = :uid WHERE placa = :placa AND estado = 'activo'")
                            ->execute(['uid' => $nuevo_id, 'placa' => $placa_escaner]);
                        // También vincular en la tabla user_vehiculo
                        $pdo->prepare("INSERT IGNORE INTO user_vehiculo (empresa_id, placa, modelo, correo, tipo_vehiculo, user_id) 
                                       SELECT empresa_id, placa, modelo, correo, tipo_vehiculo, :uid FROM registros_parqueo WHERE placa = :placa LIMIT 1")
                            ->execute(['uid' => $nuevo_id, 'placa' => $placa_escaner]);
                    }

                    $pdo->commit();

                    // Iniciar sesión
                    $user_data = [
                        'id' => $nuevo_id, 'username' => $username, 'nombre' => $nombre, 'correo' => $correo,
                        'role' => 'cliente', 'rol_en_empresa' => 'cliente', 'parqueadero_id' => 1
                    ];
                    establecer_sesion_completa($user_data, $empresa_id_qr);
                    
                    header('Location: dashboard_cliente.php');
                    exit;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error al crear la cuenta.';
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
<title>Crear Cuenta - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=999">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link rel="stylesheet" href="assets/css/client-theme.css?v=999">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
<style>
    .split-left { background: linear-gradient(135deg, #020617 0%, #0a1628 50%, #020617 100%) !important; }
    .client-badge { display: inline-block; padding: 0.3rem 0.85rem; background: rgba(14, 165, 233, 0.15); border: 1px solid rgba(14, 165, 233, 0.4); border-radius: 100px; color: #0ea5e9; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; margin-bottom: 1rem; }
    .inst-checkbox { display: flex; align-items: center; gap: 0.6rem; padding: 0.8rem; background: rgba(14, 165, 233, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 10px; cursor: pointer; margin-bottom: 1rem; }
    .inst-checkbox input { width: 20px; height: 20px; accent-color: #10b981; cursor: pointer; }
    .inst-checkbox span { font-size: 0.85rem; color: #cbd5e1; font-weight: 600; }

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
<body class="cyber-body client-theme">

<canvas id="particles-canvas"></canvas>

<div class="split-login">
  <div class="split-left">
    <div class="split-left-content">
      <div class="scene-header">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" onerror="this.src='IMG/SMARTPARK.png'">
        <span class="client-badge">◈ NUEVO CLIENTE</span>
        <h2>Únete a la red<br>de parqueaderos inteligentes</h2>
        <p>Crea tu cuenta para guardar tus vehículos, ver tu historial y pagar desde el celular.</p>
      </div>
      <div class="scene-middle">
        <div class="scene-parking">
          <div class="parking-grid">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <div class="park-cell <?= $i % 3 == 0 ? 'occupied' : 'free' ?>">
                <span class="cell-dot"></span>
                <?php if ($i % 3 == 0): ?>
                <svg class="car-svg" viewBox="0 0 40 80" xmlns="http://www.w3.org/2000/svg"><rect x="6" y="8" width="28" height="64" rx="10" fill="#10b981" opacity="0.85"/></svg>
                <?php endif; ?>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="split-right">
    <div class="split-form">
      <div style="margin-bottom: 1.5rem;">
        <h2 style="font-size: 1.75rem; font-weight: 800; margin-top: 1rem; letter-spacing: -0.02em; color: #f1f5f9;">Crear Cuenta</h2>
        <p style="color: #64748b; font-size: 0.88rem; margin-top: 0.4rem;">Regístrate para gestionar tu parqueo</p>
      </div>

      <?php if ($error): ?>
        <div class="cyber-alert" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #fca5a5;">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="cyber-form" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="nombre">Nombre completo</label>
          <input type="text" id="nombre" name="nombre" placeholder="Ej: Juan Pérez" required autofocus>
        </div>
        <div class="form-group">
          <label for="username">Nombre de usuario</label>
          <input type="text" id="username" name="username" placeholder="Ej: juanperez" required>
        </div>
        <div class="form-group">
          <label for="correo">Correo electrónico</label>
          <input type="email" id="correo" name="correo" placeholder="correo@ejemplo.com" required>
        </div>
        <div class="form-group">
          <label for="password">Contraseña (mínimo 6 caracteres)</label>
          <input type="password" id="password" name="password" placeholder="••••••••" required minlength="6">
        </div>

        <!-- NUEVO CHECKBOX INSTITUCIONAL -->
        <label class="inst-checkbox" for="es_institucional">
          <input type="checkbox" name="es_institucional" id="es_institucional" value="1" onchange="toggleInstitucional()">
          <span>Soy de una institución educativa (.edu.co)</span>
        </label>

        <button type="submit" class="cyber-btn-primary btn-block btn-lg" style="margin-top: 0.5rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; border: none;">
          Registrarme y Entrar
        </button>
      </form>

      <div class="login-help" style="margin-top: 1.5rem;">
        <a href="login_cliente.php">¿Ya tienes cuenta? Inicia sesión</a>
        <a href="index.php">← Volver al inicio</a>
      </div>
    </div>
  </div>
</div>

<script>
function toggleInstitucional() {
    const chk = document.getElementById('es_institucional');
    const inputCorreo = document.getElementById('correo');
    if (chk.checked) {
        inputCorreo.placeholder = "tu.correo@inemjose.edu.co";
        if (inputCorreo.value === '' || inputCorreo.value.endsWith('.com')) {
            inputCorreo.value = '';
        }
    } else {
        inputCorreo.placeholder = "correo@ejemplo.com";
    }
}
</script>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>