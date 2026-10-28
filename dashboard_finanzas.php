<?php
session_start();
require_once 'conexion.php';
require_once 'helpers.php';
require_once 'csrf.php';
send_security_headers();

require_role_empresa(['admin','superadmin','finanzas']);
$eid = current_empresa_id();

$empresa = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$empresa->execute(['eid' => $eid]);
$empresa = $empresa->fetch() ?: ['nombre' => 'Mi Empresa', 'nit' => ''];

$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');

// KPIs FINALIZADOS (ventas reales cobradas)

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total_pagar), 0) AS total, COUNT(*) AS cnt
    FROM registros_parqueo
    WHERE empresa_id = :eid AND estado = 'finalizado' AND estado_pago = 1
      AND DATE(fecha_salida) BETWEEN :desde AND :hasta
");
$stmt->execute(['eid' => $eid, 'desde' => $desde, 'hasta' => $hasta]);
$r = $stmt->fetch();
$totalCobrado = (float)$r['total'];
$cantCobrado = (int)$r['cnt'];
$ticketProm = $cantCobrado > 0 ? $totalCobrado / $cantCobrado : 0;

// VEHÍCULOS ACTIVOS AHORA (ingresos estimados)

$activos = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               TIMESTAMPDIFF(MINUTE, r.fecha_ingreso, NOW()) AS minutos_dentro
        FROM registros_parqueo r
        WHERE r.empresa_id = :eid AND r.estado = 'activo'
        ORDER BY r.fecha_ingreso ASC
    ");
    $stmt->execute(['eid' => $eid]);
    $activos = $stmt->fetchAll();
} catch (\Throwable $e) { $activos = []; }

