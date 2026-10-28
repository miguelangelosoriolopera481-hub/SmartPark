<?php
require_once __DIR__ . "/conexion.php";

$pdo->exec("DELETE FROM login_intentos WHERE identificador IN (\"admin\", \"cliente1\")");
echo "✅ Intentos fallidos limpiados\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM login_intentos WHERE exito = 0 AND creado_en > (NOW() - INTERVAL 15 MINUTE)");
echo "Intentos recientes en BD: " . $stmt->fetchColumn() . "\n";
