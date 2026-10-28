<?php
/**
 * preparar_para_compartir.php
 * Elimina la IA_API_KEY de la BD antes de exportar para compartir.
 */
require_once __DIR__ . '/conexion.php';

echo "=== PREPARAR PROYECTO PARA COMPARTIR ===\n\n";

echo "Eliminando IA_API_KEY de la base de datos...\n";
$stmt = $pdo->prepare("DELETE FROM configuracion WHERE clave = 'IA_API_KEY' AND empresa_id IS NULL");
$stmt->execute();
$afectadas = $stmt->rowCount();

echo "✅ IA_API_KEY eliminada ($afectadas filas)\n\n";

$check = $pdo->query("SELECT COUNT(*) FROM configuracion WHERE clave = 'IA_API_KEY'")->fetchColumn();
echo "Filas restantes: " . (int)$check . "\n\n";

echo "=== LISTO ===\n";
echo "Ahora puedes exportar la BD y subir a GitHub sin riesgo.\n";
