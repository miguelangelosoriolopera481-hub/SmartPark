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

// Info sistema
$info = [
    'PHP' => PHP_VERSION,
    'Servidor' => $_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido',
    'Sistema operativo' => PHP_OS,
    'Arquitectura' => php_uname('m'),
    'Memoria límite' => ini_get('memory_limit'),
    'Memoria usada' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
    'Memoria pico' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
    'Tiempo ejecución' => ini_get('max_execution_time') . 's',
    'Timezone' => date_default_timezone_get(),
    'Fecha servidor' => date('Y-m-d H:i:s'),
    'Uptime' => function_exists('sys_getloadavg') ? 'Disponible' : 'No disponible',
];

// Info BD
$infoBD = [];
try {
    $infoBD['Versión MySQL'] = $pdo->query("SELECT VERSION()")->fetchColumn();
    $infoBD['Base de datos'] = env('DB_NAME', 'smartpark');
    $infoBD['Charset'] = $pdo->query("SELECT @@character_set_database")->fetchColumn();
    $infoBD['Collation'] = $pdo->query("SELECT @@collation_database")->fetchColumn();
    $infoBD['Tablas'] = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
    $infoBD['Tamaño total'] = $pdo->query("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) 
        FROM information_schema.TABLES WHERE table_schema = DATABASE()
    ")->fetchColumn() . ' MB';
    $infoBD['Conexiones actuales'] = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch()['Value'] ?? '?';
    $infoBD['Uptime MySQL'] = (int)$pdo->query("SHOW STATUS LIKE 'Uptime'")->fetch()['Value'] ?? 0;
} catch (\Throwable $e) {}

// Info extensiones PHP
$extensiones = ['pdo_mysql','gd','curl','mbstring','openssl','json','iconv','zip'];
$extEstado = [];
foreach ($extensiones as $ext) {
    $extEstado[$ext] = extension_loaded($ext);
}

// Tablas más grandes
$tablasGrandes = [];
try {
    $tablasGrandes = $pdo->query("
        SELECT TABLE_NAME, 
               ROUND((data_length + index_length) / 1024, 2) AS size_kb,
               TABLE_ROWS AS filas
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE()
        ORDER BY (data_length + index_length) DESC
        LIMIT 15
    ")->fetchAll();
} catch (\Throwable $e) {}

// Actividad reciente
$actividad = [];
try {
    $actividad = $pdo->query("
        SELECT COUNT(*) as total FROM registros_parqueo WHERE DATE(fecha_ingreso) = CURDATE()
    ")->fetch();
    $totalHoy = (int)($actividad['total'] ?? 0);
} catch (\Throwable $e) { $totalHoy = 0; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Estado del sistema - Developer</title>
<link rel="stylesheet" href="assets/css/main.css?v=25">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { margin-bottom: 2rem; }
    .page-header .breadcrumb { color: #64748b; font-size: 0.82rem; margin-bottom: 0.75rem; }
    .page-header .breadcrumb a { color: #10b981; text-decoration: none; }
    .page-header h1 { font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 900; letter-spacing: -0.03em; background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.4rem; }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }

    .info-section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
    }
    .info-section h2 {
        font-size: 1.1rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0.9rem;
    }
    .info-item {
        background: rgba(2, 6, 23, 0.6);
        padding: 0.9rem 1rem;
        border-radius: 10px;
        border: 1px solid rgba(16, 185, 129, 0.12);
    }
    .info-item .key {
        font-size: 0.68rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.3rem;
        font-weight: 700;
    }
    .info-item .val {
        font-size: 0.9rem;
        color: #10b981;
        font-weight: 700;
        word-break: break-all;
        font-family: 'JetBrains Mono', monospace;
    }

    .ext-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 0.75rem;
    }
    .ext-item {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.7rem 1rem;
        border-radius: 10px;
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    .ext-item.ok { background: rgba(16, 185, 129, 0.1); }
    .ext-item.fail { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.4); }
    .ext-item .icon { font-size: 1rem; }
    .ext-item .name { font-family: 'JetBrains Mono', monospace; font-size: 0.82rem; color: #cbd5e1; font-weight: 600; }

    .tabla-info {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.85rem;
    }
    .tabla-info thead tr { border-bottom: 1px solid rgba(16, 185, 129, 0.2); }
    .tabla-info th { padding: 0.75rem; text-align: left; color: #10b981; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
    .tabla-info tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
    .tabla-info td { padding: 0.7rem 0.75rem; color: #cbd5e1; font-size: 0.85rem; }
    .tabla-info td.mono { font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb"><a href="dashboard_developer.php">← Panel Developer</a></div>
    <h1>Estado del sistema</h1>
    <p class="sub">Información técnica del servidor, base de datos y extensiones PHP.</p>
  </div>

  <!-- SERVIDOR PHP -->
  <div class="info-section">
    <h2>◆ Servidor PHP</h2>
    <div class="info-grid">
      <?php foreach ($info as $k => $v): ?>
        <div class="info-item">
          <div class="key"><?= htmlspecialchars($k) ?></div>
          <div class="val"><?= htmlspecialchars((string)$v) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- BASE DE DATOS -->
  <div class="info-section">
    <h2>◈ Base de datos</h2>
    <div class="info-grid">
      <?php foreach ($infoBD as $k => $v): ?>
        <div class="info-item">
          <div class="key"><?= htmlspecialchars($k) ?></div>
          <div class="val"><?= htmlspecialchars((string)$v) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- EXTENSIONES PHP -->
  <div class="info-section">
    <h2>◐ Extensiones PHP</h2>
    <div class="ext-grid">
      <?php foreach ($extEstado as $ext => $ok): ?>
        <div class="ext-item <?= $ok ? 'ok' : 'fail' ?>">
          <span class="icon"><?= $ok ? '✅' : '❌' ?></span>
          <span class="name"><?= htmlspecialchars($ext) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- TABLAS MÁS GRANDES -->
  <div class="info-section">
    <h2>▤ Tablas más grandes</h2>
    <div style="overflow-x: auto;">
      <table class="tabla-info">
        <thead>
          <tr><th>Tabla</th><th>Tamaño</th><th>Filas aprox.</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tablasGrandes as $t): ?>
          <tr>
            <td class="mono" style="color: #10b981;"><?= htmlspecialchars($t['TABLE_NAME']) ?></td>
            <td class="mono"><?= number_format((float)$t['size_kb'], 2, ',', '.') ?> KB</td>
            <td class="mono"><?= number_format((int)$t['filas'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ACTIVIDAD HOY -->
  <div class="info-section">
    <h2>◉ Actividad hoy</h2>
    <div class="info-grid">
      <div class="info-item">
        <div class="key">Registros de hoy</div>
        <div class="val"><?= $totalHoy ?></div>
      </div>
    </div>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
