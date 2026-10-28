<?php
/**
 * backup.php - Motor de backups SEGURO
 * ====================================
 * - Backup POR EMPRESA (solo datos de esa empresa)
 * - Backup GLOBAL (solo developer, todos los datos)
 * - Rate limiting (máx 5/hora por usuario)
 * - Audit log de cada operación
 * - Anti path traversal
 * - Archivos NO eliminables por empresas (solo developer)
 */
require_once __DIR__ . '/conexion.php';

const BACKUP_DIR_NAME = 'backups';
const BACKUP_MAX_POR_HORA = 5;
const BACKUP_MAX_TOTAL_MB = 500;

/* ============================================================
 * UTILIDADES
 * ============================================================ */

if (!function_exists('backup_dir')) {
    function backup_dir(): string {
        $dir = __DIR__ . '/' . BACKUP_DIR_NAME;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, 
                "# Bloquear acceso directo web a los backups\n" .
                "Require all denied\n" .
                "<IfModule !mod_authz_core.c>\n" .
                "    Order allow,deny\n" .
                "    Deny from all\n" .
                "</IfModule>\n"
            );
        }
        
        $gitignore = $dir . '/.gitignore';
        if (!is_file($gitignore)) {
            file_put_contents($gitignore, "*\n!.gitignore\n!.htaccess\n");
        }
        return $dir;
    }
}

if (!function_exists('backup_nombre_valido')) {
    /**
     * Formato: backup_[tipo]_[scope]_[YYYYMMDD]_[HHMMSS]_[token].sql
     * Ejemplos:
     *   backup_global_emp0_20261008_070343_a1b2c3d4.sql
     *   backup_empresa_emp5_20261008_070343_a1b2c3d4.sql
     */
    function backup_nombre_valido(string $nombre): bool {
        return (bool) preg_match('/^backup_(global|empresa)_emp[0-9]+_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}\.sql$/', $nombre);
    }
}

if (!function_exists('backup_generar_nombre')) {
    function backup_generar_nombre(string $tipo, int $empresaId): string {
        $token = bin2hex(random_bytes(4));
        return 'backup_' . $tipo . '_emp' . $empresaId . '_' . date('Ymd_His') . '_' . $token . '.sql';
    }
}

/* ============================================================
 * RATE LIMITING
 * ============================================================ */

if (!function_exists('backup_puede_crear')) {
    function backup_puede_crear(PDO $pdo, ?int $empresaId): array {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM auditoria_admin
                WHERE accion = 'backup_creado'
                  AND empresa_id " . ($empresaId === null ? "IS NULL" : "= :eid") . "
                  AND creado_en > (NOW() - INTERVAL 1 HOUR)
            ");
            $params = $empresaId === null ? [] : ['eid' => $empresaId];
            $stmt->execute($params);
            $cantidad = (int)$stmt->fetchColumn();
            if ($cantidad >= BACKUP_MAX_POR_HORA) {
                return ['ok' => false, 'mensaje' => "Límite alcanzado: máximo " . BACKUP_MAX_POR_HORA . " backups por hora."];
            }
            return ['ok' => true, 'mensaje' => ''];
        } catch (\Throwable $e) {
            return ['ok' => true, 'mensaje' => ''];
        }
    }
}

