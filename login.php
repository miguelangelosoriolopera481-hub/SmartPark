<?php
/**
 * login.php — REDIRIGIDO al login multi-tenant correcto
 * 
 * MOTIVO: El login genérico antiguo usaba iniciarSesion() que no guardaba
 * empresa_id ni rol_en_empresa. Esto causaba que al hacer clic en "Mi Cuenta"
 * el usuario fuera redirigido al login porque require_empresa() fallaba.
 *
 * Ahora TODOS los logins pasan por login_empresa.php que usa la función
 * autenticar_paso1() multi-tenant que SÍ guarda todos los datos necesarios.
 */
session_start();

// Si ya hay sesión activa, redirigir al dashboard correspondiente
if (!empty($_SESSION['usuario_id'])) {
    $rol = $_SESSION['rol_en_empresa'] ?? ($_SESSION['role'] ?? '');
    if ($rol === 'developer') {
        header('Location: dashboard_developer.php'); exit;
    }
    if (in_array($rol, ['admin', 'superadmin', 'operador'], true)) {
        header('Location: dashboard_admin.php'); exit;
    }
    if ($rol === 'finanzas') {
        header('Location: dashboard_finanzas.php'); exit;
    }
    if ($rol === 'vigilante') {
        header('Location: dashboard_vigilante.php'); exit;
    }
    header('Location: dashboard_cliente.php'); exit;
}

// Redirigir al login multi-tenant
header('Location: login_empresa.php');
exit;