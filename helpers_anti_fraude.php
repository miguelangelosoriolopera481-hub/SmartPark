<?php
/**
 * helpers_anti_fraude.php - Detección de fraude sin hardware
 * ============================================================
 * Estrategia: análisis de patrones + confirmación del vigilante + IA.
 * NO requiere cámaras ni sensores.
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mt_core.php';

/* ============================================================
 * ANÁLISIS DE PATRONES SOSPECHOSOS
 * ============================================================ */

if (!function_exists('analizar_registro_sospechoso')) {
    /**
     * Analiza si un registro cumple patrones sospechosos.
     * Devuelve ['sospechoso' => bool, 'razones' => array, 'nivel' => 'bajo|medio|alto']
     */
    function analizar_registro_sospechoso(
        PDO $pdo,
        int $empresaId,
        string $placa,
        string $tipoVehiculo,
        ?int $userId
    ): array {
        $razones = [];
        $nivel = 'bajo';

        try {
            
            if ($tipoVehiculo === 'bicicleta') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cupos WHERE empresa_id = :eid AND tipo = 'bicicleta'");
                $stmt->execute(['eid' => $empresaId]);
                $celdasBici = (int)$stmt->fetchColumn();
                if ($celdasBici === 0) {
                    $razones[] = 'No hay celdas para bicicletas creadas en este parqueadero.';
                    $nivel = 'alto';
                }
            }

            if ($userId && $tipoVehiculo === 'bicicleta') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM registros_parqueo 
                    WHERE user_id = :uid AND empresa_id = :eid 
                      AND tipo_vehiculo = 'bicicleta'
                      AND fecha_ingreso > (NOW() - INTERVAL 24 HOUR)
                ");
                $stmt->execute(['uid' => $userId, 'eid' => $empresaId]);
                $bicis24h = (int)$stmt->fetchColumn();
                if ($bicis24h >= 3) {
                    $razones[] = "Este usuario registró $bicis24h bicicletas en las últimas 24h.";
                    $nivel = 'alto';
                }
            }

            if ($tipoVehiculo === 'bicicleta' && str_starts_with($placa, 'BICI-')) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM registros_parqueo 
                    WHERE placa = :placa AND empresa_id = :eid
                      AND fecha_ingreso > (NOW() - INTERVAL 7 DAY)
                ");
                $stmt->execute(['placa' => $placa, 'eid' => $empresaId]);
                $veces = (int)$stmt->fetchColumn();
                if ($veces >= 3) {
                    $razones[] = "La placa $placa se registró $veces veces esta semana.";
                    $nivel = 'alto';
                }
            }

            if ($tipoVehiculo === 'bicicleta') {
                $stmt = $pdo->prepare("
                    SELECT AVG(TIMESTAMPDIFF(MINUTE, fecha_ingreso, fecha_salida)) AS promedio
                    FROM registros_parqueo
                    WHERE empresa_id = :eid AND tipo_vehiculo = 'bicicleta' 
                      AND estado = 'finalizado'
                      AND fecha_salida IS NOT NULL
                      AND fecha_ingreso > (NOW() - INTERVAL 30 DAY)
                ");
                $stmt->execute(['eid' => $empresaId]);
                $promedio = $stmt->fetchColumn();
                if ($promedio !== null && $promedio > 0 && $promedio < 5) {
                    $razones[] = "El promedio de salida de bicicletas es de " . round($promedio, 1) . " min (muy bajo, revisa).";
                    if ($nivel !== 'alto') $nivel = 'medio';
                }
            }

            if ($tipoVehiculo === 'bicicleta') {
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN tipo_vehiculo='bicicleta' THEN 1 ELSE 0 END) AS bicis,
                        SUM(CASE WHEN tipo_vehiculo IN ('carro','electrico') THEN 1 ELSE 0 END) AS carros
                    FROM registros_parqueo
                    WHERE empresa_id = :eid AND fecha_ingreso > (NOW() - INTERVAL 7 DAY)
                ");
                $stmt->execute(['eid' => $empresaId]);
                $r = $stmt->fetch();
                $carros = max(1, (int)$r['carros']);
                $bicis = (int)$r['bicis'];
                if ($bicis > $carros * 2 && $bicis > 10) {
                    $razones[] = "Registros de bicicletas superan en 2x a los carros.";
                    if ($nivel !== 'alto') $nivel = 'medio';
                }
            }

        } catch (\Throwable $e) {
            error_log('analizar_registro_sospechoso: ' . $e->getMessage());
        }

        return [
            'sospechoso' => !empty($razones),
            'razones' => $razones,
            'nivel' => $nivel,
        ];
    }
}

