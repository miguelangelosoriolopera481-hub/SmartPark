<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'helpers.php';
require_once 'csrf.php';
send_security_headers();

require_role_empresa(['admin','operador','superadmin']);
$eid = current_empresa_id();

$emp = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :eid");
$emp->execute(['eid' => $eid]);
$nombreEmpresa = $emp->fetchColumn() ?: 'Empresa #' . $eid;

$kpis = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM cupos WHERE empresa_id = :e1 AND estado='disponible') AS libres,
      (SELECT COUNT(*) FROM cupos WHERE empresa_id = :e2 AND estado='ocupado') AS ocupados,
      (SELECT COUNT(*) FROM cupos WHERE empresa_id = :e3 AND estado='mantenimiento') AS bloqueados,
      (SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :e4 AND estado='activo') AS activos,
      (SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :e5 AND DATE(fecha_salida)=CURDATE()) AS recaudo_hoy
");
$kpis->execute(['e1'=>$eid,'e2'=>$eid,'e3'=>$eid,'e4'=>$eid,'e5'=>$eid]);
$k = $kpis->fetch();

$stmt = $pdo->prepare("
    SELECT id, placa, cupo_asignado, tipo_vehiculo, fecha_ingreso, estado, estado_pago, total_pagar
    FROM registros_parqueo
    WHERE empresa_id = :eid
    ORDER BY id DESC LIMIT 20
");
$stmt->execute(['eid' => $eid]);
$movs = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Admin - <?= htmlspecialchars($nombreEmpresa) ?></title>
<link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<nav class="cyber-navbar"><div class="nav-container">
  <a href="dashboard_admin.php" class="cyber-brand">
    <img src="IMG/SMARTPARK_TRANSPARENTE.png" class="nav-logo">
    <span class="brand-title"><?= htmlspecialchars($nombreEmpresa) ?></span>
  </a>
  <div class="cyber-nav-links">
    <a href="admin.php" class="cyber-btn-outline">Control de Celdas</a>
    <a href="dashboard_finanzas.php" class="cyber-btn-outline">Finanzas</a>
    <a href="modulo_pagos.php" class="cyber-btn-outline">Caja</a>
    <a href="smartpark_ai.php" class="cyber-btn-outline">SmartPark AI</a>
    <a href="registro_vehiculo_usuario.php" class="cyber-btn-outline">Registrar</a>
    <a href="logout.php" style="color:var(--danger);text-decoration:none">Salir</a>
  </div>
</div></nav>
<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'helpers.php';
require_once 'csrf.php';
send_security_headers();

require_role_empresa(['admin','operador','superadmin']);
$eid = current_empresa_id();

$emp = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :eid");
$emp->execute(['eid' => $eid]);
$nombreEmpresa = $emp->fetchColumn() ?: 'Empresa #' . $eid;

$kpis = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM cupos WHERE empresa_id = :e1 AND estado='disponible') AS libres,
      (SELECT COUNT(*) FROM cupos WHERE empresa_id = :e2 AND estado='ocupado') AS ocupados,
      (SELECT COUNT(*) FROM cupos WHERE empresa_id = :e3 AND estado='mantenimiento') AS bloqueados,
      (SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :e4 AND estado='activo') AS activos,
      (SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :e5 AND DATE(fecha_salida)=CURDATE()) AS recaudo_hoy
");
$kpis->execute(['e1'=>$eid,'e2'=>$eid,'e3'=>$eid,'e4'=>$eid,'e5'=>$eid]);
$k = $kpis->fetch();

$stmt = $pdo->prepare("
    SELECT id, placa, cupo_asignado, tipo_vehiculo, fecha_ingreso, estado, estado_pago, total_pagar
    FROM registros_parqueo
    WHERE empresa_id = :eid
    ORDER BY id DESC LIMIT 20
");
$stmt->execute(['eid' => $eid]);
$movs = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Admin - <?= htmlspecialchars($nombreEmpresa) ?></title>
<link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<nav class="cyber-navbar"><div class="nav-container">
  <a href="dashboard_admin.php" class="cyber-brand">
    <img src="IMG/SMARTPARK_TRANSPARENTE.png" class="nav-logo">
    <span class="brand-title"><?= htmlspecialchars($nombreEmpresa) ?></span>
  </a>
  <div class="cyber-nav-links">
    <a href="admin.php" class="cyber-btn-outline">Control de Celdas</a>
    <a href="dashboard_finanzas.php" class="cyber-btn-outline">Finanzas</a>
    <a href="modulo_pagos.php" class="cyber-btn-outline">Caja</a>
    <a href="smartpark_ai.php" class="cyber-btn-outline">SmartPark AI</a>
    <a href="registro_vehiculo_usuario.php" class="cyber-btn-outline">Registrar</a>
    <a href="logout.php" style="color:var(--danger);text-decoration:none">Salir</a>
  </div>
</div></nav>

<div class="container cyber-entrance">
  <h2 style="color:var(--primary-cyan);margin-bottom:1.5rem">Panel de Administracion</h2>

  <div class="grid-3">
    <div class="cyber-card"><h4>Celdas Libres</h4><p style="font-size:2.2rem;color:var(--primary-green)"><?= (int)$k['libres'] ?></p></div>
    <div class="cyber-card"><h4>Celdas Ocupadas</h4><p style="font-size:2.2rem;color:var(--danger)"><?= (int)$k['ocupados'] ?></p></div>
    <div class="cyber-card"><h4>En Mantenimiento</h4><p style="font-size:2.2rem;color:var(--warning)"><?= (int)$k['bloqueados'] ?></p></div>
    <div class="cyber-card"><h4>Vehiculos Activos</h4><p style="font-size:2.2rem;color:var(--primary-cyan)"><?= (int)$k['activos'] ?></p></div>
    <div class="cyber-card"><h4>Recaudo Hoy</h4><p style="font-size:2.2rem;color:var(--primary-green)">$<?= number_format((float)$k['recaudo_hoy'],0,',','.') ?></p></div>
  </div>

  <div class="cyber-card" style="margin-top:2rem">
    <h3 style="color:var(--primary-cyan);margin-bottom:1rem">Ultimos Movimientos</h3>
    <div class="table-wrapper">
      <table>
        <thead><tr><th>ID</th><th>Placa</th><th>Celda</th><th>Tipo</th><th>Ingreso</th><th>Estado</th><th>Pago</th><th>Total</th></tr></thead>
        <tbody>
        <?php if (empty($movs)): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:1.5rem">Sin movimientos aun.</td></tr>
        <?php else: foreach ($movs as $m): ?>
          <tr>
            <td><?= (int)$m['id'] ?></td>
            <td><span class="plate-style"><?= htmlspecialchars($m['placa']) ?></span></td>
            <td><?= htmlspecialchars($m['cupo_asignado']) ?></td>
            <td><?= htmlspecialchars($m['tipo_vehiculo']) ?></td>
            <td><?= htmlspecialchars($m['fecha_ingreso']) ?></td>
            <td><?= strtoupper($m['estado']) ?></td>
            <td><?= $m['estado_pago'] ? 'OK' : 'PEND' ?></td>
            <td>$<?= number_format((float)$m['total_pagar'],0,',','.') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body></html>
