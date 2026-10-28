<?php
/**
 * ia_reportar.php - Endpoint para enviar reportes de IA
 * =======================================================
 * Casos:
 *   - ia_caida: no respondió tras varios intentos
 *   - respuesta_mala: respondió pero mal
 *   - otro: cualquier caso
 */

session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth_handler.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ia_contexto.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

if (!csrf_verificar($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido.']);
    exit;
}

$eid = (int)($_SESSION['empresa_id'] ?? 0);
$uid = (int)$_SESSION['usuario_id'];

$pregunta = trim($input['pregunta'] ?? '');
$respuesta = trim($input['respuesta'] ?? '');
$motivo = in_array($input['motivo'] ?? '', ['ia_caida','respuesta_mala','lenta','otro'], true)
        ? $input['motivo'] : 'otro';
$detalle = trim($input['detalle'] ?? '');

if ($pregunta === '') {
    echo json_encode(['ok' => false, 'error' => 'Falta la pregunta original.']);
    exit;
}

$ok = ia_guardar_reporte($pdo, $eid ?: null, $uid, $pregunta, $respuesta ?: null, $motivo, $detalle ?: null);

echo json_encode([
    'ok' => $ok,
    'mensaje' => $ok
        ? '✓ Gracias. El equipo técnico revisará tu reporte.'
        : 'No se pudo guardar el reporte. Intenta de nuevo.',
]);