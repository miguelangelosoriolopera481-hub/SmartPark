<?php
session_start();
require_once 'conexion.php';
require_once 'helpers.php';
require_once 'csrf.php';
send_security_headers();

require_role_empresa(['admin','superadmin','finanzas']);
$eid = current_empresa_id();

$formato = $_GET['formato'] ?? 'csv';
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT r.id, r.placa, r.modelo, r.tipo_vehiculo, r.cupo_asignado,
           r.fecha_ingreso, r.fecha_salida, r.horas, r.total_pagar,
           r.metodo_pago, r.tipo_tarifa, r.estado, r.estado_pago,
           0 AS es_activo
    FROM registros_parqueo r
    WHERE r.empresa_id = :eid
      AND r.estado = 'finalizado'
      AND r.estado_pago = 1
      AND r.fecha_salida IS NOT NULL
      AND DATE(r.fecha_salida) BETWEEN :desde AND :hasta
    ORDER BY r.fecha_salida DESC
");
$stmt->execute(['eid' => $eid, 'desde' => $desde, 'hasta' => $hasta]);
$finalizados = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT r.id, r.placa, r.modelo, r.tipo_vehiculo, r.cupo_asignado,
           r.fecha_ingreso, NULL AS fecha_salida, 0 AS horas, 0 AS total_pagar,
           r.metodo_pago, r.tipo_tarifa, r.estado, r.estado_pago,
           1 AS es_activo,
           TIMESTAMPDIFF(MINUTE, r.fecha_ingreso, NOW()) AS minutos_dentro
    FROM registros_parqueo r
    WHERE r.empresa_id = :eid
      AND r.estado = 'activo'
    ORDER BY r.fecha_ingreso ASC
");
$stmt->execute(['eid' => $eid]);
$activos = $stmt->fetchAll();

