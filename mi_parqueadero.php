<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'validaciones.php';

require_password_change_guard();

$placa_buscada = normalizar_placa($_GET['placa'] ?? ($_POST['placa'] ?? ''));
$token = trim($_GET['token'] ?? '');
$highlight_codigo = null;
$vehiculo_encontrado = null;
$error = '';
$empresa_actual = null;

// Detectar empresa: por token (QR) o por sesión
// FIX: si no hay token pero hay placa, buscar empresa por placa activa
if ($token === '' && !empty($placa_buscada)) {
    try {
        $stmt = $pdo->prepare("SELECT empresa_id, parqueadero_id FROM registros_parqueo WHERE placa = :p AND estado = 'activo' ORDER BY id DESC LIMIT 1");
        $stmt->execute(['p' => $placa_buscada]);
        $row = $stmt->fetch();
        if ($row) {
            $eidAutoDetectado = (int)$row['empresa_id'];
        }
    } catch (\Throwable $e) {}
}

if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id AS parqueadero_id, p.empresa_id, p.nombre, e.nombre AS empresa_nombre
            FROM parqueaderos p
            JOIN empresas e ON e.id = p.empresa_id
            WHERE p.qr_token = :t AND p.estado = 'activo' LIMIT 1
        ");
        $stmt->execute(['t' => $token]);
        $empresa_actual = $stmt->fetch();
    } catch (\Throwable $e) {}
}

$eid = $empresa_actual ? (int)$empresa_actual['empresa_id'] : ((isset($eidAutoDetectado) && $eidAutoDetectado > 0) ? $eidAutoDetectado : (int)($_SESSION['empresa_id'] ?? 0));
$pid = $empresa_actual ? (int)$empresa_actual['parqueadero_id'] : (int)($_SESSION['parqueadero_id'] ?? 0);

// FIX CRÍTICO: Buscar la placa en TODAS las empresas primero
if (!empty($placa_buscada)) {
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE placa = :placa AND estado = 'activo' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['placa' => $placa_buscada]);
    $vehiculo_encontrado = $stmt->fetch();
    
    if ($vehiculo_encontrado) {
        // Si lo encuentra, forzamos a que el mapa cargue la empresa y sede del vehículo
        $eid = (int)$vehiculo_encontrado['empresa_id'];
        $pid = (int)$vehiculo_encontrado['parqueadero_id'];
        $highlight_codigo = $vehiculo_encontrado['cupo_asignado'];
    } else {
        $error = "No se encontró un vehículo activo con la placa " . htmlspecialchars($placa_buscada) . ".";
    }
} elseif (isset($_SESSION['usuario_id']) && $eid > 0) {
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE user_id = :uid AND empresa_id = :eid AND estado = 'activo' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['uid' => $_SESSION['usuario_id'], 'eid' => $eid]);
    $vehiculo_encontrado = $stmt->fetch();
    if ($vehiculo_encontrado) {
        $highlight_codigo = $vehiculo_encontrado['cupo_asignado'];
    }
}

// Celdas SOLO de la empresa activa
$celdas = [];
if ($eid > 0) {
    $stmt_celdas = $pdo->prepare("
        SELECT c.codigo_cupo, c.tipo, c.estado, r.placa, r.fecha_ingreso
        FROM cupos c
        LEFT JOIN registros_parqueo r ON c.codigo_cupo = r.cupo_asignado
            AND r.estado = 'activo' AND r.empresa_id = c.empresa_id
        WHERE c.empresa_id = :eid
        ORDER BY c.tipo ASC, LENGTH(c.codigo_cupo) ASC, c.codigo_cupo ASC
    ");
    $stmt_celdas->execute(['eid' => $eid]);
    $celdas = $stmt_celdas->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark - Mi Parqueadero</title>
    <link rel="stylesheet" href="assets/css/main.css?v=34">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="has-mobile-topbar" class="cyber-body">
<?php $backUrl = 'javascript:history.back()'; include __DIR__ . '/partials/mobile_topbar.php'; ?>
<?php include 'partials/navbar.php'; ?>

<div class="container cyber-entrance">
    <h2 style="color: var(--primary-cyan); margin-bottom: 0.5rem;">🅿️ Mi Parqueadero</h2>
    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
        <?php if ($empresa_actual): ?>
            Vista de <strong><?= htmlspecialchars($empresa_actual['nombre']) ?></strong>
        <?php else: ?>
            Vista completa del parqueadero en tiempo real.
        <?php endif; ?>
    </p>

    <?php if ($eid <= 0): ?>
        <div class="cyber-alert">Debes escanear el QR de un parqueadero o iniciar sesión para ver la vista.</div>
    <?php else: ?>

    <?php if (!isset($_SESSION['usuario_id']) || $token !== ''): ?>
        <div class="cyber-card" style="margin-bottom: 1.5rem;">
            <form method="GET" action="mi_parqueadero.php" style="display:flex; gap:0.6rem; flex-wrap: wrap;">
                <?php if ($token): ?><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><?php endif; ?>
                <input type="text" name="placa" value="<?= htmlspecialchars($placa_buscada) ?>" placeholder="Busca tu placa (ej. ABC123)" style="flex:1; min-width: 180px; padding:0.7rem; background:#070a12; border:1px solid var(--border-cyber); border-radius:8px; color: var(--text-main); text-transform: uppercase;">
                <button type="submit" class="cyber-btn-primary">Buscar</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="cyber-alert" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($vehiculo_encontrado): ?>
        <div class="pill-cyber" style="border-color: var(--primary-green); color: var(--primary-green); margin-bottom: 1.5rem;">
            Tu vehículo <strong><?= htmlspecialchars($vehiculo_encontrado['placa']) ?></strong> está en la celda <strong><?= htmlspecialchars($vehiculo_encontrado['cupo_asignado']) ?></strong>.
        </div>
    <?php endif; ?>

    <div class="cyber-card">
        <?php
        $editable = false;
        include 'partials/floor_plan.php';
        ?>
    </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>