if (!function_exists('backup_log')) {
    function backup_log(PDO $pdo, ?int $empresaId, string $accion, string $detalle = ''): void {
        try {
            $nombre = $_SESSION['nombre'] ?? 'sistema';
            $uid = $_SESSION['usuario_id'] ?? null;
            $pdo->prepare("
                INSERT INTO auditoria_admin (empresa_id, usuario_id, usuario_nombre, accion, detalle, ip, creado_en)
                VALUES (:e, :u, :n, :a, :d, :ip, NOW())
            ")->execute([
                'e' => $empresaId,
                'u' => $uid,
                'n' => $nombre,
                'a' => $accion,
                'd' => $detalle,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $ex) {
            error_log('backup_log: ' . $ex->getMessage());
        }
    }
}

/* ============================================================
 * BACKUP POR EMPRESA
 * ============================================================ */

if (!function_exists('generarBackupEmpresa')) {
    /**
     * Genera backup con SOLO datos de una empresa.
     * @return string|false Nombre del archivo o false
     */
    function generarBackupEmpresa(PDO $pdo, int $empresaId): string|false {
        if ($empresaId <= 0) return false;

        try {
            
            $stmt = $pdo->prepare("SELECT id, nombre, nit FROM empresas WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $empresaId]);
            $empresa = $stmt->fetch();
            if (!$empresa) return false;

            $sql  = "-- ============================================================\n";
            $sql .= "-- SmartPark Pro — Backup de la empresa\n";
            $sql .= "-- Empresa: {$empresa['nombre']} (ID: {$empresa['id']})\n";
            $sql .= "-- NIT: " . ($empresa['nit'] ?? 'N/A') . "\n";
            $sql .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- ============================================================\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
            $sql .= "SET NAMES utf8mb4;\n\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- DATOS DE LA EMPRESA\n";
            $sql .= "-- ============================================================\n";
            $sql .= "INSERT INTO `empresas` (`id`, `nombre`, `nit`, `telefono`, `direccion`, `email_contacto`, `logo_url`, `estado`, `fecha_creacion`, `estado_aprobacion`, `fecha_aprobacion`, `aprobado_por`, `notas_admin`)\n";
            $sql .= "VALUES (" . implode(', ', [
                (int)$empresa['id'],
                $pdo->quote($empresa['nombre']),
                $empresa['nit'] ? $pdo->quote($empresa['nit']) : 'NULL',
                'NULL', 'NULL', 'NULL', 'NULL',
                "'activa'",
                'NOW()',
                "'aprobada'",
                'NOW()', 'NULL', 'NULL',
            ]) . ");\n\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- PARQUEADEROS DE LA EMPRESA\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'parqueaderos', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- USUARIOS VINCULADOS A LA EMPRESA\n";
            $sql .= "-- ============================================================\n";
            $stmtU = $pdo->prepare("SELECT user_id FROM usuarios_empresa WHERE empresa_id = :eid");
            $stmtU->execute(['eid' => $empresaId]);
            $uids = $stmtU->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($uids)) {
                $ids = implode(',', array_map('intval', $uids));
                $sql .= _backup_tabla_filtrada($pdo, 'user', "id IN ($ids)", ['password', 'username', 'nombre', 'correo', 'role', 'estado', 'empresa_default_id', 'fecha_creacion', 'telefono', 'argon2_migrado', 'must_change_password']);
                $sql .= "\n";
            }

            $sql .= "-- ============================================================\n";
            $sql .= "-- VÍNCULOS USUARIO-EMPRESA\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'usuarios_empresa', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- CELDAS (CUPOS) DE LA EMPRESA\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'cupos', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- REGISTROS DE PARQUEO\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'registros_parqueo', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- VEHÍCULOS REGISTRADOS\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'user_vehiculo', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- TARIFAS\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'tarifas', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- PAGOS REGISTRADOS\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'pagos', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- FACTURAS QR\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'facturas_qr', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- RESERVAS\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'reservas', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- LISTA DE ESPERA\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'waitlist', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- CONFIGURACIÓN ESPECÍFICA DE LA EMPRESA\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'configuracion', 'empresa_id = ' . (int)$empresaId);
            $sql .= "\n";

            $sql .= "-- ============================================================\n";
            $sql .= "-- LOGS DE AUDITORÍA\n";
            $sql .= "-- ============================================================\n";
            $sql .= _backup_tabla_filtrada($pdo, 'auditoria_admin', 'empresa_id = ' . (int)$empresaId, null, 500); // últimos 500
            $sql .= "\n";

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            $sql .= "-- FIN DEL BACKUP — Empresa: {$empresa['nombre']}\n";

            $nombre = backup_generar_nombre('empresa', $empresaId);
            $ruta = backup_dir() . '/' . $nombre;
            if (file_put_contents($ruta, $sql) === false) {
                return false;
            }

            backup_log($pdo, $empresaId, 'backup_creado', "Archivo: $nombre (" . formatear_tamano(filesize($ruta)) . ")");
            return $nombre;

        } catch (\Throwable $e) {
            error_log('generarBackupEmpresa: ' . $e->getMessage());
            return false;
        }
    }
}

/* ============================================================
 * BACKUP GLOBAL (DEVELOPER)
 * ============================================================ */

if (!function_exists('generarBackupGlobal')) {
    function generarBackupGlobal(PDO $pdo): string|false {
        try {
            $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($tablas)) return false;

            $sql  = "-- ============================================================\n";
            $sql .= "-- SmartPark Pro — BACKUP GLOBAL (todas las empresas)\n";
            $sql .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Base de datos: " . env('DB_NAME', 'smartpark') . "\n";
            $sql .= "-- Tablas: " . count($tablas) . "\n";
            $sql .= "-- Generado por developer\n";
            $sql .= "-- ============================================================\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
            $sql .= "SET NAMES utf8mb4;\n\n";

            foreach ($tablas as $tabla) {
                $crear = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch();
                $sql .= "-- ============================================================\n";
                $sql .= "-- TABLA: `$tabla`\n";
                $sql .= "-- ============================================================\n";
                $sql .= "DROP TABLE IF EXISTS `$tabla`;\n";
                $sql .= $crear['Create Table'] . ";\n\n";

                $filas = $pdo->query("SELECT * FROM `$tabla`");
                $numFilas = 0;
                foreach ($filas as $fila) {
                    $columnas = array_map(fn($c) => "`$c`", array_keys($fila));
                    $valores = array_map(function ($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        if (is_bool($v)) return $v ? '1' : '0';
                        return $pdo->quote((string) $v);
                    }, array_values($fila));
                    $sql .= "INSERT INTO `$tabla` (" . implode(', ', $columnas) . ") VALUES (" . implode(', ', $valores) . ");\n";
                    $numFilas++;
                }
                if ($numFilas === 0) $sql .= "-- (Sin datos)\n";
                else $sql .= "-- Total: $numFilas fila(s)\n";
                $sql .= "\n";
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            $sql .= "-- FIN DEL BACKUP GLOBAL\n";

            $nombre = backup_generar_nombre('global', 0);
            $ruta = backup_dir() . '/' . $nombre;
            if (file_put_contents($ruta, $sql) === false) return false;

            backup_log($pdo, null, 'backup_global_creado', "Archivo: $nombre (" . formatear_tamano(filesize($ruta)) . ")");
            return $nombre;
        } catch (\Throwable $e) {
            error_log('generarBackupGlobal: ' . $e->getMessage());
            return false;
        }
    }
}

/* ============================================================
 * HELPER INTERNO: backup de una tabla filtrada
 * ============================================================ */

if (!function_exists('_backup_tabla_filtrada')) {
    function _backup_tabla_filtrada(PDO $pdo, string $tabla, string $where, ?array $columnasFiltro = null, ?int $limite = null): string {
        $sql = "";
        try {
            // Estructura
            $crear = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch();
            if (!$crear) return "-- Tabla `$tabla` no existe\n";
            $sql .= "DROP TABLE IF EXISTS `$tabla`;\n";
            $sql .= $crear['Create Table'] . ";\n\n";

            // Datos filtrados
            $q = "SELECT * FROM `$tabla` WHERE $where";
            if ($limite !== null) $q .= " ORDER BY id DESC LIMIT $limite";
            $filas = $pdo->query($q);
            $num = 0;
            foreach ($filas as $fila) {
                if ($columnasFiltro !== null) {
                    $fila = array_intersect_key($fila, array_flip($columnasFiltro));
                }
                $cols = array_map(fn($c) => "`$c`", array_keys($fila));
                $vals = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    if (is_bool($v)) return $v ? '1' : '0';
                    return $pdo->quote((string) $v);
                }, array_values($fila));
                $sql .= "INSERT INTO `$tabla` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                $num++;
            }
            if ($num === 0) $sql .= "-- (Sin datos)\n";
            else $sql .= "-- Total: $num fila(s)\n";
        } catch (\Throwable $e) {
            $sql .= "-- Error al respaldar `$tabla`: " . $e->getMessage() . "\n";
        }
        return $sql;
    }
}

