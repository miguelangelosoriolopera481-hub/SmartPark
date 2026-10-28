<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';

require_role(['admin']);

$archivo = $_GET['file'] ?? '';

// Validación estricta: solo nombres generados por config.php, sin rutas ni
// caracteres extraños (evita path traversal tipo ../../etc/passwd).
if (!preg_match('/^smartpark_backup_[0-9]{8}_[0-9]{6}\.sql$/', $archivo)) {
    http_response_code(400);
    die('Nombre de archivo de backup inválido.');
}

$ruta = __DIR__ . '/backups/' . $archivo;

if (!is_file($ruta)) {
    http_response_code(404);
    die('El backup solicitado no existe.');
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . filesize($ruta));
readfile($ruta);
exit;
