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

// Filtros
$fEmpresa = (int)($_GET['empresa_id'] ?? 0);
$fParqueadero = (int)($_GET['parqueadero_id'] ?? 0);
$fEstado = $_GET['estado'] ?? '';
$fPlaca = trim($_GET['placa'] ?? '');
$fDesde = $_GET['desde'] ?? '';
$fHasta = $_GET['hasta'] ?? '';

$where = ['1=1'];
$params = [];

if ($fEmpresa > 0) { $where[] = 'r.empresa_id = :eid'; $params['eid'] = $fEmpresa; }
if ($fParqueadero > 0) { $where[] = 'r.parqueadero_id = :pid'; $params['pid'] = $fParqueadero; }
if ($fEstado !== '') { $where[] = 'r.estado = :estado'; $params['estado'] = $fEstado; }
if ($fPlaca !== '') { $where[] = 'r.placa LIKE :placa'; $params['placa'] = '%' . $fPlaca . '%'; }
if ($fDesde !== '') { $where[] = 'DATE(r.fecha_ingreso) >= :desde'; $params['desde'] = $fDesde; }
if ($fHasta !== '') { $where[] = 'DATE(r.fecha_ingreso) <= :hasta'; $params['hasta'] = $fHasta; }

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT r.*, e.nombre AS empresa_nombre, p.nombre AS parqueadero_nombre
        FROM registros_parqueo r
        LEFT JOIN empresas e ON e.id = r.empresa_id
        LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
        WHERE $whereSQL
        ORDER BY r.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $registros = $stmt->fetchAll();
} catch (\Throwable $e) { $registros = []; }

// Datos para filtros
try {
    $empresas = $pdo->query("SELECT id, nombre FROM empresas WHERE estado='activa' ORDER BY nombre")->fetchAll();
    $parqueaderos = $pdo->query("SELECT id, nombre, empresa_id FROM parqueaderos ORDER BY nombre")->fetchAll();
} catch (\Throwable $e) { $empresas = []; $parqueaderos = []; }

