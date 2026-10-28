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
$fAccion = trim($_GET['accion'] ?? '');
$fEmpresa = (int)($_GET['empresa_id'] ?? 0);
$fDesde = $_GET['desde'] ?? '';
$fHasta = $_GET['hasta'] ?? '';

$where = ['1=1'];
$params = [];

if ($fAccion !== '') { $where[] = 'l.accion LIKE :accion'; $params['accion'] = '%' . $fAccion . '%'; }
if ($fEmpresa > 0) { $where[] = 'l.empresa_id = :eid'; $params['eid'] = $fEmpresa; }
if ($fDesde !== '') { $where[] = 'DATE(l.creado_en) >= :desde'; $params['desde'] = $fDesde; }
if ($fHasta !== '') { $where[] = 'DATE(l.creado_en) <= :hasta'; $params['hasta'] = $fHasta; }

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT l.*, e.nombre AS empresa_nombre, u.nombre AS user_nombre, u.username
        FROM log_aprobaciones l
        LEFT JOIN empresas e ON e.id = l.empresa_id
        LEFT JOIN user u ON u.id = l.developer_id
        WHERE $whereSQL
        ORDER BY l.id DESC
        LIMIT 300
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (\Throwable $e) { $logs = []; }

try {
    $empresas = $pdo->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll();
} catch (\Throwable $e) { $empresas = []; }

// Stats de acciones
$statsAcciones = [];
try {
    $stmt = $pdo->query("
        SELECT accion, COUNT(*) as total FROM log_aprobaciones
        GROUP BY accion ORDER BY total DESC LIMIT 10
    ");
    $statsAcciones = $stmt->fetchAll();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logs de auditoría - Developer</title>
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

    .acciones-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .accion-card {
        background: rgba(15, 23, 42, 0.7);
        border: 1px solid rgba(16, 185, 129, 0.18);
        border-radius: 12px;
        padding: 0.9rem 0.75rem;
        text-align: center;
    }
    .accion-card .rn { font-size: 1.3rem; font-weight: 900; color: #10b981; line-height: 1; }
    .accion-card .rl { font-size: 0.7rem; color: #94a3b8; margin-top: 0.35rem; word-break: break-word; }

    .filters-card {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }
    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        align-items: end;
    }
    .filter-item label { display: block; font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; margin-bottom: 0.4rem; }
    .filter-item input, .filter-item select { width: 100%; padding: 0.7rem 0.9rem; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 9px; color: #f1f5f9; font-family: inherit; font-size: 0.88rem; }
    .filter-item input:focus, .filter-item select:focus { border-color: #10b981; outline: none; }

    .table-wrapper {
        background: rgba(15, 23, 42, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 16px;
        overflow-x: auto;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; min-width: 900px; }
    .data-table thead tr { border-bottom: 1px solid rgba(16, 185, 129, 0.2); background: rgba(16, 185, 129, 0.04); }
    .data-table th { padding: 0.75rem; text-align: left; color: #10b981; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
    .data-table tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); transition: background 0.2s; }
    .data-table tbody tr:hover { background: rgba(16, 185, 129, 0.04); }
    .data-table td { padding: 0.75rem; color: #cbd5e1; }
    .data-table td.mono { font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; }

    .accion-badge {
        display: inline-block;
        padding: 0.2rem 0.7rem;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.03em;
    }
    .accion-badge.aprobada { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4); }
    .accion-badge.rechazada { background: rgba(239, 68, 68, 0.15); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
    .accion-badge.suspendida { background: rgba(100, 116, 139, 0.2); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.4); }
    .accion-badge.reactivada { background: rgba(6, 182, 212, 0.15); color: #67e8f9; border: 1px solid rgba(6, 182, 212, 0.4); }
    .accion-badge.backup_creado { background: rgba(167, 139, 250, 0.15); color: #c4b5fd; border: 1px solid rgba(167, 139, 250, 0.4); }
    .accion-badge.backup_global_creado { background: rgba(167, 139, 250, 0.15); color: #c4b5fd; border: 1px solid rgba(167, 139, 250, 0.4); }
    .accion-badge.backup_eliminado { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.4); }
    .accion-badge.default { background: rgba(100, 116, 139, 0.15); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.3); }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb"><a href="dashboard_developer.php">← Panel Developer</a></div>
    <h1>Logs de auditoría</h1>
    <p class="sub">Registro cronológico de todas las acciones importantes: aprobaciones, backups, cambios de config.</p>
  </div>

  <!-- Stats de acciones -->
  <?php if (!empty($statsAcciones)): ?>
  <div class="acciones-row">
    <?php foreach ($statsAcciones as $sa): ?>
      <div class="accion-card">
        <div class="rn"><?= (int)$sa['total'] ?></div>
        <div class="rl"><?= htmlspecialchars($sa['accion']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- FILTROS -->
  <div class="filters-card">
    <form method="GET" class="filters-grid">
      <div class="filter-item">
        <label>Buscar acción</label>
        <input type="text" name="accion" value="<?= htmlspecialchars($fAccion) ?>" placeholder="Ej: aprobada, backup...">
      </div>
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
        <label>Desde</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($fDesde) ?>">
      </div>
      <div class="filter-item">
        <label>Hasta</label>
        <input type="date" name="hasta" value="<?= htmlspecialchars($fHasta) ?>">
      </div>
      <div style="display: flex; gap: 0.5rem;">
        <button type="submit" class="cyber-btn-primary">🔍 Filtrar</button>
        <a href="dev_logs.php" class="cyber-btn-outline">Limpiar</a>
      </div>
    </form>
  </div>

  <!-- TABLA -->
  <?php if (empty($logs)): ?>
    <div class="filters-card" style="text-align: center; padding: 3rem;">
      <p style="color: #64748b;">No hay logs que coincidan con los filtros.</p>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Fecha/Hora</th>
            <th>Acción</th>
            <th>Empresa</th>
            <th>Developer</th>
            <th>Notas</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td>#<?= (int)$l['id'] ?></td>
            <td class="mono"><?= htmlspecialchars($l['creado_en'] ?? '') ?></td>
            <td>
              <?php $accion = $l['accion'] ?? ''; ?>
              <span class="accion-badge <?= htmlspecialchars($accion) ?>">
                <?= htmlspecialchars($accion) ?>
              </span>
            </td>
            <td><?= !empty($l['empresa_nombre']) ? htmlspecialchars($l['empresa_nombre']) : '—' ?></td>
            <td><?= !empty($l['user_nombre']) ? htmlspecialchars($l['user_nombre']) : ('#' . (int)($l['developer_id'] ?? 0)) ?></td>
            <td style="color: #64748b;"><?= htmlspecialchars($l['notas'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="color: #64748b; font-size: 0.8rem; margin-top: 1rem; text-align: right;">Mostrando los últimos 300 registros.</p>
  <?php endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
