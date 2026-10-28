<?php
session_start();
require_once 'conexion.php';
require_once 'helpers.php';
require_once 'csrf.php';
require_once 'helpers_entrada.php';
send_security_headers();

require_login();
$eid = require_empresa();

$uid = (int)$_SESSION['usuario_id'];
$nombreUsuario = $_SESSION['nombre'] ?? 'Cliente';

// Vehículo activo (si tiene uno parqueado ahora)
$vehiculoActivo = null;
try {
    $stmt = $pdo->prepare("
        SELECT r.*, p.nombre AS parqueadero_nombre
        FROM registros_parqueo r
        LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
        WHERE r.empresa_id = :eid AND r.user_id = :uid AND r.estado = 'activo'
        ORDER BY r.id DESC LIMIT 1
    ");
    $stmt->execute(['eid' => $eid, 'uid' => $uid]);
    $vehiculoActivo = $stmt->fetch() ?: null;
} catch (\Throwable $e) {}

// Mis vehículos registrados
$vehiculos = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM user_vehiculo 
        WHERE empresa_id = :eid AND user_id = :uid 
        ORDER BY fecha_creacion DESC
        LIMIT 10
    ");
    $stmt->execute(['eid' => $eid, 'uid' => $uid]);
    $vehiculos = $stmt->fetchAll();
} catch (\Throwable $e) {}

// Historial (últimos 10)
$historial = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, p.nombre AS parqueadero_nombre
        FROM registros_parqueo r
        LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
        WHERE r.empresa_id = :eid AND r.user_id = :uid
        ORDER BY r.id DESC
        LIMIT 10
    ");
    $stmt->execute(['eid' => $eid, 'uid' => $uid]);
    $historial = $stmt->fetchAll();
} catch (\Throwable $e) {}

// KPIs
$stats = ['total_visitas' => 0, 'gasto_total' => 0, 'horas_total' => 0, 'vehiculos' => count($vehiculos)];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_visitas,
            COALESCE(SUM(CASE WHEN estado_pago = 1 THEN total_pagar ELSE 0 END), 0) AS gasto_total,
            COALESCE(SUM(horas), 0) AS horas_total
        FROM registros_parqueo
        WHERE empresa_id = :eid AND user_id = :uid
    ");
    $stmt->execute(['eid' => $eid, 'uid' => $uid]);
    $r = $stmt->fetch();
    $stats['total_visitas'] = (int)($r['total_visitas'] ?? 0);
    $stats['gasto_total'] = (float)($r['gasto_total'] ?? 0);
    $stats['horas_total'] = (int)($r['horas_total'] ?? 0);
} catch (\Throwable $e) {}

// Info de empresa
$empresa = ['nombre' => 'Mi Empresa'];
try {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :eid");
    $stmt->execute(['eid' => $eid]);
    $empresa['nombre'] = $stmt->fetchColumn() ?: 'Mi Empresa';
} catch (\Throwable $e) {}

