<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "=== 1. Archivo .env existe? ===\n";
echo file_exists(__DIR__ . '/.env') ? "SI\n" : "NO - FALTA EL .env\n";
echo "\n";

echo "=== 2. env.php existe? ===\n";
echo file_exists(__DIR__ . '/env.php') ? "SI\n" : "NO\n";
echo "\n";

if (file_exists(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
    echo "=== 3. Variables leidas del .env ===\n";
    echo "DB_HOST: [" . env('DB_HOST', '(no definido)') . "]\n";
    echo "DB_NAME: [" . env('DB_NAME', '(no definido)') . "]\n";
    echo "DB_USER: [" . env('DB_USER', '(no definido)') . "]\n";
    echo "DB_PASS: [" . env('DB_PASS', '(no definido)') . "]\n";
    echo "DB_CHARSET: [" . env('DB_CHARSET', 'utf8mb4') . "]\n";
} else {
    echo "No se puede leer env.php\n";
}
echo "\n";

echo "=== 4. Test conexion directa PDO ===\n";
$host = env('DB_HOST', 'localhost');
$db   = env('DB_NAME', 'smartpark');
$user = env('DB_USER', 'root');
$pass = env('DB_PASS', '');
try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $testPdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "CONEXION OK\n";
    $r = $testPdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
    echo "Usuarios en la tabla user: $r\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== 5. Extension PDO MySQL cargada? ===\n";
echo extension_loaded('pdo_mysql') ? "SI\n" : "NO - Activar pdo_mysql en php.ini\n";