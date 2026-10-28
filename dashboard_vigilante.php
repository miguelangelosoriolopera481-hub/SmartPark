<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'helpers_entrada.php';
send_security_headers();

require_login();
$eid = require_empresa();
$rol = $_SESSION['rol_en_empresa'] ?? '';
if (!in_array($rol, ['vigilante', 'admin', 'superadmin', 'operador'], true)) {
    header('Location: dashboard_cliente.php'); exit;
}

$uid = (int)$_SESSION['usuario_id'];
$nombre = $_SESSION['nombre'] ?? 'Vigilante';
$parqueaderoId = (int)($_SESSION['parqueadero_id'] ?? 0);
$msg = ''; $error = '';

// Cobrar en efectivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'cobrar_efectivo') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $regId = (int)($_POST['registro_id'] ?? 0);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE id = :id AND empresa_id = :eid AND estado = 'activo' FOR UPDATE");
            $stmt->execute(['id' => $regId, 'eid' => $eid]);
            $reg = $stmt->fetch();
            
            if (!$reg) throw new RuntimeException('Registro no encontrado.');
            
            $tarifa = 4000.0;
            $stmt2 = $pdo->prepare("SELECT precio FROM tarifas WHERE empresa_id = :eid AND tipo_vehiculo = :tv AND tipo_tarifa = 'hora' AND activo = 1 ORDER BY vigente_desde DESC LIMIT 1");
            $stmt2->execute(['eid' => $eid, 'tv' => $reg['tipo_vehiculo']]);
            $t = $stmt2->fetchColumn();
            if ($t) $tarifa = (float)$t;
            
            $ing = new DateTime($reg['fecha_ingreso']);
            $horas = max(1, (int)ceil((time() - $ing->getTimestamp()) / 3600));
            $total = $horas * $tarifa;
            $token = $reg['token_factura'] ?: bin2hex(random_bytes(16));
            
            $pdo->prepare("UPDATE registros_parqueo SET estado_pago = 1, fecha_pago = NOW(), horas = :h, total_pagar = :t, metodo_pago = 'efectivo', token_factura = :tk WHERE id = :id AND empresa_id = :eid")
                ->execute(['h' => $horas, 't' => $total, 'tk' => $token, 'id' => $regId, 'eid' => $eid]);
            
            $pdo->commit();
            registrarAuditoria($pdo, 'cobro_efectivo', "placa={$reg['placa']} total=$total");
            $msg = "✓ Cobrado: \${$total} ({$horas}h) · Placa {$reg['placa']}";
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('cobrar_efectivo: ' . $e->getMessage());
            $error = 'Error al cobrar.';
        }
    }
}

// KPIs
$stats = ['total_cupos'=>0,'libres'=>0,'ocupados'=>0,'activos'=>0,'hoy'=>0,'salidas_hoy'=>0,'pendientes_pago'=>0];
try {
    $r = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN estado='disponible' THEN 1 ELSE 0 END) AS libres, SUM(CASE WHEN estado='ocupado' THEN 1 ELSE 0 END) AS ocupados FROM cupos WHERE empresa_id = :eid");
    $r->execute(['eid' => $eid]); $row = $r->fetch();
    $stats['total_cupos'] = (int)$row['total'];
    $stats['libres'] = (int)$row['libres'];
    $stats['ocupados'] = (int)$row['ocupados'];

    $r = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado='activo'");
    $r->execute(['eid' => $eid]); $stats['activos'] = (int)$r->fetchColumn();

    $r = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado='activo' AND estado_pago=0");
    $r->execute(['eid' => $eid]); $stats['pendientes_pago'] = (int)$r->fetchColumn();

    $r = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND DATE(fecha_ingreso)=CURDATE()");
    $r->execute(['eid' => $eid]); $stats['hoy'] = (int)$r->fetchColumn();

    $r = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND DATE(fecha_salida)=CURDATE()");
    $r->execute(['eid' => $eid]); $stats['salidas_hoy'] = (int)$r->fetchColumn();
} catch (\Throwable $e) {}

// Vehículos activos
$activos = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.placa, r.modelo, r.tipo_vehiculo, r.cupo_asignado, r.fecha_ingreso, r.estado_pago,
               TIMESTAMPDIFF(MINUTE, r.fecha_ingreso, NOW()) AS minutos_dentro
        FROM registros_parqueo r
        WHERE r.empresa_id = :eid AND r.estado = 'activo'
        ORDER BY r.fecha_ingreso ASC LIMIT 50
    ");
    $stmt->execute(['eid' => $eid]);
    $activos = $stmt->fetchAll();
} catch (\Throwable $e) {}

