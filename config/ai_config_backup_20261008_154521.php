<?php
/**
 * config/ai_config.php — Cliente unificado para OpenAI / Anthropic / Gemini.
 * Uso:
 *   require_once __DIR__ . '/config/ai_config.php';
 *   $resp = ai_chat('Recomiéndame la celda más cercana a la salida', ['system' => '...']);
 */
require_once __DIR__ . '/../env.php';

function ai_chat(string $prompt, array $context = []): array {
    $provider = strtolower((string)env('AI_PROVIDER', 'openai'));
    $apiKey   = (string)env('AI_API_KEY', '');
    $model    = (string)env('AI_MODEL', 'gpt-4o-mini');

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'AI_API_KEY no configurada en .env'];
    }

    $systemPrompt = $context['system']
        ?? 'Eres SmartPark AI, asistente experto en parqueaderos. Responde SIEMPRE en JSON válido cuando se te pida una acción estructurada.';

    try {
        switch ($provider) {
            case 'openai':
                return ai_openai($apiKey, $model, $systemPrompt, $prompt);
            case 'anthropic':
                return ai_anthropic($apiKey, $model ?: 'claude-3-5-sonnet-latest', $systemPrompt, $prompt);
            case 'gemini':
                return ai_gemini($apiKey, $model ?: 'gemini-1.5-flash', $systemPrompt, $prompt);
            default:
                return ['ok' => false, 'error' => 'Proveedor IA desconocido: ' . $provider];
        }
    } catch (\Throwable $e) {
        error_log('[ai_config] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Error al contactar la IA: ' . $e->getMessage()];
    }
}

function ai_http(string $url, array $headers, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'No se pudo contactar la API de IA: ' . $err];
    }
    $data = json_decode($resp, true);
    if ($code < 200 || $code >= 300) {
        return [
            'ok'    => false,
            'error' => $data['error']['message'] ?? "HTTP $code",
            'raw'   => $data,
        ];
    }
    return ['ok' => true, 'data' => $data];
}

function ai_openai(string $key, string $model, string $system, string $user): array {
    $r = ai_http('https://api.openai.com/v1/chat/completions', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature' => 0.3,
    ]);
    if (!$r['ok']) return $r;
    return ['ok' => true, 'text' => $r['data']['choices'][0]['message']['content'] ?? ''];
}

function ai_anthropic(string $key, string $model, string $system, string $user): array {
    $r = ai_http('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ], [
        'model'      => $model,
        'max_tokens' => 1024,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user]],
    ]);
    if (!$r['ok']) return $r;
    return ['ok' => true, 'text' => $r['data']['content'][0]['text'] ?? ''];
}

function ai_gemini(string $key, string $model, string $system, string $user): array {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($key);
    $r = ai_http($url, ['Content-Type: application/json'], [
        'system_instruction' => ['parts' => [['text' => $system]]],
        'contents'           => [['role' => 'user', 'parts' => [['text' => $user]]]],
    ]);
    if (!$r['ok']) return $r;
    return ['ok' => true, 'text' => $r['data']['candidates'][0]['content']['parts'][0]['text'] ?? ''];
}