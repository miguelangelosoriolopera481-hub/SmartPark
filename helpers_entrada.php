<?php
/**
 * helpers_entrada.php - Sistema de entrada inteligente v2
 * =========================================================
 * Soporta: carro, moto, bicicleta, carro eléctrico
 * - Detección de placas colombianas por formato
 * - Sugerencia de corrección de typos (O→0, I→1, S→5...)
 * - Detección de vehículo ya dentro (anti-duplicados)
 * - Asignación de celdas: historial → IA → fallback
 * - Auditoría de intentos fallidos
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mt_core.php';

/* ============================================================
 * CONSTANTES DE TIPO
 * ============================================================ */
if (!defined('TIPO_CARRO'))     define('TIPO_CARRO', 'carro');
if (!defined('TIPO_MOTO'))      define('TIPO_MOTO', 'moto');
if (!defined('TIPO_BICI'))      define('TIPO_BICI', 'bicicleta');
if (!defined('TIPO_ELECTRICO')) define('TIPO_ELECTRICO', 'electrico');

if (!function_exists('tipos_vehiculo_validos')) {
    function tipos_vehiculo_validos(): array {
        return [TIPO_CARRO, TIPO_MOTO, TIPO_BICI, TIPO_ELECTRICO];
    }
}

if (!function_exists('etiqueta_tipo_vehiculo')) {
    function etiqueta_tipo_vehiculo(string $tipo): array {
        $mapa = [
            'carro'     => ['icono' => '🚗', 'nombre' => 'Carro',           'color' => '#10b981'],
            'moto'      => ['icono' => '🏍️', 'nombre' => 'Moto',            'color' => '#06b6d4'],
            'bicicleta' => ['icono' => '🚲', 'nombre' => 'Bicicleta',       'color' => '#a78bfa'],
            'electrico' => ['icono' => '⚡', 'nombre' => 'Carro eléctrico', 'color' => '#f59e0b'],
        ];
        return $mapa[$tipo] ?? ['icono' => '❓', 'nombre' => 'Desconocido', 'color' => '#64748b'];
    }
}

/* ============================================================
 * NORMALIZACIÓN Y DETECCIÓN DE PLACA
 * ============================================================ */

if (!function_exists('normalizar_placa_entrada')) {
    function normalizar_placa_entrada(string $placa): string {
        $placa = strtoupper(trim($placa));
        // Bicicleta: BICI-XXXX
        if (stripos($placa, 'BICI') === 0) {
            $num = preg_replace('/[^0-9]/', '', $placa);
            return 'BICI-' . str_pad(substr($num, 0, 4), 4, '0', STR_PAD_LEFT);
        }
        return preg_replace('/[^A-Z0-9]/', '', $placa);
    }
}

if (!function_exists('detectar_tipo_placa')) {

    function detectar_tipo_placa(string $placa): ?string {
        $placa = strtoupper(trim($placa));
        if (preg_match('/^[A-Z]{3}[0-9]{3}$/', $placa)) return TIPO_CARRO;
        if (preg_match('/^[A-Z]{3}[0-9]{2}[A-Z]$/', $placa)) return TIPO_MOTO;
        if (preg_match('/^BICI-[0-9]{4}$/', $placa)) return TIPO_BICI;
        return null;
    }
}

if (!function_exists('validar_placa_detallada')) {
    function validar_placa_detallada(string $placaOriginal): array {
        $placa = normalizar_placa_entrada($placaOriginal);
        $r = ['valida' => false, 'tipo' => null, 'mensaje' => '', 'sugerencia' => ''];

        if ($placa === '') {
            $r['mensaje'] = 'Ingresa una placa.';
            return $r;
        }
        if (str_starts_with($placa, 'BICI-')) {
            $r['valida'] = true;
            $r['tipo'] = TIPO_BICI;
            $r['mensaje'] = 'Bicicleta válida.';
            return $r;
        }
        if (strlen($placa) < 6) {
            $r['mensaje'] = 'La placa debe tener 6 caracteres.';
            $r['sugerencia'] = 'Ejemplo: ABC123 (carro) o ABC12D (moto).';
            return $r;
        }
        if (strlen($placa) > 6) {
            $r['mensaje'] = 'La placa tiene demasiados caracteres.';
            $r['sugerencia'] = 'Máximo 6 (letras y números).';
            return $r;
        }

        $tipo = detectar_tipo_placa($placa);
        if ($tipo === null) {
            $r['mensaje'] = 'Formato de placa no reconocido.';
            $r['sugerencia'] = 'Carro: ABC123 (termina en número). Moto: ABC12D (termina en letra).';
            return $r;
        }

        $r['valida'] = true;
        $r['tipo'] = $tipo;
        $r['mensaje'] = 'Placa válida.';
        return $r;
    }
}