// Stats de los resultados filtrados
$statsFiltro = [
    'total' => count($registros),
    'activos' => 0,
    'finalizados' => 0,
    'pagados' => 0,
    'recaudo' => 0,
];
foreach ($registros as $r) {
    if ($r['estado'] === 'activo') $statsFiltro['activos']++;
    else $statsFiltro['finalizados']++;
    if ((int)$r['estado_pago'] === 1) {
        $statsFiltro['pagados']++;
        $statsFiltro['recaudo'] += (float)$r['total_pagar'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registros - Developer</title>
<link rel="stylesheet" href="assets/css/main.css?v=25">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { margin-bottom: 2rem; }
    .page-header .breadcrumb { color: #64748b; font-size: 0.82rem; margin-bottom: 0.75rem; }
    .page-header .breadcrumb a { color: #10b981; text-decoration: none; }
    .page-header h1 { font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 900; letter-spacing: -0.03em; background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.4rem; }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }

    .filters-card {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        align-items: end;
    }
    .filter-item label {
        display: block;
        font-size: 0.75rem;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        margin-bottom: 0.4rem;
    }
    .filter-item input, .filter-item select {
        width: 100%;
        padding: 0.7rem 0.9rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 9px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.88rem;
    }
    .filter-item input:focus, .filter-item select:focus { border-color: #10b981; outline: none; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
    .filter-actions { display: flex; gap: 0.5rem; }

    .stats-mini-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .stat-mini-c {
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.18);
        border-radius: 14px;
        padding: 1rem;
        text-align: center;
    }
    .stat-mini-c .lbl { font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.35rem; }
    .stat-mini-c .val { font-size: 1.5rem; font-weight: 900; background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; }
    .stat-mini-c.warning .val { background: linear-gradient(135deg, #f59e0b, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .stat-mini-c.success .val { background: linear-gradient(135deg, #10b981, #059669); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .stat-mini-c.danger .val { background: linear-gradient(135deg, #ef4444, #dc2626); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

    .table-wrapper {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 16px;
        overflow-x: auto;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; min-width: 1100px; }
    .data-table thead tr { border-bottom: 1px solid rgba(16, 185, 129, 0.2); background: rgba(16, 185, 129, 0.04); }
    .data-table th { padding: 0.75rem; text-align: left; color: #10b981; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; white-space: nowrap; }
    .data-table tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); transition: background 0.2s; }
    .data-table tbody tr:hover { background: rgba(16, 185, 129, 0.04); }
    .data-table td { padding: 0.75rem; color: #cbd5e1; }
    .data-table td.mono { font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; }

    .plate-badge {
        display: inline-block;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #10b981;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-family: 'JetBrains Mono', monospace;
        font-weight: 700;
        font-size: 0.8rem;
        letter-spacing: 1px;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.7rem;
        border-radius: 100px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .status-badge.activo { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4); }
    .status-badge.finalizado { background: rgba(100, 116, 139, 0.2); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.4); }
    .payment-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.72rem;
        font-weight: 700;
    }
    .payment-badge.pagado { color: #10b981; }
    .payment-badge.pendiente { color: #f59e0b; }
    .payment-badge.activo-pendiente { color: #94a3b8; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb"><a href="dashboard_developer.php">← Panel Developer</a></div>
    <h1>Registros de parqueo</h1>
    <p class="sub">Historial completo de entradas y salidas. Filtra por empresa, parqueadero, placa, fecha o estado.</p>
  </div>

  <!-- FILTROS -->
  <div class="filters-card">
    <form method="GET" class="filters-grid">
      <div class="filter-item">
        <label>Empresa</label>
        <select name="empresa_id">
          <option value="0">Todas</option>
          <?php foreach ($empresas as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= $fEmpresa === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-item">
        <label>Parqueadero</label>
        <select name="parqueadero_id">
          <option value="0">Todos</option>
          <?php foreach ($parqueaderos as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $fParqueadero === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-item">
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <option value="activo" <?= $fEstado === 'activo' ? 'selected' : '' ?>>Activos</option>
          <option value="finalizado" <?= $fEstado === 'finalizado' ? 'selected' : '' ?>>Finalizados</option>
        </select>
      </div>
      <div class="filter-item">
        <label>Placa</label>
        <input type="text" name="placa" value="<?= htmlspecialchars($fPlaca) ?>" placeholder="Ej: ABC123" style="text-transform: uppercase;">
      </div>
      <div class="filter-item">
        <label>Desde</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($fDesde) ?>">
      </div>
      <div class="filter-item">
        <label>Hasta</label>
        <input type="date" name="hasta" value="<?= htmlspecialchars($fHasta) ?>">
      </div>
      <div class="filter-actions">
        <button type="submit" class="cyber-btn-primary" style="flex: 1;">🔍 Filtrar</button>
        <a href="dev_registros.php" class="cyber-btn-outline" style="text-align: center;">Limpiar</a>
      </div>
    </form>
  </div>

  <!-- STATS DEL FILTRO -->
  <div class="stats-mini-row">
    <div class="stat-mini-c"><div class="lbl">Total mostrados</div><div class="val"><?= $statsFiltro['total'] ?></div></div>
    <div class="stat-mini-c success"><div class="lbl">Activos</div><div class="val"><?= $statsFiltro['activos'] ?></div></div>
    <div class="stat-mini-c"><div class="lbl">Finalizados</div><div class="val"><?= $statsFiltro['finalizados'] ?></div></div>
    <div class="stat-mini-c warning"><div class="lbl">Pagados</div><div class="val"><?= $statsFiltro['pagados'] ?></div></div>
    <div class="stat-mini-c"><div class="lbl">Recaudo filtrado</div><div class="val">$<?= number_format($statsFiltro['recaudo'], 0, ',', '.') ?></div></div>
  </div>

  <!-- TABLA -->
  <?php if (empty($registros)): ?>
    <div class="filters-card" style="text-align: center; padding: 3rem;">
      <p style="color: #64748b;">No hay registros con esos filtros.</p>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Placa</th>
            <th>Modelo</th>
            <th>Empresa</th>
            <th>Parqueadero</th>
            <th>Celda</th>
            <th>Tipo</th>
            <th>Ingreso</th>
            <th>Salida</th>
            <th>Horas</th>
            <th>Total</th>
            <th>Estado</th>
            <th>Pago</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($registros as $r): ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><span class="plate-badge"><?= htmlspecialchars($r['placa']) ?></span></td>
            <td><?= htmlspecialchars($r['modelo'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['empresa_nombre'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['parqueadero_nombre'] ?? '—') ?></td>
            <td><strong style="color: #06b6d4;"><?= htmlspecialchars($r['cupo_asignado']) ?></strong></td>
            <td><?= htmlspecialchars($r['tipo_vehiculo']) ?></td>
            <td class="mono"><?= htmlspecialchars(substr($r['fecha_ingreso'] ?? '', 0, 16)) ?></td>
            <td class="mono"><?= $r['fecha_salida'] ? htmlspecialchars(substr($r['fecha_salida'], 0, 16)) : '—' ?></td>
            <td style="text-align: center;"><?= (int)$r['horas'] ?: '—' ?></td>
            <td style="color: #10b981; font-weight: 700;">$<?= number_format((float)$r['total_pagar'], 0, ',', '.') ?></td>
            <td>
              <span class="status-badge <?= $r['estado'] ?>">
                <?= $r['estado'] === 'activo' ? '● Activo' : '✓ Finalizado' ?>
              </span>
            </td>
            <td>
              <?php if ((int)$r['estado_pago'] === 1): ?>
                <span class="payment-badge pagado">✓ Pagado</span>
              <?php elseif ($r['estado'] === 'activo'): ?>
                <span class="payment-badge activo-pendiente">⏳ En curso</span>
              <?php else: ?>
                <span class="payment-badge pendiente">⏳ Pendiente</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="color: #64748b; font-size: 0.8rem; margin-top: 1rem; text-align: right;">
      Mostrando hasta 200 resultados más recientes.
    </p>
  <?php endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
