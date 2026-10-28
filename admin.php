<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

require_role(['admin', 'operador']);
require_password_change_guard();

$eid = current_empresa_id();
if (!$eid) { header('Location: login_empresa.php'); exit; }

// Datos de la empresa
$emp = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$emp->execute(['eid' => $eid]);
$empresa = $emp->fetch() ?: ['nombre' => 'Mi Empresa', 'nit' => ''];

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_celda'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página.";
    } else {
        $codigo_cupo  = $_POST['codigo_cupo'] ?? '';
        $nuevo_estado = $_POST['nuevo_estado'] ?? '';

        if (!empty($codigo_cupo) && in_array($nuevo_estado, ['disponible', 'ocupado', 'mantenimiento'], true)) {
            // Solo modificar celdas de MI empresa
            $stmt_upd = $pdo->prepare("UPDATE cupos SET estado = :estado WHERE codigo_cupo = :codigo AND empresa_id = :eid");
            $stmt_upd->execute(['estado' => $nuevo_estado, 'codigo' => $codigo_cupo, 'eid' => $eid]);

            if ($stmt_upd->rowCount() > 0) {
                registrarAuditoria($pdo, 'cambio_estado_celda', "Celda $codigo_cupo → $nuevo_estado");
                $mensaje = "La celda $codigo_cupo cambió a: " . strtoupper($nuevo_estado);
            } else {
                $error = "No se encontró la celda o no pertenece a tu empresa.";
            }
        } else {
            $error = "Datos inválidos para cambiar la celda.";
        }
    }
}

try {
    $stmt_totales = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_cupos,
            SUM(CASE WHEN estado = 'disponible' THEN 1 ELSE 0 END) AS disponibles,
            SUM(CASE WHEN estado = 'ocupado' THEN 1 ELSE 0 END) AS ocupados,
            SUM(CASE WHEN estado = 'mantenimiento' THEN 1 ELSE 0 END) AS bloqueados,
            SUM(CASE WHEN tipo = 'carro' THEN 1 ELSE 0 END) AS carros,
            SUM(CASE WHEN tipo = 'moto' THEN 1 ELSE 0 END) AS motos,
            SUM(CASE WHEN tipo = 'bicicleta' THEN 1 ELSE 0 END) AS bicicletas
        FROM cupos WHERE empresa_id = :eid
    ");
    $stmt_totales->execute(['eid' => $eid]);
    $stats_cupos = $stmt_totales->fetch();
} catch (\Throwable $e) {
    $stats_cupos = ['total_cupos'=>0,'disponibles'=>0,'ocupados'=>0,'bloqueados'=>0,'carros'=>0,'motos'=>0,'bicicletas'=>0];
}

try {
    $stmt_ingresos = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid");
    $stmt_ingresos->execute(['eid' => $eid]);
    $total_ingresos = (int)$stmt_ingresos->fetchColumn();

    $stmt_activos = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado = 'activo'");
    $stmt_activos->execute(['eid' => $eid]);
    $activos_ahora = (int)$stmt_activos->fetchColumn();

    $stmt_hoy = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND DATE(fecha_ingreso) = CURDATE()");
    $stmt_hoy->execute(['eid' => $eid]);
    $ingresos_hoy = (int)$stmt_hoy->fetchColumn();
} catch (\Throwable $e) {
    $total_ingresos = $activos_ahora = $ingresos_hoy = 0;
}