/* ============================================================
 * ANÁLISIS CON IA (Gemini)
 * ============================================================ */

if (!function_exists('analizar_con_ia')) {
    /**
     * Pide a Gemini que analice un registro y dé un veredicto.
     */
    function analizar_con_ia(PDO $pdo, array $contexto): ?array {
        $iaHabilitada = get_config($pdo, 'IA_HABILITADA', '1') === '1';
        if (!$iaHabilitada) return null;

        try {
            if (!file_exists(__DIR__ . '/config/ai_config.php')) return null;
            require_once __DIR__ . '/config/ai_config.php';
            if (!function_exists('ai_chat')) return null;

            $prompt = "Eres un analista antifraude de parqueaderos.\n"
                . "Analiza este registro y determina si es sospechoso:\n"
                . "Placa: {$contexto['placa']}\n"
                . "Tipo declarado: {$contexto['tipo_vehiculo']}\n"
                . "Datos: " . json_encode($contexto, JSON_UNESCAPED_UNICODE) . "\n\n"
                . "Responde SOLO JSON: {\"riesgo\":\"bajo|medio|alto\",\"analisis\":\"explicación breve\"}";

            $r = ai_chat($prompt, ['system' => 'Responde solo con JSON válido.']);
            if (!empty($r['ok']) && !empty($r['text'])) {
                $json = json_decode(trim($r['text']), true);
                if ($json && isset($json['riesgo'])) return $json;
            }
        } catch (\Throwable $e) {
            error_log('analizar_con_ia: ' . $e->getMessage());
        }
        return null;
    }
}

/* ============================================================
 * OBTENER ALERTAS PARA EL DASHBOARD
 * ============================================================ */

if (!function_exists('obtener_alertas_fraude')) {
    /**
     * Devuelve los registros sospechosos recientes para el panel admin.
     */
    function obtener_alertas_fraude(PDO $pdo, int $empresaId, int $limite = 20): array {
        try {
            $stmt = $pdo->prepare("
                SELECT id, placa, tipo_vehiculo, cupo_asignado, fecha_ingreso,
                       es_sospechoso, razon_sospechoso, analisis_ia, confirmado_vigilante,
                       nombre_ciclista, cedula_ciclista
                FROM registros_parqueo
                WHERE empresa_id = :eid 
                  AND es_sospechoso = 1
                  AND fecha_ingreso > (NOW() - INTERVAL 30 DAY)
                ORDER BY fecha_ingreso DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':eid', $empresaId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('obtener_alertas_fraude: ' . $e->getMessage());
            return [];
        }
    }
}

/* ============================================================
 * MARCAR REGISTRO COMO SOSPECHOSO
 * ============================================================ */

if (!function_exists('marcar_sospechoso')) {
    function marcar_sospechoso(PDO $pdo, int $registroId, array $analisis, ?string $iaAnalisis = null): void {
        try {
            $razones = implode(' | ', $analisis['razones']);
            $pdo->prepare("
                UPDATE registros_parqueo
                SET es_sospechoso = 1,
                    razon_sospechoso = :razon,
                    analisis_ia = :ia
                WHERE id = :id
            ")->execute([
                'razon' => $razones,
                'ia' => $iaAnalisis,
                'id' => $registroId,
            ]);
        } catch (\Throwable $e) {
            error_log('marcar_sospechoso: ' . $e->getMessage());
        }
    }
}

/* ============================================================
 * FRAUDE: PAGO SIN SALIDA
 * ============================================================
 * Detecta vehículos que:
 *   - Pagaron (estado_pago=1)
 *   - Pero siguen con estado='activo' (no han salido)
 *   - Y ya pasó el umbral de tiempo razonable (default: 2 horas desde el pago)
 *
 * Esto puede indicar:
 *   - Cliente intentó pagar solo las primeras horas y quedarse más
 *   - Cliente pagó y se fue sin autorizar salida (menos grave)
 *   - Fraude: pagar barato y quedarse todo el día
 */

