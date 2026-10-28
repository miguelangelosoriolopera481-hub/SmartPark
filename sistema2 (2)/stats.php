<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';

// Antes esta validación de rol estaba correcta aquí pero la página nunca
// estaba enlazada desde ningún menú (funcionalidad inalcanzable). Ahora se
// usa el mismo helper require_role() que el resto del sitio y ya aparece
// enlazada desde el navbar de admin.php.
require_role(['admin', 'operador']);
require_password_change_guard();

// Recaudo de Hoy
$stmt = $pdo->prepare("SELECT SUM(total_pagar) as total FROM registros_parqueo WHERE DATE(fecha_salida) = CURDATE()");
$stmt->execute();
$recaudo_hoy = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM registros_parqueo WHERE DATE(fecha_salida) = CURDATE()");
$stmt->execute();
$salidas_hoy = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT SUM(total_pagar) as total FROM registros_parqueo WHERE YEARWEEK(fecha_salida, 1) = YEARWEEK(CURDATE(), 1)");
$stmt->execute();
$recaudo_semana = $stmt->fetch()['total'] ?? 0;

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM registros_parqueo WHERE YEARWEEK(fecha_salida, 1) = YEARWEEK(CURDATE(), 1)");
$stmt->execute();
$salidas_semana = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM registros_parqueo WHERE tipo_vehiculo = 'carro' AND fecha_salida IS NOT NULL");
$stmt->execute();
$total_carros = $stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM registros_parqueo WHERE tipo_vehiculo = 'moto' AND fecha_salida IS NOT NULL");
$stmt->execute();
$total_motos = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT * FROM registros_parqueo WHERE fecha_salida IS NOT NULL ORDER BY fecha_salida DESC LIMIT 10");
$ultimas_salidas = $stmt->fetchAll();

$navbar_title = 'Admin Terminal';
$navbar_home  = 'admin.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SmartPark - Estadísticas</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>
<div class="container cyber-entrance">
    <h2 style="color: var(--primary-cyan); margin-bottom: 1.5rem;">📊 Reporte Financiero</h2>
    <div class="stats-grid">
        <div class="stat-box primary">
            <h3>Recaudo de Hoy</h3>
            <p class="stat-number">$<?= number_format($recaudo_hoy, 0, ',', '.') ?> <small>COP</small></p>
            <span class="stat-sub"><?= (int) $salidas_hoy ?> salidas</span>
        </div>
        <div class="stat-box success">
            <h3>Recaudo Semana</h3>
            <p class="stat-number">$<?= number_format($recaudo_semana, 0, ',', '.') ?> <small>COP</small></p>
            <span class="stat-sub"><?= (int) $salidas_semana ?> salidas</span>
        </div>
        <div class="stat-box warning">
            <h3>Carros atendidos</h3>
            <p class="stat-number"><?= (int) $total_carros ?></p>
        </div>
        <div class="stat-box warning">
            <h3>Motos atendidas</h3>
            <p class="stat-number"><?= (int) $total_motos ?></p>
        </div>
    </div>

    <h3 style="margin-top: 2rem;">Últimas liquidaciones</h3>
    <div class="table-wrapper">
        <table>
            <thead>
            <tr>
                <th>Placa</th>
                <th>Correo</th>
                <th>Tipo</th>
                <th>Ingreso</th>
                <th>Salida</th>
                <th>Horas</th>
                <th>Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ultimas_salidas as $s): ?>
                <tr>
                    <td><span class="plate-style"><?= htmlspecialchars($s['placa']) ?></span></td>
                    <td><?= htmlspecialchars($s['correo'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($s['tipo_vehiculo']) ?></td>
                    <td><?= htmlspecialchars($s['fecha_ingreso']) ?></td>
                    <td><?= htmlspecialchars($s['fecha_salida']) ?></td>
                    <td><?= (int) $s['horas'] ?></td>
                    <td><strong style="color: var(--primary-green);">$<?= number_format($s['total_pagar'], 0, ',', '.') ?> COP</strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
