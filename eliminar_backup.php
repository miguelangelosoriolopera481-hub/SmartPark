<?php
/**
 * eliminar_backup.php - Eliminar backup con CSRF + rol
 * Solo admin o developer.
 */
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'backup.php';

$rol = $_SESSION['role'] ?? '';
if (empty($_SESSION['usuario_id']) || !in_array($rol, ['admin', 'developer'], true)) {
    http_response_code(403);
    die('Acceso denegado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Método no permitido.');
}

if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
    header('Location: dashboard_developer.php?err=csrf');
    exit;
}

$archivo = $_POST['file'] ?? '';

if (!backup_nombre_valido($archivo)) {
    header('Location: dashboard_developer.php?err=nombre');
    exit;
}

if (eliminar_backup($archivo)) {
    header('Location: dashboard_developer.php?msg=eliminado');
} else {
    header('Location: dashboard_developer.php?err=eliminar');
}
exit;
