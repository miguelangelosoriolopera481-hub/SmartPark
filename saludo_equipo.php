<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers.php';

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("❌ Falta vendor/autoload.php. Ejecuta: composer install\n");
}
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: text/plain; charset=utf-8');

// DESTINATARIOS

$destinatarios = [
    'sebastian.ruachaverra@inemjose.edu.co' => 'Sebastián Rua Chaverra',
    'alison.mesamazo@inemjose.edu.co'       => 'Alison Mesa Mazo',
];

echo "=== CORREO DE SALUDO — INTEGRANTES DEL PROYECTO ===\n\n";
echo "Remitente: " . obtenerConfiguracion($pdo, 'MAIL_USER', '(no configurado)') . "\n";
echo "Destinatarios:\n";
foreach ($destinatarios as $mail => $nombre) {
    echo "  · $nombre <$mail>\n";
}
echo "\n";

// CONSTRUIR EL CORREO

$asunto = '👋 Saludo del equipo SmartPark Pro';

$cuerpo = "
<div style='font-family: Inter, Arial, sans-serif; max-width: 600px; margin: 0 auto; 
            padding: 0; background: #f8fafc; border-radius: 16px; overflow: hidden;'>

    <!-- Header con gradiente -->
    <div style='background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); 
                padding: 32px 24px; text-align: center;'>
        <h1 style='color: white; margin: 0 0 8px; font-size: 26px; font-weight: 900;'>
            ◈ SmartPark Pro
        </h1>
        <p style='color: rgba(255,255,255,0.9); margin: 0; font-size: 14px;'>
            Sistema de gestión inteligente de parqueaderos
        </p>
    </div>

    <!-- Contenido -->
    <div style='padding: 32px 28px;'>
        
        <h2 style='color: #0f172a; font-size: 20px; margin: 0 0 16px; font-weight: 800;'>
            ¡Hola equipo! 👋
        </h2>

        <p style='color: #334155; font-size: 15px; line-height: 1.7; margin: 0 0 16px;'>
            Les escribo para saludarlos y desearles mucho éxito en este proyecto
            que estamos construyendo juntos. <strong>SmartPark Pro</strong> está tomando forma
            y cada uno de ustedes es parte fundamental del resultado.
        </p>

        <p style='color: #334155; font-size: 15px; line-height: 1.7; margin: 0 0 20px;'>
            Este correo es también una <strong>prueba técnica</strong> del sistema de
            notificaciones que integraremos en el proyecto. Si lo están viendo,
            significa que el envío masivo a correos institucionales funciona correctamente. ✅
        </p>

        <!-- Lista de integrantes -->
        <div style='background: white; border-radius: 12px; padding: 20px; 
                    border-left: 4px solid #10b981; margin: 24px 0;'>
            <p style='color: #0f172a; font-size: 14px; font-weight: 800; 
                      margin: 0 0 12px; text-transform: uppercase; letter-spacing: 0.05em;'>
                ◆ Integrantes del equipo
            </p>
            <table style='width: 100%; border-collapse: collapse;'>
                <tr>
                    <td style='padding: 6px 0; color: #475569; font-size: 14px;'>
                        <strong style='color: #10b981;'>●</strong> &nbsp;
                        Miguel Ángel Osorio Lopera
                    </td>
                </tr>
                <tr>
                    <td style='padding: 6px 0; color: #475569; font-size: 14px;'>
                        <strong style='color: #06b6d4;'>●</strong> &nbsp;
                        Sebastián Rua Chaverra
                    </td>
                </tr>
                <tr>
                    <td style='padding: 6px 0; color: #475569; font-size: 14px;'>
                        <strong style='color: #8b5cf6;'>●</strong> &nbsp;
                        Alison Mesa Mazo
                    </td>
                </tr>
            </table>
        </div>

        <!-- Info del proyecto -->
        <p style='color: #334155; font-size: 14px; line-height: 1.7; margin: 0 0 8px;'>
            <strong>Proyecto:</strong> SmartPark Pro V3<br>
            <strong>Institución:</strong> INEM José Celestino Mutis<br>
            <strong>Estado:</strong> En desarrollo 🚀
        </p>

        <p style='color: #334155; font-size: 15px; line-height: 1.7; margin: 24px 0 0;'>
            ¡Mucho ánimo y a seguir construyendo algo grande! 💪
        </p>

        <p style='color: #0f172a; font-size: 14px; margin: 16px 0 0;'>
            <strong>— Miguel Ángel Osorio Lopera</strong><br>
            <span style='color: #64748b; font-size: 13px;'>Desarrollador principal</span>
        </p>

    </div>

    <!-- Footer -->
    <div style='background: #0f172a; padding: 20px 24px; text-align: center;'>
        <p style='color: #94a3b8; font-size: 12px; margin: 0;'>
            Este correo fue enviado automáticamente desde el sistema <strong style='color: #10b981;'>SmartPark Pro</strong>
        </p>
        <p style='color: #64748b; font-size: 11px; margin: 8px 0 0;'>
            Generado el " . date('d/m/Y \a \l\a\s H:i') . "
        </p>
    </div>

</div>";

// ENVIAR CON PHPMailer (múltiples destinatarios visibles)

echo "Enviando correo...\n\n";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = (string) obtenerConfiguracion($pdo, 'MAIL_HOST', 'smtp.gmail.com');
    $mail->Port       = (int)    obtenerConfiguracion($pdo, 'MAIL_PORT', 587);
    $mail->SMTPAuth   = true;
    $mail->Username   = (string) obtenerConfiguracion($pdo, 'MAIL_USER', '');
    $mail->Password   = (string) obtenerConfiguracion($pdo, 'MAIL_PASS', '');
    $mail->SMTPSecure = (string) obtenerConfiguracion($pdo, 'MAIL_SECURE', 'tls');
    $mail->CharSet    = 'UTF-8';

    $from     = (string) obtenerConfiguracion($pdo, 'MAIL_FROM', $mail->Username);
    $fromName = (string) obtenerConfiguracion($pdo, 'MAIL_FROM_NAME', 'SmartPark Pro');
    $mail->setFrom($from, $fromName);

    // Añadir ambos destinatarios (se ven entre ellos)
    foreach ($destinatarios as $email => $nombre) {
        $mail->addAddress($email, $nombre);
    }

    // Responder a quién
    $mail->addReplyTo($from, $fromName);

    $mail->isHTML(true);
    $mail->Subject = $asunto;
    $mail->Body    = $cuerpo;
    $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $cuerpo));

    $inicio = microtime(true);
    $mail->send();
    $tiempo = round(microtime(true) - $inicio, 2);

    echo "✅ CORREO ENVIADO EN {$tiempo}s\n\n";
    echo "Los 2 destinatarios deberían ver el mismo correo:\n";
    echo "  · Sebastián Rua Chaverra\n";
    echo "  · Alison Mesa Mazo\n\n";
    echo "RECUERDA: revisar también SPAM si no llega a la bandeja principal.\n";

} catch (PHPMailerException $e) {
    echo "❌ ERROR AL ENVIAR\n\n";
    echo "Detalle: " . $e->getMessage() . "\n\n";
    echo "Revisa: C:\\xampp\\apache\\logs\\error.log\n";
}

echo "\n=== FIN ===\n";