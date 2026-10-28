<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
send_security_headers();

try {
    $totalEmpresas = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado='activa'")->fetchColumn();
    $totalCupos    = (int)$pdo->query("SELECT COUNT(*) FROM cupos")->fetchColumn();
    $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
    $totalActivos  = (int)$pdo->query("SELECT COUNT(*) FROM registros_parqueo WHERE estado='activo'")->fetchColumn();
} catch (\Throwable $e) {
    $totalEmpresas = $totalCupos = $totalUsuarios = $totalActivos = 0;
}

$logueado = !empty($_SESSION['usuario_id']);
$rol = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SmartPark — Gestión inteligente de parqueaderos</title>
<meta name="description" content="Plataforma SaaS para gestión inteligente de parqueaderos con IA, código QR y facturación digital.">
<link rel="stylesheet" href="assets/css/main.css?v=19">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    .hero {
        min-height: 92vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 6rem 1.5rem 4rem;
        position: relative;
    }
    .hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1.2rem;
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.3);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 2rem;
        opacity: 0;
        animation: heroFadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.1s forwards;
    }
    .hero-badge .dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: #10b981;
        box-shadow: 0 0 12px #10b981;
        animation: pulseDot 2s ease infinite;
    }
    @keyframes pulseDot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(0.8); }
    }
    .hero-logo-wrap {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.75rem;
        opacity: 0;
        transform: scale(0.3) rotate(-20deg);
        animation: heroLogoIn 1.3s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s forwards;
    }
    .hero-logo-wrap::before {
        content: '';
        position: absolute;
        width: 220px; height: 220px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(16, 185, 129, 0.25) 0%, transparent 70%);
        animation: logoGlow 3s ease-in-out infinite;
        z-index: 0;
    }
    .hero-logo-wrap::after {
        content: '';
        position: absolute;
        width: 200px; height: 200px;
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 50%;
        animation: logoRing 3s ease-out infinite;
        z-index: 0;
    }
    @keyframes heroLogoIn {
        to { opacity: 1; transform: scale(1) rotate(0); }
    }
    @keyframes logoGlow {
        0%, 100% { transform: scale(1); opacity: 0.6; }
        50% { transform: scale(1.15); opacity: 1; }
    }
    @keyframes logoRing {
        0% { width: 200px; height: 200px; opacity: 0.8; }
        100% { width: 380px; height: 380px; opacity: 0; }
    }
    .hero-logo {
        height: 160px;
        width: auto;
        filter: drop-shadow(0 0 50px rgba(16, 185, 129, 0.7));
        position: relative;
        z-index: 1;
        animation: heroFloat 5s ease-in-out 1.5s infinite;
    }
    @keyframes heroFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-12px); }
    }
    .hero h1 {
        font-size: clamp(2.4rem, 6.5vw, 4.5rem);
        font-weight: 900;
        line-height: 1.05;
        letter-spacing: -0.035em;
        margin-bottom: 1.75rem;
        max-width: 900px;
        opacity: 0;
        animation: heroFadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) 0.7s forwards;
    }
    .hero h1 .accent {
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .hero-subtitle {
        font-size: clamp(1.05rem, 2vw, 1.3rem);
        color: #94a3b8;
        max-width: 660px;
        margin-bottom: 3rem;
        line-height: 1.65;
        opacity: 0;
        animation: heroFadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) 0.9s forwards;
    }
    .hero-cta {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        justify-content: center;
        margin-bottom: 4rem;
        opacity: 0;
        animation: heroFadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) 1.1s forwards;
    }
    @keyframes heroFadeUp {
        from { opacity: 0; transform: translateY(40px); filter: blur(8px); }
        to { opacity: 1; transform: translateY(0); filter: blur(0); }
    }
    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1rem;
        max-width: 800px;
        width: 100%;
        opacity: 0;
        animation: heroFadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) 1.3s forwards;
    }
    .stat-mini {
        background: rgba(15, 23, 42, 0.5);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 20px;
        padding: 1.5rem 1rem;
        text-align: center;
        transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .stat-mini:hover {
        transform: translateY(-6px);
        border-color: rgba(16, 185, 129, 0.5);
        box-shadow: 0 15px 40px rgba(16, 185, 129, 0.2);
    }
    .stat-mini .num {
        font-size: 2.2rem;
        font-weight: 900;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        line-height: 1;
        margin-bottom: 0.5rem;
    }
    .stat-mini .lbl {
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<canvas id="particles-canvas"></canvas>

<!-- NAVBAR -->
<nav class="cyber-navbar">
  <div class="nav-container">
    <a href="index.php" class="cyber-brand">
      <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" class="nav-logo">
      <span class="brand-title">SmartPark </span>
    </a>
    <div class="cyber-nav-links">
      <a href="#portales">Portales</a>
      <a href="#caracteristicas">Características</a>
      <a href="#contacto">Contacto</a>
      <?php if ($logueado): ?>
        <?php if ($rol === 'developer'): ?>
          <a href="dashboard_developer.php" class="cyber-btn-primary">Panel Developer</a>
        <?php elseif (in_array($rol, ['admin', 'operador'], true)): ?>
          <a href="dashboard_admin.php" class="cyber-btn-primary">Mi Panel</a>
        <?php else: ?>
          <a href="dashboard_cliente.php" class="cyber-btn-primary">Mi Cuenta</a>
        <?php endif; ?>
        <a href="logout.php" class="cyber-btn-outline">Salir</a>
      <?php else: ?>
        <a href="login_cliente.php" class="cyber-btn-outline">Iniciar Sesión</a>
        <a href="registro_empresa.php" class="cyber-btn-primary">Registrar Empresa</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-badge">
    <span class="dot"></span>
    Plataforma activa · Sin costo durante demo
  </div>
  <div class="hero-logo-wrap">
    <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" class="hero-logo">
  </div>
  <h1>
    Gestiona tu parqueadero<br>
    <span class="accent">con inteligencia real</span>
  </h1>
  <p class="hero-subtitle">
    Sistema SaaS multi-empresa para parqueaderos modernos. Asignación inteligente
    de celdas, código QR por parqueadero, facturación digital y análisis en tiempo real.
  </p>
  <div class="hero-cta">
    <a href="registro_empresa.php" class="cyber-btn-primary btn-lg">Registrar mi parqueadero</a>
    <a href="login_cliente.php" class="cyber-btn-outline btn-lg">Portal Cliente</a>
  </div>
  <div class="stats-row">
    <div class="stat-mini">
      <div class="num" data-counter="<?= $totalEmpresas ?>">0</div>
      <div class="lbl">Empresas</div>
    </div>
    <div class="stat-mini">
      <div class="num" data-counter="<?= $totalCupos ?>">0</div>
      <div class="lbl">Celdas</div>
    </div>
    <div class="stat-mini">
      <div class="num" data-counter="<?= $totalUsuarios ?>">0</div>
      <div class="lbl">Usuarios</div>
    </div>
    <div class="stat-mini">
      <div class="num" data-counter="<?= $totalActivos ?>">0</div>
      <div class="lbl">Vehículos activos</div>
    </div>
  </div>
</section>

<style>
    .section { max-width: 1200px; margin: 0 auto; padding: 5rem 1.5rem; }
    .section-title {
        font-size: clamp(1.8rem, 4vw, 2.8rem);
        font-weight: 800;
        text-align: center;
        margin-bottom: 0.75rem;
        letter-spacing: -0.02em;
        opacity: 0;
        transform: translateY(30px);
        transition: all 0.9s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .section-title.visible { opacity: 1; transform: translateY(0); }
    .section-sub {
        text-align: center;
        color: #94a3b8;
        max-width: 600px;
        margin: 0 auto 3rem;
        font-size: 1rem;
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.9s cubic-bezier(0.16, 1, 0.3, 1) 0.15s;
    }
    .section-sub.visible { opacity: 1; transform: translateY(0); }
    .portales-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.5rem;
        margin-top: 3rem;
    }
    .portal-card {
        position: relative;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 24px;
        padding: 2.5rem 2rem;
        text-align: center;
        text-decoration: none;
        color: inherit;
        transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        overflow: hidden;
        opacity: 0;
        transform: translateY(30px);
    }
    .portal-card.visible { opacity: 1; transform: translateY(0); }
    .portal-card:hover {
        transform: translateY(-8px);
        border-color: rgba(16, 185, 129, 0.55);
        box-shadow: 0 25px 60px rgba(16, 185, 129, 0.2);
    }
    .portal-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 80px;
        height: 80px;
        border-radius: 20px;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.3);
        font-size: 2rem;
        margin-bottom: 1.5rem;
        transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        color: #10b981;
    }
    .portal-card:hover .portal-icon {
        transform: scale(1.1) rotate(-5deg);
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
    }
    .portal-card h3 {
        font-size: 1.35rem;
        font-weight: 800;
        margin-bottom: 0.75rem;
        color: #f1f5f9;
    }
    .portal-card p {
        color: #94a3b8;
        font-size: 0.92rem;
        line-height: 1.6;
        margin-bottom: 1.5rem;
    }
    .portal-card .arrow {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        color: #10b981;
        font-weight: 700;
        font-size: 0.88rem;
        transition: gap 0.3s ease;
    }
    .portal-card:hover .arrow { gap: 0.8rem; }
    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1.25rem;
        margin-top: 3rem;
    }
    .feature {
        background: rgba(15, 23, 42, 0.4);
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-radius: 18px;
        padding: 1.75rem;
        transition: all 0.35s ease;
        opacity: 0;
        transform: translateY(20px);
    }
    .feature.visible { opacity: 1; transform: translateY(0); }
    .feature:hover {
        border-color: rgba(16, 185, 129, 0.35);
        background: rgba(15, 23, 42, 0.7);
        transform: translateY(-4px);
    }
    .feature .ico {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 46px;
        height: 46px;
        border-radius: 12px;
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
        font-size: 1.3rem;
        margin-bottom: 1rem;
        border: 1px solid rgba(16, 185, 129, 0.25);
    }
    .feature h4 { font-size: 1.02rem; font-weight: 700; margin-bottom: 0.5rem; }
    .feature p { color: #64748b; font-size: 0.85rem; line-height: 1.55; }
    .cta-final {
        max-width: 900px;
        margin: 4rem auto;
        padding: 4rem 2rem;
        text-align: center;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.08), rgba(6, 182, 212, 0.08));
        border: 1px solid rgba(16, 185, 129, 0.25);
        border-radius: 32px;
        position: relative;
        overflow: hidden;
        opacity: 0;
        transform: translateY(30px);
        transition: all 0.9s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .cta-final.visible { opacity: 1; transform: translateY(0); }
    .cta-final::before {
        content: '';
        position: absolute;
        inset: 0;
        background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.15) 0%, transparent 60%);
        pointer-events: none;
    }
    .cta-final h2 {
        font-size: clamp(1.6rem, 3.5vw, 2.4rem);
        font-weight: 900;
        margin-bottom: 1rem;
        letter-spacing: -0.02em;
        position: relative;
    }
    .cta-final p {
        color: #94a3b8;
        margin-bottom: 2rem;
        max-width: 500px;
        margin-left: auto;
        margin-right: auto;
        position: relative;
    }
    .cta-final a { position: relative; }
