<?php
/**
 * helpers.php - Utilidades de negocio (multi-tenant)
 * Compatible con el esquema nuevo de tarifas y auditoria.
 */
require_once __DIR__ . '/mt_core.php';

/* ============================================================
 * TARIFAS
 * ============================================================ */
if (!function_exists('obtenerTarifas')) {
    function obtenerTarifas(PDO $pdo): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $tarifas = ['carro' => 4000.0, 'moto' => 2000.0];

        try {
            $eid = current_empresa_id();
            $stmt = $pdo->prepare("
                SELECT tipo_vehiculo, precio
                FROM tarifas
                WHERE tipo_vehiculo IN ('carro','moto')
                  AND tipo_tarifa = 'hora'
                  AND activo = 1
                  AND (empresa_id = :eid OR :eid IS NULL)
                ORDER BY empresa_id DESC
            ");
            $stmt->execute(['eid' => $eid]);
            foreach ($stmt->fetchAll() as $fila) {
                $tarifas[$fila['tipo_vehiculo']] = (float)$fila['precio'];
            }
        } catch (\Throwable $e) {
            error_log('obtenerTarifas: ' . $e->getMessage());
        }

        $cache = $tarifas;
        return $tarifas;
    }
}

if (!function_exists('actualizarTarifa')) {
    function actualizarTarifa(PDO $pdo, string $tipo, float $precio): bool {
        if (!in_array($tipo, ['carro','moto'], true) || $precio < 0) return false;
        try {
            $eid = current_empresa_id();
            $stmt = $pdo->prepare("
                INSERT INTO tarifas (empresa_id, tipo_vehiculo, tipo_tarifa, precio, activo)
                VALUES (:eid, :tipo, 'hora', :precio, 1)
                ON DUPLICATE KEY UPDATE precio = VALUES(precio), actualizado_en = NOW()
            ");
            return $stmt->execute(['eid' => $eid, 'tipo' => $tipo, 'precio' => $precio]);
        } catch (\Throwable $e) {
            error_log('actualizarTarifa: ' . $e->getMessage());
            return false;
        }
    }
}

/* ============================================================
 * CALCULOS
 * ============================================================ */
if (!function_exists('calcularHorasYTotal')) {
    function calcularHorasYTotal(string $fechaIngreso, string $tipoVehiculo, array $tarifas): array {
        $ingreso = new DateTime($fechaIngreso);
        $ahora   = new DateTime();
        $dif     = $ingreso->diff($ahora);
        $horas   = max(1, ($dif->days * 24) + $dif->h + ($dif->i > 0 ? 1 : 0));
        $tarifaHora = $tarifas[$tipoVehiculo] ?? 0;
        return [$horas, $horas * $tarifaHora];
    }
}