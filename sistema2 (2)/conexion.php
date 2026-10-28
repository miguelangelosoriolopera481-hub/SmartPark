<?php
// conexion.php - Conexión PDO estandarizada con compatibilidad $conn / $pdo
//
// CORRECCIÓN DE SEGURIDAD: antes las credenciales de la BD estaban escritas
// directamente aquí. Ahora se leen desde variables de entorno (.env local,
// nunca versionado) a través de env.php. Si no existe un .env, se usan los
// valores por defecto típicos de un entorno local de desarrollo (XAMPP/MAMP),
// pero en producción SIEMPRE debe existir un .env real.
require_once __DIR__ . '/env.php';

$host    = env('DB_HOST', 'localhost');
$db      = env('DB_NAME', 'smartpark');
$user    = env('DB_USER', 'root');
$pass    = env('DB_PASS', '');
$charset = env('DB_CHARSET', 'utf8mb4');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $conn = $pdo; // Compatibilidad para scripts que utilicen $conn
} catch (\PDOException $e) {
    // No se expone el mensaje crudo de PDO (podría filtrar detalles del
    // servidor); se registra en el log del servidor y se muestra algo genérico.
    error_log('Error crítico de conexión PDO: ' . $e->getMessage());
    die("Error crítico de conexión a la base de datos. Verifica la configuración en tu archivo .env.");
}

require_once __DIR__ . ' /mt_core.php';

require_once __DIR__ . '/mt_core.php';
