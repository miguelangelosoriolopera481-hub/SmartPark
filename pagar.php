<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';

require_password_change_guard();


if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    die('Falta instalar las dependencias de Composer. Ejecuta: composer install (ver README_INSTALACION.md).');
}
require_once __DIR__ . '/vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$placa = strtoupper(trim($_GET['placa'] ?? $_POST['placa'] ?? ''));
$mensaje = '';
$error = '';
$registro = null;
$total_pagar = 0;
$horas = 0;
$qrBase64Pago = '';

$tarifas = obtenerTarifas($pdo);

if (!empty($placa)) {
    //  usar empresa_id de la sesión
    $eid = (int)($_SESSION['empresa_id'] ?? 0);
    if ($eid <= 0) {
        // Si no hay sesión, buscar la empresa de la placa activa más reciente
        $stmtEmp = $pdo->prepare("SELECT empresa_id FROM registros_parqueo WHERE placa = :placa AND estado = 'activo' ORDER BY id DESC LIMIT 1");
        $stmtEmp->execute(['placa' => $placa]);
        $eid = (int)$stmtEmp->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE placa = :placa AND empresa_id = :eid AND estado = 'activo' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['placa' => $placa, 'eid' => $eid]);
    $registro = $stmt->fetch();

    if ($registro) {
        [$horas, $total_pagar] = calcularHorasYTotal($registro['fecha_ingreso'], $registro['tipo_vehiculo'], $tarifas);
    }
}

function generar_qr_base64(string $url): string
{
    $qr = new QrCode($url);
    $writer = new PngWriter();
    return base64_encode($writer->write($qr)->getString());
}