// Historial del día
$historial = [];
try {
    $stmt = $pdo->prepare("
        SELECT placa, cupo_asignado, fecha_ingreso, fecha_salida, total_pagar, estado, estado_pago
        FROM registros_parqueo
        WHERE empresa_id = :eid AND DATE(fecha_ingreso) = CURDATE()
        ORDER BY fecha_ingreso DESC LIMIT 30
    ");
    $stmt->execute(['eid' => $eid]);
    $historial = $stmt->fetchAll();
} catch (\Throwable $e) {}

// Celdas
$celdas = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.codigo_cupo, c.tipo, c.estado, r.placa
        FROM cupos c
        LEFT JOIN registros_parqueo r ON c.codigo_cupo = r.cupo_asignado AND r.estado = 'activo' AND r.empresa_id = c.empresa_id
        WHERE c.empresa_id = :eid
        ORDER BY c.tipo, LENGTH(c.codigo_cupo), c.codigo_cupo
    ");
    $stmt->execute(['eid' => $eid]);
    $celdas = $stmt->fetchAll();
} catch (\Throwable $e) {}

$empresa = ['nombre' => 'Mi Empresa'];
try { $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :eid"); $stmt->execute(['eid' => $eid]); $empresa['nombre'] = $stmt->fetchColumn() ?: 'Mi Empresa'; } catch (\Throwable $e) {}

$pctOcupacion = $stats['total_cupos'] > 0 ? round(($stats['ocupados'] / $stats['total_cupos']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vigilante - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=52">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
    .hero { background: linear-gradient(135deg, rgba(15,23,42,0.9), rgba(15,23,42,0.5)); border: 1.5px solid rgba(245,158,11,0.35); border-radius: 24px; padding: 1.75rem 2rem; margin-bottom: 2rem; position: relative; overflow: hidden; }
    .hero::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background: linear-gradient(90deg,#f59e0b,#06b6d4); }
    .hero-inner { display: flex; justify-content: space-between; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
    .hero-left { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
    .avatar { width: 68px; height: 68px; border-radius: 18px; background: linear-gradient(135deg,#f59e0b,#06b6d4); display: flex; align-items: center; justify-content: center; color:#030712; font-weight:900; font-size:1.9rem; box-shadow: 0 0 40px rgba(245,158,11,0.5); }
    .hero-text h1 { font-size: 1.75rem; font-weight: 900; color: #f1f5f9; margin: 0 0 0.3rem; }
    .hero-text p { color: #94a3b8; font-size: 0.9rem; margin: 0; }
    .hero-badge { display: inline-block; padding: 0.35rem 0.85rem; background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.4); border-radius: 100px; color: #fcd34d; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; margin-bottom: 0.4rem; }
    
    .acciones-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; margin-bottom: 2rem; }
    .accion-card { display: flex; flex-direction: column; align-items: center; gap: 0.85rem; padding: 2rem 1.25rem; background: linear-gradient(160deg, rgba(15,23,42,0.9), rgba(15,23,42,0.5)); border: 2px solid rgba(16,185,129,0.3); border-radius: 22px; text-decoration: none; color: #f1f5f9; transition: all 0.35s ease; position: relative; overflow: hidden; }
    .accion-card:hover { transform: translateY(-6px); border-color: rgba(16,185,129,0.7); box-shadow: 0 20px 50px rgba(16,185,129,0.25); }
    .accion-card.cobrar { border-color: rgba(6,182,212,0.3); }
    .accion-card.cobrar:hover { border-color: rgba(6,182,212,0.7); box-shadow: 0 20px 50px rgba(6,182,212,0.25); }
    .accion-card.salida { border-color: rgba(245,158,11,0.3); }
    .accion-card.salida:hover { border-color: rgba(245,158,11,0.7); box-shadow: 0 20px 50px rgba(245,158,11,0.25); }
    .accion-icon { width: 70px; height: 70px; border-radius: 18px; background: linear-gradient(135deg,#10b981,#06b6d4); display: flex; align-items: center; justify-content: center; font-size: 2rem; color:#030712; box-shadow: 0 10px 30px rgba(16,185,129,0.4); }
    .accion-card.cobrar .accion-icon { background: linear-gradient(135deg,#06b6d4,#3b82f6); }
    .accion-card.salida .accion-icon { background: linear-gradient(135deg,#f59e0b,#f97316); }
    .accion-title { font-size: 1.15rem; font-weight: 900; letter-spacing: -0.02em; }
    .accion-sub { font-size: 0.8rem; color: #64748b; text-align: center; }
    
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .kpi { background: linear-gradient(160deg, rgba(15,23,42,0.85), rgba(15,23,42,0.5)); border: 1.5px solid rgba(16,185,129,0.18); border-radius: 18px; padding: 1.25rem; text-align: center; position: relative; overflow: hidden; }
    .kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background: linear-gradient(90deg,#10b981,#06b6d4); }
    .kpi.warning::before { background: linear-gradient(90deg,#f59e0b,#f97316); }
    .kpi.info::before { background: linear-gradient(90deg,#06b6d4,#3b82f6); }
    .kpi .lbl { font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.35rem; }
    .kpi .val { font-size: 1.75rem; font-weight: 900; background: linear-gradient(135deg,#10b981,#06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; line-height: 1; }
    .kpi.warning .val { background: linear-gradient(135deg,#f59e0b,#f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi.info .val { background: linear-gradient(135deg,#06b6d4,#3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    
    .section { background: rgba(15,23,42,0.6); backdrop-filter: blur(16px); border: 1px solid rgba(16,185,129,0.15); border-radius: 18px; padding: 1.75rem; margin-bottom: 1.5rem; }
    .section h2 { font-size: 1.1rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.35rem; }
    .section .sub { color: #64748b; font-size: 0.82rem; margin-bottom: 1.25rem; }
    
    table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
    thead tr { border-bottom: 1px solid rgba(16,185,129,0.2); }
    th { padding: 0.75rem; text-align: left; color: #10b981; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 800; }
    tbody tr { border-bottom: 1px solid rgba(255,255,255,0.05); }
    tbody tr:hover { background: rgba(16,185,129,0.04); }
    td { padding: 0.75rem; color: #cbd5e1; }
    .placa { display: inline-block; padding: 0.2rem 0.6rem; background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.3); border-radius: 6px; font-family: 'JetBrains Mono',monospace; font-weight: 700; color: #10b981; font-size: 0.82rem; }
    .celda-badge { display: inline-block; padding: 0.15rem 0.5rem; background: rgba(6,182,212,0.15); border-radius: 6px; color: #67e8f9; font-family: monospace; font-weight: 700; font-size: 0.75rem; }
    .btn-cobrar { padding: 0.35rem 0.75rem; background: rgba(6,182,212,0.15); border: 1.5px solid #06b6d4; color: #06b6d4; border-radius: 8px; font-size: 0.75rem; font-weight: 800; cursor: pointer; font-family: inherit; }
    .btn-cobrar:hover { background: #06b6d4; color: #030712; }
    .estado-pagado { color: #6ee7b7; font-weight: 700; font-size: 0.78rem; }
    .estado-pendiente { color: #fcd34d; font-weight: 700; font-size: 0.78rem; }
    
    .celdas-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(60px, 1fr)); gap: 0.5rem; }
    .celda { aspect-ratio: 1; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 800; font-family: 'JetBrains Mono', monospace; transition: transform 0.2s ease; }
    .celda.disponible { background: rgba(16,185,129,0.15); border: 1.5px solid rgba(16,185,129,0.5); color: #6ee7b7; }
    .celda.ocupado { background: rgba(245,158,11,0.15); border: 1.5px solid rgba(245,158,11,0.6); color: #fcd34d; }
    .celda.mantenimiento { background: rgba(100,116,139,0.15); border: 1.5px dashed rgba(100,116,139,0.5); color: #cbd5e1; }
    
    @media (max-width: 768px) { .acciones-grid { grid-template-columns: 1fr; } .hero-text h1 { font-size: 1.3rem; } }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="vigilante">
<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="hero">
        <div class="hero-inner">
            <div class="hero-left">
                <div class="avatar"><?= strtoupper(substr($nombre, 0, 1)) ?></div>
                <div class="hero-text">
                    <span class="hero-badge">◈ VIGILANTE</span>
                    <h1>Panel Operativo</h1>
                    <p><?= htmlspecialchars($nombre) ?> · <?= htmlspecialchars($empresa['nombre']) ?></p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Acciones -->
    <div class="acciones-grid">
        <a href="registro_entrada.php" class="accion-card">
            <div class="accion-icon">➕</div>
            <div class="accion-title">Registrar Entrada</div>
            <div class="accion-sub">Ingresar un vehículo</div>
        </a>
        <a href="modulo_pagos.php" class="accion-card cobrar">
            <div class="accion-icon">💳</div>
            <div class="accion-title">Cobrar</div>
            <div class="accion-sub">Caja rápida</div>
        </a>
        <a href="salida.php" class="accion-card salida">
            <div class="accion-icon">🚪</div>
            <div class="accion-title">Verificar Salida</div>
            <div class="accion-sub">Autorizar retiro</div>
        </a>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi"><div class="lbl">Celdas libres</div><div class="val"><?= $stats['libres'] ?></div></div>
        <div class="kpi warning"><div class="lbl">Celdas ocupadas</div><div class="val"><?= $stats['ocupados'] ?></div></div>
        <div class="kpi info"><div class="lbl">Vehículos dentro</div><div class="val"><?= $stats['activos'] ?></div></div>
        <div class="kpi warning"><div class="lbl">Pendientes de pago</div><div class="val"><?= $stats['pendientes_pago'] ?></div></div>
        <div class="kpi"><div class="lbl">Ingresos hoy</div><div class="val"><?= $stats['hoy'] ?></div></div>
        <div class="kpi info"><div class="lbl">Salidas hoy</div><div class="val"><?= $stats['salidas_hoy'] ?></div></div>
    </div>

    <!-- Vehículos dentro -->
    <div class="section">
        <h2>▤ Vehículos dentro del parqueadero (<?= count($activos) ?>)</h2>
        <p class="sub">Todos los vehículos activos con opción de cobrar en efectivo</p>
        <?php if (empty($activos)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">No hay vehículos dentro</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Placa</th><th>Modelo</th><th>Tipo</th><th>Celda</th><th>Ingreso</th><th>Tiempo</th><th>Pago</th><th>Acción</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activos as $a): 
                        $mins = (int)$a['minutos_dentro'];
                        $tiempo = floor($mins / 60) . 'h ' . ($mins % 60) . 'min';
                        $pagado = (int)$a['estado_pago'] === 1;
                    ?>
                        <tr>
                            <td><span class="placa"><?= htmlspecialchars($a['placa']) ?></span></td>
                            <td><?= htmlspecialchars($a['modelo'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(ucfirst($a['tipo_vehiculo'])) ?></td>
                            <td><span class="celda-badge"><?= htmlspecialchars($a['cupo_asignado']) ?></span></td>
                            <td style="font-family: monospace; font-size: 0.78rem;"><?= htmlspecialchars(substr($a['fecha_ingreso'], 11, 5)) ?></td>
                            <td><strong style="color: #fbbf24;"><?= $tiempo ?></strong></td>
                            <td><?php if ($pagado): ?><span class="estado-pagado">✓ Pagado</span><?php else: ?><span class="estado-pendiente">⏳ Pendiente</span><?php endif; ?></td>
                            <td>
                                <?php if (!$pagado): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('¿Cobrar en efectivo?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="cobrar_efectivo">
                                        <input type="hidden" name="registro_id" value="<?= (int)$a['id'] ?>">
                                        <button class="btn-cobrar">💵 Cobrar</button>
                                    </form>
                                <?php else: ?>
                                    <a href="pagar.php?placa=<?= urlencode($a['placa']) ?>" class="btn-cobrar" style="text-decoration: none;">Ver factura</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Mapa de celdas -->
    <div class="section">
        <h2>⬚ Estado del parqueadero</h2>
        <p class="sub">Vista en vivo de todas las celdas</p>
        <?php if (empty($celdas)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Sin celdas configuradas</p>
        <?php else: ?>
            <div class="celdas-grid">
                <?php foreach ($celdas as $c): ?>
                    <div class="celda <?= htmlspecialchars($c['estado']) ?>" title="<?= htmlspecialchars($c['codigo_cupo']) ?><?= $c['placa'] ? ' · ' . htmlspecialchars($c['placa']) : '' ?>"><?= htmlspecialchars($c['codigo_cupo']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Historial del día -->
    <div class="section">
        <h2>📜 Historial de hoy</h2>
        <p class="sub">Todos los movimientos registrados hoy</p>
        <?php if (empty($historial)): ?>
            <p style="text-align: center; color: #64748b; padding: 2rem;">Sin movimientos hoy</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Placa</th><th>Celda</th><th>Ingreso</th><th>Salida</th><th>Total</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historial as $h): ?>
                        <tr>
                            <td><span class="placa"><?= htmlspecialchars($h['placa']) ?></span></td>
                            <td><span class="celda-badge"><?= htmlspecialchars($h['cupo_asignado']) ?></span></td>
                            <td style="font-family: monospace; font-size: 0.78rem;"><?= htmlspecialchars(substr($h['fecha_ingreso'], 11, 5)) ?></td>
                            <td style="font-family: monospace; font-size: 0.78rem;"><?= $h['fecha_salida'] ? htmlspecialchars(substr($h['fecha_salida'], 11, 5)) : '—' ?></td>
                            <td style="color: #10b981; font-weight: 700;">$<?= number_format((float)$h['total_pagar'], 0, ',', '.') ?></td>
                            <td><?php if ($h['estado'] === 'activo'): ?><span class="estado-pendiente">● Activo</span><?php elseif ((int)$h['estado_pago'] === 1): ?><span class="estado-pagado">✓ Finalizado</span><?php else: ?><span style="color: #94a3b8;">Finalizado</span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>