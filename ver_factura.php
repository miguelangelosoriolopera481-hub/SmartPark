<?php
require_once 'conexion.php';
if (!file_exists(__DIR__ . '/vendor/autoload.php')) { die('Falta Composer.'); }
require_once __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$token = $_GET['token'] ?? '';
$placa = $_GET['placa'] ?? '';
$factura = null;
$qrBase64 = '';
$tarifa_aplicada = 0;

if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE token_factura = :token LIMIT 1");
    $stmt->execute(['token' => $token]);
    $factura = $stmt->fetch();
} elseif (!empty($placa)) {
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE placa = :p AND estado_pago = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute(['p' => $placa]);
    $factura = $stmt->fetch();
    if ($factura) $token = $factura['token_factura'];
}

if ($factura) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url = $scheme . '://' . $host . '/ver_factura.php?token=' . urlencode($token);

    $qrCode = new QrCode($url);
    $writer = new PngWriter();
    $qrBase64 = base64_encode($writer->write($qrCode)->getString());

    $tarifa_aplicada = ((int) $factura['horas'] > 0) ? $factura['total_pagar'] / $factura['horas'] : 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Factura Digital - SmartPark</title>
    <link rel="stylesheet" href="assets/css/main.css?v=999">
    <link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="cyber-body" style="padding-top: 2rem;">
<div class="container cyber-entrance" style="max-width: 460px; margin-top: 2rem;">
    <div class="cyber-card">
        <?php if ($factura): ?>
            <div style="text-align: center;">
                <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" style="height: 60px; margin-bottom: 10px;" onerror="this.src='IMG/SMARTPARK.png'">
                <h2 style="color: var(--primary-cyan);">Factura Oficial</h2>
                <span class="badge-cyber"><?= ((int) $factura['estado_pago'] === 1) ? '✅ PAGADA' : '⏳ PENDIENTE' ?></span>
            </div>
            <div class="ticket-details" style="margin-top: 1.5rem;">
                <div class="detail-row"><span>Placa:</span><strong><?= htmlspecialchars($factura['placa']) ?></strong></div>
                <div class="detail-row"><span>Modelo:</span><strong><?= htmlspecialchars($factura['modelo'] ?? 'N/A') ?></strong></div>
                <div class="detail-row"><span>Tipo:</span><strong><?= htmlspecialchars(ucfirst($factura['tipo_vehiculo'])) ?></strong></div>
                <div class="detail-row"><span>Celda:</span><strong><?= htmlspecialchars($factura['cupo_asignado']) ?></strong></div>
                <div class="detail-row"><span>Ingreso:</span><strong><?= htmlspecialchars($factura['fecha_ingreso']) ?></strong></div>
                <div class="detail-row"><span>Salida:</span><strong><?= htmlspecialchars($factura['fecha_salida'] ?? 'Pendiente') ?></strong></div>
                <div class="detail-row"><span>Horas:</span><strong><?= (int) $factura['horas'] ?></strong></div>
                <div class="detail-row"><span>Tarifa:</span><strong>$<?= number_format($tarifa_aplicada, 0, ',', '.') ?>/h</strong></div>
                <div class="detail-row total-row"><span>Total:</span><strong class="costo-text">$<?= number_format($factura['total_pagar'], 0, ',', '.') ?></strong></div>
            </div>
            <div class="qr-block" style="margin-top: 1.5rem;">
                <img src="data:image/png;base64,<?= $qrBase64 ?>" alt="QR Factura" style="max-width: 160px;">
                <p class="qr-caption">Verifica la autenticidad</p>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; margin-top: 1rem;">
                <a href="imprimir_factura.php?token=<?= urlencode($token) ?>" target="_blank" class="cyber-btn-primary" style="text-align: center; padding: 0.85rem;">🖨️ PDF</a>
                <a href="index.php" class="cyber-btn-outline" style="text-align: center; padding: 0.85rem;">← Inicio</a>
            </div>
        <?php else: ?>
            <h2 style="color: var(--danger); text-align: center;">❌ Factura no encontrada</h2>
            <p style="text-align: center; color: var(--text-muted);">El token o la placa no son válidos.</p>
            <form method="GET" action="ver_factura.php" style="margin-top: 1.5rem;">
                <div class="form-group">
                    <input type="text" name="placa" placeholder="Ingresa tu placa (Ej: ABC123)" style="width:100%; padding:0.7rem; background:#070a12; border:1px solid var(--border-cyber); border-radius:8px; color: var(--text-main); text-align:center; text-transform:uppercase;">
                </div>
                <button type="submit" class="cyber-btn-primary" style="width:100%; margin-top: 0.8rem;">Buscar por Placa</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>