<?php
/**
 * partials/role_context.php - Expone el rol al JavaScript
 * Se incluye SIEMPRE antes de </body> o al inicio.
 */
$_roleContext = [
    'role'        => $_SESSION['role'] ?? 'invitado',
    'rol_empresa' => $_SESSION['rol_en_empresa'] ?? null,
    'nombre'      => $_SESSION['nombre'] ?? '',
    'eid'         => $_SESSION['empresa_id'] ?? null,
    'logueado'    => !empty($_SESSION['usuario_id']),
];
?>
<script>
window.SP_CONTEXT = <?= json_encode($_roleContext, JSON_UNESCAPED_UNICODE) ?>;
</script>