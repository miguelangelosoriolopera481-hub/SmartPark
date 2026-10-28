<?php
// config_mail.php - Configuración SMTP
//
// CORRECCIÓN DE SEGURIDAD: antes este archivo tenía credenciales de ejemplo
// escritas en texto plano ('tu_correo@gmail.com', 'tu_contraseña_de_aplicacion').
// Ahora los valores se leen en este orden de prioridad:
//   1) Tabla `configuracion` en la BD (editable desde el panel admin, sin
//      tocar código ni redesplegar nada).
//   2) Variables de entorno del archivo .env (fuera del código fuente).
//   3) Valores por defecto vacíos/seguros si nada de lo anterior existe.
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/helpers.php';

if (!isset($pdo)) {
    require_once __DIR__ . '/conexion.php';
}

$mail_host      = obtenerConfiguracion($pdo, 'MAIL_HOST', 'smtp.gmail.com');
$mail_port      = (int) obtenerConfiguracion($pdo, 'MAIL_PORT', 587);
$mail_secure    = obtenerConfiguracion($pdo, 'MAIL_SECURE', 'tls');
$mail_user      = obtenerConfiguracion($pdo, 'MAIL_USER', '');
$mail_pass      = obtenerConfiguracion($pdo, 'MAIL_PASS', '');
$mail_from      = obtenerConfiguracion($pdo, 'MAIL_FROM', $mail_user);
$mail_from_name = obtenerConfiguracion($pdo, 'MAIL_FROM_NAME', 'SmartPark Pro');

/** Indica si hay suficiente configuración SMTP para intentar enviar correo. */
function smtp_esta_configurado(): bool
{
    global $mail_host, $mail_user, $mail_pass;
    return !empty($mail_host) && !empty($mail_user) && !empty($mail_pass);
}
