<?php
/**
 * generar_qr_imagen.php - Endpoint que devuelve un QR como PNG
 * =============================================================
 * NO requiere sesión: es una operación pública de renderizado.
 * Solo transforma el texto pasado en ?data= a una imagen PNG.
 */

// Sin session_start, sin require_login.

$data = $_GET['data'] ?? '';
$size = max(80, min(600, (int)($_GET['size'] ?? 200)));

if ($data === '' || strlen($data) > 2000) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Falta parámetro data o excede el tamaño permitido.');
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    // Fallback: usar servicio externo
    header('Location: https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($data));
    exit;
}

require_once $autoloadPath;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;

try {
    $qrCode = QrCode::create($data)
        ->setEncoding(new Encoding('UTF-8'))
        ->setErrorCorrectionLevel(ErrorCorrectionLevel::Medium)
        ->setSize($size)
        ->setMargin(8)
        ->setRoundBlockSizeMode(RoundBlockSizeMode::Margin);

    $writer = new PngWriter();
    $result = $writer->write($qrCode);

    // Headers de cache y seguridad
    header('Content-Type: ' . $result->getMimeType());
    header('Content-Length: ' . strlen($result->getString()));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');

    echo $result->getString();
} catch (\Throwable $e) {
    error_log('generar_qr_imagen: ' . $e->getMessage());
    // Fallback externo silencioso
    header('Location: https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($data));
    exit;
}