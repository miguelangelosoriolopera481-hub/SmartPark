<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

if (empty($_SESSION['usuario_id']) || ($_SESSION['role'] ?? '') !== 'developer') {
    header('Location: login_developer.php');
    exit;
}

try {
    $parqueaderos = $pdo->query("
        SELECT p.*, e.nombre AS empresa_nombre, e.id AS empresa_id,
            (SELECT COUNT(*) FROM cupos c WHERE c.parqueadero_id = p.id) AS total_cupos,
            (SELECT COUNT(*) FROM cupos c WHERE c.parqueadero_id = p.id AND c.estado='disponible') AS cupos_libres,
            (SELECT COUNT(*) FROM cupos c WHERE c.parqueadero_id = p.id AND c.estado='ocupado') AS cupos_ocupados,
            (SELECT COUNT(*) FROM cupos c WHERE c.parqueadero_id = p.id AND c.estado='mantenimiento') AS cupos_mantenimiento,
            (SELECT COUNT(*) FROM registros_parqueo rp WHERE rp.parqueadero_id = p.id) AS total_registros,
            (SELECT COUNT(*) FROM registros_parqueo rp WHERE rp.parqueadero_id = p.id AND rp.estado='activo') AS activos_ahora,
            (SELECT COALESCE(SUM(rp.total_pagar),0) FROM registros_parqueo rp WHERE rp.parqueadero_id = p.id AND rp.estado_pago=1) AS recaudo_total,
            (SELECT COALESCE(SUM(rp.total_pagar),0) FROM registros_parqueo rp WHERE rp.parqueadero_id = p.id AND rp.estado_pago=1 AND DATE(rp.fecha_salida)=CURDATE()) AS recaudo_hoy
        FROM parqueaderos p
        JOIN empresas e ON e.id = p.empresa_id
        ORDER BY e.nombre, p.nombre
    ")->fetchAll();
} catch (\Throwable $e) { $parqueaderos = []; }

// Stats globales
$global = [
    'parqueaderos' => count($parqueaderos),
    'cupos' => 0, 'libres' => 0, 'ocupados' => 0,
    'activos' => 0, 'recaudo_hoy' => 0, 'recaudo_total' => 0,
];
foreach ($parqueaderos as $p) {
    $global['cupos'] += (int)$p['total_cupos'];
    $global['libres'] += (int)$p['cupos_libres'];
    $global['ocupados'] += (int)$p['cupos_ocupados'];
    $global['activos'] += (int)$p['activos_ahora'];
    $global['recaudo_hoy'] += (float)$p['recaudo_hoy'];
    $global['recaudo_total'] += (float)$p['recaudo_total'];
}
$pctOcupacionGlobal = $global['cupos'] > 0 ? round(($global['ocupados'] / $global['cupos']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Parqueaderos - Developer</title>
<link rel="stylesheet" href="assets/css/main.css?v=25">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { margin-bottom: 2rem; }
    .page-header .breadcrumb { color: #64748b; font-size: 0.82rem; margin-bottom: 0.75rem; }
    .page-header .breadcrumb a { color: #10b981; text-decoration: none; }
    .page-header h1 { font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 900; letter-spacing: -0.03em; background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.4rem; }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }

    .global-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .g-stat {
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.18);
        border-radius: 16px;
        padding: 1.25rem;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .g-stat:hover { transform: translateY(-4px); border-color: rgba(16, 185, 129, 0.5); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.15); }
    .g-stat .lbl { font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.35rem; }
    .g-stat .val { font-size: 1.8rem; font-weight: 900; background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; }
    .g-stat.info .val { background: linear-gradient(135deg, #06b6d4, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .g-stat.warning .val { background: linear-gradient(135deg, #f59e0b, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

    .parq-card {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 20px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    .parq-card::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 4px;
        background: linear-gradient(180deg, #10b981, #06b6d4);
    }
    .parq-card:hover { border-color: rgba(16, 185, 129, 0.4); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.12); }

    .parq-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }
    .parq-header h3 {
        font-size: 1.2rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 0.35rem;
    }
    .parq-header .empresa-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.25rem 0.7rem;
        background: rgba(16, 185, 129, 0.12);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.72rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }
    .parq-meta { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.8rem; color: #64748b; }

    .parq-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 0.9rem;
        padding: 1rem 0;
        border-top: 1px solid rgba(255,255,255,0.05);
        border-bottom: 1px solid rgba(255,255,255,0.05);
        margin-bottom: 1rem;
    }
    .pk-item { text-align: center; }
    .pk-item .pk-num { font-size: 1.4rem; font-weight: 900; color: #10b981; line-height: 1; }
    .pk-item .pk-lbl { font-size: 0.65rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.35rem; font-weight: 700; }
    .pk-item.info .pk-num { color: #06b6d4; }
    .pk-item.warning .pk-num { color: #f59e0b; }
    .pk-item.danger .pk-num { color: #ef4444; }

    /* Barra de ocupación visual */
    .ocupacion-bar {
        display: flex;
        height: 10px;
        border-radius: 100px;
        overflow: hidden;
        background: rgba(255, 255, 255, 0.05);
        margin-bottom: 0.5rem;
    }
    .ocupacion-bar .fill-libre { background: linear-gradient(90deg, #10b981, #059669); transition: width 0.8s ease; }
    .ocupacion-bar .fill-ocupado { background: linear-gradient(90deg, #f59e0b, #f97316); transition: width 0.8s ease; }
    .ocupacion-bar .fill-mant { background: linear-gradient(90deg, #64748b, #475569); transition: width 0.8s ease; }
    .ocupacion-legend {
        display: flex;
        gap: 1.25rem;
        font-size: 0.75rem;
        color: #94a3b8;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }
    .ocupacion-legend span { display: inline-flex; align-items: center; gap: 0.4rem; }
    .ocupacion-legend .dot { width: 8px; height: 8px; border-radius: 50%; }

    .parq-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .parq-actions .btn-action {
        padding: 0.55rem 1rem;
        border-radius: 9px;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        border: 1.5px solid;
        background: transparent;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        font-family: inherit;
        transition: all 0.25s ease;
        text-decoration: none;
        color: #06b6d4;
        border-color: #06b6d4;
    }
    .parq-actions .btn-action:hover { background: #06b6d4; color: #030712; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb"><a href="dashboard_developer.php">← Panel Developer</a></div>
    <h1>Parqueaderos</h1>
    <p class="sub">Estadísticas detalladas de cada parqueadero registrado en la plataforma.</p>
  </div>

  <!-- STATS GLOBALES -->
  <div class="global-stats">
    <div class="g-stat">
      <div class="lbl">Parqueaderos</div>
      <div class="val"><?= $global['parqueaderos'] ?></div>
    </div>
    <div class="g-stat">
      <div class="lbl">Celdas totales</div>
      <div class="val"><?= $global['cupos'] ?></div>
    </div>
    <div class="g-stat">
      <div class="lbl">Celdas libres</div>
      <div class="val"><?= $global['libres'] ?></div>
    </div>
    <div class="g-stat warning">
      <div class="lbl">Ocupación</div>
      <div class="val"><?= $pctOcupacionGlobal ?>%</div>
    </div>
    <div class="g-stat info">
      <div class="lbl">Vehículos activos</div>
      <div class="val"><?= $global['activos'] ?></div>
    </div>
    <div class="g-stat info">
      <div class="lbl">Recaudo hoy</div>
      <div class="val">$<?= number_format($global['recaudo_hoy'], 0, ',', '.') ?></div>
    </div>
    <div class="g-stat">
      <div class="lbl">Recaudo histórico</div>
      <div class="val">$<?= number_format($global['recaudo_total'], 0, ',', '.') ?></div>
    </div>
  </div>

  <!-- TARJETAS POR PARQUEADERO -->
  <?php if (empty($parqueaderos)): ?>
    <div class="parq-card" style="text-align: center; padding: 3rem;">
      <p style="color: #64748b;">No hay parqueaderos registrados.</p>
    </div>
  <?php else: foreach ($parqueaderos as $p): ?>
    <?php
      $totalCupos = max(1, (int)$p['total_cupos']);
      $pctLibres = round(((int)$p['cupos_libres'] / $totalCupos) * 100);
      $pctOcupados = round(((int)$p['cupos_ocupados'] / $totalCupos) * 100);
      $pctMant = round(((int)$p['cupos_mantenimiento'] / $totalCupos) * 100);
    ?>
    <div class="parq-card">
      <div class="parq-header">
        <div>
          <span class="empresa-tag">◉ <?= htmlspecialchars($p['empresa_nombre']) ?></span>
          <h3>#<?= (int)$p['id'] ?> · <?= htmlspecialchars($p['nombre']) ?></h3>
          <div class="parq-meta">
            <?php if (!empty($p['ciudad'])): ?><span>◆ <?= htmlspecialchars($p['ciudad']) ?></span><?php endif; ?>
            <?php if (!empty($p['direccion'])): ?><span>◆ <?= htmlspecialchars($p['direccion']) ?></span><?php endif; ?>
            <span>◆ <?= (int)$p['niveles'] ?> nivel(es)</span>
            <?php if (!empty($p['qr_token'])): ?>
              <span title="<?= htmlspecialchars($p['qr_token']) ?>">◆ QR único ✓</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="parq-kpis">
        <div class="pk-item">
          <div class="pk-num"><?= (int)$p['total_cupos'] ?></div>
          <div class="pk-lbl">Celdas totales</div>
        </div>
        <div class="pk-item">
          <div class="pk-num"><?= (int)$p['cupos_libres'] ?></div>
          <div class="pk-lbl">Libres</div>
        </div>
        <div class="pk-item warning">
          <div class="pk-num"><?= (int)$p['cupos_ocupados'] ?></div>
          <div class="pk-lbl">Ocupadas</div>
        </div>
        <div class="pk-item danger">
          <div class="pk-num"><?= (int)$p['activos_ahora'] ?></div>
          <div class="pk-lbl">Vehículos ahora</div>
        </div>
        <div class="pk-item info">
          <div class="pk-num"><?= (int)$p['total_registros'] ?></div>
          <div class="pk-lbl">Registros totales</div>
        </div>
        <div class="pk-item">
          <div class="pk-num">$<?= number_format((float)$p['recaudo_total'], 0, ',', '.') ?></div>
          <div class="pk-lbl">Recaudo total</div>
        </div>
        <div class="pk-item info">
          <div class="pk-num">$<?= number_format((float)$p['recaudo_hoy'], 0, ',', '.') ?></div>
          <div class="pk-lbl">Recaudo hoy</div>
        </div>
      </div>

      <!-- Barra de ocupación -->
      <div class="ocupacion-bar">
        <div class="fill-libre" style="width: <?= $pctLibres ?>%"></div>
        <div class="fill-ocupado" style="width: <?= $pctOcupados ?>%"></div>
        <div class="fill-mant" style="width: <?= $pctMant ?>%"></div>
      </div>
      <div class="ocupacion-legend">
        <span><span class="dot" style="background: #10b981;"></span> Libres <?= $pctLibres ?>%</span>
        <span><span class="dot" style="background: #f59e0b;"></span> Ocupadas <?= $pctOcupados ?>%</span>
        <?php if ($pctMant > 0): ?>
          <span><span class="dot" style="background: #64748b;"></span> Mantenimiento <?= $pctMant ?>%</span>
        <?php endif; ?>
      </div>

      <div class="parq-actions">
        <a href="dev_registros.php?parqueadero_id=<?= (int)$p['id'] ?>" class="btn-action">▤ Ver registros</a>
        <a href="dev_backups.php" class="btn-action">⬇ Backup</a>
        <a href="exportar_reporte.php?tipo=parqueadero&parqueadero_id=<?= (int)$p['id'] ?>" class="btn-action" target="_blank">📄 Exportar informe</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
