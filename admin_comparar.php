<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

require_role(['admin', 'superadmin']);
require_password_change_guard();

$eid = current_empresa_id();
if (!$eid) { header('Location: login_empresa.php'); exit; }

$stmt = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$stmt->execute(['eid' => $eid]);
$empresa = $stmt->fetch() ?: ['nombre' => 'Mi Empresa', 'nit' => ''];

$focusId = (int)($_GET['focus'] ?? 0);
$dias = max(7, min(90, (int)($_GET['dias'] ?? 30)));

$sql = "
    SELECT p.id, p.nombre, p.ciudad, p.niveles, p.estado,
        (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :e1) AS cupos,
        (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :e2 AND estado = 'ocupado') AS ocupados,
        (SELECT COUNT(*) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :e3 AND estado = 'activo') AS activos,
        (SELECT COALESCE(SUM(total_pagar), 0) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :e4 AND estado_pago = 1 AND DATE(fecha_salida) >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)) AS recaudo_periodo,
        (SELECT COUNT(*) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :e5 AND estado = 'finalizado' AND DATE(fecha_salida) >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)) AS servicios,
        (SELECT COUNT(DISTINCT placa) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :e6 AND DATE(fecha_ingreso) >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)) AS placas_unicas
    FROM parqueaderos p
    WHERE p.empresa_id = :eid
    ORDER BY p.nombre
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'e1' => $eid, 'e2' => $eid, 'e3' => $eid, 'e4' => $eid, 'e5' => $eid, 'e6' => $eid,
    'dias' => $dias, 'eid' => $eid
]);
$sedes = $stmt->fetchAll();

// Calcular máximos para las barras relativas
$maxRecaudo = 1;
$maxServicios = 1;
$maxPlacas = 1;
foreach ($sedes as $s) {
    if ((float)$s['recaudo_periodo'] > $maxRecaudo) $maxRecaudo = (float)$s['recaudo_periodo'];
    if ((int)$s['servicios'] > $maxServicios) $maxServicios = (int)$s['servicios'];
    if ((int)$s['placas_unicas'] > $maxPlacas) $maxPlacas = (int)$s['placas_unicas'];
}

