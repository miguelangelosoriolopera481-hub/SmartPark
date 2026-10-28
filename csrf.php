<?php
/**
 * csrf.php - Protección CSRF reutilizable
 * ----------------------------------------
 * Requiere que la sesión ya esté iniciada (session_start()) antes de incluir
 * este archivo. Se usa igual en todos los formularios POST del sitio.
 */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /** Imprime un <input type="hidden"> listo para usar dentro de un <form>. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_verificar')) {
    function csrf_verificar(?string $token): bool
    {
        return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * send_security_headers() - Cabeceras HTTP de seguridad.
 * Debe llamarse al inicio de cada request (despues de session_start()).
 */
if (!function_exists('send_security_headers')) {
    function send_security_headers(): void {
        if (headers_sent()) { return; }
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "img-src 'self' data: https:; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "script-src 'self' 'unsafe-inline'; " .
            "connect-src 'self' https:; " .
            "frame-ancestors 'none'; " .
            "base-uri 'self'"
        );
    }
}
