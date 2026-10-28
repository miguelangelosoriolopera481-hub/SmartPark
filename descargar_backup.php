<?php
/**
 * descargar_backup.php - Descarga segura de backups
 * Solo admin o developer. Valida nombre y evita path traversal.
 */
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'backup.php';

// Solo admin global o developer
$rol = $_SESSION['role'] ?? '';
if (empty($_SESSION['usuario_id']) || !in_array($rol, ['admin', 'developer'], true)) {
    http_response_code(403);
    die('Acceso denegado.');
}

$archivo = $_GET['file'] ?? '';

if (!backup_nombre_valido($archivo)) {
    http_response_code(400);
    die('Nombre de archivo inválido.');
}

$ruta = backup_dir() . '/' . $archivo;

// Verificación de path traversal
$real = realpath($ruta);
$realDir = realpath(backup_dir());
if ($real === false || $realDir === false || strpos($real, $realDir) !== 0) {
    http_response_code(400);
    die('Archivo fuera del directorio permitido.');
}

if (!is_file($ruta)) {
    http_response_code(404);
    die('El backup solicitado no existe.');
}

// Enviar el archivo
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($ruta);
exit;
