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
        // Valores por defecto si la BD falla
        $tarifas = ['carro' => 4000.0, 'moto' => 2000.0];

        try {
            $eid = current_empresa_id();
            $sql = "SELECT tipo_vehiculo, precio FROM tarifas WHERE tipo_vehiculo IN ('carro','moto') AND tipo_tarifa = 'hora' AND activo = 1";
            $params = [];
            
            if ($eid !== null) {
                $sql .= " AND (empresa_id = :eid1 OR empresa_id IS NULL)";
                $params['eid1'] = $eid;
            }
            
            // FIX CRÍTICO: Ordenar ASC (Menor a mayor) para que las globales (NULL) se lean primero
            // y las de tu empresa (ID mayor) las sobrescriban al final.
            $sql .= " ORDER BY empresa_id ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $fila) {
                $tarifas[$fila['tipo_vehiculo']] = (float)$fila['precio'];
            }
        } catch (\Throwable $e) {
            error_log('obtenerTarifas: ' . $e->getMessage());
        }

        return $tarifas;
    }
}

if (!function_exists('actualizarTarifa')) {
    function actualizarTarifa(PDO $pdo, string $tipo, float $precio): bool {
        if (!in_array($tipo, ['carro','moto'], true) || $precio < 0) return false;
        try {
            $eid = current_empresa_id();
            if ($eid === null) return false; // No permitir modificar globales desde aquí
            
            // 1. Intentar actualizar la tarifa que ya existe para esta empresa
            $stmt = $pdo->prepare("
                UPDATE tarifas 
                SET precio = :p1, precio_hora = :p2, actualizado_en = NOW() 
                WHERE empresa_id = :eid AND tipo_vehiculo = :tipo AND tipo_tarifa = 'hora'
            ");
            $stmt->execute(['p1' => $precio, 'p2' => $precio, 'eid' => $eid, 'tipo' => $tipo]);
            
            // 2. Si la empresa no tenía una tarifa propia (0 filas afectadas), la creamos
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO tarifas (empresa_id, tipo_vehiculo, tipo_tarifa, precio, precio_hora, activo) 
                    VALUES (:eid, :tipo, 'hora', :p1, :p2, 1)
                ");
                $stmt->execute(['eid' => $eid, 'tipo' => $tipo, 'p1' => $precio, 'p2' => $precio]);
            }
            return true;
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