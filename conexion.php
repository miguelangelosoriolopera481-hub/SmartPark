<?php
/**
 * conexion.php — Conexión PDO + timezone Colombia
 * =================================================
 * IMPORTANTE: Fuerza PHP y MySQL a usar America/Bogota (UTC-5)
 * para que TODOS los registros y cálculos de tiempo sean consistentes.
 */
require_once __DIR__ . '/env.php';

// FIX TIMEZONE: forzar America/Bogota en PHP

date_default_timezone_set('America/Bogota');

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
    $conn = $pdo;

    // FIX TIMEZONE: forzar America/Bogota (UTC-5) en MySQL
    // Esto afecta a NOW(), CURDATE(), TIMESTAMPDIFF(), etc.
    
    $pdo->exec("SET time_zone = '-05:00'");

} catch (\PDOException $e) {
    error_log('Error critico PDO: ' . $e->getMessage());
    http_response_code(503);
    die("Error critico de conexion a la base de datos. Verifica el .env");
}

require_once __DIR__ . '/mt_core.php';