<?php
require_once 'conexion.php';

// Verificar si Composer está instalado
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die('Falta instalar las dependencias de Composer. Ejecuta: composer install (ver README_INSTALACION.md).');
}
require_once __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$token = $_GET['token'] ?? '';
$factura = null;
$qrBase64 = '';
$tarifa_aplicada = 0;

if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE token_factura = :token LIMIT 1");
    $stmt->execute(['token' => $token]);
    $factura = $stmt->fetch();

    if ($factura) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/ver_factura.php?token=' . urlencode($token);

        $qrCode = new QrCode($url);
        $writer = new PngWriter();
        $qrBase64 = base64_encode($writer->write($qrCode)->getString());

        // Tarifa realmente aplicada = lo cobrado / horas (así refleja la
        // tarifa vigente en el momento del cobro aunque luego cambie en config.php)
        $tarifa_aplicada = ((int) $factura['horas'] > 0)
            ? $factura['total_pagar'] / $factura['horas']
            : 0;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SmartPark - Factura Digital</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<div class="container cyber-entrance" style="max-width: 460px; margin-top: 3rem;">
    <div class="cyber-card">
        <?php if ($factura): ?>
            <div style="text-align: center;">
                <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" style="height: 60px; margin-bottom: 10px;">
                <h2 style="color: var(--primary-cyan);">Factura Oficial</h2>
                <span class="badge-cyber"><?= ((int) $factura['estado_pago'] === 1) ? '✅ PAGADA' : '⏳ PENDIENTE DE PAGO' ?></span>
            </div>
            <div class="ticket-details">
                <div class="detail-row"><span>Placa:</span><strong><?= htmlspecialchars($factura['placa']) ?></strong></div>
                <div class="detail-row"><span>Modelo:</span><strong><?= htmlspecialchars($factura['modelo'] ?? 'N/A') ?></strong></div>
                <div class="detail-row"><span>Tipo de vehículo:</span><strong><?= htmlspecialchars(ucfirst($factura['tipo_vehiculo'])) ?></strong></div>
                <div class="detail-row"><span>Celda:</span><strong><?= htmlspecialchars($factura['cupo_asignado']) ?></strong></div>
                <div class="detail-row"><span>Ingreso:</span><strong><?= htmlspecialchars($factura['fecha_ingreso']) ?></strong></div>
                <div class="detail-row"><span>Salida:</span><strong><?= htmlspecialchars($factura['fecha_salida'] ?? 'Pendiente (aún parqueado)') ?></strong></div>
                <div class="detail-row"><span>Horas:</span><strong><?= (int) $factura['horas'] ?></strong></div>
                <div class="detail-row"><span>Tarifa aplicada:</span><strong>$<?= number_format($tarifa_aplicada, 0, ',', '.') ?> COP/hora</strong></div>
                <div class="detail-row total-row"><span>Total:</span><strong class="costo-text">$<?= number_format($factura['total_pagar'], 0, ',', '.') ?> COP</strong></div>
                <div class="detail-row"><span>Folio de verificación:</span><strong style="font-size: 0.75rem; word-break: break-all;"><?= htmlspecialchars($factura['token_factura']) ?></strong></div>
            </div>
            <div class="qr-block">
                <img src="data:image/png;base64,<?= $qrBase64 ?>" alt="QR Factura">
                <p class="qr-caption">Escanea para verificar la factura · Preséntalo en la barrera de salida</p>
            </div>
        <?php else: ?>
            <h2 style="color: var(--danger); text-align: center;">❌ Factura no encontrada</h2>
            <p style="text-align: center; color: var(--text-muted);">El token proporcionado no es válido.</p>
            <form method="GET" action="ver_factura.php" style="margin-top: 1.5rem;">
                <div class="form-group">
                    <label style="color: var(--text-muted); font-size: 0.85rem;">Ingresa tu folio/token:</label>
                    <input type="text" name="token" placeholder="Token de la factura" style="width:100%; padding:0.7rem; background:#070a12; border:1px solid var(--border-cyber); border-radius:8px; color: var(--text-main);">
                </div>
                <button type="submit" class="cyber-btn-primary" style="width:100%; margin-top: 0.8rem;">Buscar</button>
            </form>
        <?php endif; ?>
        <p class="auth-footer" style="margin-top: 1.5rem;"><a href="index.php">← Volver al inicio</a></p>
    </div>
</div>
</body>
</html>
