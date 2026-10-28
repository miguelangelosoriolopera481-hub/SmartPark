<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'helpers.php';
require_once 'helpers_url.php';
send_security_headers();

require_role(['admin', 'superadmin']);
require_password_change_guard();

$datos = $_SESSION['sede_creada'] ?? null;
if (!$datos) {
    header('Location: admin_sedes.php');
    exit;
}
unset($_SESSION['sede_creada']);

$baseUrl = detectar_url_base();
$urlEntrada = $baseUrl . 'entrada_qr.php?token=' . urlencode($datos['qr_token']);
$urlRecepcion = $baseUrl . 'mi_auto.php?token=' . urlencode($datos['qr_token']);
$urlCelda = $urlEntrada . '&modo=celda';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sede Creada - SmartPark</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.15) 0%, transparent 60%), #030712;
        color: #f1f5f9;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 2rem 1rem;
    }
    .card {
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(20px);
        border: 1.5px solid rgba(16, 185, 129, 0.4);
        border-radius: 24px;
        padding: 2.5rem 2rem;
        max-width: 640px;
        width: 100%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    }
    .ok-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 90px; height: 90px;
        border-radius: 50%;
        background: rgba(16, 185, 129, 0.15);
        border: 3px solid #10b981;
        color: #10b981;
        font-size: 2.5rem;
        margin-bottom: 1.25rem;
        animation: pulse 2s ease-in-out infinite;
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 40px rgba(16, 185, 129, 0.5); }
        50%      { transform: scale(1.05); box-shadow: 0 0 60px rgba(16, 185, 129, 0.8); }
    }
    h1 { font-size: 1.75rem; font-weight: 900; margin: 0 0 0.6rem; color: #f1f5f9; }
    p.sub { color: #94a3b8; font-size: 0.92rem; margin-bottom: 1.75rem; line-height: 1.5; }
    .info-box {
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.3);
        border-radius: 14px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        text-align: left;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        font-size: 0.88rem;
        gap: 0.75rem;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .lbl { color: #94a3b8; }
    .info-row .val { color: #10b981; font-weight: 800; font-family: 'JetBrains Mono', monospace; word-break: break-all; text-align: right; }
    h3 { font-size: 0.9rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.9rem; text-align: left; }
    .qr-links {
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
        margin-bottom: 1.5rem;
    }
    .qr-link {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.85rem 1.1rem;
        background: rgba(2, 6, 23, 0.6);
        border: 1px solid rgba(16, 185, 129, 0.25);
        border-radius: 12px;
        color: #f1f5f9;
        text-decoration: none;
        font-size: 0.85rem;
        transition: all 0.25s ease;
        gap: 0.75rem;
    }
    .qr-link:hover {
        border-color: #10b981;
        background: rgba(16, 185, 129, 0.08);
        transform: translateX(3px);
    }
    .qr-link .tipo { font-weight: 800; color: #10b981; min-width: 90px; }
    .qr-link .url { color: #64748b; font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
    .btn {
        display: inline-block;
        padding: 1rem 2rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        margin: 0.25rem;
    }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.5); }
    .btn.outline {
        background: transparent;
        border: 1.5px solid #10b981;
        color: #10b981;
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>
<div class="card">

    <div class="ok-icon">✓</div>
    <h1>¡Sede creada!</h1>
    <p class="sub">La nueva sede ha sido configurada correctamente</p>

    <div class="info-box">
        <div class="info-row">
            <span class="lbl">Nombre</span>
            <span class="val"><?= htmlspecialchars($datos['nombre']) ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Total de celdas</span>
            <span class="val"><?= (int)$datos['total_celdas'] ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Token QR</span>
            <span class="val" style="font-size: 0.72rem;"><?= htmlspecialchars(substr($datos['qr_token'], 0, 20)) ?>...</span>
        </div>
    </div>

    <h3>▦ URLs de los QR (copia y pruébalas)</h3>
    <div class="qr-links">
        <a href="<?= htmlspecialchars($urlEntrada) ?>" target="_blank" class="qr-link">
            <span class="tipo">🚪 Entrada</span>
            <span class="url"><?= htmlspecialchars(substr($urlEntrada, 0, 60)) ?>...</span>
        </a>
        <a href="<?= htmlspecialchars($urlRecepcion) ?>" target="_blank" class="qr-link">
            <span class="tipo">🏢 Recepción</span>
            <span class="url"><?= htmlspecialchars(substr($urlRecepcion, 0, 60)) ?>...</span>
        </a>
        <a href="<?= htmlspecialchars($urlCelda) ?>" target="_blank" class="qr-link">
            <span class="tipo">🅿️ Celda</span>
            <span class="url"><?= htmlspecialchars(substr($urlCelda, 0, 60)) ?>...</span>
        </a>
    </div>

    <a href="ver_qr.php?parqueadero_id=<?= (int)$datos['parqueadero_id'] ?>" class="btn">▦ Ver e imprimir QRs</a>
    <a href="admin_sedes.php" class="btn outline">◉ Ver mis sedes</a>

</div>
</body>
</html>