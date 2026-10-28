<?php
/**
 * env.php - Carga variables de entorno desde un archivo .env
 * -----------------------------------------------------------
 * Se implementa un parser propio (sin depender de vlucas/phpdotenv) para que
 * la corrección de seguridad "credenciales fuera del código fuente" no quede
 * bloqueada por que el hosting tenga o no acceso a internet para bajar
 * dependencias de Composer. Si el proyecto ya usa phpdotenv, esta función
 * simplemente no hace nada malo: solo rellena getenv()/$_ENV si la variable
 * aún no existe.
 *
 * Uso: require_once __DIR__ . '/env.php'; luego env('DB_HOST', 'localhost');
 */

if (!function_exists('smartpark_load_env')) {
    function smartpark_load_env(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lineas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lineas === false) {
            return;
        }

        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }
            if (strpos($linea, '=') === false) {
                continue;
            }

            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            // Quita comillas envolventes si las hay: VALOR="algo" o VALOR='algo'
            if (strlen($valor) >= 2) {
                $primero = $valor[0];
                $ultimo  = $valor[strlen($valor) - 1];
                if (($primero === '"' && $ultimo === '"') || ($primero === "'" && $ultimo === "'")) {
                    $valor = substr($valor, 1, -1);
                }
            }

            if ($clave === '') {
                continue;
            }

            // No sobreescribe variables ya definidas a nivel de servidor/sistema
            if (getenv($clave) === false) {
                putenv("$clave=$valor");
                $_ENV[$clave]    = $valor;
                $_SERVER[$clave] = $valor;
            }
        }
    }
}

if (!function_exists('env')) {
    /**
     * Obtiene una variable de entorno con valor por defecto opcional.
     */
    function env(string $clave, $default = null)
    {
        $valor = getenv($clave);
        if ($valor === false) {
            $valor = $_ENV[$clave] ?? null;
        }
        return ($valor !== null && $valor !== '') ? $valor : $default;
    }
}

// Carga el .env real del proyecto (si existe). En producción SIEMPRE debe
// existir un .env propio (no versionado) basado en .env.example.
smartpark_load_env(__DIR__ . '/.env');