try {
    $stmt_celdas = $pdo->prepare("
        SELECT c.codigo_cupo, c.tipo, c.estado, r.placa, r.fecha_ingreso
        FROM cupos c
        LEFT JOIN registros_parqueo r ON c.codigo_cupo = r.cupo_asignado 
            AND r.estado = 'activo' AND r.empresa_id = c.empresa_id
        WHERE c.empresa_id = :eid
        ORDER BY c.tipo ASC, LENGTH(c.codigo_cupo) ASC, c.codigo_cupo ASC
    ");
    $stmt_celdas->execute(['eid' => $eid]);
    $celdas = $stmt_celdas->fetchAll();
} catch (\Throwable $e) {
    $celdas = [];
}

$buscar_placa  = trim($_GET['buscar_placa'] ?? '');
$buscar_correo = trim($_GET['buscar_correo'] ?? '');
$buscar_fecha  = trim($_GET['buscar_fecha'] ?? '');

$query_historial = "SELECT * FROM registros_parqueo WHERE empresa_id = :eid";
$params = ['eid' => $eid];

if (!empty($buscar_placa)) {
    $query_historial .= " AND placa LIKE :placa";
    $params['placa'] = "%$buscar_placa%";
}
if (!empty($buscar_correo)) {
    $query_historial .= " AND correo LIKE :correo";
    $params['correo'] = "%$buscar_correo%";
}
if (!empty($buscar_fecha)) {
    $query_historial .= " AND DATE(fecha_ingreso) = :fecha";
    $params['fecha'] = $buscar_fecha;
}

$query_historial .= " ORDER BY id DESC LIMIT 50";

try {
    $stmt_historial = $pdo->prepare($query_historial);
    $stmt_historial->execute($params);
    $registros_filtrados = $stmt_historial->fetchAll();
} catch (\Throwable $e) {
    $registros_filtrados = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Control de Celdas - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=35">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1400px; margin: 0 auto; padding: 0 1.5rem; }

    .page-header { margin-bottom: 2rem; }
    .page-header h1 {
        font-size: clamp(1.6rem, 3.5vw, 2.2rem);
        font-weight: 900;
        letter-spacing: -0.03em;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 0.4rem;
    }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }
    .empresa-badge {
        display: inline-flex; align-items: center; gap: 0.4rem;
        padding: 0.35rem 0.9rem;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        color: #10b981; font-size: 0.75rem; font-weight: 800;
        letter-spacing: 0.03em;
        margin-bottom: 0.75rem;
    }

    /* Quick actions */
    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .quick-action {
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        gap: 0.75rem;
        padding: 1.5rem 1rem;
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.18);
        border-radius: 18px;
        text-decoration: none;
        color: #f1f5f9;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
        overflow: hidden;
    }
    .quick-action::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #10b981, #06b6d4);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.4s ease;
    }
    .quick-action:hover::before { transform: scaleX(1); }
    .quick-action:hover {
        transform: translateY(-6px);
        border-color: rgba(16, 185, 129, 0.55);
        box-shadow: 0 20px 50px rgba(16, 185, 129, 0.2);
    }
    .quick-action .qa-icon {
        width: 52px; height: 52px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 14px;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #10b981;
        font-size: 1.5rem;
        transition: all 0.35s ease;
    }
    .quick-action:hover .qa-icon {
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        transform: scale(1.1) rotate(-5deg);
    }
    .quick-action .qa-label { font-size: 0.92rem; font-weight: 700; text-align: center; line-height: 1.25; }
    .quick-action .qa-sub { font-size: 0.72rem; color: #64748b; text-align: center; }

    /* KPIs */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .kpi-card {
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.18);
        border-radius: 18px;
        padding: 1.35rem;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
        overflow: hidden;
    }
    .kpi-card::before {
        content: '';
        position: absolute; top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #10b981, #06b6d4);
        opacity: 0.6;
    }
    .kpi-card:hover {
        transform: translateY(-5px);
        border-color: rgba(16, 185, 129, 0.5);
        box-shadow: 0 20px 50px rgba(16, 185, 129, 0.18);
    }
    .kpi-card.warning::before { background: linear-gradient(90deg, #f59e0b, #f97316); }
    .kpi-card.danger::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
    .kpi-card.info::before { background: linear-gradient(90deg, #06b6d4, #3b82f6); }
    .kpi-card .icon {
        width: 42px; height: 42px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 12px;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #10b981;
        font-size: 1.3rem;
        margin-bottom: 0.9rem;
    }
    .kpi-card.warning .icon { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); color: #f59e0b; }
    .kpi-card.danger .icon { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.3); color: #ef4444; }
    .kpi-card.info .icon { background: rgba(6, 182, 212, 0.12); border-color: rgba(6, 182, 212, 0.3); color: #06b6d4; }
    .kpi-card .lbl {
        font-size: 0.7rem; color: #94a3b8;
        text-transform: uppercase; letter-spacing: 0.08em;
        font-weight: 700; margin-bottom: 0.35rem;
    }
    .kpi-card .val {
        font-size: 1.9rem; font-weight: 900;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1;
    }
    .kpi-card.warning .val { background: linear-gradient(135deg, #f59e0b, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi-card.danger .val { background: linear-gradient(135deg, #ef4444, #dc2626); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi-card.info .val { background: linear-gradient(135deg, #06b6d4, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

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
        font-size: 1.1rem; font-weight: 800;
        color: #f1f5f9; margin-bottom: 0.4rem;
        display: flex; align-items: center; gap: 0.6rem;
    }
    .section .sub {
        color: #64748b; font-size: 0.82rem;
        margin-bottom: 1.25rem;
    }

    /* Tabla */
    .data-table { width: 100%; border-collapse: collapse; font-size: 0.83rem; }
    .data-table thead tr { border-bottom: 1px solid rgba(16, 185, 129, 0.2); }
    .data-table th {
        padding: 0.7rem; text-align: left;
        color: #10b981; font-size: 0.7rem;
        text-transform: uppercase; letter-spacing: 0.06em;
        font-weight: 800;
    }
    .data-table tbody tr { border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
    .data-table tbody tr:hover { background: rgba(16, 185, 129, 0.04); }
    .data-table td { padding: 0.7rem; color: #cbd5e1; }

    .plate-badge {
        display: inline-block;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #10b981;
        padding: 0.15rem 0.55rem;
        border-radius: 6px;
        font-family: 'JetBrains Mono', monospace;
        font-weight: 700;
        font-size: 0.78rem;
    }

    /* Filtros */
    .filtros-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        align-items: end;
        margin-bottom: 1.25rem;
    }
    .filtro-item label {
        display: block;
        font-size: 0.72rem; color: #94a3b8;
        text-transform: uppercase; letter-spacing: 0.05em;
        font-weight: 700; margin-bottom: 0.4rem;
    }
    .filtro-item input, .filtro-item select {
        width: 100%;
        padding: 0.7rem 0.9rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 9px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.88rem;
    }
    .filtro-item input:focus, .filtro-item select:focus {
        border-color: #10b981; outline: none;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
    }
    .filtro-actions { display: flex; gap: 0.5rem; }

    /* Card overlay para el plano */
    .floor-plan-wrapper {
        background: rgba(2, 6, 23, 0.4);
        border-radius: 14px;
        padding: 1.25rem;
    }

    @media (max-width: 768px) {
        .kpi-card .val { font-size: 1.6rem; }
        .quick-actions-grid { grid-template-columns: repeat(2, 1fr); }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <!-- HEADER -->
  <div class="page-header">
    <span class="empresa-badge">◉ <?= htmlspecialchars($empresa['nombre']) ?></span>
    <h1>Control de Celdas</h1>
    <p class="sub">Bienvenido, <strong style="color: #10b981;"><?= htmlspecialchars($_SESSION['nombre']) ?></strong> · Gestión operativa de tu parqueadero</p>
  </div>

  <?php if ($mensaje): ?><div class="cyber-success" style="margin-bottom: 1.5rem;">✓ <?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- QUICK ACTIONS -->
  <div class="quick-actions-grid">
    <a href="dashboard_admin.php" class="quick-action">
      <div class="qa-icon">📊</div>
      <div class="qa-label">Dashboard</div>
      <div class="qa-sub">Vista general</div>
    </a>
    <a href="registro_entrada.php" class="quick-action">
      <div class="qa-icon">➕</div>
      <div class="qa-label">Registrar</div>
      <div class="qa-sub">Nueva entrada</div>
    </a>
    <a href="modulo_pagos.php" class="quick-action">
      <div class="qa-icon">💳</div>
      <div class="qa-label">Cobrar</div>
      <div class="qa-sub">Caja rápida</div>
    </a>
    <a href="salida.php" class="quick-action">
      <div class="qa-icon">➜</div>
      <div class="qa-label">Salida</div>
      <div class="qa-sub">Autorizar retiro</div>
    </a>
    <a href="ver_qr.php" class="quick-action">
      <div class="qa-icon">🔳</div>
      <div class="qa-label">QR de acceso</div>
      <div class="qa-sub">Imprimir códigos</div>
    </a>
  </div>

  <!-- KPIs -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="icon">⬚</div>
      <div class="lbl">Total celdas</div>
      <div class="val" data-counter="<?= (int)$stats_cupos['total_cupos'] ?>">0</div>
    </div>
    <div class="kpi-card">
      <div class="icon">○</div>
      <div class="lbl">Disponibles</div>
      <div class="val" data-counter="<?= (int)$stats_cupos['disponibles'] ?>">0</div>
    </div>
    <div class="kpi-card warning">
      <div class="icon">●</div>
      <div class="lbl">Ocupadas</div>
      <div class="val" data-counter="<?= (int)$stats_cupos['ocupados'] ?>">0</div>
    </div>
    <div class="kpi-card danger">
      <div class="icon">🔧</div>
      <div class="lbl">Mantenimiento</div>
      <div class="val" data-counter="<?= (int)$stats_cupos['bloqueados'] ?>">0</div>
    </div>
    <div class="kpi-card info">
      <div class="icon">▤</div>
      <div class="lbl">Vehículos activos</div>
      <div class="val" data-counter="<?= (int)$activos_ahora ?>">0</div>
    </div>
    <div class="kpi-card">
      <div class="icon">◐</div>
      <div class="lbl">Ingresos hoy</div>
      <div class="val" data-counter="<?= (int)$ingresos_hoy ?>">0</div>
    </div>
    <div class="kpi-card">
      <div class="icon">◆</div>
      <div class="lbl">Histórico total</div>
      <div class="val" data-counter="<?= (int)$total_ingresos ?>">0</div>
    </div>
  </div>

  <!-- PLANO VISUAL DE CELDAS -->
  <div class="section">
    <h2>⬚ Plano del parqueadero</h2>
    <p class="sub">Estado en tiempo real. Haz clic en cualquier celda para bloquear o habilitar.</p>

    <div class="floor-plan-wrapper">
      <?php
      $editable = true;
      $highlight_codigo = null;
      include 'partials/floor_plan.php';
      ?>
    </div>
  </div>

  <!-- BUSCADOR DE REGISTROS -->
  <div class="section">
    <h2>▤ Buscador de registros</h2>
    <p class="sub">Historial completo de tu parqueadero. Filtra por placa, correo o fecha.</p>

    <form action="admin.php" method="GET" class="filtros-grid">
      <div class="filtro-item">
        <label>Buscar por placa</label>
        <input type="text" name="buscar_placa" value="<?= htmlspecialchars($buscar_placa) ?>" placeholder="ABC123" style="text-transform: uppercase;">
      </div>
      <div class="filtro-item">
        <label>Buscar por correo</label>
        <input type="text" name="buscar_correo" value="<?= htmlspecialchars($buscar_correo) ?>" placeholder="correo@dominio.com">
      </div>
      <div class="filtro-item">
        <label>Fecha de ingreso</label>
        <input type="date" name="buscar_fecha" value="<?= htmlspecialchars($buscar_fecha) ?>">
      </div>
      <div class="filtro-actions">
        <button type="submit" class="cyber-btn-primary" style="flex: 1;">🔍 Filtrar</button>
        <a href="admin.php" class="cyber-btn-outline" style="text-align: center;">Limpiar</a>
      </div>
    </form>

    <?php if (empty($registros_filtrados)): ?>
      <p style="text-align: center; color: #64748b; padding: 2rem;">No se encontraron registros con esos criterios.</p>
    <?php else: ?>
      <div style="overflow-x: auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Placa</th>
              <th>Modelo</th>
              <th>Correo</th>
              <th>Tipo</th>
              <th>Celda</th>
              <th>Ingreso</th>
              <th>Estado</th>
              <th>Pago</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($registros_filtrados as $reg): ?>
              <tr>
                <td>#<?= (int)$reg['id'] ?></td>
                <td><span class="plate-badge"><?= htmlspecialchars($reg['placa']) ?></span></td>
                <td><?= htmlspecialchars($reg['modelo'] ?? 'N/A') ?></td>
                <td style="color: #94a3b8; font-size: 0.8rem;"><?= htmlspecialchars($reg['correo'] ?? '—') ?></td>
                <td><?= htmlspecialchars(ucfirst($reg['tipo_vehiculo'])) ?></td>
                <td><strong style="color: #06b6d4;"><?= htmlspecialchars($reg['cupo_asignado']) ?></strong></td>
                <td style="font-size: 0.78rem;"><?= htmlspecialchars(substr($reg['fecha_ingreso'], 0, 16)) ?></td>
                <td><?= $reg['estado'] === 'activo' ? '● Activo' : '✓ Finalizado' ?></td>
                <td><?= ((int)$reg['estado_pago'] === 1) ? '✅' : '⏳' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p style="color: #64748b; font-size: 0.78rem; margin-top: 1rem; text-align: right;">
        Mostrando hasta 50 resultados más recientes.
      </p>
    <?php endif; ?>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>

<?php include __DIR__ . '/partials/ia_widget.php'; ?>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>