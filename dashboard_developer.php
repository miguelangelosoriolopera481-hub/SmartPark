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

// Stats globales rápidos
$stats = ['empresas_total'=>0,'empresas_pendientes'=>0,'parqueaderos'=>0,'usuarios'=>0,'cupos'=>0,'activos'=>0,'registros_total'=>0,'recaudo'=>0,'reportes_ia'=>0];
try { $stats['empresas_total'] = (int)$pdo->query("SELECT COUNT(*) FROM empresas")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['empresas_pendientes'] = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado_aprobacion='pendiente'")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['parqueaderos'] = (int)$pdo->query("SELECT COUNT(*) FROM parqueaderos")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['usuarios'] = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['cupos'] = (int)$pdo->query("SELECT COUNT(*) FROM cupos")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['activos'] = (int)$pdo->query("SELECT COUNT(*) FROM registros_parqueo WHERE estado='activo'")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['registros_total'] = (int)$pdo->query("SELECT COUNT(*) FROM registros_parqueo")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['recaudo'] = (float)$pdo->query("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE estado_pago=1")->fetchColumn(); } catch (\Throwable $e) {}
try { $stats['reportes_ia'] = (int)$pdo->query("SELECT COUNT(*) FROM ia_reportes WHERE estado='pendiente'")->fetchColumn(); } catch (\Throwable $e) {}

// Últimas empresas para vista rápida
$empresasRecientes = [];
try {
    $empresasRecientes = $pdo->query("
        SELECT id, nombre, estado_aprobacion, fecha_creacion
        FROM empresas
        ORDER BY fecha_creacion DESC
        LIMIT 5
    ")->fetchAll();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Developer - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=60">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }

    /* HERO */
    .dev-hero {
        background: linear-gradient(135deg, rgba(147, 51, 234, 0.12), rgba(6, 182, 212, 0.06));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(167, 139, 250, 0.3);
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
    .dev-hero::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #a78bfa, #06b6d4);
        background-size: 200% 100%;
        animation: gradientShift 4s ease infinite;
    }
    @keyframes gradientShift {
        0%, 100% { background-position: 0% 50%; }
        50%      { background-position: 100% 50%; }
    }
    .dev-hero::after {
        content: '';
        position: absolute;
        top: -50%; right: -20%;
        width: 400px; height: 400px;
        background: radial-gradient(circle, rgba(167, 139, 250, 0.1) 0%, transparent 70%);
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
    .hero-left { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
    .hero-avatar {
        width: 72px; height: 72px;
        border-radius: 20px;
        background: linear-gradient(135deg, #a78bfa, #06b6d4);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #030712;
        font-weight: 900;
        font-size: 2rem;
        box-shadow: 0 0 40px rgba(167, 139, 250, 0.5);
        flex-shrink: 0;
    }
    .hero-text h1 {
        font-size: clamp(1.6rem, 3.5vw, 2.4rem);
        font-weight: 900;
        color: #f1f5f9;
        margin: 0 0 0.35rem;
        letter-spacing: -0.03em;
    }
    .hero-text p { color: #94a3b8; font-size: 0.92rem; margin: 0; }
    .dev-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.9rem;
        background: rgba(167, 139, 250, 0.15);
        border: 1px solid rgba(167, 139, 250, 0.5);
        border-radius: 100px;
        color: #a78bfa;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        margin-bottom: 0.6rem;
    }
    .dev-badge::before {
        content: '';
        width: 6px; height: 6px;
        border-radius: 50%;
        background: #a78bfa;
        box-shadow: 0 0 8px #a78bfa;
        animation: pulseDot 2s ease infinite;
    }
    @keyframes pulseDot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.5; transform: scale(0.8); }
    }

    /* STATS MINI */
    .stats-strip {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 0.75rem;
        margin-bottom: 2.5rem;
    }
    .stat-mini {
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(167, 139, 250, 0.2);
        border-radius: 14px;
        padding: 1rem 0.85rem;
        text-align: center;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) backwards;
    }
    .stat-mini:nth-child(1) { animation-delay: 0.05s; }
    .stat-mini:nth-child(2) { animation-delay: 0.1s; }
    .stat-mini:nth-child(3) { animation-delay: 0.15s; }
    .stat-mini:nth-child(4) { animation-delay: 0.2s; }
    .stat-mini:nth-child(5) { animation-delay: 0.25s; }
    .stat-mini:nth-child(6) { animation-delay: 0.3s; }
    .stat-mini:hover {
        transform: translateY(-4px);
        border-color: rgba(167, 139, 250, 0.6);
        box-shadow: 0 15px 40px rgba(167, 139, 250, 0.2);
    }
    .stat-mini .lbl { font-size: 0.65rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; margin-bottom: 0.35rem; }
    .stat-mini .val {
        font-size: 1.6rem; font-weight: 900;
        background: linear-gradient(135deg, #a78bfa 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1;
    }
    .stat-mini.warning .val {
        background: linear-gradient(135deg, #f59e0b, #f97316);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .stat-mini.danger .val {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    /* SECCIONES */
    .hub-section { margin-bottom: 2.5rem; animation: fadeInUp 0.7s cubic-bezier(0.16, 1, 0.3, 1) backwards; }
    .hub-section:nth-child(1) { animation-delay: 0.1s; }
    .hub-section:nth-child(2) { animation-delay: 0.2s; }
    .hub-section:nth-child(3) { animation-delay: 0.3s; }
    .hub-section:nth-child(4) { animation-delay: 0.4s; }
    .hub-section:nth-child(5) { animation-delay: 0.5s; }
    .hub-section:nth-child(6) { animation-delay: 0.6s; }

    .hub-section-title {
        font-size: 1rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding-left: 0.75rem;
        border-left: 3px solid #a78bfa;
        letter-spacing: -0.01em;
    }
    .hub-section-title .badge-count {
        background: rgba(167, 139, 250, 0.15);
        color: #a78bfa;
        padding: 0.15rem 0.55rem;
        border-radius: 100px;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.03em;
    }

    .hub-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 1rem;
    }

    /* BOTONES TIPO TARJETA */
    .hub-card {
        display: flex;
        flex-direction: column;
        padding: 1.4rem 1.25rem;
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.5));
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(167, 139, 250, 0.18);
        border-radius: 18px;
        text-decoration: none;
        color: #f1f5f9;
        transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
        overflow: hidden;
        cursor: pointer;
    }
    .hub-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, #a78bfa, #06b6d4);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.5s ease;
    }
    .hub-card:hover::before { transform: scaleX(1); }
    .hub-card:hover {
        transform: translateY(-6px);
        border-color: rgba(167, 139, 250, 0.6);
        box-shadow: 0 25px 60px rgba(167, 139, 250, 0.25);
    }
    .hub-card .hub-icon {
        width: 48px; height: 48px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        background: rgba(167, 139, 250, 0.12);
        border: 1px solid rgba(167, 139, 250, 0.35);
        color: #a78bfa;
        font-size: 1.4rem;
        margin-bottom: 0.9rem;
        transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        flex-shrink: 0;
    }
    .hub-card:hover .hub-icon {
        background: linear-gradient(135deg, #a78bfa, #06b6d4);
        color: #030712;
        transform: scale(1.12) rotate(-8deg);
        box-shadow: 0 8px 25px rgba(167, 139, 250, 0.5);
    }
    .hub-card .hub-title {
        font-size: 0.98rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 0.3rem;
        letter-spacing: -0.01em;
    }
    .hub-card .hub-desc {
        font-size: 0.78rem;
        color: #64748b;
        line-height: 1.4;
        flex-grow: 1;
    }
    .hub-card .hub-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.25rem 0.7rem;
        background: rgba(167, 139, 250, 0.12);
        border-radius: 100px;
        font-size: 0.68rem;
        font-weight: 800;
        color: #a78bfa;
        margin-top: 0.75rem;
        width: fit-content;
        letter-spacing: 0.03em;
    }
    .hub-card.warning .hub-icon {
        background: rgba(245, 158, 11, 0.12);
        border-color: rgba(245, 158, 11, 0.35);
        color: #f59e0b;
    }
    .hub-card.warning:hover .hub-icon { background: linear-gradient(135deg, #f59e0b, #f97316); }
    .hub-card.warning::before { background: linear-gradient(90deg, #f59e0b, #f97316); }
    .hub-card.warning .hub-badge {
        background: rgba(245, 158, 11, 0.15);
        color: #fcd34d;
    }

    .hub-card.danger .hub-icon {
        background: rgba(239, 68, 68, 0.12);
        border-color: rgba(239, 68, 68, 0.35);
        color: #ef4444;
    }
    .hub-card.danger:hover .hub-icon { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .hub-card.danger::before { background: linear-gradient(90deg, #ef4444, #dc2626); }
    .hub-card.danger:hover {
        border-color: rgba(239, 68, 68, 0.6);
        box-shadow: 0 25px 60px rgba(239, 68, 68, 0.25);
    }

    .hub-card.success .hub-icon {
        background: rgba(16, 185, 129, 0.12);
        border-color: rgba(16, 185, 129, 0.35);
        color: #10b981;
    }
    .hub-card.success:hover .hub-icon { background: linear-gradient(135deg, #10b981, #06b6d4); }
    .hub-card.success::before { background: linear-gradient(90deg, #10b981, #06b6d4); }
    .hub-card.success:hover {
        border-color: rgba(16, 185, 129, 0.6);
        box-shadow: 0 25px 60px rgba(16, 185, 129, 0.25);
    }

    .hub-card.info .hub-icon {
        background: rgba(6, 182, 212, 0.12);
        border-color: rgba(6, 182, 212, 0.35);
        color: #06b6d4;
    }
    .hub-card.info:hover .hub-icon { background: linear-gradient(135deg, #06b6d4, #3b82f6); }
    .hub-card.info::before { background: linear-gradient(90deg, #06b6d4, #3b82f6); }
    .hub-card.info:hover {
        border-color: rgba(6, 182, 212, 0.6);
        box-shadow: 0 25px 60px rgba(6, 182, 212, 0.25);
    }

    /* ÚLTIMAS EMPRESAS */
    .mini-empresas {
        background: rgba(15, 23, 42, 0.5);
        border: 1px solid rgba(167, 139, 250, 0.15);
        border-radius: 18px;
        padding: 1.25rem 1.5rem;
    }
    .mini-empresa-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.7rem 0;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        gap: 0.75rem;
    }
    .mini-empresa-item:last-child { border-bottom: none; }
    .mini-empresa-item .nombre {
        color: #e2e8f0;
        font-weight: 700;
        font-size: 0.88rem;
    }
    .mini-empresa-item .fecha {
        color: #64748b;
        font-size: 0.72rem;
    }
    .mini-empresa-item .badge {
        padding: 0.2rem 0.6rem;
        border-radius: 100px;
        font-size: 0.68rem;
        font-weight: 800;
        text-transform: uppercase;
    }
    .mini-empresa-item .badge.pendiente { background: rgba(245,158,11,0.15); color: #fcd34d; }
    .mini-empresa-item .badge.aprobada { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    .mini-empresa-item .badge.rechazada { background: rgba(239,68,68,0.15); color: #fca5a5; }

    @media (max-width: 768px) {
        .stats-strip { grid-template-columns: repeat(2, 1fr); }
        .hub-grid { grid-template-columns: repeat(2, 1fr); }
        .hub-card { padding: 1.1rem 1rem; }
        .hub-card .hub-icon { width: 40px; height: 40px; font-size: 1.2rem; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <!-- HERO -->
    <div class="dev-hero">
        <div class="hero-inner">
            <div class="hero-left">
                <div class="hero-avatar">◇</div>
                <div class="hero-text">
                    <span class="dev-badge">DEVELOPER MODE</span>
                    <h1>Panel Global</h1>
                    <p>
                        Bienvenido, <strong style="color: #a78bfa;"><?= htmlspecialchars($_SESSION['nombre']) ?></strong>
                        · Control total de SmartPark Pro
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- STATS MINI -->
    <div class="stats-strip">
        <div class="stat-mini">
            <div class="lbl">Empresas</div>
            <div class="val" data-counter="<?= $stats['empresas_total'] ?>">0</div>
        </div>
        <div class="stat-mini warning">
            <div class="lbl">Pendientes</div>
            <div class="val" data-counter="<?= $stats['empresas_pendientes'] ?>">0</div>
        </div>
        <div class="stat-mini">
            <div class="lbl">Parqueaderos</div>
            <div class="val" data-counter="<?= $stats['parqueaderos'] ?>">0</div>
        </div>
        <div class="stat-mini">
            <div class="lbl">Usuarios</div>
            <div class="val" data-counter="<?= $stats['usuarios'] ?>">0</div>
        </div>
        <div class="stat-mini info">
            <div class="lbl">Vehículos activos</div>
            <div class="val" data-counter="<?= $stats['activos'] ?>">0</div>
        </div>
        <div class="stat-mini danger">
            <div class="lbl">Reportes IA</div>
            <div class="val" data-counter="<?= $stats['reportes_ia'] ?>">0</div>
        </div>
    </div>

    <!-- SECCIÓN 1: GESTIÓN DE EMPRESAS -->
    <div class="hub-section">
        <div class="hub-section-title">
            📊 Gestión de Empresas
            <span class="badge-count"><?= $stats['empresas_total'] ?> activas</span>
        </div>
        <div class="hub-grid">
            <a href="dev_empresas.php" class="hub-card">
                <div class="hub-icon">◉</div>
                <div class="hub-title">Empresas</div>
                <div class="hub-desc">Aprobar, rechazar o suspender empresas. Ver estado y estadísticas.</div>
                <?php if ($stats['empresas_pendientes'] > 0): ?>
                    <span class="hub-badge">⚠ <?= $stats['empresas_pendientes'] ?> pendientes</span>
                <?php else: ?>
                    <span class="hub-badge">✓ Todo al día</span>
                <?php endif; ?>
            </a>
            <a href="dev_parqueaderos.php" class="hub-card info">
                <div class="hub-icon">⬚</div>
                <div class="hub-title">Parqueaderos</div>
                <div class="hub-desc">Estadísticas por sede: celdas, ocupación, recaudo individual.</div>
                <span class="hub-badge"><?= $stats['parqueaderos'] ?> sedes</span>
            </a>
            <a href="dev_usuarios.php" class="hub-card success">
                <div class="hub-icon">◇</div>
                <div class="hub-title">Usuarios globales</div>
                <div class="hub-desc">Todos los usuarios con sus empresas y roles asociados.</div>
                <span class="hub-badge"><?= $stats['usuarios'] ?> cuentas</span>
            </a>
            <a href="dev_registros.php" class="hub-card">
                <div class="hub-icon">▤</div>
                <div class="hub-title">Registros</div>
                <div class="hub-desc">Historial global de entradas y salidas de todas las empresas.</div>
                <span class="hub-badge"><?= $stats['registros_total'] ?> registros</span>
            </a>
        </div>
    </div>

    <!-- SECCIÓN 2: OPERACIÓN -->
    <div class="hub-section">
        <div class="hub-section-title">
            ⚙️ Operación diaria
        </div>
        <div class="hub-grid">
            <a href="registro_entrada.php" class="hub-card success">
                <div class="hub-icon">➕</div>
                <div class="hub-title">Registrar entrada</div>
                <div class="hub-desc">Registrar un vehículo como si fueras parte del staff.</div>
                <span class="hub-badge">Staff mode</span>
            </a>
            <a href="modulo_pagos.php" class="hub-card info">
                <div class="hub-icon">💳</div>
                <div class="hub-title">Cobrar</div>
                <div class="hub-desc">Caja rápida. Procesar pagos y generar facturas.</div>
                <span class="hub-badge">Caja</span>
            </a>
            <a href="salida.php" class="hub-card">
                <div class="hub-icon">➜</div>
                <div class="hub-title">Control de salida</div>
                <div class="hub-desc">Autorizar retiro de vehículos con o sin QR.</div>
                <span class="hub-badge">Barrera</span>
            </a>
            
        </div>
    </div>

    <!-- SECCIÓN 3: FINANZAS Y ANÁLISIS -->
    <div class="hub-section">
        <div class="hub-section-title">
            💰 Finanzas y Análisis
        </div>
        <div class="hub-grid">
            
            <a href="exportar_reporte.php?tipo=registros&formato=pdf" target="_blank" class="hub-card info">
                <div class="hub-icon">📄</div>
                <div class="hub-title">Exportar PDF</div>
                <div class="hub-desc">Reporte global de registros en formato PDF listo para imprimir.</div>
                <span class="hub-badge">Global</span>
            </a>
            <a href="exportar_finanzas.php?formato=csv" class="hub-card">
                <div class="hub-icon">📊</div>
                <div class="hub-title">Exportar CSV</div>
                <div class="hub-desc">Descargar datos financieros en formato Excel/CSV.</div>
                <span class="hub-badge">CSV</span>
            </a>
            <a href="dev_registros.php" class="hub-card info">
                <div class="hub-icon">📈</div>
                <div class="hub-title">Análisis de datos</div>
                <div class="hub-desc">Búsqueda avanzada de registros con filtros múltiples.</div>
                <span class="hub-badge">Analytics</span>
            </a>
        </div>
    </div>

    <!-- SECCIÓN 4: SISTEMA Y SEGURIDAD -->
    <div class="hub-section">
        <div class="hub-section-title">
            🔒 Sistema y Seguridad
        </div>
        <div class="hub-grid">
            <a href="dev_backups.php" class="hub-card warning">
                <div class="hub-icon">⬇</div>
                <div class="hub-title">Backups</div>
                <div class="hub-desc">Crear, descargar y administrar respaldos globales o por empresa.</div>
                <span class="hub-badge">Sistema</span>
            </a>
            <a href="dev_logs.php" class="hub-card">
                <div class="hub-icon">◎</div>
                <div class="hub-title">Logs de auditoría</div>
                <div class="hub-desc">Registro cronológico de acciones: aprobaciones, backups, cambios.</div>
                <span class="hub-badge">Auditoría</span>
            </a>
            <a href="dev_sistema.php" class="hub-card info">
                <div class="hub-icon">◈</div>
                <div class="hub-title">Estado del sistema</div>
                <div class="hub-desc">Versión PHP, MySQL, tamaño BD, memoria usada, extensiones.</div>
                <span class="hub-badge">Técnico</span>
            </a>
            <a href="dev_config.php#reportes" class="hub-card danger">
                <div class="hub-icon">⚠️</div>
                <div class="hub-title">Reportes IA</div>
                <div class="hub-desc">Preguntas que fallaron, intentos fuera de alcance o respuestas malas.</div>
                <?php if ($stats['reportes_ia'] > 0): ?>
                    <span class="hub-badge"><?= $stats['reportes_ia'] ?> pendientes</span>
                <?php else: ?>
                    <span class="hub-badge">Sin pendientes</span>
                <?php endif; ?>
            </a>
        </div>
    </div>

    <!-- SECCIÓN 5: CONFIGURACIÓN -->
    <div class="hub-section">
        <div class="hub-section-title">
            ⚙️ Configuración
        </div>
        <div class="hub-grid">
            <a href="dev_config.php" class="hub-card">
                <div class="hub-icon">⚙</div>
                <div class="hub-title">Configuración global</div>
                <div class="hub-desc">Modo mantenimiento, registro de empresas, idioma, moneda.</div>
                <span class="hub-badge">6 controles</span>
            </a>
            <a href="dev_config.php#ia" class="hub-card info">
                <div class="hub-icon">🤖</div>
                <div class="hub-title">Configuración IA</div>
                <div class="hub-desc">API key de Gemini, modelo, límites de tokens, proveedor.</div>
                <span class="hub-badge">Gemini</span>
            </a>
            <a href="dev_config.php" class="hub-card">
                <div class="hub-icon">📧</div>
                <div class="hub-title">Configuración SMTP</div>
                <div class="hub-desc">Datos del servidor de correo para envío de facturas y OTP.</div>
                <span class="hub-badge">Gmail</span>
            </a>
            <a href="dev_config.php#sistema" class="hub-card warning">
                <div class="hub-icon">🔧</div>
                <div class="hub-title">Modo mantenimiento</div>
                <div class="hub-desc">Activar el bloqueo del sistema para clientes y empresas.</div>
                <span class="hub-badge">Sistema</span>
            </a>
        </div>
    </div>

    <!-- ÚLTIMAS EMPRESAS + ACCIONES DE CUENTA -->
    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
        <div class="mini-empresas">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="font-size: 0.95rem; font-weight: 800; color: #f1f5f9; margin: 0;">◉ Empresas recientes</h3>
                <a href="dev_empresas.php" style="color: #a78bfa; font-size: 0.8rem; text-decoration: none; font-weight: 700;">Ver todas →</a>
            </div>
            <?php if (empty($empresasRecientes)): ?>
                <p style="text-align: center; color: #64748b; font-size: 0.85rem; padding: 1rem;">No hay empresas registradas.</p>
            <?php else: ?>
                <?php foreach ($empresasRecientes as $e): ?>
                    <div class="mini-empresa-item">
                        <div>
                            <div class="nombre">#<?= (int)$e['id'] ?> · <?= htmlspecialchars($e['nombre']) ?></div>
                            <div class="fecha"><?= htmlspecialchars(substr($e['fecha_creacion'], 0, 10)) ?></div>
                        </div>
                        <span class="badge <?= htmlspecialchars($e['estado_aprobacion']) ?>">
                            <?= htmlspecialchars($e['estado_aprobacion']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="hub-section">
            <div class="hub-section-title">👤 Cuenta</div>
            <div class="hub-grid">
                <a href="logout.php?cambiar=1" class="hub-card warning">
                    <div class="hub-icon">🔄</div>
                    <div class="hub-title">Cambiar de cuenta</div>
                    <div class="hub-desc">Cerrar la sesión actual e iniciar como otro usuario.</div>
                    <span class="hub-badge">Cambiar</span>
                </a>
                <a href="logout.php" class="hub-card danger">
                    <div class="hub-icon">✕</div>
                    <div class="hub-title">Cerrar sesión</div>
                    <div class="hub-desc">Salir del sistema completamente.</div>
                    <span class="hub-badge">Salir</span>
                </a>
            </div>
        </div>
    </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
</body>
</html>