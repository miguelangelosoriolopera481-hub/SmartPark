<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'helpers.php';

require_password_change_guard();

$placa = trim($_GET['placa'] ?? '');
$registro = null;
$error = '';
$tarifas = obtenerTarifas($pdo);

if (!empty($placa)) {
    $stmt = $pdo->prepare("SELECT r.*, u.nombre AS nombre_usuario
                           FROM registros_parqueo r
                           LEFT JOIN user u ON u.id = r.user_id
                           WHERE r.placa = :placa AND r.estado = 'activo'
                           ORDER BY r.id DESC LIMIT 1");
    $stmt->execute(['placa' => $placa]);
    $registro = $stmt->fetch();

    if (!$registro) {
        $error = "No se encontró ningún vehículo activo con la placa: " . htmlspecialchars($placa);
    }
} elseif (isset($_SESSION['usuario_id'])) {
    // CORRECCIÓN DE BUG DE PRIVACIDAD: antes esta consulta hacía
    // "JOIN user u ON u.id = :uid" sin relacionar realmente la fila de
    // registros_parqueo con el usuario logueado, por lo que a cualquier
    // cliente se le mostraba el último vehículo activo de OTRA persona.
    // Ahora se filtra directamente por la columna real registros_parqueo.user_id.
    $stmt = $pdo->prepare("SELECT r.*, u.nombre AS nombre_usuario
                           FROM registros_parqueo r
                           JOIN user u ON u.id = r.user_id
                           WHERE r.user_id = :uid AND r.estado = 'activo'
                           ORDER BY r.id DESC LIMIT 1");
    $stmt->execute(['uid' => $_SESSION['usuario_id']]);
    $registro = $stmt->fetch();

    if (!$registro) {
        $error = "No tienes ningún vehículo activo en este momento.";
    }
}

$costo_calculado = 0;
$horas = 0;
if ($registro) {
    [$horas, $costo_calculado] = calcularHorasYTotal($registro['fecha_ingreso'], $registro['tipo_vehiculo'], $tarifas);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SmartPark - Estado de Parqueo</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>
<div class="container" style="max-width: 500px; margin-top: 3rem;">
    <div class="cyber-card">
        <h2 style="color: var(--primary-cyan); text-align: center;">Estado de Parqueo</h2>

        <?php if (!$registro): ?>
            <form method="GET" action="estado_parqueo.php" class="cyber-form" style="margin-bottom: 1rem;">
                <div class="form-group">
                    <label>Consultar por placa:</label>
                    <input type="text" name="placa" placeholder="ABC123" style="text-transform: uppercase; text-align: center;">
                </div>
                <button type="submit" class="cyber-btn-outline" style="width: 100%;">Consultar</button>
            </form>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="cyber-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($registro): ?>
            <div class="ticket-details">
                <p><strong>Placa:</strong> <?= htmlspecialchars($registro['placa']) ?></p>
                <p><strong>Modelo:</strong> <?= htmlspecialchars($registro['modelo'] ?? 'N/A') ?></p>
                <p><strong>Celda:</strong> <?= htmlspecialchars($registro['cupo_asignado']) ?></p>
                <p><strong>Ingreso:</strong> <?= htmlspecialchars($registro['fecha_ingreso']) ?></p>
                <p><strong>Horas:</strong> <?= (int) $horas ?></p>
                <p><strong>Total a pagar:</strong> $<?= number_format($costo_calculado, 0, ',', '.') ?> COP</p>
            </div>
            <a href="pagar.php?placa=<?= urlencode($registro['placa']) ?>" class="cyber-btn-primary" style="display:block; text-align:center; margin-top: 1rem;">💳 Ir a Pagar</a>
            <a href="mi_parqueadero.php?placa=<?= urlencode($registro['placa']) ?>" class="cyber-btn-outline" style="display:block; text-align:center; margin-top: 0.6rem;">🅿️ Ver en el mapa</a>
        <?php endif; ?>

        <div style="margin-top:1rem; text-align:center;">
            <a href="index.php">← Volver al inicio</a>
        </div>
    </div>
</div>
</body>
</html>