</style>

<!-- PORTALES -->
<section class="section" id="portales">
  <h2 class="section-title" data-reveal>3 portales, <span class="text-gradient">un solo sistema</span></h2>
  <p class="section-sub" data-reveal>Cada tipo de usuario tiene su propio espacio diseñado para ser cómodo y eficiente.</p>

  <div class="portales-grid">
    <a href="login_empresa.php" class="portal-card" data-reveal-card>
      <div class="portal-icon">◉</div>
      <h3>Empresas y Dueños</h3>
      <p>Administra tu parqueadero: celdas, tarifas, personal, reportes y finanzas.</p>
      <span class="arrow">Ingresar →</span>
    </a>
    <a href="login_empresa.php" class="portal-card" data-reveal-card>
      <div class="portal-icon">◇</div>
      <h3>Conductores</h3>
      <p>Escanea el QR del parqueadero, encuentra tu celda ideal y paga fácil.</p>
      <span class="arrow">Ingresar →</span>
    </a>
    <a href="login_developer.php" class="portal-card" data-reveal-card>
      <div class="portal-icon">◈</div>
      <h3>Developers</h3>
      <p>Panel global de administración de todas las empresas y configuración del sistema.</p>
      <span class="arrow">Ingresar →</span>
    </a>

    <a href="login_empresa.php?rol=vigilante" class="portal-card" data-reveal-card>
      <div class="portal-icon">◐</div>
      <h3>Vigilantes</h3>
      <p>Acceso rápido para guardias de puerta. Registro de entradas y control de salidas en segundos.</p>
      <span class="arrow">Ingresar →</span>
    </a>

    <a href="login_empresa.php?rol=operador" class="portal-card" data-reveal-card>
      <div class="portal-icon">◑</div>
      <h3>Operadores</h3>
      <p>Staff de caja. Cobra, registra vehículos y gestiona celdas desde un solo panel.</p>
      <span class="arrow">Ingresar →</span>
    </a>
  </div>
