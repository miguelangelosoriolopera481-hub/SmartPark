<?php
/**
 * partials/ia_widget.php - Widget flotante del chat con IA
 * ========================================================
 * Incluir al final del <body> en cualquier dashboard.
 *
 * REQUIERE en scope:
 *   - $_SESSION (usuario_id, nombre, rol_en_empresa, empresa_id)
 *   - $pdo (para queries opcionales)
 *   - csrf_token() (función)
 *   - current_empresa_id() (función)
 */

if (empty($_SESSION['usuario_id'])) return;

$__iaRol = $_SESSION['rol_en_empresa'] ?? ($_SESSION['role'] ?? 'cliente');
$__iaNombre = $_SESSION['nombre'] ?? 'Usuario';
$__iaEid = function_exists('current_empresa_id') ? current_empresa_id() : null;

// Nombre de empresa (developer NO debe asociarse a una empresa específica)
$__iaEmpresaNombre = 'Sistema Global';
if ($__iaRol === 'developer') {
    $__iaEmpresaNombre = 'todas las empresas';
} elseif ($__iaEid && isset($pdo)) {
    try {
        $__stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :eid");
        $__stmt->execute(['eid' => $__iaEid]);
        $__iaEmpresaNombre = $__stmt->fetchColumn() ?: 'SmartPark';
    } catch (\Throwable $e) {}
}

// Sugerencias según rol
$__sugerencias = match($__iaRol) {
    'developer' => ['Ver resumen global', 'Empresas activas', 'Errores recientes'],
    'admin', 'superadmin' => ['Ocupación actual', 'Ingresos de hoy', 'Celdas libres'],
    'finanzas' => ['Recaudo del mes', 'Métodos de pago', 'Tendencia semanal'],
    'vigilante' => ['Cómo registrar entrada', 'Celdas disponibles', 'Vehículos activos'],
    'operador' => ['Cómo cobrar', 'Cómo registrar entrada', 'Vehículos activos'],
    'cliente' => ['¿Cuánto cuesta?', 'Mi vehículo', 'Cómo pagar'],
    default => ['¿Qué puedes hacer?', 'Ayuda general'],
};
?>
<link rel="stylesheet" href="assets/css/ia_widget.css?v=99">

<script>
    window.IA_WIDGET_CONFIG = {
        apiUrl: 'ia_chat_api.php',
        reportUrl: 'ia_reportar.php',
        csrfToken: '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>',
        role: '<?= htmlspecialchars($__iaRol, ENT_QUOTES) ?>',
        userName: '<?= htmlspecialchars($__iaNombre, ENT_QUOTES) ?>',
        empresaNombre: '<?= htmlspecialchars($__iaEmpresaNombre, ENT_QUOTES) ?>',
        suggestions: <?= json_encode(array_slice($__sugerencias, 0, 3), JSON_UNESCAPED_UNICODE) ?>,
    };
</script>

<!-- Botón flotante -->
<button type="button" class="ia-fab" title="Asistente IA" aria-label="Abrir asistente IA" style="position:fixed !important;top:1rem !important;right:1rem !important;bottom:auto !important;left:auto !important;z-index:99999 !important;">✦</button>

<!-- Panel del chat -->
<div class="ia-panel" role="dialog" aria-label="Asistente IA" style="position:fixed !important;top:4.5rem !important;right:1rem !important;bottom:auto !important;left:auto !important;z-index:99999 !important;">
    <div class="ia-header">
        <div class="ia-header-info">
            <div class="ia-header-avatar">✦</div>
            <div class="ia-header-text">
                <h3>SmartPark AI</h3>
                <span class="ia-status">En línea</span>
            </div>
        </div>
        <button type="button" class="ia-close" aria-label="Cerrar">✕</button>
    </div>

    <div class="ia-messages" role="log" aria-live="polite"></div>

    <div class="ia-suggestions"></div>

    <form class="ia-input-form" onsubmit="return false;">
        <textarea
            class="ia-input"
            placeholder="Pregúntame lo que necesites..."
            rows="1"
            maxlength="1000"
            aria-label="Mensaje"></textarea>
        <button type="button" class="ia-send" aria-label="Enviar">➤</button>
    </form>
</div>

<script src="assets/js/ia_widget.js?v=99" defer></script>