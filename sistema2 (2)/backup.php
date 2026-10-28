<?php
/**
 * backup.php - Generación de copias de seguridad de la base de datos
 * ----------------------------------------------------------------------
 * Implementado en PHP puro (SHOW CREATE TABLE + SELECT) en vez de invocar
 * el binario `mysqldump` con exec()/shell_exec(), porque muchos hostings
 * compartidos deshabilitan esas funciones por seguridad. Esta operación es
 * de SOLO LECTURA sobre la base de datos: nunca hace DROP, DELETE ni UPDATE,
 * por lo que no puede dañar ni vaciar los datos en producción.
 */

if (!function_exists('generarBackupSQL')) {
    function generarBackupSQL(PDO $pdo, string $rutaDestino): bool
    {
        try {
            $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $sql = "-- ============================================================\n";
            $sql .= "-- Backup SmartPark Pro generado el " . date('Y-m-d H:i:s') . "\n";
            $sql .= "-- Generado desde el panel de administración (config.php)\n";
            $sql .= "-- ============================================================\n\n";
            $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tablas as $tabla) {
                $crear = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch();
                $sql .= "-- Estructura de la tabla `$tabla`\n";
                $sql .= "DROP TABLE IF EXISTS `$tabla`;\n";
                $sql .= $crear['Create Table'] . ";\n\n";

                $filas = $pdo->query("SELECT * FROM `$tabla`");
                $numFilas = 0;
                foreach ($filas as $fila) {
                    if ($numFilas === 0) {
                        $sql .= "-- Datos de la tabla `$tabla`\n";
                    }
                    $columnas = array_map(fn($c) => "`$c`", array_keys($fila));
                    $valores = array_map(function ($v) use ($pdo) {
                        return $v === null ? 'NULL' : $pdo->quote((string) $v);
                    }, array_values($fila));

                    $sql .= "INSERT INTO `$tabla` (" . implode(', ', $columnas) . ") VALUES (" . implode(', ', $valores) . ");\n";
                    $numFilas++;
                }
                $sql .= "\n";
            }

            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

            return file_put_contents($rutaDestino, $sql) !== false;
        } catch (\Throwable $e) {
            error_log('generarBackupSQL: ' . $e->getMessage());
            return false;
        }
    }
}
