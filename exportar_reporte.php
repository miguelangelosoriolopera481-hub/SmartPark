<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

$rol = $_SESSION['role'] ?? '';
$eid = (int)($_SESSION['empresa_id'] ?? 0);

if (empty($_SESSION['usuario_id'])) {
    http_response_code(403);
    die('Acceso denegado.');
}

$tipo = $_GET['tipo'] ?? 'registros';
$formato = $_GET['formato'] ?? 'pdf';
$empresaId = (int)($_GET['empresa_id'] ?? 0);
$parqueaderoId = (int)($_GET['parqueadero_id'] ?? 0);
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// Determinar scope
$scopeEmpresa = null;
if ($rol === 'developer') {
    $scopeEmpresa = $empresaId > 0 ? $empresaId : null;
} else {
    // Admin solo puede ver SU empresa
    $scopeEmpresa = $eid;
}

$titulo = '';
$datos = [];
$columnas = [];

if ($tipo === 'registros') {
    $titulo = 'Reporte de Registros de Parqueo';
    $where = ['1=1'];
    $params = [];
    if ($scopeEmpresa) { $where[] = 'r.empresa_id = :eid'; $params['eid'] = $scopeEmpresa; }
    if ($parqueaderoId > 0) { $where[] = 'r.parqueadero_id = :pid'; $params['pid'] = $parqueaderoId; }
    $where[] = 'DATE(r.fecha_ingreso) BETWEEN :desde AND :hasta';
    $params['desde'] = $desde;
    $params['hasta'] = $hasta;
    $whereSQL = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT r.id, r.placa, r.modelo, e.nombre AS empresa, p.nombre AS parqueadero,
               r.cupo_asignado, r.tipo_vehiculo, r.fecha_ingreso, r.fecha_salida,
               r.horas, r.total_pagar, r.estado, r.estado_pago
        FROM registros_parqueo r
        LEFT JOIN empresas e ON e.id = r.empresa_id
        LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
        WHERE $whereSQL
        ORDER BY r.fecha_ingreso DESC
        LIMIT 5000
    ");
    $stmt->execute($params);
    $datos = $stmt->fetchAll();
    $columnas = ['ID','Placa','Modelo','Empresa','Parqueadero','Celda','Tipo','Ingreso','Salida','Horas','Total','Estado','Pago'];
}
elseif ($tipo === 'empresa') {
    $titulo = 'Reporte Completo de Empresa';
    $empresaId = $empresaId ?: $scopeEmpresa;
    if (!$empresaId) die('Falta empresa_id');
    $stmt = $pdo->prepare("SELECT * FROM empresas WHERE id = :id");
    $stmt->execute(['id' => $empresaId]);
    $empresa = $stmt->fetch();
    if (!$empresa) die('Empresa no encontrada');
    if ($rol !== 'developer' && $empresaId !== $eid) die('Acceso denegado');

    $titulo = 'Reporte — ' . $empresa['nombre'];
    $parqueaderos = $pdo->prepare("SELECT * FROM parqueaderos WHERE empresa_id = :eid");
    $parqueaderos->execute(['eid' => $empresaId]);
    $datos = $parqueaderos->fetchAll();
    $columnas = ['ID','Nombre','Ciudad','Dirección','Niveles','Estado'];
}
elseif ($tipo === 'parqueadero') {
    $parqueaderoId = $parqueaderoId ?: 0;
    if (!$parqueaderoId) die('Falta parqueadero_id');
    $stmt = $pdo->prepare("
        SELECT p.*, e.nombre AS empresa 
        FROM parqueaderos p 
        JOIN empresas e ON e.id = p.empresa_id
        WHERE p.id = :id
    ");
    $stmt->execute(['id' => $parqueaderoId]);
    $p = $stmt->fetch();
    if (!$p) die('Parqueadero no encontrado');
    if ($rol !== 'developer' && (int)$p['empresa_id'] !== $eid) die('Acceso denegado');

    $titulo = 'Reporte — ' . $p['nombre'];
    $stmt = $pdo->prepare("
        SELECT r.id, r.placa, r.modelo, r.cupo_asignado, r.tipo_vehiculo,
               r.fecha_ingreso, r.fecha_salida, r.horas, r.total_pagar, r.estado, r.estado_pago
        FROM registros_parqueo r
        WHERE r.parqueadero_id = :pid AND DATE(r.fecha_ingreso) BETWEEN :desde AND :hasta
        ORDER BY r.fecha_ingreso DESC LIMIT 5000
    ");
    $stmt->execute(['pid' => $parqueaderoId, 'desde' => $desde, 'hasta' => $hasta]);
    $datos = $stmt->fetchAll();
    $columnas = ['ID','Placa','Modelo','Celda','Tipo','Ingreso','Salida','Horas','Total','Estado','Pago'];
}

// FORMATO CSV

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_' . $tipo . '_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, [$titulo]);
    fputcsv($out, ['Generado:', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Rango:', "$desde a $hasta"]);
    fputcsv($out, []);
    fputcsv($out, $columnas);
    foreach ($datos as $row) {
        $vals = array_values($row);
        fputcsv($out, $vals);
    }
    fclose($out);
    exit;
}

// FORMATO HTML imprimible (PDF via navegador)

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($titulo) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Inter', Arial, sans-serif;
        background: #fff;
        color: #1a2332;
        padding: 2rem;
        font-size: 12px;
    }
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-bottom: 1rem;
        border-bottom: 3px solid #10b981;
        margin-bottom: 1.5rem;
    }
    .header h1 { color: #10b981; font-size: 22px; font-weight: 800; margin-bottom: 0.25rem; }
    .header .sub { color: #64748b; font-size: 11px; }
    .header .logo { font-size: 32px; }
    .meta {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
        background: #f0fdf4;
        padding: 1rem;
        border-radius: 8px;
        border-left: 4px solid #10b981;
    }
    .meta-item .lbl { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 0.2rem; }
    .meta-item .val { font-size: 12px; color: #1a2332; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 10px; }
    th {
        background: #10b981;
        color: #fff;
        text-align: left;
        padding: 8px 6px;
        text-transform: uppercase;
        font-size: 9px;
        letter-spacing: 0.05em;
        font-weight: 800;
    }
    td { padding: 6px; border-bottom: 1px solid #e2e8f0; color: #334155; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    .total-row { background: #d1fae5 !important; font-weight: 800; }
    .footer {
        margin-top: 2rem;
        padding-top: 1rem;
        border-top: 1px solid #e2e8f0;
        text-align: center;
        color: #94a3b8;
        font-size: 10px;
    }
    .btn-print {
        position: fixed;
        top: 20px;
        right: 20px;
        background: #10b981;
        color: #fff;
        border: none;
        padding: 12px 20px;
        border-radius: 10px;
        font-weight: 700;
        cursor: pointer;
        font-size: 14px;
        box-shadow: 0 4px 12px rgba(16,185,129,0.3);
        z-index: 100;
    }
    .btn-print:hover { background: #059669; }
    @media print {
        body { padding: 0.5rem; }
        .btn-print { display: none; }
        th { background: #10b981 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>

<div class="header">
    <div>
        <h1><?= htmlspecialchars($titulo) ?></h1>
        <div class="sub">SmartPark Pro · Sistema de gestión inteligente de parqueaderos</div>
    </div>
    <div class="logo">◇</div>
</div>

<div class="meta">
    <div class="meta-item">
        <div class="lbl">Generado por</div>
        <div class="val"><?= htmlspecialchars($_SESSION['nombre'] ?? 'Sistema') ?></div>
    </div>
    <div class="meta-item">
        <div class="lbl">Fecha de generación</div>
        <div class="val"><?= date('Y-m-d H:i:s') ?></div>
    </div>
    <div class="meta-item">
        <div class="lbl">Rango de datos</div>
        <div class="val"><?= htmlspecialchars($desde) ?> → <?= htmlspecialchars($hasta) ?></div>
    </div>
</div>

<?php if (empty($datos)): ?>
    <p style="text-align: center; padding: 3rem; color: #94a3b8;">No hay datos en el rango seleccionado.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <?php foreach ($columnas as $c): ?>
                    <th><?= htmlspecialchars($c) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $totalRecaudo = 0;
            foreach ($datos as $row): 
                $vals = array_values($row);
                if (isset($row['total_pagar'])) $totalRecaudo += (float)$row['total_pagar'];
            ?>
                <tr>
                    <?php foreach ($vals as $v): ?>
                        <td><?= htmlspecialchars((string)$v) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($totalRecaudo > 0): ?>
                <tr class="total-row">
                    <td colspan="<?= count($columnas) - 1 ?>" style="text-align: right;">TOTAL RECAUDO:</td>
                    <td>$<?= number_format($totalRecaudo, 0, ',', '.') ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <p style="margin-top: 1rem; color: #64748b; font-size: 10px;">
        Total de registros: <strong><?= count($datos) ?></strong>
    </p>
<?php endif; ?>

<div class="footer">
    SmartPark Pro © <?= date('Y') ?> · Reporte generado automáticamente · Documento confidencial
</div>

</body>
</html>