// Cálculo de tiempo del vehículo activo
$tiempoActivo = null;
if ($vehiculoActivo) {
    $ingreso = new DateTime($vehiculoActivo['fecha_ingreso']);
    $ahora = new DateTime();
    $diff = $ingreso->diff($ahora);
    $minutos = ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
    $horas = max(1, ceil($minutos / 60));

    // Tarifa
    $tarifa = 4000.0;
    try {
        $stmt = $pdo->prepare("
            SELECT precio FROM tarifas
            WHERE empresa_id = :eid AND tipo_vehiculo = :tv AND tipo_tarifa = 'hora' AND activo = 1
            ORDER BY vigente_desde DESC LIMIT 1
        ");
        $stmt->execute(['eid' => $eid, 'tv' => $vehiculoActivo['tipo_vehiculo']]);
        $t = $stmt->fetchColumn();
        if ($t) $tarifa = (float)$t;
    } catch (\Throwable $e) {}

    $tiempoActivo = [
        'minutos' => $minutos,
        'texto' => floor($minutos / 60) . 'h ' . ($minutos % 60) . 'min',
        'horas' => $horas,
        'tarifa' => $tarifa,
        'estimado' => $horas * $tarifa,
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi Cuenta - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=42">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }

    /* HEADER */
    .cliente-hero {
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.25);
        border-radius: 24px;
        padding: 2rem 2rem 1.75rem;
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .cliente-hero::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #10b981, #06b6d4);
        background-size: 200% 100%;
        animation: gradientShift 4s ease infinite;
    }
    @keyframes gradientShift {
        0%, 100% { background-position: 0% 50%; }
        50%      { background-position: 100% 50%; }
    }
    .cliente-hero::after {
        content: '';
        position: absolute;
        top: -50%; right: -20%;
        width: 400px; height: 400px;
        background: radial-gradient(circle, rgba(16, 185, 129, 0.08) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
    }
    .hero-inner {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }
    .hero-left {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        flex-wrap: wrap;
    }
    .hero-avatar {
        width: 72px; height: 72px;
        border-radius: 20px;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #030712;
        font-weight: 900;
        font-size: 2rem;
        box-shadow: 0 0 40px rgba(16, 185, 129, 0.5);
        flex-shrink: 0;
    }
    .hero-text h1 {
        font-size: clamp(1.4rem, 3vw, 2rem);
        font-weight: 900;
        color: #f1f5f9;
        margin: 0 0 0.35rem;
        letter-spacing: -0.03em;
    }
    .hero-text p { color: #94a3b8; font-size: 0.9rem; margin: 0; }
    .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.9rem;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.75rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

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
        transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
        overflow: hidden;
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) backwards;
    }
    .kpi-card:nth-child(1) { animation-delay: 0.05s; }
    .kpi-card:nth-child(2) { animation-delay: 0.1s; }
    .kpi-card:nth-child(3) { animation-delay: 0.15s; }
    .kpi-card:nth-child(4) { animation-delay: 0.2s; }
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
    .kpi-icon {
        width: 44px; height: 44px;
        border-radius: 12px;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        color: #10b981;
        margin-bottom: 0.85rem;
    }
    .kpi-lbl { font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.4rem; }
    .kpi-val {
        font-size: 1.9rem; font-weight: 900;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1;
    }
    .kpi-card.info .kpi-icon { background: rgba(6, 182, 212, 0.12); border-color: rgba(6, 182, 212, 0.3); color: #06b6d4; }
    .kpi-card.info .kpi-val { background: linear-gradient(135deg, #06b6d4, #3b82f6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi-card.warning .kpi-icon { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); color: #f59e0b; }
    .kpi-card.warning .kpi-val { background: linear-gradient(135deg, #f59e0b, #f97316); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .kpi-card.purple .kpi-icon { background: rgba(167, 139, 250, 0.12); border-color: rgba(167, 139, 250, 0.3); color: #a78bfa; }
    .kpi-card.purple .kpi-val { background: linear-gradient(135deg, #a78bfa, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

    /* VEHÍCULO ACTIVO */
    .activo-card {
        background: linear-gradient(160deg, rgba(16, 185, 129, 0.12), rgba(6, 182, 212, 0.06));
        border: 2px solid rgba(16, 185, 129, 0.4);
        border-radius: 22px;
        padding: 1.75rem;
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.25s backwards;
    }
    .activo-card::before {
        content: '';
        position: absolute;
        top: -30%; right: -10%;
        width: 300px; height: 300px;
        background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
        animation: pulseGlow 4s ease-in-out infinite;
    }
    @keyframes pulseGlow {
        0%, 100% { opacity: 0.4; transform: scale(1); }
        50%      { opacity: 0.7; transform: scale(1.1); }
    }
    .activo-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }
    .activo-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        border-radius: 100px;
        color: #030712;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.05em;
        box-shadow: 0 0 25px rgba(16, 185, 129, 0.5);
    }
    .activo-badge::before {
        content: '';
        width: 8px; height: 8px;
        border-radius: 50%;
        background: #030712;
        animation: pulseDot 1.5s ease infinite;
    }
    @keyframes pulseDot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.5; transform: scale(0.7); }
    }
    .activo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
        position: relative;
        z-index: 1;
    }
    .activo-item {
        background: rgba(2, 6, 23, 0.6);
        border-radius: 14px;
        padding: 1rem;
        text-align: center;
    }
    .activo-item .lbl { font-size: 0.68rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; margin-bottom: 0.4rem; }
    .activo-item .val {
        font-size: 1.15rem;
        font-weight: 800;
        color: #10b981;
        font-family: 'JetBrains Mono', monospace;
        word-break: break-all;
    }
    .activo-item.destacado .val {
        font-size: 1.6rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .activo-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.6rem;
        position: relative;
        z-index: 1;
    }
    .btn-activo {
        padding: 1rem;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.88rem;
        text-align: center;
        text-decoration: none;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }
    .btn-activo.outline {
        background: rgba(16, 185, 129, 0.12);
        border: 1.5px solid #10b981;
        color: #10b981;
    }
    .btn-activo.outline:hover { background: #10b981; color: #030712; }
    .btn-activo.primary {
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
    }
    .btn-activo.primary:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.6); }

    /* SIN VEHÍCULO ACTIVO */
    .empty-card {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1.5px dashed rgba(16, 185, 129, 0.3);
        border-radius: 22px;
        padding: 2.5rem 2rem;
        text-align: center;
        margin-bottom: 2rem;
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.25s backwards;
    }
    .empty-card .ico {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 80px; height: 80px;
        border-radius: 50%;
        background: rgba(16, 185, 129, 0.1);
        border: 2px dashed rgba(16, 185, 129, 0.4);
        font-size: 2rem;
        color: #10b981;
        margin-bottom: 1.25rem;
    }
    .empty-card h2 { font-size: 1.25rem; font-weight: 800; color: #f1f5f9; margin: 0 0 0.5rem; }
    .empty-card p { color: #94a3b8; font-size: 0.88rem; margin: 0 0 1.5rem; max-width: 400px; margin-left: auto; margin-right: auto; line-height: 1.5; }
    .empty-card .btn-primary {
        display: inline-block;
        padding: 1rem 2rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.95rem;
        box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .empty-card .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.6); }

    /* SECCIONES */
    .section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.3s backwards;
    }
    .section h2 {
        font-size: 1.15rem;
        font-weight: 800;
        color: #f1f5f9;
        margin: 0 0 0.35rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .section .sub {
        color: #64748b;
        font-size: 0.82rem;
        margin: 0 0 1.25rem;
    }

    /* MIS VEHÍCULOS */
    .vehiculos-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 0.85rem;
    }
    .vehiculo-mini {
        background: rgba(2, 6, 23, 0.5);
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-radius: 14px;
        padding: 1.1rem;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    .vehiculo-mini::before {
        content: '';
        position: absolute;
        left: 0; top: 0; bottom: 0;
        width: 3px;
        background: linear-gradient(180deg, #10b981, #06b6d4);
    }
    .vehiculo-mini:hover {
        border-color: rgba(16, 185, 129, 0.5);
        transform: translateY(-3px);
        box-shadow: 0 10px 30px rgba(16, 185, 129, 0.15);
    }
    .vehiculo-placa {
        display: inline-block;
        padding: 0.3rem 0.7rem;
        background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 8px;
        color: #10b981;
        font-family: 'JetBrains Mono', monospace;
        font-weight: 800;
        font-size: 0.95rem;
        letter-spacing: 0.05em;
        margin-bottom: 0.6rem;
    }
    .vehiculo-modelo { color: #cbd5e1; font-size: 0.85rem; margin-bottom: 0.4rem; }
    .vehiculo-tipo {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.72rem;
        color: #94a3b8;
        padding: 0.15rem 0.5rem;
        background: rgba(100, 116, 139, 0.15);
        border-radius: 6px;
    }

    /* HISTORIAL */
    .historial-list { display: flex; flex-direction: column; gap: 0.6rem; }
    .historial-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.15rem;
        background: rgba(2, 6, 23, 0.5);
        border-radius: 12px;
        gap: 1rem;
        flex-wrap: wrap;
        transition: all 0.25s ease;
        border-left: 3px solid transparent;
    }
    .historial-item:hover {
        background: rgba(2, 6, 23, 0.7);
        border-left-color: #10b981;
    }
    .historial-item.activo {
        border-left-color: #f59e0b;
        background: rgba(245, 158, 11, 0.06);
    }
    .historial-left {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        flex: 1;
        min-width: 0;
    }
    .historial-placa {
        font-family: 'JetBrains Mono', monospace;
        font-weight: 800;
        color: #10b981;
        font-size: 0.88rem;
        letter-spacing: 0.05em;
        flex-shrink: 0;
    }
    .historial-info {
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
        min-width: 0;
    }
    .historial-info .h-fecha { font-size: 0.78rem; color: #cbd5e1; }
    .historial-info .h-celda { font-size: 0.72rem; color: #64748b; }
    .historial-right { display: flex; flex-direction: column; align-items: flex-end; gap: 0.3rem; }
    .historial-total { font-weight: 800; color: #10b981; font-size: 0.95rem; }
    .historial-estado {
        font-size: 0.68rem;
        padding: 0.15rem 0.55rem;
        border-radius: 100px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .historial-estado.pagado { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; }
    .historial-estado.activo { background: rgba(245, 158, 11, 0.15); color: #fcd34d; }
    .historial-estado.pendiente { background: rgba(148, 163, 184, 0.15); color: #cbd5e1; }

    /* EMPTY STATE */
    .empty-mini {
        text-align: center;
        padding: 2rem;
        color: #64748b;
        font-size: 0.85rem;
    }
    .empty-mini .ico {
        display: block;
        font-size: 2rem;
        opacity: 0.4;
        margin-bottom: 0.75rem;
    }

    @media (max-width: 768px) {
        .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        .activo-actions { grid-template-columns: 1fr; }
        .historial-item { flex-direction: column; align-items: stretch; }
        .historial-right { align-items: flex-start; }
        .hero-inner { flex-direction: column; align-items: flex-start; }
        .hero-avatar { width: 60px; height: 60px; font-size: 1.6rem; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="cliente">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <!-- HERO -->
    <div class="cliente-hero">
        <div class="hero-inner">
            <div class="hero-left">
                <div class="hero-avatar"><?= strtoupper(substr($nombreUsuario, 0, 1)) ?></div>
                <div class="hero-text">
                    <span class="hero-badge">◉ CLIENTE</span>
                    <h1>Hola, <?= htmlspecialchars($nombreUsuario) ?> 👋</h1>
                    <p><?= htmlspecialchars($empresa['nombre']) ?> · Bienvenido a tu panel personal</p>
                </div>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon">🚗</div>
            <div class="kpi-lbl">Mis vehículos</div>
            <div class="kpi-val" data-counter="<?= $stats['vehiculos'] ?>">0</div>
        </div>
        <div class="kpi-card info">
            <div class="kpi-icon">📊</div>
            <div class="kpi-lbl">Visitas totales</div>
            <div class="kpi-val" data-counter="<?= $stats['total_visitas'] ?>">0</div>
        </div>
        <div class="kpi-card warning">
            <div class="kpi-icon">⏱️</div>
            <div class="kpi-lbl">Horas parqueado</div>
            <div class="kpi-val" data-counter="<?= $stats['horas_total'] ?>">0</div>
        </div>
        <div class="kpi-card purple">
            <div class="kpi-icon">💳</div>
            <div class="kpi-lbl">Gasto total</div>
            <div class="kpi-val">$<?= number_format($stats['gasto_total'], 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- VEHÍCULO ACTIVO / SIN VEHÍCULO -->
    <?php if ($vehiculoActivo && $tiempoActivo): ?>
        <div class="activo-card">
            <div class="activo-header">
                <div>
                    <span class="activo-badge">● PARQUEADO AHORA</span>
                </div>
                <div style="color: #94a3b8; font-size: 0.8rem;">
                    Ingresó: <?= htmlspecialchars(substr($vehiculoActivo['fecha_ingreso'], 11, 5)) ?>
                </div>
            </div>

            <div class="activo-grid">
                <div class="activo-item">
                    <div class="lbl">Placa</div>
                    <div class="val"><?= htmlspecialchars($vehiculoActivo['placa']) ?></div>
                </div>
                <div class="activo-item destacado">
                    <div class="lbl">Tu celda</div>
                    <div class="val"><?= htmlspecialchars($vehiculoActivo['cupo_asignado']) ?></div>
                </div>
                <div class="activo-item">
                    <div class="lbl">Tiempo</div>
                    <div class="val" style="color: #fbbf24;"><?= $tiempoActivo['texto'] ?></div>
                </div>
                <div class="activo-item">
                    <div class="lbl">Estimado</div>
                    <div class="val">$<?= number_format($tiempoActivo['estimado'], 0, ',', '.') ?></div>
                </div>
            </div>

            <div class="activo-actions">
                <a href="mi_parqueadero.php?placa=<?= urlencode($vehiculoActivo['placa']) ?>" class="btn-activo outline">
                    🗺️ Ver ubicación
                </a>
                <a href="pagar.php?placa=<?= urlencode($vehiculoActivo['placa']) ?>" class="btn-activo primary">
                    💳 Salir y pagar
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-card">
            <div class="ico">🚗</div>
            <h2>No tienes un vehículo parqueado</h2>
            <p>Cuando ingreses al parqueadero, verás aquí tu celda asignada y toda la información.</p>
            <a href="mi_parqueadero.php" class="btn-primary">➕ Registrar entrada (Escanea el QR del parqueadero)</a>
        </div>
    <?php endif; ?>

    <!-- MIS VEHÍCULOS -->
    <div class="section">
        <h2>🚗 Mis vehículos</h2>
        <p class="sub">Los vehículos que has registrado en este parqueadero</p>

        <?php if (empty($vehiculos)): ?>
            <div class="empty-mini">
                <span class="ico">📭</span>
                Aún no has registrado vehículos. Al hacer tu primera entrada aparecerán aquí.
            </div>
        <?php else: ?>
            <div class="vehiculos-grid">
                <?php foreach ($vehiculos as $v): ?>
                    <div class="vehiculo-mini">
                        <div class="vehiculo-placa"><?= htmlspecialchars($v['placa']) ?></div>
                        <div class="vehiculo-modelo"><?= htmlspecialchars($v['modelo'] ?? 'Sin modelo') ?></div>
                        <div class="vehiculo-tipo">
                            <?= $v['tipo_vehiculo'] === 'moto' ? '🏍️' : '🚗' ?>
                            <?= htmlspecialchars(ucfirst($v['tipo_vehiculo'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- HISTORIAL -->
    <div class="section">
        <h2>📜 Historial reciente</h2>
        <p class="sub">Tus últimas visitas a este parqueadero</p>

        <?php if (empty($historial)): ?>
            <div class="empty-mini">
                <span class="ico">📭</span>
                Sin historial todavía. Registra tu primer vehículo para empezar.
            </div>
        <?php else: ?>
            <div class="historial-list">
                <?php foreach ($historial as $h): ?>
                    <?php 
                        $esActivo = $h['estado'] === 'activo';
                        $esPagado = (int)$h['estado_pago'] === 1;
                    ?>
                    <div class="historial-item <?= $esActivo ? 'activo' : '' ?>">
                        <div class="historial-left">
                            <div class="historial-placa"><?= htmlspecialchars($h['placa']) ?></div>
                            <div class="historial-info">
                                <div class="h-fecha">
                                    <?= htmlspecialchars(substr($h['fecha_ingreso'], 0, 16)) ?>
                                    <?php if (!empty($h['parqueadero_nombre'])): ?>
                                        · <?= htmlspecialchars($h['parqueadero_nombre']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="h-celda">
                                    Celda <?= htmlspecialchars($h['cupo_asignado']) ?>
                                    <?php if (!empty($h['horas'])): ?>
                                        · <?= (int)$h['horas'] ?>h
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="historial-right">
                            <div class="historial-total">
                                $<?= number_format((float)$h['total_pagar'], 0, ',', '.') ?>
                            </div>
                            <?php if ($esActivo): ?>
                                <span class="historial-estado activo">● En curso</span>
                            <?php elseif ($esPagado): ?>
                                <span class="historial-estado pagado">✓ Pagado</span>
                            <?php else: ?>
                                <span class="historial-estado pendiente">Pendiente</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>