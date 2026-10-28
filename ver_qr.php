<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'helpers_url.php';
send_security_headers();

require_login();
$eid = require_empresa();
$rol = $_SESSION['rol_en_empresa'] ?? '';
if (!in_array($rol, ['admin', 'operador', 'superadmin'], true)) {
    header('Location: dashboard_admin.php');
    exit;
}

// Datos de la empresa y parqueaderos
$emp = $pdo->prepare("SELECT id, nombre, nit FROM empresas WHERE id = :eid");
$emp->execute(['eid' => $eid]);
$empresa = $emp->fetch() ?: ['id' => $eid, 'nombre' => 'Mi Empresa', 'nit' => ''];

$parqueaderos = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, ciudad, direccion, qr_token
        FROM parqueaderos
        WHERE empresa_id = :eid AND estado = 'activo'
        ORDER BY nombre
    ");
    $stmt->execute(['eid' => $eid]);
    $parqueaderos = $stmt->fetchAll();
} catch (\Throwable $e) { $parqueaderos = []; }

// Detectar URL base automáticamente (funciona en localhost, ngrok, dominio real)
$baseUrl = detectar_url_base();

// Si algún parqueadero no tiene token, generarlo ahora
foreach ($parqueaderos as &$p) {
    if (empty($p['qr_token'])) {
        try {
            $token = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("UPDATE parqueaderos SET qr_token = :t WHERE id = :id AND empresa_id = :eid");
            $stmt->execute(['t' => $token, 'id' => $p['id'], 'eid' => $eid]);
            $p['qr_token'] = $token;
        } catch (\Throwable $e) {}
    }
}
unset($p);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Códigos QR - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=37">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }

    .page-header { margin-bottom: 2rem; text-align: center; }
    .page-header h1 {
        font-size: clamp(1.8rem, 4vw, 2.4rem);
        font-weight: 900;
        letter-spacing: -0.03em;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 0.4rem;
    }
    .page-header p { color: #64748b; font-size: 0.92rem; max-width: 600px; margin: 0 auto; }

    .info-banner {
        background: rgba(6, 182, 212, 0.08);
        border: 1px solid rgba(6, 182, 212, 0.3);
        border-radius: 14px;
        padding: 1.25rem;
        margin-bottom: 2rem;
        display: flex;
        gap: 1rem;
        align-items: flex-start;
    }
    .info-banner .icon { font-size: 1.5rem; flex-shrink: 0; }
    .info-banner .text { flex: 1; }
    .info-banner .text strong { color: #67e8f9; display: block; margin-bottom: 0.25rem; font-size: 0.9rem; }
    .info-banner .text p { color: #94a3b8; font-size: 0.82rem; line-height: 1.5; margin: 0; }
    .info-banner .text code {
        background: rgba(2, 6, 23, 0.6);
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
        font-family: 'JetBrains Mono', monospace;
        color: #6ee7b7;
        font-size: 0.78rem;
    }

    .parqueadero-block {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 22px;
        padding: 2rem;
        margin-bottom: 2rem;
    }
    .parqueadero-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
        padding-bottom: 1.25rem;
        border-bottom: 1px solid rgba(16, 185, 129, 0.15);
    }
    .parqueadero-header h2 {
        font-size: 1.3rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 0.3rem;
    }
    .parqueadero-header .meta {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        font-size: 0.8rem;
        color: #64748b;
    }
    .token-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.85rem;
        background: rgba(2, 6, 23, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.3);
        border-radius: 100px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.72rem;
        color: #10b981;
    }

    .qr-tabs {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .qr-tab {
        padding: 0.85rem 1rem;
        background: rgba(2, 6, 23, 0.5);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 12px;
        color: #94a3b8;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        text-align: center;
        transition: all 0.25s ease;
        font-family: inherit;
    }
    .qr-tab:hover { border-color: rgba(16, 185, 129, 0.4); color: #10b981; }
    .qr-tab.active {
        background: rgba(16, 185, 129, 0.12);
        border-color: #10b981;
        color: #10b981;
    }
    .qr-tab .tab-icon { font-size: 1.3rem; display: block; margin-bottom: 0.2rem; }
    .qr-tab .tab-sub { font-size: 0.7rem; color: #64748b; font-weight: 400; margin-top: 0.15rem; }

    .qr-panel {
        display: none;
    }
    .qr-panel.active {
        display: block;
    }

    .qr-actions {
        display: flex;
        gap: 0.6rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }
    .qr-actions button, .qr-actions a {
        padding: 0.65rem 1.1rem;
        border-radius: 10px;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: all 0.25s ease;
        border: 1.5px solid;
    }
    .btn-action {
        background: rgba(16, 185, 129, 0.12);
        border-color: #10b981;
        color: #10b981;
    }
    .btn-action:hover { background: #10b981; color: #030712; }
    .btn-secondary {
        background: transparent;
        border-color: rgba(148, 163, 184, 0.3);
        color: #94a3b8;
    }
    .btn-secondary:hover { border-color: #10b981; color: #10b981; }

    /* QR preview cards - 3 tamaños */
    .qr-preview {
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 2rem 1rem;
        background: rgba(2, 6, 23, 0.4);
        border-radius: 16px;
    }
    .qr-preview.entrada img { max-width: 380px; }
    .qr-preview.recepcion img { max-width: 240px; }
    .qr-preview.celda img { max-width: 140px; }

    .qr-preview-inner {
        background: white;
        padding: 1.25rem;
        border-radius: 16px;
        box-shadow: 0 0 40px rgba(16, 185, 129, 0.2);
        text-align: center;
    }
    .qr-preview-inner .qr-logo {
        height: 40px;
        margin-bottom: 0.6rem;
    }
    .qr-preview-inner .qr-titulo {
        color: #0a1628;
        font-weight: 800;
        font-size: 1rem;
        margin-bottom: 0.2rem;
    }
    .qr-preview-inner .qr-sub {
        color: #64748b;
        font-size: 0.78rem;
        margin-bottom: 1rem;
    }
    .qr-preview-inner img { display: block; margin: 0 auto; }
    .qr-preview-inner .qr-url {
        margin-top: 0.9rem;
        font-size: 0.68rem;
        color: #64748b;
        font-family: 'JetBrains Mono', monospace;
        word-break: break-all;
        max-width: 320px;
        margin-left: auto;
        margin-right: auto;
    }

    .empty-state {
        text-align: center;
        padding: 3rem;
        background: rgba(15, 23, 42, 0.5);
        border: 1px dashed rgba(148, 163, 184, 0.2);
        border-radius: 18px;
    }
    .empty-state .icon { font-size: 3rem; opacity: 0.5; margin-bottom: 1rem; }
    .empty-state p { color: #64748b; }

    @media (max-width: 768px) {
        .qr-tabs { grid-template-columns: 1fr; }
        .qr-preview.entrada img { max-width: 280px; }
        .qr-preview.recepcion img { max-width: 200px; }
    }

    /* Print mode */
    @media print {
        body { padding-top: 0; background: white; color: black; }
        .no-print { display: none !important; }
        .parqueadero-block {
            background: white;
            border: 2px solid #10b981;
            break-inside: avoid;
            margin-bottom: 2rem;
            padding: 2rem;
        }
        .parqueadero-header h2, .parqueadero-header .meta { color: black; }
        .token-badge { background: #f0fdf4; color: #065f46; border-color: #10b981; }
        .qr-preview { background: white; }
        .qr-tab:not(.active) { display: none; }
        .qr-panel:not(.active) { display: none; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header no-print">
    <h1>🔳 Códigos QR de acceso</h1>
    <p>Imprime y coloca estos QR en tu parqueadero. Los clientes los escanean con su celular y entran sin instalar nada.</p>
  </div>

  <div class="info-banner no-print">
    <span class="icon">📱</span>
    <div class="text">
      <strong>Cómo funciona</strong>
      <p>
        Cada parqueadero tiene un <code>token único</code>. Al escanear el QR, el cliente va a <code>entrada_qr.php?token=...</code>
        donde ingresa su placa y el sistema le asigna celda. Sirve en <strong>localhost, ngrok o dominio real</strong> — la URL se detecta automáticamente.
      </p>
    </div>
  </div>

  <?php if (empty($parqueaderos)): ?>
    <div class="empty-state">
      <div class="icon">⬚</div>
      <p>No tienes parqueaderos activos todavía.</p>
    </div>
  <?php else: ?>

    <?php foreach ($parqueaderos as $p): 
      $qrUrlBase = $baseUrl . 'entrada_qr.php?token=' . urlencode($p['qr_token']);
      $qrUrlEntrada   = $qrUrlBase;
      $qrUrlRecepcion = $baseUrl . 'mi_auto.php?token=' . urlencode($p['qr_token']);
      $qrUrlCelda     = $qrUrlBase . '&modo=celda';
    ?>
      <div class="parqueadero-block" data-parqueadero="<?= (int)$p['id'] ?>">

        <div class="parqueadero-header">
          <div>
            <h2>⬚ <?= htmlspecialchars($p['nombre']) ?></h2>
            <div class="meta">
              <?php if (!empty($p['ciudad'])): ?><span>◆ <?= htmlspecialchars($p['ciudad']) ?></span><?php endif; ?>
              <?php if (!empty($p['direccion'])): ?><span>◆ <?= htmlspecialchars($p['direccion']) ?></span><?php endif; ?>
              <span>◆ Empresa: <?= htmlspecialchars($empresa['nombre']) ?></span>
            </div>
          </div>
          <div class="token-badge" title="Token único del parqueadero">
            🔑 <?= htmlspecialchars(substr($p['qr_token'], 0, 16)) ?>...
          </div>
        </div>

        <!-- Tabs de tipo -->
        <div class="qr-tabs no-print">
          <button type="button" class="qr-tab active" data-panel="entrada-<?= (int)$p['id'] ?>">
            <span class="tab-icon">🚪</span>
            Entrada
            <div class="tab-sub">Pegar en la puerta</div>
          </button>
          <button type="button" class="qr-tab" data-panel="recepcion-<?= (int)$p['id'] ?>">
            <span class="tab-icon">🏢</span>
            Recepción
            <div class="tab-sub">Mostrador / caja</div>
          </button>
          <button type="button" class="qr-tab" data-panel="celda-<?= (int)$p['id'] ?>">
            <span class="tab-icon">🅿️</span>
            Por celda
            <div class="tab-sub">Check-in individual</div>
          </button>
        </div>

        <div class="qr-actions no-print">
          <button type="button" onclick="window.print()" class="btn-action">🖨️ Imprimir</button>
          <a href="exportar_qr_pdf.php?parqueadero_id=<?= (int)$p['id'] ?>&tipo=entrada" target="_blank" class="btn-action">📄 PDF entrada</a>
          <a href="exportar_qr_pdf.php?parqueadero_id=<?= (int)$p['id'] ?>&tipo=recepcion" target="_blank" class="btn-action">📄 PDF recepción</a>
          <a href="<?= htmlspecialchars($qrUrlEntrada) ?>" target="_blank" class="btn-secondary">🔍 Probar entrada</a>
        </div>

        <!-- Panel ENTRADA (grande) -->
        <div class="qr-panel active" id="panel-entrada-<?= (int)$p['id'] ?>">
          <div class="qr-preview entrada">
            <div class="qr-preview-inner">
              <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" class="qr-logo" onerror="this.style.display='none'">
              <div class="qr-titulo"><?= htmlspecialchars($p['nombre']) ?></div>
              <div class="qr-sub">Escanea para estacionar</div>
              <img src="generar_qr_imagen.php?data=<?= urlencode($qrUrlEntrada) ?>&size=320" 
                   alt="QR Entrada" style="width: 320px; height: 320px;">
              <div class="qr-url"><?= htmlspecialchars($qrUrlEntrada) ?></div>
            </div>
          </div>
        </div>

        <!-- Panel RECEPCION (mediano) -->
        <div class="qr-panel" id="panel-recepcion-<?= (int)$p['id'] ?>">
          <div class="qr-preview recepcion">
            <div class="qr-preview-inner">
              <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" class="qr-logo" onerror="this.style.display='none'">
              <div class="qr-titulo">Recepción</div>
              <div class="qr-sub">Escanea para registrar tu vehículo</div>
              <img src="generar_qr_imagen.php?data=<?= urlencode($qrUrlRecepcion) ?>&size=220" 
                   alt="QR Recepción" style="width: 220px; height: 220px;">
              <div class="qr-url"><?= htmlspecialchars($qrUrlRecepcion) ?></div>
            </div>
          </div>
        </div>

        <!-- Panel CELDA (pequeño) -->
        <div class="qr-panel" id="panel-celda-<?= (int)$p['id'] ?>">
          <div class="qr-preview celda">
            <div class="qr-preview-inner">
              <div class="qr-titulo" style="font-size: 0.9rem;">Check-in</div>
              <img src="generar_qr_imagen.php?data=<?= urlencode($qrUrlCelda) ?>&size=140" 
                   alt="QR Celda" style="width: 140px; height: 140px;">
              <div class="qr-url" style="font-size: 0.6rem;">Individual por celda</div>
            </div>
          </div>
        </div>

      </div>
    <?php endforeach; ?>

  <?php endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script>
(function() {
    'use strict';

    // Tabs de tipo de QR
    document.querySelectorAll('.qr-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            const parqueaderoBlock = this.closest('.parqueadero-block');
            const targetPanelId = this.dataset.panel;

            // Desactivar todas las tabs del mismo parqueadero
            parqueaderoBlock.querySelectorAll('.qr-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Ocultar todos los paneles del mismo parqueadero
            parqueaderoBlock.querySelectorAll('.qr-panel').forEach(p => p.classList.remove('active'));

            // Mostrar el panel objetivo
            const targetPanel = parqueaderoBlock.querySelector('#panel-' + targetPanelId);
            if (targetPanel) targetPanel.classList.add('active');
        });
    });
})();
</script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>