/* ============================================================
 * LISTAR / ELIMINAR
 * ============================================================ */

if (!function_exists('listar_backups')) {
    function listar_backups(): array {
        $dir = backup_dir();
        $archivos = glob($dir . '/backup_*.sql') ?: [];
        $resultado = [];
        foreach ($archivos as $ruta) {
            $nombre = basename($ruta);
            if (!backup_nombre_valido($nombre)) continue;
            // Extraer tipo y empresa del nombre
            preg_match('/^backup_(global|empresa)_emp([0-9]+)_([0-9]{8})_([0-9]{6})_/', $nombre, $m);
            $resultado[] = [
                'nombre' => $nombre,
                'tamano' => filesize($ruta),
                'fecha'  => date('Y-m-d H:i:s', filemtime($ruta)),
                'tipo'   => $m[1] ?? 'desconocido',
                'empresa_id' => isset($m[2]) ? (int)$m[2] : 0,
            ];
        }
        usort($resultado, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
        return $resultado;
    }
}

if (!function_exists('eliminar_backup')) {
    /**
     * Eliminar backup — SOLO developer.
     * Empresas NO pueden eliminar sus propios backups por seguridad.
     */
    function eliminar_backup(PDO $pdo, string $nombre, string $rol): bool {
        // Guard: solo developer
        if ($rol !== 'developer') {
            backup_log($pdo, null, 'backup_eliminacion_denegada', "Intento de eliminar $nombre");
            return false;
        }
        if (!backup_nombre_valido($nombre)) return false;
        $ruta = backup_dir() . '/' . $nombre;
        if (!is_file($ruta)) return false;
        $real = realpath($ruta);
        $realDir = realpath(backup_dir());
        if ($real === false || $realDir === false || strpos($real, $realDir) !== 0) return false;

        $resultado = @unlink($ruta);
        if ($resultado) {
            backup_log($pdo, null, 'backup_eliminado', "Archivo: $nombre");
        }
        return $resultado;
    }
}

if (!function_exists('formatear_tamano')) {
    function formatear_tamano(int $bytes): string {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1024 / 1024, 2) . ' MB';
    }
}
