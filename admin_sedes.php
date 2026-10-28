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

// Info empresa
$stmt = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$stmt->execute(['eid' => $eid]);
$empresa = $stmt->fetch() ?: ['nombre' => 'Mi Empresa', 'nit' => ''];

$stmt = $pdo->prepare("
    SELECT p.id, p.nombre, p.ciudad, p.direccion, p.niveles, p.estado, p.qr_token,
           (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :eid1) AS total_cupos,
           (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :eid2 AND estado = 'disponible') AS libres,
           (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :eid3 AND estado = 'ocupado') AS ocupados,
           (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :eid4 AND estado = 'mantenimiento') AS mantenimiento,
           (SELECT COUNT(*) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :eid5 AND estado = 'activo') AS activos_ahora,
           (SELECT COALESCE(SUM(total_pagar), 0) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :eid6 AND estado_pago = 1) AS recaudo_total,
           (SELECT COALESCE(SUM(total_pagar), 0) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :eid7 AND estado_pago = 1 AND DATE(fecha_salida) = CURDATE()) AS recaudo_hoy,
           (SELECT COALESCE(SUM(total_pagar), 0) FROM registros_parqueo WHERE parqueadero_id = p.id AND empresa_id = :eid8 AND estado_pago = 1 AND DATE(fecha_salida) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS recaudo_30dias
    FROM parqueaderos p
    WHERE p.empresa_id = :eid9
    ORDER BY p.nombre
");
$stmt->execute(['eid1' => $eid, 'eid2' => $eid, 'eid3' => $eid, 'eid4' => $eid, 'eid5' => $eid, 'eid6' => $eid, 'eid7' => $eid, 'eid8' => $eid, 'eid9' => $eid]);
$sedes = $stmt->fetchAll();

// Totales consolidados
$totales = [
    'sedes' => count($sedes),
    'cupos' => 0, 'libres' => 0, 'ocupados' => 0, 'mantenimiento' => 0,
    'activos' => 0,
    'recaudo_hoy' => 0, 'recaudo_30dias' => 0, 'recaudo_total' => 0,
];
foreach ($sedes as $s) {
    $totales['cupos'] += (int)$s['total_cupos'];
    $totales['libres'] += (int)$s['libres'];
    $totales['ocupados'] += (int)$s['ocupados'];
    $totales['mantenimiento'] += (int)$s['mantenimiento'];
    $totales['activos'] += (int)$s['activos_ahora'];
    $totales['recaudo_hoy'] += (float)$s['recaudo_hoy'];
    $totales['recaudo_30dias'] += (float)$s['recaudo_30dias'];
    $totales['recaudo_total'] += (float)$s['recaudo_total'];
}

$pctOcupacionGlobal = $totales['cupos'] > 0 ? round(($totales['ocupados'] / $totales['cupos']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mis Sedes - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=52">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1300px; margin: 0 auto; padding: 0 1.5rem; }

    /* HEADER */
    .page-header {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05));
        border: 1.5px solid rgba(16, 185, 129, 0.3);
        border-radius: 22px;
        padding: 1.75rem 2rem;
        margin-bottom: 2rem;
    }
    .page-header .badge {
        display: inline-block;
        padding: 0.3rem 0.85rem;
        background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        margin-bottom: 0.6rem;
    }
    .page-header h1 {
        font-size: clamp(1.5rem, 3vw, 2.1rem);
        font-weight: 900;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0 0 0.3rem;
        letter-spacing: -0.02em;
    }
    .page-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }

    /* CONSOLIDADO TOP */
    .consolidado {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2.5rem;
    }
    .cons-item {
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.2);
        border-radius: 18px;
        padding: 1.4rem;
        position: relative;
        overflow: hidden;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .cons-item::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #10b981, #06b6d4);
    }
    .cons-item:hover {
        transform: translateY(-4px);
        border-color: rgba(16, 185, 129, 0.55);
        box-shadow: 0 20px 50px rgba(16, 185, 129, 0.2);
    }
    .cons-item .lbl { font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.4rem; }
    .cons-item .val {
        font-size: 1.85rem; font-weight: 900;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1;
    }
    .cons-item.info::before { background: linear-gradient(90deg, #06b6d4, #3b82f6); }
    .cons-item.info .val { background: linear-gradient(135deg, #06b6d4, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .cons-item.warning::before { background: linear-gradient(90deg, #f59e0b, #f97316); }
    .cons-item.warning .val { background: linear-gradient(135deg, #f59e0b, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

    /* ACCIONES GLOBALES */
    .acciones-globales {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
        margin-bottom: 2.5rem;
    }
    .accion-global {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.18);
        border-radius: 18px;
        text-decoration: none;
        color: #f1f5f9;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .accion-global:hover {
        transform: translateY(-4px);
        border-color: rgba(16, 185, 129, 0.55);
        box-shadow: 0 20px 50px rgba(16, 185, 129, 0.2);
    }
    .accion-global .ico {
        width: 50px; height: 50px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 14px;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.35);
        color: #10b981;
        font-size: 1.4rem;
        flex-shrink: 0;
        transition: all 0.4s ease;
    }
    .accion-global:hover .ico {
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        transform: scale(1.1) rotate(-6deg);
    }
    .accion-global .txt strong { display: block; font-size: 0.95rem; font-weight: 800; margin-bottom: 0.15rem; }
    .accion-global .txt span { font-size: 0.75rem; color: #64748b; }

    /* TARJETAS DE SEDES */
    .sedes-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
        gap: 1.5rem;
    }
    .sede-card {
        background: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 20px;
        padding: 1.75rem;
        position: relative;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    .sede-card::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 4px;
        background: linear-gradient(180deg, #10b981, #06b6d4);
    }
    .sede-card.estado-mantenimiento::before { background: linear-gradient(180deg, #f59e0b, #f97316); }
    .sede-card.estado-inactivo::before { background: linear-gradient(180deg, #64748b, #475569); }
    .sede-card:hover {
        border-color: rgba(16, 185, 129, 0.4);
        transform: translateY(-3px);
        box-shadow: 0 20px 50px rgba(16, 185, 129, 0.15);
    }
    .sede-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }
    .sede-head h3 {
        font-size: 1.15rem;
        font-weight: 800;
        color: #f1f5f9;
        margin: 0 0 0.3rem;
    }
    .sede-meta {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        font-size: 0.78rem;
        color: #64748b;
    }
    .estado-badge {
        display: inline-block;
        padding: 0.25rem 0.7rem;
        border-radius: 100px;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .estado-badge.activo { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4); }
    .estado-badge.mantenimiento { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.4); }
    .estado-badge.inactivo { background: rgba(100, 116, 139, 0.2); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.4); }

    .sede-kpis {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
        padding: 1rem 0;
        border-top: 1px solid rgba(255,255,255,0.06);
        border-bottom: 1px solid rgba(255,255,255,0.06);
        margin-bottom: 1rem;
    }
    .sede-kpi { text-align: center; }
    .sede-kpi .num { font-size: 1.35rem; font-weight: 900; color: #10b981; line-height: 1; }
    .sede-kpi .num.warning { color: #f59e0b; }
    .sede-kpi .num.info { color: #06b6d4; }
    .sede-kpi .lbl { font-size: 0.62rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.25rem; font-weight: 700; }

    .barra-ocupacion {
        display: flex;
        height: 8px;
        border-radius: 100px;
        overflow: hidden;
        background: rgba(255,255,255,0.05);
        margin-bottom: 0.5rem;
    }
    .barra-ocupacion .fill-libre { background: linear-gradient(90deg, #10b981, #059669); transition: width 0.8s ease; }
    .barra-ocupacion .fill-ocupado { background: linear-gradient(90deg, #f59e0b, #f97316); transition: width 0.8s ease; }
    .barra-ocupacion .fill-mant { background: linear-gradient(90deg, #64748b, #475569); transition: width 0.8s ease; }

    .sede-recaudo {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        margin-bottom: 0.9rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .sede-recaudo .lbl { font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; }
    .sede-recaudo .val { font-size: 1rem; font-weight: 800; color: #10b981; font-family: 'JetBrains Mono', monospace; }

    .sede-acciones {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
    }
    .sede-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        padding: 0.7rem 0.5rem;
        border-radius: 10px;
        font-size: 0.78rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.3s ease;
        border: 1.5px solid;
        cursor: pointer;
    }
    .sede-btn.outline {
        background: rgba(16, 185, 129, 0.12);
        border-color: #10b981;
        color: #10b981;
    }
    .sede-btn.outline:hover { background: #10b981; color: #030712; }
    .sede-btn.secondary {
        background: transparent;
        border-color: rgba(148, 163, 184, 0.3);
        color: #94a3b8;
    }
    .sede-btn.secondary:hover { border-color: #10b981; color: #10b981; }

    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background: rgba(15, 23, 42, 0.5);
        border: 1px dashed rgba(16, 185, 129, 0.3);
        border-radius: 20px;
    }
    .empty-state .ico { font-size: 3rem; color: #10b981; opacity: 0.4; display: block; margin-bottom: 1rem; }
    .empty-state p { color: #64748b; margin-bottom: 1.5rem; }

    @media (max-width: 768px) {
        .sedes-grid { grid-template-columns: 1fr; }
        .acciones-globales { grid-template-columns: 1fr; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <!-- HEADER -->
    <div class="page-header">
        <span class="badge">◉ <?= htmlspecialchars($empresa['nombre']) ?></span>
        <h1>Mis Sedes</h1>
        <p>Panel consolidado de todas tus sedes · Bienvenido, <strong style="color: #10b981;"><?= htmlspecialchars($_SESSION['nombre']) ?></strong></p>
        <a href="admin_crear_sede.php" style="display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 1rem; padding: 0.85rem 1.75rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; text-decoration: none; border-radius: 12px; font-weight: 800; font-size: 0.92rem; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.35);">+ Crear nueva sede</a>
    </div>

    <!-- CONSOLIDADO -->
    <div class="consolidado">
        <div class="cons-item">
            <div class="lbl">Total de sedes</div>
            <div class="val"><?= $totales['sedes'] ?></div>
        </div>
        <div class="cons-item">
            <div class="lbl">Celdas totales</div>
            <div class="val"><?= $totales['cupos'] ?></div>
        </div>
        <div class="cons-item warning">
            <div class="lbl">Ocupación global</div>
            <div class="val"><?= $pctOcupacionGlobal ?>%</div>
        </div>
        <div class="cons-item info">
            <div class="lbl">Vehículos activos</div>
            <div class="val"><?= $totales['activos'] ?></div>
        </div>
        <div class="cons-item">
            <div class="lbl">Recaudo hoy</div>
            <div class="val">$<?= number_format($totales['recaudo_hoy'], 0, ',', '.') ?></div>
        </div>
        <div class="cons-item info">
            <div class="lbl">Recaudo 30 días</div>
            <div class="val">$<?= number_format($totales['recaudo_30dias'], 0, ',', '.') ?></div>
        </div>
        <div class="cons-item">
            <div class="lbl">Recaudo histórico</div>
            <div class="val">$<?= number_format($totales['recaudo_total'], 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- ACCIONES GLOBALES -->
    <div class="acciones-globales">
        <a href="admin_comparar.php" class="accion-global">
            <div class="ico">◐</div>
            <div class="txt">
                <strong>Comparar sedes</strong>
                <span>Análisis comparativo detallado</span>
            </div>
        </a>
        <a href="dashboard_admin.php" class="accion-global">
            <div class="ico">⌂</div>
            <div class="txt">
                <strong>Panel general</strong>
                <span>Vista de operación diaria</span>
            </div>
        </a>
        <a href="config.php" class="accion-global">
            <div class="ico">⚙</div>
            <div class="txt">
                <strong>Configuración</strong>
                <span>Tarifas, SMTP, usuarios</span>
            </div>
        </a>
        <a href="admin.php" class="accion-global">
            <div class="ico">▢</div>
            <div class="txt">
                <strong>Control de celdas</strong>
                <span>Estado operativo</span>
            </div>
        </a>
    </div>

    <!-- SEDES -->
    <h2 style="font-size: 1.3rem; font-weight: 800; color: #f1f5f9; margin-bottom: 1.25rem;">
        ◉ Sedes activas (<?= count($sedes) ?>)
    </h2>

    <?php if (empty($sedes)): ?>
        <div class="empty-state">
            <span class="ico">⬚</span>
            <p>Aún no tienes sedes registradas.</p>
            <a href="config.php" class="cyber-btn-primary">Configurar primera sede</a>
        </div>
    <?php else: ?>
        <div class="sedes-grid">
            <?php foreach ($sedes as $s):
                $totalCupos = max(1, (int)$s['total_cupos']);
                $pctLibres = round(((int)$s['libres'] / $totalCupos) * 100);
                $pctOcupados = round(((int)$s['ocupados'] / $totalCupos) * 100);
                $pctMant = round(((int)$s['mantenimiento'] / $totalCupos) * 100);
                $estadoClase = 'estado-' . $s['estado'];
            ?>
                <div class="sede-card <?= $estadoClase ?>">
                    <div class="sede-head">
                        <div style="flex: 1;">
                            <h3><?= htmlspecialchars($s['nombre']) ?></h3>
                            <div class="sede-meta">
                                <?php if (!empty($s['ciudad'])): ?><span>◆ <?= htmlspecialchars($s['ciudad']) ?></span><?php endif; ?>
                                <?php if (!empty($s['niveles'])): ?><span>◆ <?= (int)$s['niveles'] ?> nivel(es)</span><?php endif; ?>
                                <span>◆ <?= (int)$s['total_cupos'] ?> celdas</span>
                            </div>
                        </div>
                        <span class="estado-badge <?= htmlspecialchars($s['estado']) ?>">
                            <?= htmlspecialchars(ucfirst($s['estado'])) ?>
                        </span>
                    </div>

                    <div class="sede-kpis">
                        <div class="sede-kpi">
                            <div class="num"><?= (int)$s['libres'] ?></div>
                            <div class="lbl">Libres</div>
                        </div>
                        <div class="sede-kpi">
                            <div class="num warning"><?= (int)$s['ocupados'] ?></div>
                            <div class="lbl">Ocupadas</div>
                        </div>
                        <div class="sede-kpi">
                            <div class="num info"><?= (int)$s['activos_ahora'] ?></div>
                            <div class="lbl">Vehículos</div>
                        </div>
                    </div>

                    <div class="barra-ocupacion">
                        <div class="fill-libre" style="width: <?= $pctLibres ?>%"></div>
                        <div class="fill-ocupado" style="width: <?= $pctOcupados ?>%"></div>
                        <div class="fill-mant" style="width: <?= $pctMant ?>%"></div>
                    </div>
                    <div style="display: flex; gap: 1rem; font-size: 0.7rem; color: #94a3b8; margin-bottom: 1rem;">
                        <span>● <?= $pctLibres ?>% libres</span>
                        <span>● <?= $pctOcupados ?>% ocupadas</span>
                        <?php if ($pctMant > 0): ?><span>● <?= $pctMant ?>% mant.</span><?php endif; ?>
                    </div>

                    <div class="sede-recaudo">
                        <span class="lbl">Recaudo hoy</span>
                        <span class="val">$<?= number_format((float)$s['recaudo_hoy'], 0, ',', '.') ?></span>
                    </div>
                    <div class="sede-recaudo" style="border-top: 1px solid rgba(255,255,255,0.06);">
                        <span class="lbl">Recaudo histórico</span>
                        <span class="val" style="color: #06b6d4;">$<?= number_format((float)$s['recaudo_total'], 0, ',', '.') ?></span>
                    </div>

                    <div class="sede-acciones">
                        <a href="dashboard_admin.php?parqueadero_id=<?= (int)$s['id'] ?>" class="sede-btn outline">
                            ⌂ Abrir sede
                        </a>
                        <a href="admin.php?parqueadero_id=<?= (int)$s['id'] ?>" class="sede-btn secondary">
                            ▢ Ver celdas
                        </a>
                        <a href="ver_qr.php?parqueadero_id=<?= (int)$s['id'] ?>" class="sede-btn secondary">
                            ▦ QR
                        </a>
                        <a href="admin_comparar.php?focus=<?= (int)$s['id'] ?>" class="sede-btn secondary">
                            ◐ Comparar
                        </a>
                        <a href="admin_editar_sede.php?id=<?= (int)$s['id'] ?>" class="sede-btn outline" style="grid-column: span 2;">✎ Editar propiedades</a>
                    </div>
                        <a href="admin_editar_sede.php?id=<?= (int)$s['id'] ?>" class="sede-btn outline" style="grid-column: span 2;">✎ Editar propiedades</a>
                </div>
            <?php endforeach; ?>
                        <a href="admin_editar_sede.php?id=<?= (int)$s['id'] ?>" class="sede-btn outline" style="grid-column: span 2;">✎ Editar propiedades</a>
        </div>
    <?php endif; ?>

                        <a href="admin_editar_sede.php?id=<?= (int)$s['id'] ?>" class="sede-btn outline" style="grid-column: span 2;">✎ Editar propiedades</a>
</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
