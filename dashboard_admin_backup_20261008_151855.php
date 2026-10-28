<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'backup.php';
send_security_headers();

$rol = $_SESSION['rol_en_empresa'] ?? '';
if (empty($_SESSION['usuario_id']) || !in_array($rol, ['admin', 'operador', 'superadmin'], true)) {
    header('Location: login_empresa.php');
    exit;
}

$eid = (int)($_SESSION['empresa_id'] ?? 0);
if ($eid <= 0) { header('Location: login_empresa.php'); exit; }

$msg = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'crear_backup') {
            $check = backup_puede_crear($pdo, $eid);
            if (!$check['ok']) { $error = $check['mensaje']; }
            else {
                $resultado = generarBackupEmpresa($pdo, $eid);
                if ($resultado) $msg = "Backup creado: $resultado";
                else $error = 'No se pudo crear el backup.';
            }
        }
    }
}

// ============ DATOS ============
$empresa = $pdo->prepare("SELECT * FROM empresas WHERE id = :id LIMIT 1");
$empresa->execute(['id' => $eid]);
$empresa = $empresa->fetch();
if (!$empresa) { header('Location: login_empresa.php'); exit; }

// KPIs principales
$stats = [];
try {
    $r = $pdo->prepare("SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN estado='disponible' THEN 1 ELSE 0 END) AS libres,
        SUM(CASE WHEN estado='ocupado' THEN 1 ELSE 0 END) AS ocupados,
        SUM(CASE WHEN estado='mantenimiento' THEN 1 ELSE 0 END) AS mantenimiento
        FROM cupos WHERE empresa_id = :eid");
    $r->execute(['eid' => $eid]);
    $stats['cupos'] = $r->fetch();
    
    $r = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado='activo'");
    $r->execute(['eid' => $eid]);
    $stats['activos'] = (int)$r->fetchColumn();
    
    $r = $pdo->prepare("SELECT COUNT(*) FROM parqueaderos WHERE empresa_id = :eid");
    $r->execute(['eid' => $eid]);
    $stats['parqueaderos'] = (int)$r->fetchColumn();
    
    $r = $pdo->prepare("SELECT COUNT(*) FROM usuarios_empresa WHERE empresa_id = :eid AND activo=1");
    $r->execute(['eid' => $eid]);
    $stats['usuarios'] = (int)$r->fetchColumn();
    
    $r = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND DATE(fecha_salida)=CURDATE()");
    $r->execute(['eid' => $eid]);
    $stats['recaudo_hoy'] = (float)$r->fetchColumn();
    
    $r = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND MONTH(fecha_salida)=MONTH(CURDATE()) AND YEAR(fecha_salida)=YEAR(CURDATE())");
    $r->execute(['eid' => $eid]);
    $stats['recaudo_mes'] = (float)$r->fetchColumn();
} catch (\Throwable $e) {}

// Tendencia (comparado con ayer)
$tendencia = ['hoy' => 0, 'ayer' => 0, 'pct' => 0];
try {
    $r = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND DATE(fecha_salida)=CURDATE()");
    $r->execute(['eid' => $eid]);
    $tendencia['hoy'] = (float)$r->fetchColumn();
    
    $r = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND DATE(fecha_salida)=(CURDATE() - INTERVAL 1 DAY)");
    $r->execute(['eid' => $eid]);
    $tendencia['ayer'] = (float)$r->fetchColumn();
    
    if ($tendencia['ayer'] > 0) {
        $tendencia['pct'] = round((($tendencia['hoy'] - $tendencia['ayer']) / $tendencia['ayer']) * 100);
    }
} catch (\Throwable $e) {}

// Alertas
$alertas = [];
try {
    // Pagos pendientes
    $r = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado='activo' AND estado_pago=0 AND fecha_ingreso < (NOW() - INTERVAL 4 HOUR)");
    $r->execute(['eid' => $eid]);
    $pendientes = (int)$r->fetchColumn();
    if ($pendientes > 0) {
        $alertas[] = ['tipo' => 'warning', 'icon' => '⏳', 'titulo' => "$pendientes pago(s) pendiente(s)", 'desc' => 'Vehículos con más de 4 horas sin pagar'];
    }
    
    // Celdas en mantenimiento
    $r = $pdo->prepare("SELECT COUNT(*) FROM cupos WHERE empresa_id = :eid AND estado='mantenimiento'");
    $r->execute(['eid' => $eid]);
    $mant = (int)$r->fetchColumn();
    if ($mant > 0) {
        $alertas[] = ['tipo' => 'info', 'icon' => '⬚', 'titulo' => "$mant celda(s) en mantenimiento", 'desc' => 'Revisa si se pueden habilitar'];
    }
    
    // Ocupación alta (>80%)
    $total = max(1, (int)$stats['cupos']['total']);
    $pctOcup = round(((int)$stats['cupos']['ocupados'] / $total) * 100);
    if ($pctOcup > 80) {
        $alertas[] = ['tipo' => 'danger', 'icon' => '⚠', 'titulo' => "Ocupación al $pctOcup%", 'desc' => 'Considera abrir celdas adicionales'];
    }
    
    // Si todo OK
    if (empty($alertas)) {
        $alertas[] = ['tipo' => 'success', 'icon' => '✓', 'titulo' => 'Todo en orden', 'desc' => 'No hay alertas pendientes'];
    }
} catch (\Throwable $e) {}

// Últimos 7 días de recaudo
$recaudo7Dias = [];
try {
    $r = $pdo->prepare("
        SELECT DATE(fecha_salida) AS dia, COALESCE(SUM(total_pagar),0) AS total
        FROM registros_parqueo 
        WHERE empresa_id = :eid AND estado_pago=1 AND fecha_salida >= (CURDATE() - INTERVAL 6 DAY)
        GROUP BY DATE(fecha_salida) ORDER BY dia ASC
    ");
    $r->execute(['eid' => $eid]);
    $datos = $r->fetchAll();
    
    // Rellenar días sin datos
    for ($i = 6; $i >= 0; $i--) {
        $fecha = date('Y-m-d', strtotime("-$i days"));
        $encontrado = null;
        foreach ($datos as $d) { if ($d['dia'] === $fecha) { $encontrado = $d; break; } }
        $recaudo7Dias[] = [
            'fecha' => $fecha,
            'label' => date('D', strtotime($fecha)),
            'total' => $encontrado ? (float)$encontrado['total'] : 0
        ];
    }
} catch (\Throwable $e) {}

// Top 5 placas más frecuentes
$topPlacas = [];
try {
    $r = $pdo->prepare("
        SELECT placa, modelo, COUNT(*) AS visitas, MAX(fecha_ingreso) AS ultima_visita
        FROM registros_parqueo WHERE empresa_id = :eid
        GROUP BY placa ORDER BY visitas DESC LIMIT 5
    ");
    $r->execute(['eid' => $eid]);
    $topPlacas = $r->fetchAll();
} catch (\Throwable $e) {}

// Últimos pagos
$ultimosPagos = [];
try {
    $r = $pdo->prepare("
        SELECT placa, total_pagar, fecha_salida, metodo_pago
        FROM registros_parqueo 
        WHERE empresa_id = :eid AND estado_pago=1
        ORDER BY fecha_salida DESC LIMIT 5
    ");
    $r->execute(['eid' => $eid]);
    $ultimosPagos = $r->fetchAll();
} catch (\Throwable $e) {}

// Celdas para el mapa mini
$celdasMini = [];
try {
    $r = $pdo->prepare("SELECT codigo_cupo, tipo, estado FROM cupos WHERE empresa_id = :eid ORDER BY tipo, LENGTH(codigo_cupo), codigo_cupo");
    $r->execute(['eid' => $eid]);
    $celdasMini = $r->fetchAll();
} catch (\Throwable $e) {}

// Parqueaderos
$parqueaderos = [];
try {
    $r = $pdo->prepare("
        SELECT p.*, 
            (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id) AS total_cupos,
            (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND estado='ocupado') AS ocupados
        FROM parqueaderos p WHERE p.empresa_id = :eid ORDER BY p.nombre
    ");
    $r->execute(['eid' => $eid]);
    $parqueaderos = $r->fetchAll();
} catch (\Throwable $e) {}

// Últimos registros
$registros = [];
try {
    $r = $pdo->prepare("
        SELECT r.*, p.nombre AS parqueadero_nombre
        FROM registros_parqueo r
        LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
        WHERE r.empresa_id = :eid
        ORDER BY r.id DESC LIMIT 10
    ");
    $r->execute(['eid' => $eid]);
    $registros = $r->fetchAll();
} catch (\Throwable $e) {}

// Usuarios
$usuarios = [];
try {
    $r = $pdo->prepare("
        SELECT u.id, u.username, u.nombre, u.correo, ue.rol_en_empresa, ue.activo
        FROM usuarios_empresa ue
        JOIN user u ON u.id = ue.user_id
        WHERE ue.empresa_id = :eid
        ORDER BY ue.rol_en_empresa, u.nombre LIMIT 15
    ");
    $r->execute(['eid' => $eid]);
    $usuarios = $r->fetchAll();
} catch (\Throwable $e) {}

// Backups
$backupsEmpresa = [];
foreach (listar_backups() as $b) {
    if ($b['tipo'] === 'empresa' && (int)$b['empresa_id'] === $eid) $backupsEmpresa[] = $b;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Admin - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=30">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { margin-bottom: 2rem; }
    .page-header h1 { font-size: clamp(1.6rem, 3.5vw, 2.2rem); font-weight: 900; letter-spacing: -0.03em; background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.4rem; }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }
    .empresa-badge { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.35rem 0.9rem; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 100px; color: #10b981; font-size: 0.75rem; font-weight: 800; letter-spacing: 0.03em; margin-bottom: 0.75rem; }
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .kpi-card { background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5)); backdrop-filter: blur(20px); border: 1.5px solid rgba(16, 185, 129, 0.18); border-radius: 18px; padding: 1.35rem; transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1); position: relative; overflow: hidden; }
    .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #10b981, #06b6d4); opacity: 0.6; }
    .kpi-card:hover { transform: translateY(-5px); border-color: rgba(16, 185, 129, 0.5); box-shadow: 0 20px 50px rgba(16, 185, 129, 0.18); }
    .kpi-card.info::before { background: linear-gradient(90deg, #06b6d4, #3b82f6); }
    .kpi-card.warning::before { background: linear-gradient(90deg, #f59e0b, #f97316); }
    .kpi-card.danger::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
    .kpi-card .icon { width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; border-radius: 12px; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; font-size: 1.3rem; margin-bottom: 0.9rem; }
    .kpi-card.info .icon { background: rgba(6, 182, 212, 0.12); border-color: rgba(6, 182, 212, 0.3); color: #06b6d4; }
    .kpi-card.warning .icon { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); color: #f59e0b; }
    .kpi-card.danger .icon { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.3); color: #ef4444; }
    .kpi-card .lbl { font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.35rem; }
    .kpi-card .val { font-size: 1.9rem; font-weight: 900; background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; }
    .kpi-card.warning .val { background: linear-gradient(135deg, #f59e0b, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi-card.danger .val { background: linear-gradient(135deg, #ef4444, #dc2626); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi-card.info .val { background: linear-gradient(135deg, #06b6d4, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .section { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 18px; padding: 1.75rem; margin-bottom: 1.5rem; }
    .section h2 { font-size: 1.1rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.4rem; display: flex; align-items: center; gap: 0.6rem; }
    .section .sub { color: #64748b; font-size: 0.82rem; margin-bottom: 1.25rem; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .data-table thead tr { border-bottom: 1px solid rgba(16, 185, 129, 0.2); }
    .data-table th { padding: 0.7rem; text-align: left; color: #10b981; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
    .data-table tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
    .data-table tbody tr:hover { background: rgba(16, 185, 129, 0.04); }
    .data-table td { padding: 0.7rem; color: #cbd5e1; }
    .plate-badge { display: inline-block; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; padding: 0.15rem 0.55rem; border-radius: 6px; font-family: monospace; font-weight: 700; font-size: 0.78rem; }
    .rol-badge { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 100px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
    .rol-badge.admin { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
    .rol-badge.operador { background: rgba(6, 182, 212, 0.15); color: #67e8f9; }
    .rol-badge.cliente { background: rgba(100, 116, 139, 0.15); color: #cbd5e1; }
    .rol-badge.vigilante { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
    .rol-badge.finanzas { background: rgba(59, 130, 246, 0.15); color: #93c5fd; }
    .backup-list { margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem; }
    .backup-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: rgba(2, 6, 23, 0.6); border-radius: 10px; gap: 1rem; flex-wrap: wrap; }
    .backup-item .bname { font-family: monospace; font-size: 0.8rem; color: #cbd5e1; word-break: break-all; }
    .backup-item .bdate { font-size: 0.75rem; color: #64748b; }
    .btn-mini { padding: 0.45rem 0.85rem; border-radius: 8px; font-size: 0.78rem; font-weight: 700; text-decoration: none; border: 1.5px solid #10b981; background: transparent; color: #10b981; cursor: pointer; font-family: inherit; transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 0.35rem; }
    .btn-mini:hover { background: #10b981; color: #030712; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <!-- HEADER -->
  <div class="page-header">
    <span class="empresa-badge">◉ <?= htmlspecialchars($empresa['nombre']) ?></span>
    <h1>Panel de Administración</h1>
    <p class="sub">Bienvenido, <strong style="color: #10b981;"><?= htmlspecialchars($_SESSION['nombre']) ?></strong> · Datos exclusivos de tu empresa</p>
  </div>

  <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- ACCIONES RÁPIDAS -->
  <div class="quick-actions-grid">
    <a href="registro_vehiculo_usuario.php" class="quick-action">
      <div class="qa-icon">＋</div>
      <div class="qa-label">Registrar Vehículo</div>
      <div class="qa-sub">Entrada nueva</div>
    </a>
    <a href="modulo_pagos.php" class="quick-action">
      <div class="qa-icon">$</div>
      <div class="qa-label">Cobrar / Pagos</div>
      <div class="qa-sub">Caja rápida</div>
    </a>
    <a href="admin.php" class="quick-action">
      <div class="qa-icon">⬚</div>
      <div class="qa-label">Control de Celdas</div>
      <div class="qa-sub">Bloquear/habilitar</div>
    </a>
    <a href="salida.php" class="quick-action">
      <div class="qa-icon">➜</div>
      <div class="qa-label">Control de Salida</div>
      <div class="qa-sub">Autorizar salida</div>
    </a>
    <a href="dashboard_finanzas.php" class="quick-action">
      <div class="qa-icon">◐</div>
      <div class="qa-label">Finanzas</div>
      <div class="qa-sub">Reportes y análisis</div>
    </a>
    <a href="#backups" class="quick-action">
      <div class="qa-icon">⬇</div>
      <div class="qa-label">Backups</div>
      <div class="qa-sub">Respaldar mi empresa</div>
    </a>
  </div>

  <!-- ALERTAS -->
  <div class="alertas-container">
    <?php foreach ($alertas as $a): ?>
      <div class="alerta-item <?= htmlspecialchars($a['tipo']) ?>">
        <span class="a-icon"><?= $a['icon'] ?></span>
        <div class="a-content">
          <div class="a-title"><?= htmlspecialchars($a['titulo']) ?></div>
          <div class="a-desc"><?= htmlspecialchars($a['desc']) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- KPIs -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="icon">⬚</div>
      <div class="lbl">Celdas totales</div>
      <div class="val" data-counter="<?= (int)$stats['cupos']['total'] ?>">0</div>
    </div>
    <div class="kpi-card warning">
      <div class="icon">●</div>
      <div class="lbl">Ocupadas</div>
      <div class="val" data-counter="<?= (int)$stats['cupos']['ocupados'] ?>">0</div>
    </div>
    <div class="kpi-card">
      <div class="icon">○</div>
      <div class="lbl">Libres</div>
      <div class="val" data-counter="<?= (int)$stats['cupos']['libres'] ?>">0</div>
    </div>
    <div class="kpi-card info">
      <div class="icon">▤</div>
      <div class="lbl">Vehículos activos</div>
      <div class="val" data-counter="<?= (int)$stats['activos'] ?>">0</div>
    </div>
    <div class="kpi-card">
      <div class="icon">◉</div>
      <div class="lbl">Parqueaderos</div>
      <div class="val" data-counter="<?= (int)$stats['parqueaderos'] ?>">0</div>
    </div>
    <div class="kpi-card info">
      <div class="icon">◇</div>
      <div class="lbl">Usuarios</div>
      <div class="val" data-counter="<?= (int)$stats['usuarios'] ?>">0</div>
    </div>
    <div class="kpi-card danger">
      <div class="icon">$</div>
      <div class="lbl">Recaudo hoy</div>
      <div class="val">$<?= number_format($stats['recaudo_hoy'], 0, ',', '.') ?></div>
      <?php if ($tendencia['ayer'] > 0): ?>
        <span class="kpi-trend <?= $tendencia['pct'] > 0 ? 'up' : ($tendencia['pct'] < 0 ? 'down' : 'neutral') ?>">
          <?= $tendencia['pct'] > 0 ? '▲' : ($tendencia['pct'] < 0 ? '▼' : '●') ?> <?= abs($tendencia['pct']) ?>% vs ayer
        </span>
      <?php endif; ?>
    </div>
    <div class="kpi-card">
      <div class="icon">$</div>
      <div class="lbl">Recaudo mes</div>
      <div class="val">$<?= number_format($stats['recaudo_mes'], 0, ',', '.') ?></div>
    </div>
  </div>

  <!-- GRÁFICOS -->
  <div class="charts-row">

    <!-- Donut de ocupación -->
    <div class="chart-box">
      <h3>◐ Ocupación actual</h3>
      <p class="chart-sub">Celdas ocupadas vs totales en tiempo real</p>
      <?php
        $totalC = max(1, (int)$stats['cupos']['total']);
        $pctOcup = round(((int)$stats['cupos']['ocupados'] / $totalC) * 100);
        $degOcup = $pctOcup * 3.6;
      ?>
      <div class="donut-wrap">
        <div class="donut" style="background: conic-gradient(#10b981 0deg <?= $degOcup ?>deg, rgba(255,255,255,0.05) <?= $degOcup ?>deg 360deg);">
          <div class="donut-inner">
            <div class="d-num"><?= $pctOcup ?>%</div>
            <div class="d-lbl">Ocupación</div>
          </div>
        </div>
      </div>
      <div class="mini-celdas-legend" style="justify-content: center;">
        <span><span class="dot" style="background:#10b981;"></span> Ocupadas <?= (int)$stats['cupos']['ocupados'] ?></span>
        <span><span class="dot" style="background:rgba(255,255,255,0.15);"></span> Libres <?= (int)$stats['cupos']['libres'] ?></span>
      </div>
    </div>

    <!-- Barras 7 días -->
    <div class="chart-box">
      <h3>▤ Recaudo últimos 7 días</h3>
      <p class="chart-sub">Ingresos diarios por servicios prestados</p>
      <?php
        $maxRecaudo = 1;
        foreach ($recaudo7Dias as $d) { if ($d['total'] > $maxRecaudo) $maxRecaudo = $d['total']; }
        $dias = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie','Sat'=>'Sáb','Sun'=>'Dom'];
      ?>
      <div class="bars-chart">
        <?php foreach ($recaudo7Dias as $d): 
          $altura = $d['total'] > 0 ? max(6, round(($d['total'] / $maxRecaudo) * 100)) : 4;
          $label = $dias[$d['label']] ?? $d['label'];
        ?>
          <div class="bar-day">
            <span class="bar-val"><?= $d['total'] > 0 ? '$' . number_format($d['total']/1000, 0) . 'K' : '' ?></span>
            <div class="bar-fill-v" style="height: <?= $altura ?>%;" title="<?= date('d/m', strtotime($d['fecha'])) ?>: $<?= number_format($d['total'], 0, ',', '.') ?>"></div>
            <span class="bar-lbl"><?= htmlspecialchars($label) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Top placas -->
    <div class="chart-box">
      <h3>◆ Top 5 placas frecuentes</h3>
      <p class="chart-sub">Vehículos que más visitan tu parqueadero</p>
      <?php if (empty($topPlacas)): ?>
        <p style="color: #64748b; text-align: center; padding: 2rem;">Sin datos aún.</p>
      <?php else: ?>
        <div class="top-placas-list">
          <?php foreach ($topPlacas as $i => $p): ?>
            <div class="top-placa-item">
              <div style="display: flex; align-items: center; gap: 0.6rem;">
                <span style="color: #64748b; font-weight: 800; font-size: 0.85rem; min-width: 20px;">#<?= $i + 1 ?></span>
                <span class="tp-placa"><?= htmlspecialchars($p['placa']) ?></span>
              </div>
              <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span class="tp-count"><?= (int)$p['visitas'] ?> visitas</span>
                <?php if ($i === 0): ?><span class="tp-badge">★ Top</span><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- MAPA MINI DE CELDAS -->
  <div class="section">
    <h2>⬚ Mapa de celdas en vivo</h2>
    <p class="sub">Estado actual de todas tus celdas. Para modificar alguna, ve a <a href="admin.php" style="color: #10b981;">Control de Celdas</a>.</p>
    <?php
      $zonas = ['carro' => [], 'moto' => []];
      foreach ($celdasMini as $c) { $zonas[$c['tipo']][] = $c; }
    ?>
    <?php foreach (['carro' => '🚗 Zona Carros', 'moto' => '🏍️ Zona Motos'] as $tipo => $nombre): ?>
      <?php if (!empty($zonas[$tipo])): ?>
        <h3 style="font-size: 0.85rem; color: #94a3b8; margin: 1rem 0 0.6rem; text-transform: uppercase; letter-spacing: 0.05em;"><?= $nombre ?></h3>
        <div class="mini-celdas-grid">
          <?php foreach ($zonas[$tipo] as $c): ?>
            <div class="mini-celda <?= htmlspecialchars($c['estado']) ?>" title="<?= htmlspecialchars($c['codigo_cupo']) ?> - <?= ucfirst($c['estado']) ?>">
              <?= htmlspecialchars($c['codigo_cupo']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="mini-celdas-legend">
      <span><span class="dot" style="background:#10b981;"></span> Disponible</span>
      <span><span class="dot" style="background:#f59e0b;"></span> Ocupada</span>
      <span><span class="dot" style="background:#64748b;"></span> Mantenimiento</span>
    </div>
  </div>

  <!-- MIS PARQUEADEROS -->
  <?php if (!empty($parqueaderos)): ?>
  <div class="section">
    <h2>◉ Mis parqueaderos</h2>
    <p class="sub">Detalle de cada sede con su ocupación actual.</p>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
      <?php foreach ($parqueaderos as $p): 
        $totalP = max(1, (int)$p['total_cupos']);
        $pctP = round(((int)$p['ocupados'] / $totalP) * 100);
      ?>
        <div style="background: rgba(2,6,23,0.5); border-radius: 14px; padding: 1.25rem; border-left: 4px solid #10b981;">
          <h4 style="font-size: 1rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.25rem;"><?= htmlspecialchars($p['nombre']) ?></h4>
          <div style="color: #64748b; font-size: 0.78rem; margin-bottom: 0.9rem;">
            <?php if (!empty($p['ciudad'])): ?>◆ <?= htmlspecialchars($p['ciudad']) ?><?php endif; ?>
            <?php if (!empty($p['niveles'])): ?> · <?= (int)$p['niveles'] ?> nivel(es)<?php endif; ?>
          </div>
          <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-bottom: 0.75rem;">
            <div style="text-align: center;">
              <div style="font-size: 1.15rem; font-weight: 800; color: #10b981;"><?= (int)$p['total_cupos'] ?></div>
              <div style="font-size: 0.62rem; color: #64748b; text-transform: uppercase;">Total</div>
            </div>
            <div style="text-align: center;">
              <div style="font-size: 1.15rem; font-weight: 800; color: #f59e0b;"><?= (int)$p['ocupados'] ?></div>
              <div style="font-size: 0.62rem; color: #64748b; text-transform: uppercase;">Ocupadas</div>
            </div>
            <div style="text-align: center;">
              <div style="font-size: 1.15rem; font-weight: 800; color: #06b6d4;"><?= $pctP ?>%</div>
              <div style="font-size: 0.62rem; color: #64748b; text-transform: uppercase;">Ocupación</div>
            </div>
          </div>
          <div style="height: 6px; background: rgba(255,255,255,0.05); border-radius: 100px; overflow: hidden;">
            <div style="height: 100%; width: <?= $pctP ?>%; background: linear-gradient(90deg, #10b981, #06b6d4); border-radius: 100px; transition: width 1s ease;"></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ÚLTIMOS REGISTROS + ÚLTIMOS PAGOS -->
  <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    
    <div class="section" style="margin-bottom: 0;">
      <h2>▤ Últimos registros</h2>
      <p class="sub">Las últimas 10 entradas/salidas</p>
      <?php if (empty($registros)): ?>
        <p style="color: #64748b; text-align: center; padding: 2rem;">Sin registros aún.</p>
      <?php else: ?>
        <div style="overflow-x: auto;">
          <table class="data-table">
            <thead>
              <tr><th>ID</th><th>Placa</th><th>Celda</th><th>Ingreso</th><th>Estado</th><th>Total</th></tr>
            </thead>
            <tbody>
            <?php foreach ($registros as $r): ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td><span class="plate-badge"><?= htmlspecialchars($r['placa']) ?></span></td>
                <td><strong style="color: #06b6d4;"><?= htmlspecialchars($r['cupo_asignado']) ?></strong></td>
                <td style="font-size: 0.78rem;"><?= htmlspecialchars(substr($r['fecha_ingreso'], 0, 16)) ?></td>
                <td><?= $r['estado'] === 'activo' ? '● Activo' : '✓ Finalizado' ?></td>
                <td style="color: #10b981; font-weight: 700;">$<?= number_format((float)$r['total_pagar'], 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="section" style="margin-bottom: 0;">
      <h2>$ Últimos pagos</h2>
      <p class="sub">Pagos recientes</p>
      <?php if (empty($ultimosPagos)): ?>
        <p style="color: #64748b; text-align: center; padding: 2rem;">Sin pagos aún.</p>
      <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 0.6rem;">
          <?php foreach ($ultimosPagos as $p): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.6rem 0.8rem; background: rgba(2,6,23,0.5); border-radius: 10px; gap: 0.5rem;">
              <div>
                <div style="font-family: monospace; font-weight: 700; color: #10b981; font-size: 0.82rem;"><?= htmlspecialchars($p['placa']) ?></div>
                <div style="font-size: 0.68rem; color: #64748b;"><?= htmlspecialchars(substr($p['fecha_salida'], 0, 16)) ?></div>
              </div>
              <div style="text-align: right;">
                <div style="font-weight: 800; color: #10b981; font-size: 0.88rem;">$<?= number_format((float)$p['total_pagar'], 0, ',', '.') ?></div>
                <div style="font-size: 0.65rem; color: #64748b;"><?= htmlspecialchars($p['metodo_pago'] ?? 'efectivo') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- USUARIOS -->
  <div class="section">
    <h2>◇ Usuarios de mi empresa</h2>
    <p class="sub">Administradores, operadores, vigilantes y clientes vinculados a <?= htmlspecialchars($empresa['nombre']) ?>.</p>
    <?php if (empty($usuarios)): ?>
      <p style="color: #64748b; text-align: center; padding: 2rem;">Sin usuarios registrados.</p>
    <?php else: ?>
      <div style="overflow-x: auto;">
        <table class="data-table">
          <thead>
            <tr><th>Usuario</th><th>Nombre</th><th>Correo</th><th>Rol en empresa</th><th>Activo</th></tr>
          </thead>
          <tbody>
          <?php foreach ($usuarios as $u): ?>
            <tr>
              <td style="color: #10b981; font-family: monospace;">@<?= htmlspecialchars($u['username']) ?></td>
              <td><?= htmlspecialchars($u['nombre']) ?></td>
              <td style="color: #94a3b8;"><?= htmlspecialchars($u['correo'] ?? '—') ?></td>
              <td><span class="rol-badge <?= htmlspecialchars($u['rol_en_empresa']) ?>"><?= htmlspecialchars($u['rol_en_empresa']) ?></span></td>
              <td><?= $u['activo'] ? '✓' : '✗' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- BACKUPS -->
  <div class="section" id="backups">
    <h2>⬇ Backups de mi empresa</h2>
    <p class="sub">Crea y descarga copias de seguridad SOLO de tu empresa. Solo el developer puede eliminar backups por seguridad.</p>

    <div style="background: linear-gradient(160deg, rgba(16, 185, 129, 0.08), rgba(6, 182, 212, 0.05)); border: 1.5px solid rgba(16, 185, 129, 0.3); border-radius: 18px; padding: 1.75rem;">
      <h3 style="font-size: 0.95rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.5rem;">Crear backup ahora</h3>
      <p style="color: #64748b; font-size: 0.82rem; margin-bottom: 1rem;">
        El backup incluirá: tu empresa, parqueaderos, celdas, usuarios, registros, vehículos, tarifas, pagos, reservas y configuración.
      </p>
      <form method="POST" onsubmit="return confirm('¿Crear un backup de tu empresa ahora?');">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="crear_backup">
        <button type="submit" class="cyber-btn-primary">◆ Crear backup</button>
      </form>

      <?php if (!empty($backupsEmpresa)): ?>
        <h3 style="font-size: 0.95rem; font-weight: 800; color: #f1f5f9; margin-top: 1.5rem; margin-bottom: 0.5rem;">Backups disponibles (<?= count($backupsEmpresa) ?>)</h3>
        <div class="backup-list">
          <?php foreach (array_slice($backupsEmpresa, 0, 10) as $b): ?>
            <div class="backup-item">
              <div style="flex: 1;">
                <div class="bname"><?= htmlspecialchars($b['nombre']) ?></div>
                <div class="bdate"><?= htmlspecialchars($b['fecha']) ?> · <?= formatear_tamano($b['tamano']) ?></div>
              </div>
              <a href="descargar_backup.php?file=<?= urlencode($b['nombre']) ?>" class="btn-mini">⬇ Descargar</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p style="color: #64748b; font-size: 0.82rem; margin-top: 1rem; text-align: center;">
          Aún no tienes backups. Crea el primero con el botón de arriba.
        </p>
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>
</body>
</html>
