<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';

require_password_change_guard();

// CORRECCIÓN CRÍTICA: antes este archivo hacía require_once 'vendor/autoload.php'
// SIN validar que existiera, lo que provocaba un error fatal de PHP al pagar
// si nunca se había corrido `composer install` (causa raíz de "el QR no funciona").
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
    $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE placa = :placa AND estado = 'activo' ORDER BY id DESC LIMIT 1");
    $stmt->execute(['placa' => $placa]);
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
            // como 'finalizado'. Tal como dice la propia página de inicio
            // ("Presenta el QR en la barrera de salida y el sistema libera la
            // celda"), la celda se libera en salida.php cuando el vehículo
            // realmente sale, no en el momento de pagar. Esto evita que otro
            // vehículo sea asignado a una celda que sigue físicamente ocupada.
            $stmt_close = $pdo->prepare("
                UPDATE registros_parqueo
                SET horas = :horas, total_pagar = :total, estado_pago = 1, token_factura = :token
                WHERE id = :id
            ");
            $stmt_close->execute([
                'horas' => $horas,
                'total' => $total_pagar,
                'token' => $token,
                'id'    => $registro['id'],
            ]);

            $pdo->commit();

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url    = $scheme . '://' . $host . '/ver_factura.php?token=' . urlencode($token);

            // El QR SIEMPRE se genera y se muestra en pantalla (antes solo se
            // enviaba por correo, y fallaba en silencio si el SMTP no estaba
            // configurado, dejando al cliente sin ninguna forma de ver su QR).
            $qrBase64Pago = generar_qr_base64($url);

            // Envío de correo: best-effort, nunca bloquea ni oculta el QR ya mostrado.
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
                        $mail->Subject = 'Factura SmartPark - ' . $registro['placa'];
                        $mail->Body = "<h2>Factura SmartPark</h2>
                            <p>Placa: <b>{$registro['placa']}</b></p>
                            <p>Celda: <b>{$registro['cupo_asignado']}</b></p>
                            <p>Total: <b>$" . number_format($total_pagar, 0, ',', '.') . " COP</b></p>
                            <p>QR: <img src='data:image/png;base64,{$qrBase64Pago}' alt='QR'></p>
                            <p>También puedes verlo en línea: <a href='{$url}'>{$url}</a></p>";
                        $mail->send();
                    } catch (PHPMailerException $e) {
                        error_log("Error enviando correo de factura: " . $e->getMessage());
                        // No se muestra error al cliente: el QR en pantalla ya es suficiente.
                    }
                }
            }

            $mensaje = "Pago procesado correctamente.";
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
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>
<div class="container cyber-entrance" style="max-width: 520px; margin-top: 2rem;">
    <div class="cyber-card">
        <?php if ($mensaje): ?>
            <div class="cyber-success" style="text-align: center;"><?= htmlspecialchars($mensaje) ?></div>

            <?php if ($qrBase64Pago): ?>
                <div class="qr-block">
                    <img src="data:image/png;base64,<?= $qrBase64Pago ?>" alt="QR Factura">
                    <p class="qr-caption">Presenta este QR (o tu placa) en la barrera de salida para retirar tu vehículo.</p>
                </div>
            <?php endif; ?>

            <a href="ver_factura.php?token=<?= urlencode($registro['token_factura'] ?? '') ?>" class="cyber-btn-primary btn-block" style="margin-top: 1rem;">Ver factura completa</a>
            <a href="registro_carro.php" class="cyber-btn-outline btn-block" style="margin-top: 0.6rem;">Registrar otro vehículo</a>
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
                <button type="submit" class="cyber-btn-primary" style="width: 100%; background: #22c55e; color: black;">🔒 Pagar ahora</button>
            </form>
        <?php else: ?>
            <div class="cyber-alert">No se encontró un registro activo para esa placa.</div>
            <a href="ver_factura.php" class="cyber-btn-outline" style="display: block; text-align: center; margin-top: 1rem;">Consultar factura</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