// Historial de los N días
$stmt = $pdo->prepare("
    SELECT DATE(fecha_salida) AS dia, parqueadero_id, COUNT(*) AS cnt, COALESCE(SUM(total_pagar), 0) AS total
    FROM registros_parqueo
    WHERE empresa_id = :eid AND estado_pago = 1 AND fecha_salida IS NOT NULL
      AND DATE(fecha_salida) >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
    GROUP BY DATE(fecha_salida), parqueadero_id
    ORDER BY dia ASC
");
$stmt->execute(['eid' => $eid, 'dias' => $dias]);
$porDiaPorSede = [];
foreach ($stmt->fetchAll() as $row) {
    $porDiaPorSede[$row['parqueadero_id']][$row['dia']] = $row;
}

$colores = ['#10b981', '#06b6d4', '#a78bfa', '#f59e0b', '#3b82f6', '#ec4899', '#8b5cf6', '#14b8a6'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comparar Sedes - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=53">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; }

    .page-header {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05));
        border: 1.5px solid rgba(16, 185, 129, 0.3);
        border-radius: 22px;
        padding: 1.75rem 2rem;
        margin-bottom: 2rem;
    }
    .page-header .badge { display: inline-block; padding: 0.3rem 0.85rem; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 100px; color: #10b981; font-size: 0.72rem; font-weight: 800; margin-bottom: 0.6rem; }
    .page-header h1 { font-size: 2rem; font-weight: 900; background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 0.3rem; }
    .page-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }

    .filtros { background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 16px; padding: 1rem 1.25rem; margin-bottom: 2rem; display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; }
    .filtros label { color: #94a3b8; font-size: 0.82rem; font-weight: 700; }
    .filtros select { padding: 0.7rem 1rem; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 10px; color: #f1f5f9; font-family: inherit; font-size: 0.88rem; }

    .section { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 18px; padding: 1.75rem; margin-bottom: 1.5rem; }
    .section h2 { font-size: 1.15rem; font-weight: 800; color: #f1f5f9; margin: 0 0 0.35rem; display: flex; align-items: center; gap: 0.6rem; }
    .section .sub { color: #64748b; font-size: 0.82rem; margin-bottom: 1.5rem; }

    /* Ranking */
    .ranking { display: flex; flex-direction: column; gap: 1rem; }
    .rank-item { padding: 1.25rem; background: rgba(2, 6, 23, 0.5); border-radius: 14px; border-left: 4px solid #10b981; position: relative; transition: all 0.3s ease; }
    .rank-item:hover { background: rgba(2, 6, 23, 0.7); transform: translateX(4px); }
    .rank-item .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.85rem; }
    .rank-item .pos { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 10px; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; font-weight: 900; font-size: 0.9rem; flex-shrink: 0; }
    .rank-item .rank-info { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
    .rank-item .rank-info h3 { font-size: 1rem; font-weight: 800; color: #f1f5f9; margin: 0; }
    .rank-item .rank-info .meta { font-size: 0.78rem; color: #64748b; }
    .rank-item .kpis-mini { display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: 0.8rem; }
    .rank-item .kpis-mini .k { color: #94a3b8; }
    .rank-item .kpis-mini .k strong { color: #10b981; font-weight: 800; font-family: 'JetBrains Mono', monospace; }

    .barra-comp { height: 8px; background: rgba(255,255,255,0.05); border-radius: 100px; overflow: hidden; margin-top: 0.6rem; }
    .barra-comp .fill { height: 100%; border-radius: 100px; background: linear-gradient(90deg, #10b981, #06b6d4); transition: width 0.8s ease; }

    /* Gráfico de líneas */
    .chart-container { position: relative; height: 280px; padding: 1rem; background: rgba(2, 6, 23, 0.4); border-radius: 14px; overflow: hidden; }
    .chart-svg { width: 100%; height: 100%; }
    .chart-leyenda { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1rem; font-size: 0.78rem; }
    .chart-leyenda .item { display: inline-flex; align-items: center; gap: 0.4rem; color: #cbd5e1; }
    .chart-leyenda .dot { width: 10px; height: 10px; border-radius: 50%; }

    @media (max-width: 768px) {
        .rank-item .kpis-mini { font-size: 0.75rem; gap: 0.75rem; }
        .chart-container { height: 200px; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="page-header">
        <span class="badge">◉ <?= htmlspecialchars($empresa['nombre']) ?></span>
        <h1>Comparar Sedes</h1>
        <p>Análisis comparativo de rendimiento entre tus parqueaderos</p>
    </div>

    <form method="GET" class="filtros">
        <label>Período:</label>
        <select name="dias" onchange="this.form.submit()">
            <option value="7" <?= $dias === 7 ? 'selected' : '' ?>>Últimos 7 días</option>
            <option value="30" <?= $dias === 30 ? 'selected' : '' ?>>Últimos 30 días</option>
            <option value="60" <?= $dias === 60 ? 'selected' : '' ?>>Últimos 60 días</option>
            <option value="90" <?= $dias === 90 ? 'selected' : '' ?>>Últimos 90 días</option>
        </select>
        <a href="admin_sedes.php" class="cyber-btn-outline" style="margin-left: auto;">← Volver a mis sedes</a>
    </form>

    <?php if (empty($sedes)): ?>
        <div class="section">
            <p style="text-align: center; color: #64748b; padding: 3rem;">No tienes sedes configuradas todavía.</p>
        </div>
    <?php else: ?>

    <!-- RANKING POR RECAUDO -->
    <div class="section">
        <h2>◆ Ranking por recaudo</h2>
        <p class="sub">Ordenado de mayor a menor recaudo en los últimos <?= $dias ?> días</p>

        <?php 
        usort($sedes, fn($a, $b) => (float)$b['recaudo_periodo'] <=> (float)$a['recaudo_periodo']);
        foreach ($sedes as $i => $s): 
            $pctRecaudo = $maxRecaudo > 0 ? round(((float)$s['recaudo_periodo'] / $maxRecaudo) * 100) : 0;
            $pctOcup = (int)$s['cupos'] > 0 ? round(((int)$s['ocupados'] / (int)$s['cupos']) * 100) : 0;
        ?>
            <div class="rank-item" style="border-left-color: <?= $colores[$i % count($colores)] ?>;">
                <div class="head">
                    <div class="rank-info">
                        <span class="pos"><?= $i + 1 ?></span>
                        <div>
                            <h3><?= htmlspecialchars($s['nombre']) ?></h3>
                            <div class="meta">
                                <?= $s['ciudad'] ? htmlspecialchars($s['ciudad']) . ' · ' : '' ?>
                                <?= (int)$s['cupos'] ?> celdas · <?= (int)$s['niveles'] ?> niveles
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 1.5rem; font-weight: 900; color: #10b981; font-family: 'JetBrains Mono', monospace;">
                            $<?= number_format((float)$s['recaudo_periodo'], 0, ',', '.') ?>
                        </div>
                        <div style="font-size: 0.75rem; color: #64748b;">en <?= $dias ?> días</div>
                    </div>
                </div>

                <div class="kpis-mini">
                    <div class="k"><strong><?= (int)$s['servicios'] ?></strong> servicios</div>
                    <div class="k"><strong><?= (int)$s['placas_unicas'] ?></strong> vehículos únicos</div>
                    <div class="k"><strong><?= $pctOcup ?>%</strong> ocupación actual</div>
                    <div class="k"><strong><?= (int)$s['activos'] ?></strong> dentro ahora</div>
                </div>

                <div class="barra-comp">
                    <div class="fill" style="width: <?= $pctRecaudo ?>%; background: linear-gradient(90deg, <?= $colores[$i % count($colores)] ?>, <?= $colores[$i % count($colores)] ?>aa);"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- COMPARATIVA VISUAL DE MÉTRICAS -->
    <div class="section">
        <h2>◐ Comparativa de métricas</h2>
        <p class="sub">Comparación visual relativa entre todas tus sedes</p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
            <!-- Recaudo -->
            <div>
                <h3 style="font-size: 0.85rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem;">Recaudo (<?= $dias ?> días)</h3>
                <?php foreach ($sedes as $i => $s): 
                    $pct = $maxRecaudo > 0 ? round(((float)$s['recaudo_periodo'] / $maxRecaudo) * 100) : 0;
                ?>
                    <div style="margin-bottom: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.78rem; color: #cbd5e1; margin-bottom: 0.3rem;">
                            <span><?= htmlspecialchars($s['nombre']) ?></span>
                            <span style="color: #10b981; font-weight: 800;">$<?= number_format((float)$s['recaudo_periodo'], 0, ',', '.') ?></span>
                        </div>
                        <div class="barra-comp"><div class="fill" style="width: <?= $pct ?>%;"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Servicios -->
            <div>
                <h3 style="font-size: 0.85rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem;">Servicios (<?= $dias ?> días)</h3>
                <?php foreach ($sedes as $i => $s): 
                    $pct = $maxServicios > 0 ? round(((int)$s['servicios'] / $maxServicios) * 100) : 0;
                ?>
                    <div style="margin-bottom: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.78rem; color: #cbd5e1; margin-bottom: 0.3rem;">
                            <span><?= htmlspecialchars($s['nombre']) ?></span>
                            <span style="color: #06b6d4; font-weight: 800;"><?= (int)$s['servicios'] ?></span>
                        </div>
                        <div class="barra-comp"><div class="fill" style="width: <?= $pct ?>%; background: linear-gradient(90deg, #06b6d4, #3b82f6);"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Vehículos únicos -->
            <div>
                <h3 style="font-size: 0.85rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem;">Vehículos únicos</h3>
                <?php foreach ($sedes as $i => $s): 
                    $pct = $maxPlacas > 0 ? round(((int)$s['placas_unicas'] / $maxPlacas) * 100) : 0;
                ?>
                    <div style="margin-bottom: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.78rem; color: #cbd5e1; margin-bottom: 0.3rem;">
                            <span><?= htmlspecialchars($s['nombre']) ?></span>
                            <span style="color: #a78bfa; font-weight: 800;"><?= (int)$s['placas_unicas'] ?></span>
                        </div>
                        <div class="barra-comp"><div class="fill" style="width: <?= $pct ?>%; background: linear-gradient(90deg, #a78bfa, #8b5cf6);"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>