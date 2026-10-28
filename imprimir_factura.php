<?php
/**
 * imprimir_factura.php - Versión imprimible/PDF de la factura
 * Se abre desde ver_factura.php (botón "Imprimir").
 */
require_once 'conexion.php';
require_once 'helpers_url.php';

$token = $_GET['token'] ?? '';
$factura = null;

if ($token !== '') {
    try {
        $stmt = $pdo->prepare("SELECT r.*, e.nombre AS empresa_nombre, e.nit AS empresa_nit, p.nombre AS parqueadero_nombre
            FROM registros_parqueo r
            LEFT JOIN empresas e ON e.id = r.empresa_id
            LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
            WHERE r.token_factura = :t LIMIT 1");
        $stmt->execute(['t' => $token]);
        $factura = $stmt->fetch();
    } catch (\Throwable $e) {}
}

if (!$factura) {
    http_response_code(404);
    die('Factura no encontrada.');
}

$qr_url = detectar_url_base() . 'ver_factura.php?token=' . urlencode($token);
$qr_img = 'generar_qr_imagen.php?data=' . urlencode($qr_url) . '&size=200';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Factura <?= htmlspecialchars($factura['placa']) ?> - SmartPark</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Inter', Arial, sans-serif;
        background: #f8fafc;
        color: #1a2332;
        padding: 1.5rem;
        font-size: 12px;
    }
    .factura {
        max-width: 420px;
        margin: 0 auto;
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        overflow: hidden;
    }
    .header {
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: white;
        padding: 1.5rem;
        text-align: center;
    }
    .header h1 {
        font-size: 22px;
        font-weight: 800;
        margin: 0 0 0.25rem;
    }
    .header p { font-size: 12px; opacity: 0.95; margin: 0; }
    .content { padding: 1.5rem; }
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 0.5rem 0;
        border-bottom: 1px solid #e2e8f0;
        font-size: 12px;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .lbl { color: #64748b; }
    .info-row .val { color: #1a2332; font-weight: 700; }
    .total-row {
        background: #f0fdf4;
        border-radius: 10px;
        padding: 1rem;
        margin-top: 1rem;
        text-align: center;
    }
    .total-row .lbl { color: #065f46; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
    .total-row .val { color: #10b981; font-size: 28px; font-weight: 900; }
    .qr-section {
        text-align: center;
        padding: 1.5rem;
        border-top: 2px dashed #cbd5e1;
        margin-top: 1rem;
    }
    .qr-section img {
        max-width: 180px;
        display: block;
        margin: 0 auto 0.75rem;
    }
    .qr-section .caption {
        font-size: 10px;
        color: #64748b;
        line-height: 1.4;
    }
    .footer {
        text-align: center;
        padding: 1rem;
        font-size: 10px;
        color: #94a3b8;
        border-top: 1px solid #e2e8f0;
    }
    .btn-print {
        display: block;
        width: 100%;
        max-width: 420px;
        margin: 1.5rem auto 0;
        padding: 1rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: white;
        border: none;
        border-radius: 12px;
        font-weight: 800;
        font-size: 15px;
        cursor: pointer;
        font-family: inherit;
    }
    @media print {
        body { background: white; padding: 0; }
        .btn-print { display: none; }
        .factura { box-shadow: none; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<div class="factura">
    <div class="header">
        <h1>◈ SmartPark</h1>
        <p>Factura Digital</p>
    </div>

    <div class="content">
        <div class="info-row">
            <span class="lbl">Empresa</span>
            <span class="val"><?= htmlspecialchars($factura['empresa_nombre'] ?? '-') ?></span>
        </div>
        <?php if (!empty($factura['empresa_nit'])): ?>
        <div class="info-row">
            <span class="lbl">NIT</span>
            <span class="val"><?= htmlspecialchars($factura['empresa_nit']) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="lbl">Parqueadero</span>
            <span class="val"><?= htmlspecialchars($factura['parqueadero_nombre'] ?? '-') ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Placa</span>
            <span class="val" style="font-family: monospace; font-size: 15px;"><?= htmlspecialchars($factura['placa']) ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Celda</span>
            <span class="val"><?= htmlspecialchars($factura['cupo_asignado']) ?></span>
        </div>
        <div class="info-row">
            <span class="lbl">Ingreso</span>
            <span class="val"><?= htmlspecialchars(substr($factura['fecha_ingreso'], 0, 16)) ?></span>
        </div>
        <?php if ($factura['fecha_salida']): ?>
        <div class="info-row">
            <span class="lbl">Salida</span>
            <span class="val"><?= htmlspecialchars(substr($factura['fecha_salida'], 0, 16)) ?></span>
        </div>
        <?php endif; ?>
        <div class="info-row">
            <span class="lbl">Horas</span>
            <span class="val"><?= (int)$factura['horas'] ?></span>
        </div>

        <div class="total-row">
            <div class="lbl">Total pagado</div>
            <div class="val">$<?= number_format((float)$factura['total_pagar'], 0, ',', '.') ?></div>
        </div>
    </div>

    <div class="qr-section">
        <img src="<?= htmlspecialchars($qr_img) ?>" alt="QR Verificación">
        <div class="caption">
            Escanea este QR para verificar la autenticidad de la factura<br>
            Folio: <?= htmlspecialchars(substr($token, 0, 20)) ?>...
        </div>
    </div>

    <div class="footer">
        SmartPark Pro · <?= date('Y') ?><br>
        Documento generado automáticamente
    </div>
</div>

<button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>

</body>
</html>