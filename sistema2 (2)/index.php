<?php
ob_start();
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';

$mensaje_exito = '';
$error = '';
$modo = $_GET['modo'] ?? 'inicio';

// Cierre de sesión
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

// Procesamiento unificado de login/registro (antes duplicado en login.php y registro_usuario.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action_register']) || isset($_POST['action_login']))) {
    $resultado = procesarAuthPost($pdo, $modo);
    $modo          = $resultado['modo'];
    $error         = $resultado['error'];
    $mensaje_exito = $resultado['mensaje_exito'];
    if ($resultado['redirect']) {
        header("Location: " . $resultado['redirect']);
        exit;
    }
}

require_password_change_guard();

// Consultar disponibilidad en tiempo real
$stmt_cupos = $pdo->query("SELECT COUNT(*) AS total, SUM(estado='disponible') AS libres, SUM(estado='ocupado') AS ocupados, SUM(estado='mantenimiento') AS mantenimiento FROM cupos");
$disp = $stmt_cupos->fetch();

$tarifas = obtenerTarifas($pdo);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark Pro - Parqueadero Inteligente</title>
    <link rel="stylesheet" href="CSS/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Animaciones personalizadas -->
    <style>
        /* Animaciones adicionales */
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .animate-float { animation: float 3s ease-in-out infinite; }
        .animate-pulse { animation: pulse 2s infinite; }
        .slide-in-left { animation: slideInLeft 0.8s ease-out forwards; }

        /* Botones flotantes de acción rápida */
        .quick-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        .quick-action-btn {
            background: rgba(0, 242, 254, 0.1);
            border: 1px solid var(--primary-cyan);
            color: var(--primary-cyan);
            padding: 0.8rem 1.8rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .quick-action-btn:hover {
            background: var(--primary-cyan);
            color: #070a12;
            box-shadow: 0 0 20px rgba(0, 242, 254, 0.6);
            transform: scale(1.05);
        }

        /* Hero con fondo animado */
        .hero-bg {
            background: radial-gradient(circle at 20% 30%, rgba(0, 242, 254, 0.15) 0%, transparent 50%),
            radial-gradient(circle at 80% 70%, rgba(0, 255, 157, 0.1) 0%, transparent 50%);
        }
    </style>
</head>
<body class="cyber-body">

<?php include 'partials/navbar.php'; ?>

<?php if ($modo === 'inicio'): ?>
    <!-- HERO -->
    <section class="hero-section hero-bg">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" class="animate-float" style="height: 90px; margin-bottom: 20px; filter: drop-shadow(0 0 15px rgba(0,242,254,0.6));">
        <h1>Gestiona tu parqueadero <span style="background: linear-gradient(90deg, var(--primary-cyan), var(--primary-green)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">en tiempo real</span></h1>
        <p class="subtitle">Sistema 100% digital, seguro y sin papel. Registro, pago y facturación en un solo lugar.</p>

        <div class="hero-cta">
            <?php if (!isset($_SESSION['usuario_id'])): ?>
                <a href="index.php?modo=registro" class="cyber-btn-primary animate-pulse">Crear Cuenta Gratis</a>
                <a href="index.php?modo=login" class="cyber-btn-outline">Iniciar Sesión</a>
            <?php else: ?>
                <a href="registro_carro.php" class="cyber-btn-primary">🚗 Registrar Vehículo</a>
                <a href="estado_parqueo.php" class="cyber-btn-outline">Consulta tu Parqueo</a>
            <?php endif; ?>
        </div>

        <!-- Botones rápidos siempre visibles -->
        <div class="quick-actions">
            <a href="registro_carro.php" class="quick-action-btn">➕ Registrar Auto</a>
            <a href="ver_factura.php" class="quick-action-btn">📄 Ver Factura</a>
            <a href="mi_parqueadero.php" class="quick-action-btn">🅿️ Mi Parqueadero</a>
            <a href="salida.php" class="quick-action-btn">🚪 Control Salida</a>
        </div>
    </section>

    <!-- Disponibilidad en vivo -->
    <section class="landing-section">
        <h2 class="section-title">Disponibilidad en Tiempo Real</h2>
        <div class="disponibilidad-bar slide-in-left">
            <div class="disp-chip libres">
                <div class="num animate-pulse"><?= (int) $disp['libres'] ?></div>
                <div class="label">Libres</div>
            </div>
            <div class="disp-chip ocupados">
                <div class="num"><?= (int) $disp['ocupados'] ?></div>
                <div class="label">Ocupados</div>
            </div>
            <div class="disp-chip mantenimiento">
                <div class="num"><?= (int) $disp['mantenimiento'] ?></div>
                <div class="label">Mantenimiento</div>
            </div>
        </div>
    </section>

    <!-- Cómo funciona -->
    <section class="landing-section">
        <h2 class="section-title">¿Cómo funciona?</h2>
        <div class="steps-grid">
            <div class="step-card slide-in-left">
                <div class="step-num">1</div>
                <h3>Registra tu vehículo</h3>
                <p>Ingresa la placa y el modelo; el sistema detecta el tipo de vehículo y asigna automáticamente una celda libre.</p>
            </div>
            <div class="step-card">
                <div class="step-num">2</div>
                <h3>Paga desde tu celular</h3>
                <p>Genera tu factura con código QR y paga en línea de forma segura, sin salir del vehículo.</p>
            </div>
            <div class="step-card slide-in-left">
                <div class="step-num">3</div>
                <h3>Retira tu vehículo</h3>
                <p>Presenta el QR o tu placa en la barrera de salida y el sistema libera la celda.</p>
            </div>
        </div>
    </section>

    <!-- Tarifas -->
    <section class="landing-section">
        <h2 class="section-title">Tarifas</h2>
        <div class="tarifas-grid">
            <div class="tarifa-card">
                <h3>🚗 Carro</h3>
                <div class="precio">$<?= number_format($tarifas['carro'], 0, ',', '.') ?> <small>/ hora</small></div>
                <p style="color: var(--text-muted); font-size: 0.8rem;">Ideal para vehículos particulares</p>
            </div>
            <div class="tarifa-card">
                <h3>🏍️ Moto</h3>
                <div class="precio">$<?= number_format($tarifas['moto'], 0, ',', '.') ?> <small>/ hora</small></div>
                <p style="color: var(--text-muted); font-size: 0.8rem;">Para motocicletas livianas</p>
            </div>
        </div>
    </section>

    <!-- Beneficios -->
    <section class="landing-section">
        <h2 class="section-title">Beneficios</h2>
        <div class="grid-3">
            <div class="cyber-card">
                <h4>🔒 Seguridad Total</h4>
                <p>Base de datos con consultas preparadas contra inyecciones SQL, protección CSRF y límite de intentos de acceso.</p>
            </div>
            <div class="cyber-card">
                <h4>📱 100% Digital</h4>
                <p>Factura con QR visible al instante en pantalla (y por correo si está configurado). Cero papel.</p>
            </div>
            <div class="cyber-card">
                <h4>🌱 Menor Consumo</h4>
                <p>Cero impresiones y tema oscuro que reduce el consumo energético en pantallas OLED.</p>
            </div>
        </div>
    </section>

    <footer class="cyber-footer">
        <p>© 2026 SmartPark Pro · Soporte: soporte@smartpark.com</p>
        <p>
            <a href="index.php">Inicio</a> ·
            <a href="registro_carro.php">Registrar Vehículo</a> ·
            <a href="mi_parqueadero.php">Mi Parqueadero</a> ·
            <a href="salida.php">Salida</a>
        </p>
    </footer>

<?php else: ?>
    <?php
    $form_action = 'index.php';
    include 'partials/auth_forms.php';
    ?>
<?php endif; ?>
</body>
</html>
