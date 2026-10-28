<?php
/**
 * mailer.php - Envio de correo via PHPMailer usando config de la BD.
 */
require_once __DIR__ . '/helpers.php';

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    error_log('[mailer] vendor/autoload.php no encontrado. Ejecuta composer install.');
    if (!function_exists('enviar_correo')) {
        function enviar_correo(PDO $pdo, string $to, string $subject, string $html): bool {
            error_log("[mailer] Intento de enviar a $to sin PHPMailer instalado");
            return false;
        }
    }
    return;
}
require_once __DIR__ . '/vendor/autoload.php';

if (!function_exists('enviar_correo')) {
    function enviar_correo(PDO $pdo, string $to, string $subject, string $html): bool {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = (string)get_config($pdo, 'MAIL_HOST');
            $mail->Port       = (int)get_config($pdo, 'MAIL_PORT', 587);
            $mail->SMTPAuth   = true;
            $mail->Username   = (string)get_config($pdo, 'MAIL_USER');
            $mail->Password   = (string)get_config($pdo, 'MAIL_PASS');
            $mail->SMTPSecure = (string)get_config($pdo, 'MAIL_SECURE', 'tls');
            $mail->CharSet    = 'UTF-8';

            if ($mail->Host === '' || $mail->Username === '') {
                error_log('[mailer] SMTP no configurado. Configura desde el panel admin.');
                return false;
            }

            $from     = (string)get_config($pdo, 'MAIL_FROM', $mail->Username);
            $fromName = (string)get_config($pdo, 'MAIL_FROM_NAME', 'SmartPark Pro');
            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[mailer] ' . $e->getMessage());
            return false;
        }
    }
}