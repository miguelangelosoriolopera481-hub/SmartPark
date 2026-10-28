<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';

require_login();
require_password_change_guard();

// Los administradores/operadores tienen su propio panel; "Mi cuenta" es
// exclusivamente la vista de cliente pedida en el punto 6.
if (in_array($_SESSION['role'] ?? '', ['admin', 'operador'], true)) {
    header("Location: admin.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM user WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $_SESSION['usuario_id']]);
$usuario = $stmt->fetch();

// Historial real del cliente: se apoya en la relación user_id agregada a
// registros_parqueo (antes esta vinculación no existía y era imposible

$stmt_hist = $pdo->prepare("
    SELECT * FROM registros_parqueo
    WHERE user_id = :uid
    ORDER BY fecha_ingreso DESC
    LIMIT 30
");
$stmt_hist->execute(['uid' => $_SESSION['usuario_id']]);
$historial = $stmt_hist->fetchAll();

// Vehículos asociados a la cuenta (tabla vehículo-propietario)
$stmt_veh = $pdo->prepare("SELECT * FROM user_vehiculo WHERE user_id = :uid ORDER BY fecha_creacion DESC");
$stmt_veh->execute(['uid' => $_SESSION['usuario_id']]);
$mis_vehiculos = $stmt_veh->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark - Mi Cuenta</title>
    <link rel="stylesheet" href="assets/css/main.css?v=34">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>

<div class="container cyber-entrance">
    <h2 style="color: var(--primary-cyan); margin-bottom: 1.5rem;">👤 Mi Cuenta</h2>

    <div class="grid-3" style="margin-bottom: 2rem;">
        <div class="cyber-card">
            <h4>Nombre</h4>
            <p style="font-size: 1.1rem; margin-top: 0.5rem;"><?= htmlspecialchars($usuario['nombre']) ?></p>
        </div>
        <div class="cyber-card">
            <h4>Usuario</h4>
            <p style="font-size: 1.1rem; margin-top: 0.5rem;"><?= htmlspecialchars($usuario['username']) ?></p>
        </div>
        <div class="cyber-card">
            <h4>Correo</h4>
            <p style="font-size: 1.1rem; margin-top: 0.5rem;"><?= htmlspecialchars($usuario['correo'] ?? 'No registrado') ?></p>
        </div>
    </div>

    <div class="cyber-card" style="margin-bottom: 2rem;">
        <h3 style="color: var(--primary-cyan); margin-bottom: 1rem;">🚗 Mis Vehículos</h3>
        <?php if (empty($mis_vehiculos)): ?>
            <p style="color: var(--text-muted);">Aún no tienes vehículos asociados. Regístralos en <a href="registro_carro.php">Registrar Vehículo</a>.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Placa</th><th>Modelo</th><th>Tipo</th></tr></thead>
                    <tbody>
                    <?php foreach ($mis_vehiculos as $v): ?>
                        <tr>
                            <td><span class="plate-style"><?= htmlspecialchars($v['placa']) ?></span></td>
                            <td><?= htmlspecialchars($v['modelo']) ?></td>
                            <td><?= ucfirst($v['tipo_vehiculo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="cyber-card">
        <h3 style="color: var(--primary-cyan); margin-bottom: 1rem;">🕓 Historial de Parqueo</h3>
        <?php if (empty($historial)): ?>
            <p style="color: var(--text-muted);">No tienes historial de parqueo todavía.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr><th>Placa</th><th>Celda</th><th>Ingreso</th><th>Salida</th><th>Total</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historial as $h): ?>
                        <tr>
                            <td><span class="plate-style"><?= htmlspecialchars($h['placa']) ?></span></td>
                            <td><?= htmlspecialchars($h['cupo_asignado']) ?></td>
                            <td><?= htmlspecialchars($h['fecha_ingreso']) ?></td>
                            <td><?= htmlspecialchars($h['fecha_salida'] ?? 'En curso') ?></td>
                            <td>$<?= number_format($h['total_pagar'], 0, ',', '.') ?> COP</td>
                            <td>
                                <?php if ($h['estado'] === 'activo'): ?>
                                    <a href="pagar.php?placa=<?= urlencode($h['placa']) ?>" class="cyber-btn-outline" style="padding:0.2rem 0.6rem; font-size:0.75rem;">Pagar</a>
                                <?php else: ?>
                                    <a href="ver_factura.php?token=<?= urlencode($h['token_factura'] ?? '') ?>" class="cyber-btn-outline" style="padding:0.2rem 0.6rem; font-size:0.75rem;">Factura</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <p style="margin-top: 1.5rem;"><a href="cambiar_password.php" style="color: var(--text-muted);">🔑 Cambiar mi contraseña</a></p>
</div>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
