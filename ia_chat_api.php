<?php
/**
 * ia_chat_api.php - Endpoint AJAX del chat con IA
 * =================================================
 * Recibe: { pregunta, csrf_token }
 * Responde: { ok, respuesta, tiempo, tokens_aprox }
 *
 * Seguridad:
 *   - CSRF obligatorio
 *   - Requiere sesión activa
 *   - Contexto filtrado por rol
 *   - Bloquea preguntas fuera de alcance
 *   - Rate limiting por sesión
 */

session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth_handler.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ia_contexto.php';
require_once __DIR__ . '/config/ai_config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ============================================================
 * 1. VERIFICACIONES BÁSICAS
 * ============================================================ */

// A. Sesión activa
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada. Recarga la página.']);
    exit;
}

// B. Método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

// C. CSRF
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!csrf_verificar($input['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.']);
    exit;
}

// D. Datos de sesión
$eid = (($_SESSION['role'] ?? '') === 'developer') ? 0 : (int)($_SESSION['empresa_id'] ?? 0);
$uid = (int)$_SESSION['usuario_id'];
$rol = $_SESSION['rol_en_empresa'] ?? ($_SESSION['role'] ?? 'cliente');
$parqueaderoId = isset($_SESSION['parqueadero_id']) ? (int)$_SESSION['parqueadero_id'] : null;
$nombreUsuario = $_SESSION['nombre'] ?? 'Usuario';

// E. Pregunta
$pregunta = trim($input['pregunta'] ?? '');
if ($pregunta === '') {
    echo json_encode(['ok' => false, 'error' => 'Escribe una pregunta.']);
    exit;
}
if (mb_strlen($pregunta) > 1000) {
    echo json_encode(['ok' => false, 'error' => 'La pregunta es demasiado larga (máximo 1000 caracteres).']);
    exit;
}

/* ============================================================
 * 2. RATE LIMITING (10 preguntas / 5 min por sesión)
 * ============================================================ */

$ahora = time();
if (!isset($_SESSION['ia_ratelimit'])) {
    $_SESSION['ia_ratelimit'] = [];
}
// Limpiar entradas > 5 min
$_SESSION['ia_ratelimit'] = array_filter($_SESSION['ia_ratelimit'], fn($t) => ($ahora - $t) < 300);

if (count($_SESSION['ia_ratelimit']) >= 10) {
    echo json_encode([
        'ok' => false,
        'error' => 'Límite alcanzado: máximo 10 preguntas cada 5 minutos. Espera un poco.'
    ]);
    exit;
}
$_SESSION['ia_ratelimit'][] = $ahora;

/* ============================================================
 * 3. FILTRO DE ALCANCE POR ROL
 * ============================================================ */

$bloqueada = ia_pregunta_fuera_de_alcance($pregunta, $rol);
if ($bloqueada !== null) {
    
    ia_guardar_reporte($pdo, ($eid ?: null), $uid, $pregunta, null, 'fuera_de_alcance',
        "Rol: $rol · Intento: $pregunta");
    echo json_encode([
        'ok' => false,
        'error' => $bloqueada,
        'tipo' => 'fuera_de_alcance',
    ]);
    exit;
}

/* ============================================================
 * 4. CONSTRUIR CONTEXTO SEGÚN ROL
 * ============================================================ */

try {
    $contextoRol = ia_construir_contexto($pdo, $rol, $eid, $uid, $parqueaderoId);
} catch (\Throwable $e) {
    error_log('ia_chat_api - contexto: ' . $e->getMessage());
    $contextoRol = 'Eres SmartPark AI. Ayuda al usuario con su pregunta.';
}

$systemPrompt = $contextoRol
    . "\n\nUsuario: $nombreUsuario (rol: $rol)\n"
    . "\nREGLAS ESTRICTAS:\n"
    . "1. Responde en español, claro y breve (máximo 200 palabras).\n"
    . "2. Si te preguntan algo fuera de tu contexto, di amablemente que no tienes esa información.\n"
    . "3. NUNCA inventes datos. Si no sabes algo, dilo.\n"
    . "4. NUNCA menciones datos de otras empresas.\n"
    . "5. Puedes usar emojis para hacer las respuestas más amigables.\n"
    . "6. Si te piden una acción concreta (crear, modificar, borrar), responde con un JSON: "
    . "{\"accion\":\"...\",\"detalles\":{...},\"confirmar\":true}. Si no es una acción, "
    . "responde en lenguaje natural.";

/* ============================================================
 * 5. LLAMAR A LA IA (con reintentos)
 * ============================================================ */

$inicio = microtime(true);

try {
    if (!function_exists('ai_chat')) {
        throw new RuntimeException('Función ai_chat() no disponible.');
    }

    $r = ai_chat($pregunta, ['system' => $systemPrompt]);
    $tiempo = round(microtime(true) - $inicio, 2);

    if (empty($r['ok'])) {
        // IA caída → guardar reporte automático
        ia_guardar_reporte($pdo, ($eid ?: null), $uid, $pregunta, null, 'ia_caida', $r['error'] ?? 'desconocido');

        echo json_encode([
            'ok' => false,
            'error' => 'La IA está tardando en responder. Intenta de nuevo en unos segundos.',
            'detalle_tecnico' => $r['error'] ?? null,
            'tipo' => 'ia_caida',
            'sugerir_reporte' => true,
        ]);
        exit;
    }

    $respuesta = trim($r['text']);

    try {
        $pdo->prepare("
            INSERT INTO auditoria_admin (empresa_id, usuario_id, usuario_nombre, accion, detalle, ip, creado_en)
            VALUES (:eid, :uid, :nom, 'ia_chat', :det, :ip, NOW())
        ")->execute([
            'eid' => $eid ?: null,
            'uid' => $uid,
            'nom' => $nombreUsuario,
            'det' => 'Pregunta: ' . mb_substr($pregunta, 0, 200),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {}

    echo json_encode([
        'ok' => true,
        'respuesta' => $respuesta,
        'tiempo' => $tiempo,
        'rol' => $rol,
    ]);
} catch (\Throwable $e) {
    error_log('ia_chat_api: ' . $e->getMessage());
    ia_guardar_reporte($pdo, ($eid ?: null), $uid, $pregunta, null, 'error_interno', $e->getMessage());

    echo json_encode([
        'ok' => false,
        'error' => 'Error al procesar tu pregunta. El equipo técnico fue notificado.',
        'tipo' => 'error_interno',
        'sugerir_reporte' => true,
    ]);
}