if (!function_exists('sugerir_placa_corregida')) {

    function sugerir_placa_corregida(string $placa): ?string {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', $placa));
        if (strlen($placa) !== 6) return null;

        $mapa = ['O' => '0', 'I' => '1', 'S' => '5', 'B' => '8', 'Z' => '2', 'G' => '6'];

        // Intento 1: convertir las últimas 3 posiciones a números (para carro)
        $sug1 = $placa;
        for ($i = 3; $i < 6; $i++) {
            if (isset($mapa[$sug1[$i]])) $sug1[$i] = $mapa[$sug1[$i]];
        }
        if (detectar_tipo_placa($sug1) === TIPO_CARRO && $sug1 !== $placa) return $sug1;

        // Intento 2: convertir las posiciones 4-5 a números (para moto)
        $sug2 = $placa;
        for ($i = 3; $i < 5; $i++) {
            if (isset($mapa[$sug2[$i]])) $sug2[$i] = $mapa[$sug2[$i]];
        }
        if (detectar_tipo_placa($sug2) === TIPO_MOTO && $sug2 !== $placa) return $sug2;

        return null;
    }
}

/* ============================================================
 * DETECCIÓN DE VEHÍCULO YA DENTRO
 * ============================================================ */

if (!function_exists('detectar_vehiculo_dentro')) {
    /**
     * Detecta si una placa ya está activa.
     * REGLA COLOMBIA: las placas son únicas a nivel país.
     * Por eso buscamos en TODAS las empresas.
     */
    function detectar_vehiculo_dentro(PDO $pdo, int $empresaId, string $placa): ?array {
        try {
            
            $stmt = $pdo->prepare("
                SELECT r.*, p.nombre AS parqueadero_nombre, e.nombre AS empresa_nombre,
                       TIMESTAMPDIFF(MINUTE, r.fecha_ingreso, NOW()) AS minutos_dentro
                FROM registros_parqueo r
                LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
                LEFT JOIN empresas e ON e.id = r.empresa_id
                WHERE r.placa = :placa AND r.estado = 'activo'
                ORDER BY r.id DESC LIMIT 1
            ");
            $stmt->execute(['placa' => $placa]);
            $row = $stmt->fetch();

            // Si no está activo en ningún lado → OK
            if (!$row) return null;

            // Si está activo → devolver con info de si es la misma empresa
            $row['es_misma_empresa'] = ((int)$row['empresa_id'] === $empresaId);
            return $row;
        } catch (\Throwable $e) {
            error_log('detectar_vehiculo_dentro: ' . $e->getMessage());
            return null;
        }
    }
}

/* ============================================================
 * HISTORIAL DE CELDAS
 * ============================================================ */

if (!function_exists('historial_placas_celda')) {
    function historial_placas_celda(PDO $pdo, int $empresaId, string $placa): ?string {
        try {
            $stmt = $pdo->prepare("
                SELECT cupo_asignado, COUNT(*) AS veces
                FROM registros_parqueo
                WHERE placa = :placa AND empresa_id = :eid AND estado = 'finalizado'
                GROUP BY cupo_asignado
                ORDER BY veces DESC LIMIT 1
            ");
            $stmt->execute(['placa' => $placa, 'eid' => $empresaId]);
            $r = $stmt->fetch();
            return ($r && (int)$r['veces'] >= 2) ? $r['cupo_asignado'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

/* ============================================================
 * ASIGNACIÓN DE CELDA
 * ============================================================ */

if (!function_exists('mapear_tipo_a_cupo')) {

    function mapear_tipo_a_cupo(string $tipoVehiculo): array {
        return match($tipoVehiculo) {
            TIPO_CARRO     => [TIPO_CARRO],
            TIPO_MOTO      => [TIPO_MOTO],
            TIPO_BICI      => [TIPO_BICI],
            TIPO_ELECTRICO => [TIPO_ELECTRICO, TIPO_CARRO],
            default        => [TIPO_CARRO],
        };
    }
}

if (!function_exists('asignar_celda_disponible')) {
    function asignar_celda_disponible(PDO $pdo, int $empresaId, string $tipoVehiculo, ?int $parqueaderoId = null): ?string {
        try {
            $tiposCupo = mapear_tipo_a_cupo($tipoVehiculo);

            foreach ($tiposCupo as $tipoCupo) {
                $sql = "SELECT codigo_cupo FROM cupos
                        WHERE empresa_id = :eid AND tipo = :tipo AND estado = 'disponible'";
                $params = ['eid' => $empresaId, 'tipo' => $tipoCupo];
                if ($parqueaderoId) { $sql .= " AND parqueadero_id = :pid"; $params['pid'] = $parqueaderoId; }
                $sql .= " ORDER BY LENGTH(codigo_cupo), codigo_cupo LIMIT 1";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $codigo = $stmt->fetchColumn();
                if ($codigo) return $codigo;
            }
            return null;
        } catch (\Throwable $e) {
            error_log('asignar_celda_disponible: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('sugerir_celda_con_ia')) {
    function sugerir_celda_con_ia(PDO $pdo, int $empresaId, string $placa, string $tipoVehiculo): ?array {
        // Solo si IA está habilitada
        $iaHabilitada = get_config($pdo, 'IA_HABILITADA', '1') === '1';
        if (!$iaHabilitada) return null;

        $celdaPreferida = historial_placas_celda($pdo, $empresaId, $placa);
        if ($celdaPreferida) {
            $stmt = $pdo->prepare("SELECT codigo_cupo FROM cupos WHERE codigo_cupo = :c AND empresa_id = :eid AND estado = 'disponible' LIMIT 1");
            $stmt->execute(['c' => $celdaPreferida, 'eid' => $empresaId]);
            if ($stmt->fetch()) {
                return ['celda' => $celdaPreferida, 'razon' => 'Este vehículo suele estacionarse aquí', 'fuente' => 'historial'];
            }
        }

        try {
            if (!file_exists(__DIR__ . '/config/ai_config.php')) return null;
            require_once __DIR__ . '/config/ai_config.php';
            if (!function_exists('ai_chat')) return null;

            $tiposCupo = mapear_tipo_a_cupo($tipoVehiculo);
            $placeholders = implode(',', array_fill(0, count($tiposCupo), '?'));
            $sql = "SELECT codigo_cupo FROM cupos WHERE empresa_id = ? AND tipo IN ($placeholders) AND estado = 'disponible' ORDER BY LENGTH(codigo_cupo), codigo_cupo LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$empresaId], $tiposCupo));
            $celdasLibres = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($celdasLibres)) return null;

            $prompt = "Eres SmartPark AI. Analiza:\n"
                . "- Tipo vehículo: $tipoVehiculo\n"
                . "- Placa: $placa\n"
                . "- Celdas disponibles: " . implode(', ', $celdasLibres) . "\n"
                . "- Hora actual: " . date('H:i') . "\n\n"
                . "Responde SOLO con JSON: {\"celda\":\"CODIGO\",\"razon\":\"explicación corta\"}";

            $r = ai_chat($prompt, ['system' => 'Responde ÚNICAMENTE con JSON válido.']);
            if (!empty($r['ok']) && !empty($r['text'])) {
                $json = json_decode(trim($r['text']), true);
                if ($json && isset($json['celda']) && in_array($json['celda'], $celdasLibres, true)) {
                    return ['celda' => $json['celda'], 'razon' => $json['razon'] ?? 'Sugerencia IA', 'fuente' => 'ia'];
                }
            }
        } catch (\Throwable $e) {
            error_log('sugerir_celda_con_ia: ' . $e->getMessage());
        }
        return null;
    }
}

/* ============================================================
 * CREAR REGISTRO DE ENTRADA
 * ============================================================ */

if (!function_exists('crear_registro_entrada')) {
    function crear_registro_entrada(
        PDO $pdo,
        int $empresaId,
        ?int $parqueaderoId,
        string $placa,
        string $modelo,
        string $tipoVehiculo,
        string $celda,
        ?int $userId = null,
        string $correo = '',
        string $tipoTarifa = 'hora',
        bool $esElectrico = false
    ): array {
        $r = ['exito' => false, 'registro_id' => 0, 'token_qr' => '', 'celda' => '', 'mensaje' => ''];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT codigo_cupo FROM cupos WHERE codigo_cupo = :c AND empresa_id = :eid AND estado = 'disponible' FOR UPDATE");
            $stmt->execute(['c' => $celda, 'eid' => $empresaId]);
            if (!$stmt->fetch()) {
                $pdo->rollBack();
                $r['mensaje'] = 'La celda ya no está disponible.';
                return $r;
            }

            // Tipo final a guardar en registros_parqueo
            $tipoGuardar = ($esElectrico && $tipoVehiculo === TIPO_CARRO) ? TIPO_ELECTRICO : $tipoVehiculo;

            // Token único para el ticket/factura (columna real: token_factura)
            $tokenQR = bin2hex(random_bytes(16));

            $ins = $pdo->prepare("
                INSERT INTO registros_parqueo
                (empresa_id, parqueadero_id, placa, modelo, correo, tipo_vehiculo, cupo_asignado,
                 fecha_ingreso, estado, user_id, tipo_tarifa, token_factura)
                VALUES (:eid, :pid, :placa, :modelo, :correo, :tipo, :cupo,
                        NOW(), 'activo', :uid, :tt, :qr)
            ");
            $ins->execute([
                'eid' => $empresaId,
                'pid' => $parqueaderoId,
                'placa' => $placa,
                'modelo' => $modelo ?: 'N/A',
                'correo' => $correo,
                'tipo' => $tipoGuardar,
                'cupo' => $celda,
                'uid' => $userId,
                'tt' => $tipoTarifa,
                'qr' => $tokenQR,
            ]);
            $registroId = (int)$pdo->lastInsertId();

            // Marcar celda como ocupada
            $pdo->prepare("UPDATE cupos SET estado = 'ocupado' WHERE codigo_cupo = :c AND empresa_id = :eid")
                ->execute(['c' => $celda, 'eid' => $empresaId]);

            if ($userId && !str_starts_with($placa, 'BICI-')) {
                $pdo->prepare("
                    INSERT INTO user_vehiculo (empresa_id, placa, modelo, correo, tipo_vehiculo, user_id)
                    VALUES (:eid, :placa, :modelo, :correo, :tipo, :uid)
                    ON DUPLICATE KEY UPDATE modelo = VALUES(modelo), user_id = COALESCE(VALUES(user_id), user_id)
                ")->execute([
                    'eid' => $empresaId,
                    'placa' => $placa,
                    'modelo' => $modelo,
                    'correo' => $correo,
                    'tipo' => $tipoGuardar,
                    'uid' => $userId,
                ]);
            }

            $pdo->commit();

            $r['exito'] = true;
            $r['registro_id'] = $registroId;
            $r['token_qr'] = $tokenQR;
            $r['celda'] = $celda;
            $r['mensaje'] = 'Vehículo registrado correctamente.';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('crear_registro_entrada: ' . $e->getMessage());
            $r['mensaje'] = 'Error al registrar. Intenta nuevamente.';
        }
        return $r;
    }
}

/* ============================================================
 * AUDITORÍA DE INTENTOS FALLIDOS
 * ============================================================ */

if (!function_exists('registrar_intento_placa_invalida')) {
    function registrar_intento_placa_invalida(PDO $pdo, int $empresaId, string $placa, string $razon): void {
        try {
            $pdo->prepare("
                INSERT INTO auditoria_admin (empresa_id, usuario_id, usuario_nombre, accion, detalle, ip, creado_en)
                VALUES (:eid, :uid, :nombre, 'placa_invalida_intento', :detalle, :ip, NOW())
            ")->execute([
                'eid' => $empresaId,
                'uid' => $_SESSION['usuario_id'] ?? null,
                'nombre' => $_SESSION['nombre'] ?? 'sistema',
                'detalle' => "placa=$placa razon=$razon",
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('registrar_intento_placa_invalida: ' . $e->getMessage());
        }
    }
}