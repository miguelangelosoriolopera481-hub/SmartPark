<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'helpers_url.php';
send_security_headers();

require_login();
$eid = require_empresa();
$rol = $_SESSION['rol_en_empresa'] ?? '';
if (!in_array($rol, ['admin', 'operador', 'superadmin'], true)) {
    http_response_code(403); die('Acceso denegado.');
}

$parqueaderoId = (int)($_GET['parqueadero_id'] ?? 0);
$tipo = in_array($_GET['tipo'] ?? '', ['entrada', 'recepcion', 'celda']) ? $_GET['tipo'] : 'entrada';

$stmt = $pdo->prepare("
    SELECT p.id, p.nombre, p.ciudad, p.direccion, p.qr_token,
           e.nombre AS empresa_nombre, e.nit AS empresa_nit
    FROM parqueaderos p
    JOIN empresas e ON e.id = p.empresa_id
    WHERE p.id = :id AND p.empresa_id = :eid AND p.estado = 'activo'
    LIMIT 1
");
$stmt->execute(['id' => $parqueaderoId, 'eid' => $eid]);
$p = $stmt->fetch();

if (!$p) { http_response_code(404); die('Parqueadero no encontrado.'); }

$baseUrl = detectar_url_base();
// URL según tipo de QR
$urlBaseQr = $baseUrl . 'entrada_qr.php?token=' . urlencode($p['qr_token']);
if ($tipo === 'recepcion') {
    $qrUrl = $urlBaseQr . '&modo=recepcion';
} elseif ($tipo === 'celda') {
    $qrUrl = $urlBaseQr . '&modo=celda';
} else {
    $qrUrl = $urlBaseQr;
}

$tamanos = ['entrada' => 320, 'recepcion' => 220, 'celda' => 140];
$size = $tamanos[$tipo];
$qrImgUrl = 'generar_qr_imagen.php?data=' . urlencode($qrUrl) . '&size=' . $size;

$titulos = [
    'entrada'   => 'Escanea para estacionar',
    'recepcion' => 'Recepción · Registra tu vehículo',
    'celda'     => 'Check-in de celda',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>QR - <?= htmlspecialchars($p['nombre']) ?></title>
<style>
    @page { size: A4 portrait; margin: 2cm; }
    body {
        font-family: 'Inter', Arial, sans-serif;
        margin: 0; padding: 0;
        background: white; color: #0a1628;
    }
    .header { text-align: center; padding: 1rem 0 1.5rem; border-bottom: 2px solid #10b981; margin-bottom: 1.5rem; }
    .header h1 { font-size: 20px; font-weight: 800; color: #10b981; margin: 0 0 0.4rem; }
    .header p { font-size: 12px; color: #64748b; margin: 0; }
    .qr-box {
        text-align: center;
        padding: 1.5rem;
        border: 3px solid #10b981;
        border-radius: 20px;
        max-width: 500px;
        margin: 1.5rem auto;
        page-break-inside: avoid;
    }
    .qr-box .logo { height: 60px; margin-bottom: 1rem; }
    .qr-box .titulo { font-size: 22px; font-weight: 800; color: #0a1628; margin: 0 0 0.4rem; }
    .qr-box .sub { font-size: 14px; color: #64748b; margin: 0 0 1.5rem; }
    .qr-box img.qr { display: block; margin: 0 auto; }
    .qr-box .url { margin-top: 1rem; font-size: 10px; color: #94a3b8; font-family: monospace; word-break: break-all; }
    .empresa { text-align: center; font-size: 11px; color: #64748b; margin-top: 1rem; }
    .instrucciones {
        margin-top: 2rem;
        padding: 1rem;
        background: #f0fdf4;
        border-left: 3px solid #10b981;
        font-size: 11px;
        color: #065f46;
    }
    .btn-print {
        display: block; margin: 1rem auto;
        padding: 12px 24px; background: #10b981; color: white;
        border: none; border-radius: 10px; font-weight: 700; cursor: pointer;
        font-size: 14px;
    }
    @media print { .btn-print { display: none; } }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar como PDF</button>

    <div class="header">
        <h1><?= htmlspecialchars($p['empresa_nombre']) ?></h1>
        <p><?= htmlspecialchars($p['nombre']) ?><?= $p['ciudad'] ? ' · ' . htmlspecialchars($p['ciudad']) : '' ?></p>
    </div>

    <div class="qr-box">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" class="logo" onerror="this.style.display='none'">
        <h2 class="titulo"><?= htmlspecialchars($p['nombre']) ?></h2>
        <p class="sub"><?= htmlspecialchars($titulos[$tipo]) ?></p>
        <img src="<?= htmlspecialchars($qrImgUrl) ?>" alt="QR" class="qr" width="<?= $size ?>" height="<?= $size ?>">
        <div class="url"><?= htmlspecialchars($qrUrl) ?></div>
    </div>

    <div class="instrucciones">
        <strong>Cómo usar este código QR:</strong>
        <ol style="margin: 0.5rem 0 0 1rem; padding: 0;">
            <li>Abre la cámara de tu celular</li>
            <li>Apunta al código QR</li>
            <li>Toca el enlace que aparece</li>
            <li>Ingresa tu placa y listo</li>
        </ol>
    </div>

    <div class="empresa">
        <?= htmlspecialchars($p['empresa_nombre']) ?><?= $p['empresa_nit'] ? ' · NIT ' . htmlspecialchars($p['empresa_nit']) : '' ?><br>
        Generado por SmartPark Pro · <?= date('Y') ?>
    </div>
</body>
</html>