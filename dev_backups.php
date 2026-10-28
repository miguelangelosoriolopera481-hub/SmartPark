<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'backup.php';
send_security_headers();

if (empty($_SESSION['usuario_id']) || ($_SESSION['role'] ?? '') !== 'developer') {
    header('Location: login_developer.php');
    exit;
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'crear_global') {
            $check = backup_puede_crear($pdo, null);
            if (!$check['ok']) {
                $error = $check['mensaje'];
            } else {
                $resultado = generarBackupGlobal($pdo);
                if ($resultado) {
                    $msg = "Backup global creado: $resultado";
                } else {
                    $error = 'No se pudo crear el backup global.';
                }
            }
        }

        if ($accion === 'crear_empresa' && !empty($_POST['empresa_id'])) {
            $eid = (int)$_POST['empresa_id'];
            $check = backup_puede_crear($pdo, $eid);
            if (!$check['ok']) {
                $error = $check['mensaje'];
            } else {
                $resultado = generarBackupEmpresa($pdo, $eid);
                if ($resultado) {
                    $msg = "Backup de empresa creado: $resultado";
                } else {
                    $error = 'No se pudo crear el backup.';
                }
            }
        }

        if ($accion === 'eliminar' && !empty($_POST['archivo'])) {
            $resultado = eliminar_backup($pdo, $_POST['archivo'], 'developer');
            if ($resultado) {
                $msg = 'Backup eliminado.';
            } else {
                $error = 'No se pudo eliminar el backup.';
            }
        }
    }
}

// Backups existentes
$backups = listar_backups();

// Empresas para el selector
try {
    $empresas = $pdo->query("SELECT id, nombre FROM empresas WHERE estado='activa' ORDER BY nombre")->fetchAll();
} catch (\Throwable $e) { $empresas = []; }

