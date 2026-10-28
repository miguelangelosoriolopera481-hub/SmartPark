<?php
/**
 * config/ai_config.php — Cliente unificado IA (lee de tabla configuracion)
 */
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../mt_core.php';

if (!function_exists('ai_get_config')) {
    function ai_get_config(string $clave, $default = null) {
        global $pdo;
        try {
            if (isset($pdo) && $pdo instanceof PDO && function_exists('get_config')) {
                $v = get_config($pdo, $clave, null);
                if ($v !== null && $v !== '') return $v;
            }
        } catch (\Throwable $e) {}
        return env($clave, $default);
    }
}

if (!function_exists('_ai_chat_real')) {
    function _ai_chat_real(string $prompt, array $context = []): array {
        $habilitada = ai_get_config('IA_HABILITADA', '1');
        if ($habilitada !== '1') {
            return ['ok' => false, 'error' => 'IA deshabilitada por el administrador.'];
        }
        $provider = strtolower((string)ai_get_config('IA_PROVEEDOR', 'gemini'));
        $apiKey   = (string)ai_get_config('IA_API_KEY', '');
        $model    = (string)ai_get_config('IA_MODELO', '');
        if ($model === '') {
            $model = match($provider) {
                'openai'    => 'gpt-4o-mini',
                'anthropic' => 'claude-3-5-sonnet-latest',
                'gemini'    => 'gemini-3.1-flash-lite',
                default     => 'gemini-3.1-flash-lite',
            };
        }
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'IA_API_KEY no configurada.'];
        }
        $systemPrompt = $context['system'] ?? 'Eres SmartPark AI, asistente experto en gestión de parqueaderos. Responde claro, breve y en español.';
        try {
            return match($provider) {
                'openai'    => ai_openai($apiKey, $model, $systemPrompt, $prompt),
                'anthropic' => ai_anthropic($apiKey, $model, $systemPrompt, $prompt),
                'gemini'    => ai_gemini($apiKey, $model, $systemPrompt, $prompt),
                default     => ['ok' => false, 'error' => 'Proveedor IA desconocido: ' . $provider],
            };
        } catch (\Throwable $e) {
            error_log('[ai_config] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error al contactar la IA: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('ai_http')) {
    function ai_http(string $url, array $headers, array $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'error' => 'No se pudo contactar la API: ' . $err];
        }
        $data = json_decode($resp, true);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => $data['error']['message'] ?? $data['error'] ?? "HTTP $code", 'raw' => $data];
        }
        return ['ok' => true, 'data' => $data];
    }
}

if (!function_exists('ai_openai')) {
    function ai_openai(string $key, string $model, string $system, string $user): array {
        $r = ai_http('https://api.openai.com/v1/chat/completions', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ], [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.3,
            'max_tokens'  => 1024,
        ]);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'text' => $r['data']['choices'][0]['message']['content'] ?? ''];
    }
}

if (!function_exists('ai_anthropic')) {
    function ai_anthropic(string $key, string $model, string $system, string $user): array {
        $r = ai_http('https://api.anthropic.com/v1/messages', [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ], [
            'model' => $model, 'max_tokens' => 1024,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ]);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'text' => $r['data']['content'][0]['text'] ?? ''];
    }
}

if (!function_exists('ai_gemini')) {
    function ai_gemini(string $key, string $model, string $system, string $user): array {
        // Si hay un modelo override (fallback entre modelos), usarlo
        if (!empty($GLOBALS['__ai_modelo_override'])) {
            $model = $GLOBALS['__ai_modelo_override'];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/"
             . urlencode($model)
             . ":generateContent";
        $r = ai_http($url, ['Content-Type: application/json', 'x-goog-api-key: ' . $key], [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig'   => ['temperature' => 0.3, 'maxOutputTokens' => 1024],
        ]);
        if (!$r['ok']) return $r;
        return ['ok' => true, 'text' => $r['data']['candidates'][0]['content']['parts'][0]['text'] ?? ''];
    }
}

/* ============================================================
 * WRAPPER PÚBLICO con reintentos automáticos
 * ============================================================
 * Se agrega al final del archivo para asegurar que las funciones
 * internas (_ai_chat_real) ya estén definidas cuando ai_chat()
 * las llame.
 * ============================================================ */

if (!function_exists('ai_chat')) {
    /**
     * Wrapper con reintentos agresivos ante errores temporales de Gemini.
     * - Hasta 5 intentos
     * - Espera exponencial: 2s, 4s, 8s, 12s (total ~26s de espera)
     * - Fallback a gemini-3.1-flash-lite si el principal está saturado
     */
    function ai_chat(string $prompt, array $context = []): array {
        $maxIntentos = 2;
        $esperas = [1]; // segundos entre intentos
        $ultimoError = '';

        for ($i = 1; $i <= $maxIntentos; $i++) {
            $r = _ai_chat_real($prompt, $context);
            if (!empty($r['ok'])) {
                return $r;
            }

            $err = $r['error'] ?? '';
            $ultimoError = $err;

            $esTemporal = (
                stripos($err, 'high demand') !== false ||
                stripos($err, 'overload') !== false ||
                stripos($err, 'rate limit') !== false ||
                stripos($err, 'quota') !== false ||
                stripos($err, 'temporarily') !== false ||
                stripos($err, 'try again') !== false ||
                stripos($err, '503') !== false ||
                stripos($err, '429') !== false ||
                stripos($err, 'timeout') !== false ||
                stripos($err, 'unavailable') !== false
            );

            if (!$esTemporal || $i === $maxIntentos) {
                break;
            }

            // Espera exponencial
            if (isset($esperas[$i - 1])) {
                sleep($esperas[$i - 1]);
            }
        }

        return [
            'ok'    => false,
            'error' => $ultimoError . ' (intentado ' . $maxIntentos . ' veces con espera)',
        ];
    }
}

/* ============================================================
 * VERSIÓN DE _ai_chat_real QUE ACEPTA UN MODELO ESPECÍFICO
 * ============================================================ */

if (!function_exists('_ai_chat_real_con_modelo')) {
    function _ai_chat_real_con_modelo(string $prompt, array $context, string $modeloOverride): array {
        
        $originalModelo = ai_get_config('IA_MODELO', '');
        $GLOBALS['__ai_modelo_override'] = $modeloOverride;

        $r = _ai_chat_real($prompt, $context);

        // Limpiar
        unset($GLOBALS['__ai_modelo_override']);

        return $r;
    }
}
