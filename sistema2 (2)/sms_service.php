<?php
/**
 * sms_service.php - Envio de SMS via Twilio / AWS SNS.
 * Si no esta configurado, devuelve false sin romper el flujo.
 */
require_once __DIR__ . '/helpers.php';

if (!function_exists('enviar_sms')) {
    function enviar_sms(PDO $pdo, string $telefono, string $mensaje): bool {
        if ($telefono === '') return false;
        $provider = strtolower((string)get_config($pdo, 'SMS_PROVIDER', 'none'));

        try {
            if ($provider === 'twilio') {
                $sid   = (string)get_config($pdo, 'TWILIO_SID');
                $token = (string)get_config($pdo, 'TWILIO_TOKEN');
                $from  = (string)get_config($pdo, 'TWILIO_FROM');
                if (!$sid || !$token || !$from) {
                    error_log('[sms] Twilio no configurado.');
                    return false;
                }
                $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
                $ch  = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD        => "$sid:$token",
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'From' => $from, 'To' => $telefono, 'Body' => $mensaje,
                    ]),
                    CURLOPT_TIMEOUT        => 10,
                ]);
                curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return $code >= 200 && $code < 300;
            }

            if ($provider === 'sns') {
                if (!class_exists('Aws\Sns\SnsClient')) {
                    error_log('[sms] AWS SDK no instalado. Ejecuta: composer require aws/aws-sdk-php');
                    return false;
                }
                $sns = new \Aws\Sns\SnsClient([
                    'version' => 'latest',
                    'region'  => (string)get_config($pdo, 'AWS_REGION', 'us-east-1'),
                    'credentials' => [
                        'key'    => (string)get_config($pdo, 'AWS_ACCESS_KEY'),
                        'secret' => (string)get_config($pdo, 'AWS_SECRET_KEY'),
                    ],
                ]);
                $sns->publish(['PhoneNumber' => $telefono, 'Message' => $mensaje]);
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[sms] ' . $e->getMessage());
        }
        return false;
    }
}