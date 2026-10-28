<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'validaciones.php';

require_password_change_guard();

$placa_buscada = normalizar_placa($_GET['placa'] ?? ($_POST['placa'] ?? ''));
$highlight_codigo = null;
$vehiculo_encontrado = null;
$error = '';

if (!empty($placa_buscada)) {
    // Flujo sin sesión: buscar por placa explícita
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE placa = :placa AND estado = 'activo' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['placa' => $placa_buscada]);
    $vehiculo_encontrado = $stmt->fetch();
    if (!$vehiculo_encontrado) {
        $error = "No se encontró un vehículo activo con la placa " . htmlspecialchars($placa_buscada) . ".";
    } else {
        $highlight_codigo = $vehiculo_encontrado['cupo_asignado'];
    }
} elseif (isset($_SESSION['usuario_id'])) {
    // Flujo con sesión: usar la relación real usuario-vehículo
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE user_id = :uid AND estado = 'activo' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['uid' => $_SESSION['usuario_id']]);
    $vehiculo_encontrado = $stmt->fetch();
    if ($vehiculo_encontrado) {
        $highlight_codigo = $vehiculo_encontrado['cupo_asignado'];
    }
}

$stmt_celdas = $pdo->query("
    SELECT c.codigo_cupo, c.tipo, c.estado, r.placa, r.fecha_ingreso
    FROM cupos c
    LEFT JOIN registros_parqueo r ON c.codigo_cupo = r.cupo_asignado AND r.estado = 'activo'
    ORDER BY c.tipo ASC, LENGTH(c.codigo_cupo) ASC, c.codigo_cupo ASC
");
$celdas = $stmt_celdas->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark - Mi Parqueadero</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>

<div class="container cyber-entrance">
    <h2 style="color: var(--primary-cyan); margin-bottom: 0.5rem;">🅿️ Mi Parqueadero</h2>
    <p style="color: var(--text-muted); margin-bottom: 1.5rem;">Vista completa del parqueadero en tiempo real. Tu vehículo (si está activo) aparece resaltado.</p>

    <?php if (!isset($_SESSION['usuario_id'])): ?>
        <div class="cyber-card" style="margin-bottom: 1.5rem;">
            <form method="GET" action="mi_parqueadero.php" style="display:flex; gap:0.6rem; flex-wrap: wrap;">
                <input type="text" name="placa" value="<?= htmlspecialchars($placa_buscada) ?>" placeholder="Busca tu placa (ej. ABC123)" style="flex:1; min-width: 180px; padding:0.7rem; background:#070a12; border:1px solid var(--border-cyber); border-radius:8px; color: var(--text-main); text-transform: uppercase;">
                <button type="submit" class="cyber-btn-primary">Buscar</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="cyber-alert" style="margin-bottom: 1.5rem;"><?= $error ?></div>
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
</div>
</body>
</html>