</section>

<!-- CARACTERÍSTICAS -->
<section class="section" id="caracteristicas">
  <h2 class="section-title" data-reveal>Todo lo que <span class="text-gradient">necesitas</span></h2>
  <p class="section-sub" data-reveal>Tecnología moderna para un negocio tradicional.</p>

  <div class="features-grid">
    <div class="feature" data-reveal-feature>
      <div class="ico">✦</div>
      <h4>IA con Gemini</h4>
      <p>Sugiere celdas óptimas, predice horas pico y genera reportes inteligentes.</p>
    </div>
    <div class="feature" data-reveal-feature>
      <div class="ico">◎</div>
      <h4>QR Único</h4>
      <p>Cada parqueadero tiene su QR. Los clientes escanean, entran y pagan sin apps.</p>
    </div>
    <div class="feature" data-reveal-feature>
      <div class="ico">▤</div>
      <h4>Pago Digital</h4>
      <p>Efectivo, tarjeta, transferencia, PSE, QR bancario y prepago.</p>
    </div>
    <div class="feature" data-reveal-feature>
      <div class="ico">◐</div>
      <h4>Finanzas en vivo</h4>
      <p>Reportes de ingresos por día, tipo de vehículo y método de pago.</p>
    </div>
    <div class="feature" data-reveal-feature>
      <div class="ico">◆</div>
      <h4>Multi-empresa aislado</h4>
      <p>Cada empresa ve solo sus datos. Cumple seguridad multi-tenant.</p>
    </div>
    <div class="feature" data-reveal-feature>
      <div class="ico">◆</div>
      <h4>Notificaciones</h4>
      <p>Telegram y correo para OTP, facturas y alertas de entrada/salida.</p>
    </div>
    <div class="feature" data-reveal-feature>
      <div class="ico">◈</div>
      <h4>Analítica avanzada</h4>
      <p>Ocupación por hora, vehículos frecuentes y proyecciones.</p>
    </div>
    <div class="feature" data-reveal-feature>
      <div class="ico">◇</div>
      <h4>Multi-idioma</h4>
      <p>Español e inglés, con soporte para dólares y pesos.</p>
    </div>
  </div>