if (!function_exists('detectar_pagos_sin_salida')) {
    function detectar_pagos_sin_salida(PDO $pdo, int $empresaId, int $horasUmbral = 2): array {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    r.id, r.placa, r.modelo, r.tipo_vehiculo, r.cupo_asignado,
                    r.fecha_ingreso, r.fecha_pago, r.horas, r.total_pagar,
                    r.estado_pago, r.estado,
                    TIMESTAMPDIFF(MINUTE, r.fecha_pago, NOW()) AS minutos_desde_pago,
                    TIMESTAMPDIFF(MINUTE, r.fecha_ingreso, NOW()) AS minutos_totales
                FROM registros_parqueo r
                WHERE r.empresa_id = :eid
                  AND r.estado_pago = 1
                  AND r.estado = 'activo'
                  AND r.fecha_pago IS NOT NULL
                  AND r.fecha_pago < (NOW() - INTERVAL :horas HOUR)
                ORDER BY r.fecha_pago ASC
            ");
            $stmt->bindValue(':eid', $empresaId, PDO::PARAM_INT);
            $stmt->bindValue(':horas', $horasUmbral, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('detectar_pagos_sin_salida: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('marcar_pago_sin_salida')) {
    /**
     * Marca un registro como fraude de pago sin salida.
     */
    function marcar_pago_sin_salida(PDO $pdo, int $registroId, string $razon): bool {
        try {
            $pdo->prepare("
                UPDATE registros_parqueo
                SET es_sospechoso = 1,
                    alerta_pago_sin_salida = 1,
                    razon_sospechoso = CONCAT(COALESCE(razon_sospechoso, ''), '[PagoSinSalida] ', :razon)
                WHERE id = :id
            ")->execute(['razon' => $razon, 'id' => $registroId]);
            return true;
        } catch (\Throwable $e) {
            error_log('marcar_pago_sin_salida: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('recalcular_cobro_al_salir')) {
    /**
     * Al autorizar la salida, calcula si hay que cobrar extra por
     * tiempo no cubierto por el pago original.
     *
     * Devuelve ['extra' => float, 'horas_extra' => int, 'mensaje' => string]
     */
    function recalcular_cobro_al_salir(PDO $pdo, array $registro, int $empresaId): array {
        $r = ['extra' => 0.0, 'horas_extra' => 0, 'mensaje' => ''];

        try {
            if (empty($registro['fecha_pago']) || (int)$registro['estado_pago'] !== 1) {
                return $r; // No aplica
            }

            // Horas pagadas originalmente
            $horasPagadas = (int)($registro['horas'] ?? 0);

            // Horas reales transcurridas
            $ingreso = new DateTime($registro['fecha_ingreso']);
            $ahora = new DateTime();
            $diff = $ingreso->diff($ahora);
            $horasReales = max(1, ($diff->days * 24) + $diff->h + ($diff->i > 0 ? 1 : 0));

            // Diferencia
            $horasExtra = max(0, $horasReales - $horasPagadas);

            if ($horasExtra > 0) {
                
                $stmt = $pdo->prepare("
                    SELECT precio FROM tarifas
                    WHERE empresa_id = :eid AND tipo_vehiculo = :tv AND tipo_tarifa = 'hora' AND activo = 1
                    ORDER BY vigente_desde DESC LIMIT 1
                ");
                $stmt->execute(['eid' => $empresaId, 'tv' => $registro['tipo_vehiculo']]);
                $tarifa = (float)($stmt->fetchColumn() ?: ($registro['tipo_vehiculo'] === 'moto' ? 2000 : 4000));

                $r['horas_extra'] = $horasExtra;
                $r['extra'] = $horasExtra * $tarifa;
                $r['mensaje'] = "Horas extra: {$horasExtra} × $" . number_format($tarifa, 0, ',', '.') . " = $" . number_format($r['extra'], 0, ',', '.');
            }
        } catch (\Throwable $e) {
            error_log('recalcular_cobro_al_salir: ' . $e->getMessage());
        }
        return $r;
    }
}
