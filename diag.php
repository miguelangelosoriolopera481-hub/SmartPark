<?php
error_reporting(E_ALL);
ini_set("display_errors", "1");

echo "=== DIAGNOSTICO COMPLETO ===\n\n";

echo "1. Session antes de conexion:\n";
echo "   isset($_SESSION[empresa_id]): " . (isset($_SESSION["empresa_id"]) ? "SI" : "NO") . "\n";

require_once __DIR__ . "/conexion.php";

echo "\n2. Session despues de conexion:\n";
echo "   isset($_SESSION[empresa_id]): " . (isset($_SESSION["empresa_id"]) ? "SI" : "NO") . "\n";
echo "   current_empresa_id(): " . var_export(current_empresa_id(), true) . "\n";

echo "\n3. Contenido crudo de tabla configuracion (MAIL*):\n";
try {
    $rows = $pdo->query("SELECT empresa_id, clave, valor FROM configuracion WHERE clave LIKE \"MAIL%\" ORDER BY clave")->fetchAll();
    foreach ($rows as $r) {
        echo "   empresa_id=" . var_export($r["empresa_id"], true) . " | " . $r["clave"] . " = " . substr($r["valor"], 0, 30) . "\n";
    }
    if (empty($rows)) echo "   (no hay filas MAIL*)\n";
} catch (\Throwable $e) { echo "   ERROR: " . $e->getMessage() . "\n"; }

echo "\n4. Test directo de query global:\n";
try {
    $stmt = $pdo->prepare("SELECT valor FROM configuracion WHERE empresa_id IS NULL AND clave = :k LIMIT 1");
    $stmt->execute(["k" => "MAIL_USER"]);
    $val = $stmt->fetchColumn();
    echo "   MAIL_USER (IS NULL): " . var_export($val, true) . "\n";
} catch (\Throwable $e) { echo "   ERROR: " . $e->getMessage() . "\n"; }

echo "\n5. Test get_config:\n";
try {
    $v = get_config($pdo, "MAIL_USER", "DEFAULT");
    echo "   get_config(MAIL_USER): " . var_export($v, true) . "\n";
} catch (\Throwable $e) { echo "   ERROR: " . $e->getMessage() . "\n"; }

echo "\n6. Test obtenerConfiguracion (alias):\n";
try {
    $v = obtenerConfiguracion($pdo, "MAIL_USER", "DEFAULT");
    echo "   obtenerConfiguracion(MAIL_USER): " . var_export($v, true) . "\n";
} catch (\Throwable $e) { echo "   ERROR: " . $e->getMessage() . "\n"; }