// Calcular horas y montos estimados de activos
$totalEstimadoActivos = 0;
foreach ($activos as &$a) {
    $minutos = (int)$a['minutos_dentro'];
    $horas = max(1, ceil($minutos / 60));
    
    $tarifa = 4000.0;
    try {
        $stmt = $pdo->prepare("
            SELECT precio FROM tarifas
            WHERE empresa_id = :eid AND tipo_vehiculo = :tv AND tipo_tarifa = 'hora' AND activo = 1
            ORDER BY vigente_desde DESC LIMIT 1
        ");
        $stmt->execute(['eid' => $eid, 'tv' => $a['tipo_vehiculo']]);
        $t = $stmt->fetchColumn();
        if ($t) $tarifa = (float)$t;
    } catch (\Throwable $e) {}
    
    $a['horas'] = $horas;
    $a['tarifa'] = $tarifa;
    $a['estimado'] = $horas * $tarifa;
    $a['tiempo_texto'] = floor($minutos / 60) . 'h ' . ($minutos % 60) . 'min';
    
    $totalEstimadoActivos += $a['estimado'];
}
unset($a);

// DESGLOSE POR MÉTODO DE PAGO (solo finalizados)

$porMetodo = [];
try {
    $stmt = $pdo->prepare("
        SELECT metodo_pago, COUNT(*) AS cnt, COALESCE(SUM(total_pagar), 0) AS total
        FROM registros_parqueo
        WHERE empresa_id = :eid AND estado_pago = 1 AND metodo_pago IS NOT NULL
          AND DATE(fecha_salida) BETWEEN :desde AND :hasta
        GROUP BY metodo_pago
        ORDER BY total DESC
    ");
    $stmt->execute(['eid' => $eid, 'desde' => $desde, 'hasta' => $hasta]);
    $porMetodo = $stmt->fetchAll();
} catch (\Throwable $e) {}

// INGRESOS POR DÍA (últimos 30 días)

$porDia = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE(fecha_salida) AS dia,
               COUNT(*) AS cnt,
               COALESCE(SUM(total_pagar), 0) AS total
        FROM registros_parqueo
        WHERE empresa_id = :eid AND estado_pago = 1 AND fecha_salida IS NOT NULL
          AND DATE(fecha_salida) BETWEEN :desde AND :hasta
        GROUP BY DATE(fecha_salida)
        ORDER BY dia DESC
        LIMIT 30
    ");
    $stmt->execute(['eid' => $eid, 'desde' => $desde, 'hasta' => $hasta]);
    $porDia = $stmt->fetchAll();
} catch (\Throwable $e) {}

$coloresMetodo = [
    'efectivo' => '#10b981',
    'tarjeta' => '#06b6d4',
    'transferencia' => '#a78bfa',
    'qr' => '#f59e0b',
    'mixto' => '#3b82f6',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Finanzas - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=51">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1300px; margin: 0 auto; padding: 0 1.5rem; }
    
    .empresa-header {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05));
        border: 1.5px solid rgba(16, 185, 129, 0.3);
        border-radius: 20px;
        padding: 1.75rem 2rem;
        margin-bottom: 2rem;
    }
    .empresa-header .badge {
        display: inline-block;
        padding: 0.3rem 0.85rem;
        background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        margin-bottom: 0.6rem;
    }
    .empresa-header h1 {
        font-size: clamp(1.5rem, 3vw, 2rem);
        font-weight: 900;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0 0 0.3rem;
        letter-spacing: -0.02em;
    }
    .empresa-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }
    
    /* Filtros */
    .filtros {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 2rem;
        display: grid;
        grid-template-columns: 1fr 1fr auto;
        gap: 1rem;
        align-items: end;
    }
    .filtro-item label {
        display: block;
        font-size: 0.72rem;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        margin-bottom: 0.4rem;
    }
    .filtro-item input {
        width: 100%;
        padding: 0.7rem 0.9rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 9px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.88rem;
        box-sizing: border-box;
    }
    .btn-aplicar {
        padding: 0.75rem 1.5rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        border: none;
        border-radius: 10px;
        font-weight: 800;
        font-size: 0.88rem;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.3s ease;
    }
    .btn-aplicar:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4); }
    
    /* KPIs */
    .kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .kpi {
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.18);
        border-radius: 18px;
        padding: 1.4rem;
        position: relative;
        overflow: hidden;
        transition: all 0.35s ease;
    }
    .kpi::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #10b981, #06b6d4);
    }
    .kpi:hover { transform: translateY(-4px); border-color: rgba(16, 185, 129, 0.5); }
    .kpi .lbl {
        font-size: 0.7rem;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    .kpi .val {
        font-size: 1.9rem;
        font-weight: 900;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1;
    }
    .kpi.info::before { background: linear-gradient(90deg, #06b6d4, #3b82f6); }
    .kpi.info .val { background: linear-gradient(135deg, #06b6d4, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi.warning::before { background: linear-gradient(90deg, #f59e0b, #f97316); }
    .kpi.warning .val { background: linear-gradient(135deg, #f59e0b, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi .sub { font-size: 0.72rem; color: #64748b; margin-top: 0.4rem; }
    
    /* Sección genérica */
    .section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
    }
    .section h2 {
        font-size: 1.1rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 0.35rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .section .sub { color: #64748b; font-size: 0.82rem; margin-bottom: 1.25rem; }
    
    /* Tabla */
    table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    thead tr { border-bottom: 1px solid rgba(16, 185, 129, 0.2); }
    th {
        padding: 0.75rem;
        text-align: left;
        color: #10b981;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 800;
    }
    tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
    tbody tr:hover { background: rgba(16, 185, 129, 0.04); }
    td { padding: 0.75rem; color: #cbd5e1; }
    td.mono { font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; }
    td.dinero { color: #10b981; font-weight: 700; text-align: right; }
    .badge-tipo {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-family: 'JetBrains Mono', monospace;
        font-weight: 700;
        font-size: 0.78rem;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #10b981;
    }
    .estado-badge {
        display: inline-block;
        padding: 0.15rem 0.55rem;
        border-radius: 100px;
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
    }
    .estado-badge.activo { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
    .estado-badge.pagado { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
    
    /* Métodos de pago */
    .metodos-list { display: flex; flex-direction: column; gap: 0.9rem; }
    .metodo-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }
    .metodo-barra {
        flex: 1;
        height: 8px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 100px;
        overflow: hidden;
    }
    .metodo-barra .fill { height: 100%; border-radius: 100px; transition: width 1s ease; }
    
    .empty { text-align: center; padding: 2rem; color: #64748b; font-size: 0.85rem; }
    .empty .ico { font-size: 2rem; opacity: 0.4; display: block; margin-bottom: 0.5rem; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">
<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="empresa-header">
        <span class="badge">◉ <?= htmlspecialchars($empresa['nombre']) ?></span>
        <h1>Panel Financiero</h1>
        <p>Vista completa de ingresos, vehículos activos y análisis por período</p>
    </div>

    <!-- FILTROS -->
    <form method="GET" class="filtros">
        <div class="filtro-item">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>">
        </div>
        <div class="filtro-item">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
        </div>
        <button type="submit" class="btn-aplicar">🔍 Aplicar filtro</button>
    </form>

    <!-- KPIs -->
    <div class="kpis">
        <div class="kpi">
            <div class="lbl">Cobrado en el período</div>
            <div class="val">$<?= number_format($totalCobrado, 0, ',', '.') ?></div>
            <div class="sub"><?= $cantCobrado ?> servicios completados</div>
        </div>
        <div class="kpi info">
            <div class="lbl">Ticket promedio</div>
            <div class="val">$<?= number_format($ticketProm, 0, ',', '.') ?></div>
            <div class="sub">Por vehículo atendido</div>
        </div>
        <div class="kpi warning">
            <div class="lbl">Vehículos activos ahora</div>
            <div class="val"><?= count($activos) ?></div>
            <div class="sub">Estimado por cobrar: $<?= number_format($totalEstimadoActivos, 0, ',', '.') ?></div>
        </div>
        <div class="kpi info">
            <div class="lbl">Ingreso estimado activo</div>
            <div class="val">$<?= number_format($totalEstimadoActivos, 0, ',', '.') ?></div>
            <div class="sub">Si salieran ahora</div>
        </div>
    </div>

    <!-- VEHÍCULOS ACTIVOS -->
    <div class="section">
        <h2>▤ Vehículos activos en este momento</h2>
        <p class="sub">Vehículos dentro del parqueadero con su tiempo y monto acumulado estimado</p>
        
        <?php if (empty($activos)): ?>
            <div class="empty"><span class="ico">🚗</span>No hay vehículos activos ahora</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Placa</th>
                            <th>Modelo</th>
                            <th>Tipo</th>
                            <th>Celda</th>
                            <th>Ingreso</th>
                            <th>Tiempo dentro</th>
                            <th>Tarifa/hora</th>
                            <th style="text-align: right;">Estimado a cobrar</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activos as $a): ?>
                        <tr>
                            <td><span class="badge-tipo"><?= htmlspecialchars($a['placa']) ?></span></td>
                            <td><?= htmlspecialchars($a['modelo'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(ucfirst($a['tipo_vehiculo'])) ?></td>
                            <td><strong style="color: #06b6d4;"><?= htmlspecialchars($a['cupo_asignado']) ?></strong></td>
                            <td class="mono"><?= htmlspecialchars(substr($a['fecha_ingreso'], 11, 5)) ?></td>
                            <td><strong style="color: #fbbf24;"><?= htmlspecialchars($a['tiempo_texto']) ?></strong></td>
                            <td>$<?= number_format($a['tarifa'], 0, ',', '.') ?></td>
                            <td class="dinero">$<?= number_format($a['estimado'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top: 2px solid rgba(16, 185, 129, 0.4);">
                            <td colspan="7" style="text-align: right; font-weight: 800; color: #94a3b8;">TOTAL ESTIMADO:</td>
                            <td class="dinero" style="font-size: 1.1rem;">$<?= number_format($totalEstimadoActivos, 0, ',', '.') ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- MÉTODOS DE PAGO -->
    <?php if (!empty($porMetodo)): ?>
    <div class="section">
        <h2>💳 Métodos de pago (en el período)</h2>
        <p class="sub">Distribución de ingresos cobrados según el método</p>
        
        <?php
        $maxM = 1;
        foreach ($porMetodo as $m) { if ((float)$m['total'] > $maxM) $maxM = (float)$m['total']; }
        ?>
        
        <div class="metodos-list">
            <?php foreach ($porMetodo as $m): 
                $metodo = $m['metodo_pago'] ?? 'otro';
                $color = $coloresMetodo[$metodo] ?? '#64748b';
                $pct = $maxM > 0 ? round(((float)$m['total'] / $maxM) * 100) : 0;
            ?>
                <div class="metodo-row">
                    <div style="min-width: 140px;">
                        <span style="display: inline-block; padding: 0.25rem 0.7rem; border-radius: 100px; background: <?= $color ?>22; color: <?= $color ?>; font-weight: 800; font-size: 0.72rem; text-transform: uppercase;"><?= htmlspecialchars($metodo) ?></span>
                        <span style="color: #64748b; font-size: 0.75rem; margin-left: 0.5rem;"><?= (int)$m['cnt'] ?> pagos</span>
                    </div>
                    <div class="metodo-barra">
                        <div class="fill" style="width: <?= $pct ?>%; background: linear-gradient(90deg, <?= $color ?>, <?= $color ?>aa);"></div>
                    </div>
                    <div style="color: <?= $color ?>; font-weight: 800; min-width: 110px; text-align: right;">$<?= number_format((float)$m['total'], 0, ',', '.') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- INGRESOS POR DÍA -->
    <div class="section">
        <h2>📊 Ingresos por día</h2>
        <p class="sub">Historial de cobros en el rango seleccionado (máx. 30 días)</p>
        
        <?php if (empty($porDia)): ?>
            <div class="empty"><span class="ico">📊</span>Sin datos en el rango seleccionado</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th style="text-align: center;">Servicios</th>
                            <th style="text-align: right;">Recaudo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($porDia as $d): ?>
                        <tr>
                            <td class="mono"><?= htmlspecialchars($d['dia']) ?></td>
                            <td style="text-align: center;"><?= (int)$d['cnt'] ?></td>
                            <td class="dinero">$<?= number_format((float)$d['total'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- EXPORTAR -->
    <div class="section">
        <h2>📥 Exportar reportes</h2>
        <p class="sub">Descarga los datos en formato CSV o PDF</p>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="exportar_finanzas.php?formato=csv&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>" class="cyber-btn-outline">📊 Descargar CSV</a>
            <a href="exportar_finanzas.php?formato=pdf&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>" target="_blank" class="cyber-btn-outline">📄 Descargar PDF</a>
        </div>
    </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>