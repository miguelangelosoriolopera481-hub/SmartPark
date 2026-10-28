<?php
/**
 * helpers_url.php — Detecta la URL base correcta en CADA petición
 * =============================================================
 * ORDEN DE PRIORIDAD:
 *   1. APP_URL del .env   ← si está definido como URL pública (ngrok, dominio)
 *   2. HTTP_HOST          ← si viene de otro host (producción/ngrok/proxy)
 *   3. Auto-detección     ← ipconfig en tiempo real (LAN)
 *
 * IMPORTANTE: NO usar APP_LAN_IP del .env como prioridad porque cambia
 * al cambiar de red. Se usa como fallback solo si la auto-detección falla.
 */
if (!function_exists('detectar_url_base')) {
    function detectar_url_base(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hostSinPuerto = explode(':', $host)[0];

        $appUrl = env('APP_URL', '');
        if (!empty($appUrl) && strpos($appUrl, 'localhost') === false && strpos($appUrl, '127.0.0.1') === false) {
            return rtrim($appUrl, '/') . '/';
        }

        if (!in_array($hostSinPuerto, ['localhost', '127.0.0.1', '::1'], true)) {
            $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
            if ($dir === '.' || $dir === '\\') $dir = '';
            return rtrim($scheme . '://' . $host . $dir, '/') . '/';
        }

        $ipLan = _detectar_ip_lan_actual();

        if (!$ipLan) {
            $ipEnv = env('APP_LAN_IP', '');
            if (!empty($ipEnv) && filter_var($ipEnv, FILTER_VALIDATE_IP)) {
                $ipLan = $ipEnv;
            }
        }

        if (!$ipLan) $ipLan = $hostSinPuerto;

        $puerto = '';
        if (strpos($host, ':') !== false) {
            $partes = explode(':', $host);
            $puerto = ':' . ($partes[1] ?? '');
        }
        $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        if ($dir === '.' || $dir === '\\') $dir = '';

        return rtrim($scheme . '://' . $ipLan . $puerto . $dir, '/') . '/';
    }
}

if (!function_exists('_detectar_ip_lan_actual')) {
    /**
     * Detecta la IP LAN activa AHORA MISMO.
     * Filtra gateways, adaptadores virtuales y APIPA.
     * Prioriza: 192.168.43.X y 172.20.10.X (hotspots), luego 192.168.X.X, luego 10.X
     */
    function _detectar_ip_lan_actual(): ?string {
        if (!function_exists('shell_exec')) return null;

        $salida = @shell_exec('ipconfig 2>nul');
        if (!$salida) return null;

        $lineas = preg_split('/\r?\n/', $salida);
        $candidatas = [];

        foreach ($lineas as $linea) {
            if (stripos($linea, 'IPv4') === false) continue;
            if (!preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $linea, $m)) continue;

            $ip = $m[1];

            // Filtros duros
            if (strpos($ip, '127.') === 0) continue;                  // loopback
            if (strpos($ip, '169.254.') === 0) continue;              // APIPA
            if (substr($ip, -2) === '.0') continue;                   // red
            if (substr($ip, -2) === '.1') continue;                   // gateway tipico
            if (preg_match('/^192\.168\.56\./', $ip)) continue;       // VirtualBox host-only

            // Prioridad (menor = mejor)
            $prio = 99;
            if (preg_match('/^192\.168\.43\./', $ip))         $prio = 0;   // Android hotspot
            elseif (preg_match('/^172\.20\.10\./', $ip))      $prio = 0;   // iPhone hotspot
            elseif (preg_match('/^192\.168\./', $ip))         $prio = 1;   // WiFi/red normal
            elseif (preg_match('/^10\./', $ip))               $prio = 2;   // corporativa
            elseif (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip)) $prio = 3;

            if ($prio < 99) {
                $candidatas[$ip] = $prio;
            }
        }

        if (empty($candidatas)) return null;

        asort($candidatas);
        return array_key_first($candidatas);
    }
}

// Función de debug
if (!function_exists('detectar_url_base_debug')) {
    function detectar_url_base_debug(): array {
        $url = detectar_url_base();
        $ip = _detectar_ip_lan_actual();
        return [
            'url_base' => $url,
            'ip_detectada' => $ip,
            'app_url_env' => env('APP_URL', ''),
            'app_lan_ip_env' => env('APP_LAN_IP', ''),
            'http_host' => $_SERVER['HTTP_HOST'] ?? '',
        ];
    }
}