</section>

<!-- CTA FINAL -->
<section class="cta-final" data-reveal>
  <h2>¿Listo para digitalizar tu parqueadero?</h2>
  <p>Registra tu empresa gratis hoy. Durante la fase demo no cobramos suscripción.</p>
  <a href="registro_empresa.php" class="cyber-btn-primary btn-lg">Comenzar ahora</a>
</section>

<!-- FOOTER -->
<footer class="cyber-footer" id="contacto">
  <p style="margin-bottom: 1rem;">
    <strong class="text-gradient">SmartPark Pro</strong> · Sistema de gestión inteligente de parqueaderos
  </p>
  <p style="margin-bottom: 1.5rem; font-size: 0.85rem;">
    ¿Preguntas? Escríbenos a <a href="mailto:soporte@smartpark.com">soporte@smartpark.com</a>
  </p>
  <p style="font-size: 0.82rem;">
    <a href="index.php">Inicio</a> ·
    <a href="login_empresa.php">Empresas</a> ·
    <a href="login_developer.php">Developers</a> ·
    <a href="registro_empresa.php">Registro</a>
  </p>
  <p style="margin-top: 1.5rem; font-size: 0.75rem; color: #475569;">
    © <?= date('Y') ?> SmartPark Pro · Todos los derechos reservados
  </p>
</footer>

<script src="assets/js/app.js?v=999"></script>
<script>
// Reveal on scroll
document.addEventListener('DOMContentLoaded', function() {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    document.querySelectorAll('[data-reveal], [data-reveal-card], [data-reveal-feature]').forEach((el, i) => {
        setTimeout(() => el.classList.add('visible'), i * 100);
        observer.observe(el);
    });
});
</script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
