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
    SELECT r.id, r.placa, r.tipo_vehiculo, r.cupo_asignado,
           r.fecha_ingreso, r.fecha_salida, r.horas, r.total_pagar,
           r.metodo_pago, r.tipo_tarifa
    FROM registros_parqueo r
    WHERE r.empresa_id = :eid
      AND r.fecha_salida IS NOT NULL
      AND DATE(r.fecha_salida) BETWEEN :desde AND :hasta
    ORDER BY r.fecha_salida DESC
");
$stmt->execute(['eid' => $eid, 'desde' => $desde, 'hasta' => $hasta]);
$rows = $stmt->fetchAll();

$empresa = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$empresa->execute(['eid' => $eid]);
$empresa = $empresa->fetch();

$nombreArchivo = 'finanzas_' . preg_replace('/[^a-z0-9]/i', '_', $empresa['nombre']) . '_' . date('Ymd_His');

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"{$nombreArchivo}.csv\"");
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SmartPark Pro - Reporte Financiero']);
    fputcsv($out, ['Empresa:', $empresa['nombre']]);
    fputcsv($out, ['NIT:', $empresa['nit'] ?? '-']);
    fputcsv($out, ['Periodo:', "$desde a $hasta"]);
    fputcsv($out, []);
    fputcsv($out, ['ID','Placa','Tipo','Celda','Ingreso','Salida','Horas','Tarifa','Metodo','Total COP']);

    $suma = 0;
    foreach ($rows as $r) {
        $suma += (float) $r['total_pagar'];
        fputcsv($out, [
            $r['id'], $r['placa'], $r['tipo_vehiculo'], $r['cupo_asignado'],
            $r['fecha_ingreso'], $r['fecha_salida'], $r['horas'],
            $r['tipo_tarifa'], $r['metodo_pago'] ?? '-',
            number_format((float)$r['total_pagar'], 2, ',', '.'),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', '', '', '', 'TOTAL:', number_format($suma, 2, ',', '.')]);
    fclose($out);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><title>Reporte Financiero</title>
<style>
  body { font-family: Arial, sans-serif; padding: 2rem; color: #222; }
  h1 { color: #0a5c7a; }
  .meta { color: #666; font-size: .9rem; margin-bottom: 1rem; }
  table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: .85rem; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
  th { background: #0a5c7a; color: #fff; }
  tr:nth-child(even) { background: #f4f7fa; }
  .total { font-weight: bold; background: #e0f7e9; }
  @media print { .no-print { display: none; } }
</style>
</head><body>
  <div class="no-print" style="text-align:right;margin-bottom:1rem">
    <button onclick="window.print()" style="padding:8px 16px;background:#0a5c7a;color:#fff;border:0;border-radius:6px;cursor:pointer">
      Imprimir / Guardar como PDF
    </button>
  </div>

  <h1>SmartPark Pro - Reporte Financiero</h1>
  <div class="meta">
    <strong>Empresa:</strong> <?= htmlspecialchars($empresa['nombre']) ?><br>
    <strong>NIT:</strong> <?= htmlspecialchars($empresa['nit'] ?? '-') ?><br>
    <strong>Periodo:</strong> <?= htmlspecialchars($desde) ?> a <?= htmlspecialchars($hasta) ?><br>
    <strong>Generado:</strong> <?= date('Y-m-d H:i:s') ?>
  </div>

  <table>
    <thead><tr>
      <th>ID</th><th>Placa</th><th>Tipo</th><th>Celda</th><th>Ingreso</th>
      <th>Salida</th><th>Horas</th><th>Metodo</th><th>Total (COP)</th>
    </tr></thead>
    <tbody>
    <?php $suma = 0; foreach ($rows as $r): $suma += (float)$r['total_pagar']; ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= htmlspecialchars($r['placa']) ?></td>
        <td><?= htmlspecialchars($r['tipo_vehiculo']) ?></td>
        <td><?= htmlspecialchars($r['cupo_asignado']) ?></td>
        <td><?= htmlspecialchars($r['fecha_ingreso']) ?></td>
        <td><?= htmlspecialchars($r['fecha_salida']) ?></td>
        <td><?= (int)$r['horas'] ?></td>
        <td><?= htmlspecialchars($r['metodo_pago'] ?? '-') ?></td>
        <td style="text-align:right">$<?= number_format((float)$r['total_pagar'], 0, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="total">
        <td colspan="8" style="text-align:right">TOTAL:</td>
        <td style="text-align:right">$<?= number_format($suma, 0, ',', '.') ?> COP</td>
      </tr>
    </tfoot>
  </table>
</body></html>