// Procesar pago
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_pago'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página e inténtalo de nuevo.";
    } elseif ($registro) {
        try {
            $pdo->beginTransaction();

            $token = $registro['token_factura'] ?? bin2hex(random_bytes(16));

            // NOTA DE DISEÑO: el pago YA NO libera la celda ni marca el registro
            
            $stmt_close = $pdo->prepare("
                UPDATE registros_parqueo
                SET horas = :horas, total_pagar = :total, estado_pago = 1,
                    token_factura = :token, fecha_pago = NOW()
                WHERE id = :id
            ");
            $stmt_close->execute([
                'horas' => $horas,
                'total' => $total_pagar,
                'token' => $token,
                'id'    => $registro['id'],
            ]);

            $pdo->commit();
            
            // FIX CRÍTICO: Actualizar la variable local para que el botón "Ver Factura" funcione
            $registro['token_factura'] = $token;

            // FIX: usar detectar_url_base() para URL correcta
            require_once __DIR__ . '/helpers_url.php';
    $url = detectar_url_base() . 'ver_factura.php?token=' . urlencode($token);

            // El QR SIEMPRE se genera y se muestra en pantalla (antes solo se
            
            $qrBase64Pago = generar_qr_base64($url);

            // Envío de correo: nunca bloquea ni oculta el QR ya mostrado.
            if (!empty($registro['correo'])) {
                require_once 'config_mail.php';
                if (smtp_esta_configurado()) {
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host = $mail_host;
                        $mail->SMTPAuth = true;
                        $mail->Username = $mail_user;
                        $mail->Password = $mail_pass;
                        $mail->SMTPSecure = $mail_secure;
                        $mail->Port = $mail_port;
                        $mail->setFrom($mail_from, $mail_from_name);
                        $mail->addAddress($registro['correo']);
                        $mail->isHTML(true);
                        $mail->CharSet = 'UTF-8';
                        $mail->Subject = 'Factura SmartPark - ' . $registro['placa'];
                        // Generamos la URL pública para la imagen del QR
                        $qrImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url);

                        $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; background-color: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;'>
                            <div style='text-align: center; background-color: #10b981; padding: 20px; border-radius: 10px 10px 0 0; color: white;'>
                                <h1 style='margin: 0; font-size: 24px;'>SmartPark Pro</h1>
                                <p style='margin: 5px 0 0; font-size: 14px;'>Factura de Pago Exitosa</p>
                            </div>
                            
                            <div style='padding: 25px; background-color: white; border-radius: 0 0 10px 10px; color: #1e293b;'>
                                <p style='font-size: 16px; margin-bottom: 10px;'><strong>Placa:</strong> <span style='font-family: monospace; font-size: 18px; color: #0f172a;'>{$registro['placa']}</span></p>
                                <p style='font-size: 16px; margin-bottom: 10px;'><strong>Celda:</strong> {$registro['cupo_asignado']}</p>
                                <div style='background-color: #f0fdf4; border-left: 4px solid #10b981; padding: 15px; margin: 20px 0;'>
                                    <p style='margin: 0; font-size: 18px; color: #065f46;'><strong>Total Pagado:</strong> $" . number_format($total_pagar, 0, ',', '.') . " COP</p>
                                </div>

                                <div style='text-align: center; margin: 25px 0;'>
                                    <img src='{$qrImgUrl}' alt='QR Factura' style='width: 180px; height: 180px; border: 2px solid #e2e8f0; border-radius: 10px; padding: 10px;'>
                                    <p style='font-size: 12px; color: #64748b; margin-top: 10px;'>Presenta este QR en la barrera de salida.</p>
                                </div>

                                <div style='text-align: center; margin-top: 30px;'>
                                    <a href='{$url}' style='background-color: #10b981; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; display: inline-block;'>Ver Factura en Línea</a>
                                </div>
                            </div>
                        </div>";
                        $mail->send();
                    } catch (PHPMailerException $e) {
                        error_log("Error enviando correo de factura: " . $e->getMessage());
                        // No se muestra error al cliente: el QR en pantalla ya es suficiente.
                    }
                }
            }

            $mensaje = "Pago exitoso. Dirigete a la salida y presenta el QR en la barrera para liberar tu celda.";
            $registro['estado_pago'] = 1; // refresca para la vista de confirmación
        } catch (\Exception $e) {
            $pdo->rollBack();
            error_log('pagar.php: ' . $e->getMessage());
            $error = "Error al procesar el pago. Intenta nuevamente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SmartPark - Pago Seguro</title>
    <link rel="stylesheet" href="assets/css/main.css?v=34">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="has-mobile-topbar" class="cyber-body">
<?php $backUrl = 'javascript:history.back()'; include __DIR__ . '/partials/mobile_topbar.php'; ?>
<?php include 'partials/navbar.php'; ?>
<div class="container cyber-entrance" style="max-width: 520px; margin-top: 2rem;">
    <div class="cyber-card">
        <?php if ($mensaje): ?>
            <div class="cyber-success" style="text-align: center; font-size: 1.1rem; padding: 1.5rem;">
                <strong><?= htmlspecialchars($mensaje) ?></strong>
            </div>

            <?php if ($qrBase64Pago): ?>
                <div class="qr-block">
                    <img src="data:image/png;base64,<?= $qrBase64Pago ?>" alt="QR Factura">
                    <p class="qr-caption">Presenta este QR (o tu placa) en la barrera de salida para retirar tu vehículo.</p>
                </div>
            <?php endif; ?>

            <!-- MENSAJE DE SEGURIDAD: EL CLIENTE NO PUEDE LIBERAR LA CELDA -->
            <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.4); padding: 1rem; border-radius: 12px; margin-top: 1rem; text-align: center;">
                <strong style="color: #fcd34d; display: block; margin-bottom: 0.5rem;">⚠️ Muestra este QR al vigilante</strong>
                <p style="color: #94a3b8; font-size: 0.85rem; margin: 0;">Para abrir la barrera y liberar tu celda, el personal de seguridad debe escanear tu código.</p>
            </div>

            <!-- BOTÓN SECUNDARIO: VER FACTURA -->
            <a href="ver_factura.php?token=<?= urlencode($registro['token_factura'] ?? '') ?>" class="cyber-btn-outline btn-block" style="margin-top: 0.6rem;">
                🧾 Ver factura completa
            </a>
            
            <a href="dashboard_cliente.php" class="cyber-btn-outline btn-block" style="margin-top: 0.6rem; border-color: rgba(255,255,255,0.1); color: #64748b;">
                ← Volver al inicio
            </a>
        <?php elseif ($error): ?>
            <div class="cyber-alert"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($registro): ?>
            <h2 style="color: var(--primary-cyan); text-align: center;">Confirmar Pago</h2>
            <div style="background: rgba(0,0,0,0.4); padding: 1rem; border-radius: 8px; margin: 1rem 0;">
                <p>Placa: <strong><?= htmlspecialchars($registro['placa']) ?></strong></p>
                <p>Modelo: <strong><?= htmlspecialchars($registro['modelo'] ?? 'N/A') ?></strong></p>
                <p>Celda: <strong><?= htmlspecialchars($registro['cupo_asignado']) ?></strong></p>
                <p>Horas: <strong><?= (int) $horas ?></strong></p>
                <p style="font-size: 1.3rem; color: var(--primary-green); font-weight: bold;">Total: $<?= number_format($total_pagar, 0, ',', '.') ?> COP</p>
            </div>
            <form action="pagar.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="placa" value="<?= htmlspecialchars($registro['placa']) ?>">
                <input type="hidden" name="procesar_pago" value="1">
                <button type="submit" class="cyber-btn-primary" style="width: 100%; background: #22c55e; color: black; font-weight: 800;">✅ Confirmar pago (GRATIS - demo)</button>
                <p style="text-align:center; color:#f59e0b; font-size:.72rem; margin-top:.5rem;">
                    ◈ Modo demo activo: el pago se marca como completado sin cargo real
                </p>
            </form>
        <?php else: ?>
            <div class="cyber-alert">No se encontró un registro activo para esa placa.</div>
            <a href="ver_factura.php" class="cyber-btn-outline" style="display: block; text-align: center; margin-top: 1rem;">Consultar factura</a>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