// Estadísticas de backups
$statsBackups = [
    'total' => count($backups),
    'globales' => 0,
    'por_empresa' => 0,
    'tamano_total' => 0,
];
foreach ($backups as $b) {
    if ($b['tipo'] === 'global') $statsBackups['globales']++;
    else $statsBackups['por_empresa']++;
    $statsBackups['tamano_total'] += $b['tamano'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Backups - Developer</title>
<link rel="stylesheet" href="assets/css/main.css?v=25">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { margin-bottom: 2rem; }
    .page-header .breadcrumb {
        color: #64748b;
        font-size: 0.82rem;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .page-header .breadcrumb a { color: #10b981; text-decoration: none; }
    .page-header .breadcrumb a:hover { text-decoration: underline; }
    .page-header h1 {
        font-size: clamp(1.8rem, 4vw, 2.5rem);
        font-weight: 900;
        letter-spacing: -0.03em;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 0.4rem;
    }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }

    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .stat-card {
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.18);
        border-radius: 14px;
        padding: 1.15rem 1rem;
        text-align: center;
    }
    .stat-card .lbl { font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.35rem; }
    .stat-card .val { font-size: 1.6rem; font-weight: 900; background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
    }
    .action-card {
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.18);
        border-radius: 18px;
        padding: 1.75rem;
    }
    .action-card.warning { border-color: rgba(245, 158, 11, 0.25); }
    .action-card h3 { font-size: 1.05rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }
    .action-card p { color: #64748b; font-size: 0.85rem; margin-bottom: 1.25rem; line-height: 1.5; }

    .backups-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    .backups-table thead tr { border-bottom: 1px solid rgba(16, 185, 129, 0.2); }
    .backups-table th { padding: 0.8rem; text-align: left; color: #10b981; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
    .backups-table tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); transition: background 0.2s ease; }
    .backups-table tbody tr:hover { background: rgba(16, 185, 129, 0.04); }
    .backups-table td { padding: 0.8rem; }
    .badge-tipo {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }
    .badge-tipo.global { background: rgba(167, 139, 250, 0.15); color: #a78bfa; border: 1px solid rgba(167, 139, 250, 0.4); }
    .badge-tipo.empresa { background: rgba(6, 182, 212, 0.15); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.4); }

    .btn-row {
        display: inline-flex;
        gap: 0.4rem;
    }
    .btn-mini {
        padding: 0.4rem 0.75rem;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        text-decoration: none;
        border: 1.5px solid;
        background: transparent;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }
    .btn-mini.download { color: #10b981; border-color: #10b981; }
    .btn-mini.download:hover { background: #10b981; color: #030712; }
    .btn-mini.delete { color: #ef4444; border-color: #ef4444; }
    .btn-mini.delete:hover { background: #ef4444; color: #fff; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb">
      <a href="dashboard_developer.php">← Panel Developer</a>
      <span>·</span>
      <span>Backups</span>
    </div>
    <h1>Backups del sistema</h1>
    <p class="sub">Respalda toda la plataforma o empresas individuales. Descarga y administra archivos .sql de forma segura.</p>
  </div>

  <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-card"><div class="lbl">Total backups</div><div class="val"><?= $statsBackups['total'] ?></div></div>
    <div class="stat-card"><div class="lbl">Globales</div><div class="val"><?= $statsBackups['globales'] ?></div></div>
    <div class="stat-card"><div class="lbl">Por empresa</div><div class="val"><?= $statsBackups['por_empresa'] ?></div></div>
    <div class="stat-card"><div class="lbl">Tamaño total</div><div class="val"><?= formatear_tamano($statsBackups['tamano_total']) ?></div></div>
  </div>

  <!-- ACCIONES -->
  <div class="actions-grid">
    <div class="action-card warning">
      <h3>⬇ Crear backup GLOBAL</h3>
      <p>Respaldo COMPLETO de toda la base de datos: todas las empresas, usuarios, registros y configuración. Útil antes de actualizaciones grandes.</p>
      <form method="POST" onsubmit="return confirm('¿Crear backup GLOBAL completo del sistema?');">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear_global">
        <button type="submit" class="cyber-btn-primary">◆ Crear backup global ahora</button>
      </form>
    </div>

    <div class="action-card">
      <h3>◉ Crear backup de UNA empresa</h3>
      <p>Respaldo SOLO con datos de la empresa seleccionada: sus usuarios, parqueaderos, celdas, registros, pagos y configuración. Ideal para compartir o migrar.</p>
      <form method="POST" onsubmit="return confirm('¿Crear backup de esa empresa?');">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear_empresa">
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
          <select name="empresa_id" required style="flex: 1; min-width: 180px; padding: 0.7rem; background: rgba(2,6,23,0.7); border: 1.5px solid rgba(148,163,184,0.15); border-radius: 9px; color: #f1f5f9; font-family: inherit;">
            <option value="">— Selecciona empresa —</option>
            <?php foreach ($empresas as $e): ?>
              <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="cyber-btn-primary">Crear backup</button>
        </div>
      </form>
    </div>
  </div>

  <!-- LISTA DE BACKUPS -->
  <h2 style="font-size: 1.3rem; font-weight: 800; color: #f1f5f9; margin-bottom: 1rem;">Archivos de respaldo</h2>
  <?php if (empty($backups)): ?>
    <div class="action-card" style="text-align: center; padding: 3rem;">
      <p style="color: #64748b;">Aún no has creado ningún backup. Empieza creando uno global.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; background: rgba(15,23,42,0.6); border: 1px solid rgba(16,185,129,0.15); border-radius: 14px;">
      <table class="backups-table">
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Archivo</th>
            <th>Empresa</th>
            <th>Fecha</th>
            <th>Tamaño</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><span class="badge-tipo <?= $b['tipo'] ?>"><?= $b['tipo'] ?></span></td>
            <td style="color: #cbd5e1; font-family: monospace; font-size: 0.78rem;"><?= htmlspecialchars($b['nombre']) ?></td>
            <td><?= $b['tipo'] === 'empresa' ? '#' . $b['empresa_id'] : '—' ?></td>
            <td style="color: #94a3b8;"><?= htmlspecialchars($b['fecha']) ?></td>
            <td style="color: #10b981; font-weight: 700;"><?= formatear_tamano($b['tamano']) ?></td>
            <td>
              <div class="btn-row">
                <a href="descargar_backup.php?file=<?= urlencode($b['nombre']) ?>" class="btn-mini download">⬇ Descargar</a>
                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar este backup permanentemente?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="archivo" value="<?= htmlspecialchars($b['nombre']) ?>">
                  <button type="submit" class="btn-mini delete">🗑 Eliminar</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