// Calcular tarifa y estimado para activos
foreach ($activos as &$a) {
    $minutos = (int)$a['minutos_dentro'];
    $horas = max(1, ceil($minutos / 60));

    $tarifa = 4000.0;
    try {
        $stmt2 = $pdo->prepare("
            SELECT precio FROM tarifas
            WHERE empresa_id = :eid AND tipo_vehiculo = :tv AND tipo_tarifa = 'hora' AND activo = 1
            ORDER BY vigente_desde DESC LIMIT 1
        ");
        $stmt2->execute(['eid' => $eid, 'tv' => $a['tipo_vehiculo']]);
        $t = $stmt2->fetchColumn();
        if ($t) $tarifa = (float)$t;
    } catch (\Throwable $e) {}

    $a['horas'] = $horas;
    $a['total_pagar'] = $horas * $tarifa;
    $a['metodo_pago'] = null;
    $a['estado'] = 'activo';
}
unset($a);

$stmt = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$stmt->execute(['eid' => $eid]);
$empresa = $stmt->fetch() ?: ['nombre' => 'Mi Empresa', 'nit' => ''];

$nombreArchivo = 'finanzas_' . preg_replace('/[^a-z0-9]/i', '_', $empresa['nombre']) . '_' . date('Ymd_His');

// FORMATO CSV

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$nombreArchivo}.csv\"");
    echo "\xEF\xBB\xBF"; // BOM UTF-8

    $out = fopen('php://output', 'w');

    fputcsv($out, ['SmartPark Pro - Reporte Financiero']);
    fputcsv($out, ['Empresa:', $empresa['nombre']]);
    fputcsv($out, ['NIT:', $empresa['nit'] ?? '-']);
    fputcsv($out, ['Periodo:', "$desde a $hasta"]);
    fputcsv($out, ['Generado:', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    // SECCIÓN 1: Cobrados
    fputcsv($out, ['=== REGISTROS COBRADOS (FINALIZADOS) ===']);
    fputcsv($out, ['ID','Placa','Modelo','Tipo','Celda','Ingreso','Salida','Horas','Metodo','Total COP']);

    $sumaCobrado = 0;
    foreach ($finalizados as $r) {
        $sumaCobrado += (float)$r['total_pagar'];
        fputcsv($out, [
            $r['id'], $r['placa'], $r['modelo'], $r['tipo_vehiculo'], $r['cupo_asignado'],
            $r['fecha_ingreso'], $r['fecha_salida'], $r['horas'],
            $r['metodo_pago'] ?? '-',
            number_format((float)$r['total_pagar'], 0, ',', '.'),
        ]);
    }
    fputcsv($out, ['', '', '', '', '', '', '', '', 'SUBTOTAL:', number_format($sumaCobrado, 0, ',', '.')]);
    fputcsv($out, []);

    // SECCIÓN 2: Activos
    fputcsv($out, ['=== VEHICULOS ACTIVOS AHORA (ESTIMADO) ===']);
    fputcsv($out, ['ID','Placa','Modelo','Tipo','Celda','Ingreso','Tiempo dentro','Horas','Tarifa/hora','Estimado COP']);

    $sumaEstimado = 0;
    foreach ($activos as $a) {
        $sumaEstimado += (float)$a['total_pagar'];
        $minutos = (int)$a['minutos_dentro'];
        $tiempoTexto = floor($minutos / 60) . 'h ' . ($minutos % 60) . 'min';
        fputcsv($out, [
            $a['id'], $a['placa'], $a['modelo'], $a['tipo_vehiculo'], $a['cupo_asignado'],
            $a['fecha_ingreso'], $tiempoTexto, $a['horas'],
            '$' . number_format($a['total_pagar'] / max(1, $a['horas']), 0, ',', '.'),
            number_format((float)$a['total_pagar'], 0, ',', '.'),
        ]);
    }
    fputcsv($out, ['', '', '', '', '', '', '', '', 'SUBTOTAL:', number_format($sumaEstimado, 0, ',', '.')]);
    fputcsv($out, []);

    // TOTALES
    fputcsv($out, ['=== RESUMEN ===']);
    fputcsv($out, ['Cobrado en el periodo:', '$' . number_format($sumaCobrado, 0, ',', '.')]);
    fputcsv($out, ['Estimado por cobrar (activos):', '$' . number_format($sumaEstimado, 0, ',', '.')]);
    fputcsv($out, ['TOTAL GENERAL:', '$' . number_format($sumaCobrado + $sumaEstimado, 0, ',', '.')]);

    fclose($out);
    exit;
}

// FORMATO HTML imprimible (PDF via navegador)

$sumaCobrado = 0;
foreach ($finalizados as $r) { $sumaCobrado += (float)$r['total_pagar']; }
$sumaEstimado = 0;
foreach ($activos as $a) { $sumaEstimado += (float)$a['total_pagar']; }

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte Financiero - <?= htmlspecialchars($empresa['nombre']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', Arial, sans-serif; padding: 2rem; color: #1a2332; font-size: 12px; background: white; }
    .header { border-bottom: 3px solid #10b981; padding-bottom: 1rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; }
    .header h1 { color: #10b981; font-size: 22px; font-weight: 800; margin-bottom: 0.25rem; }
    .header .sub { color: #64748b; font-size: 11px; }
    .header .logo { font-size: 32px; color: #10b981; }
    .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; background: #f0fdf4; padding: 1rem; border-radius: 8px; border-left: 4px solid #10b981; margin-bottom: 1.5rem; }
    .meta-item .lbl { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 0.2rem; }
    .meta-item .val { font-size: 12px; color: #1a2332; font-weight: 700; }
    .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
    .kpi { background: #f8fafc; border-left: 4px solid #10b981; border-radius: 8px; padding: 1rem; }
    .kpi .kpi-lbl { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 700; }
    .kpi .kpi-val { font-size: 20px; color: #10b981; font-weight: 900; margin-top: 0.25rem; }
    .kpi.info { border-left-color: #06b6d4; }
    .kpi.info .kpi-val { color: #06b6d4; }
    .kpi.warning { border-left-color: #f59e0b; }
    .kpi.warning .kpi-val { color: #f59e0b; }
    h2 { color: #10b981; font-size: 15px; margin: 1.5rem 0 0.75rem; padding-bottom: 0.4rem; border-bottom: 1px solid #e2e8f0; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; font-size: 10px; }
    th { background: #10b981; color: white; text-align: left; padding: 8px 6px; text-transform: uppercase; font-size: 9px; font-weight: 800; }
    td { padding: 6px; border-bottom: 1px solid #e2e8f0; color: #334155; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    .total-row { background: #d1fae5 !important; font-weight: 800; }
    .total-row td { color: #065f46; }
    .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: 800; text-transform: uppercase; }
    .badge.activo { background: #fef3c7; color: #92400e; }
    .badge.pagado { background: #d1fae5; color: #065f46; }
    .footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; text-align: center; color: #94a3b8; font-size: 10px; }
    .btn-print { position: fixed; top: 20px; right: 20px; background: #10b981; color: #fff; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 14px; box-shadow: 0 4px 12px rgba(16,185,129,0.3); z-index: 100; }
    .btn-print:hover { background: #059669; }
    @media print { body { padding: 0.5rem; } .btn-print { display: none; } th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>

<div class="header">
    <div>
        <h1>Reporte Financiero</h1>
        <div class="sub">SmartPark Pro · <?= htmlspecialchars($empresa['nombre']) ?></div>
    </div>
    <div class="logo">◇</div>
</div>

<div class="meta">
    <div class="meta-item">
        <div class="lbl">Empresa</div>
        <div class="val"><?= htmlspecialchars($empresa['nombre']) ?></div>
    </div>
    <div class="meta-item">
        <div class="lbl">NIT</div>
        <div class="val"><?= htmlspecialchars($empresa['nit'] ?? '-') ?></div>
    </div>
    <div class="meta-item">
        <div class="lbl">Periodo</div>
        <div class="val"><?= htmlspecialchars($desde) ?> → <?= htmlspecialchars($hasta) ?></div>
    </div>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="kpi-lbl">Cobrado en el período</div>
        <div class="kpi-val">$<?= number_format($sumaCobrado, 0, ',', '.') ?></div>
    </div>
    <div class="kpi warning">
        <div class="kpi-lbl">Estimado por cobrar (activos)</div>
        <div class="kpi-val">$<?= number_format($sumaEstimado, 0, ',', '.') ?></div>
    </div>
    <div class="kpi info">
        <div class="kpi-lbl">Total general</div>
        <div class="kpi-val">$<?= number_format($sumaCobrado + $sumaEstimado, 0, ',', '.') ?></div>
    </div>
</div>

<h2>Registros cobrados (finalizados)</h2>
<?php if (empty($finalizados)): ?>
    <p style="color: #94a3b8; text-align: center; padding: 1rem;">Sin registros cobrados en el período.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Placa</th><th>Tipo</th><th>Celda</th>
                <th>Ingreso</th><th>Salida</th><th>Horas</th>
                <th>Método</th><th style="text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($finalizados as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><strong><?= htmlspecialchars($r['placa']) ?></strong></td>
                    <td><?= htmlspecialchars($r['tipo_vehiculo']) ?></td>
                    <td><?= htmlspecialchars($r['cupo_asignado']) ?></td>
                    <td><?= htmlspecialchars(substr($r['fecha_ingreso'], 0, 16)) ?></td>
                    <td><?= htmlspecialchars(substr($r['fecha_salida'], 0, 16)) ?></td>
                    <td><?= (int)$r['horas'] ?></td>
                    <td><?= htmlspecialchars($r['metodo_pago'] ?? '-') ?></td>
                    <td style="text-align:right; color:#10b981; font-weight:700;">$<?= number_format((float)$r['total_pagar'], 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="8" style="text-align:right;">TOTAL COBRADO:</td>
                <td style="text-align:right;">$<?= number_format($sumaCobrado, 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>
<?php endif; ?>

<h2>Vehículos activos ahora (estimado a cobrar)</h2>
<?php if (empty($activos)): ?>
    <p style="color: #94a3b8; text-align: center; padding: 1rem;">No hay vehículos activos ahora.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Placa</th><th>Tipo</th><th>Celda</th>
                <th>Ingreso</th><th>Tiempo dentro</th><th>Horas</th>
                <th>Estado</th><th style="text-align:right;">Estimado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($activos as $a): 
                $minutos = (int)$a['minutos_dentro'];
                $tiempoTexto = floor($minutos / 60) . 'h ' . ($minutos % 60) . 'min';
            ?>
                <tr>
                    <td><?= (int)$a['id'] ?></td>
                    <td><strong><?= htmlspecialchars($a['placa']) ?></strong></td>
                    <td><?= htmlspecialchars($a['tipo_vehiculo']) ?></td>
                    <td><?= htmlspecialchars($a['cupo_asignado']) ?></td>
                    <td><?= htmlspecialchars(substr($a['fecha_ingreso'], 0, 16)) ?></td>
                    <td><strong style="color:#f59e0b;"><?= $tiempoTexto ?></strong></td>
                    <td><?= (int)$a['horas'] ?></td>
                    <td><span class="badge activo">Activo</span></td>
                    <td style="text-align:right; color:#f59e0b; font-weight:700;">$<?= number_format((float)$a['total_pagar'], 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="8" style="text-align:right;">TOTAL ESTIMADO:</td>
                <td style="text-align:right;">$<?= number_format($sumaEstimado, 0, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>
<?php endif; ?>

<div class="footer">
    SmartPark Pro © <?= date('Y') ?> · Reporte generado el <?= date('d/m/Y H:i') ?> · Documento confidencial
</div>

</body>
</html>