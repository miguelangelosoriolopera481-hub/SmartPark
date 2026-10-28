<?php
/**
 * regenerar_tokens_qr.php
 * Asegura que TODOS los parqueaderos activos tengan un qr_token valido.
 * Ejecutar 1 vez (o periodicamente).
 */
require_once __DIR__ . '/conexion.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== REGENERAR TOKENS QR ===\n\n";

$stmt = $pdo->query("
    SELECT id, empresa_id, nombre, qr_token, estado
    FROM parqueaderos
    WHERE estado = 'activo'
    ORDER BY empresa_id, id
");
$parqueaderos = $stmt->fetchAll();

echo "Parqueaderos activos: " . count($parqueaderos) . "\n\n";

$generados = 0;
$yaTienen = 0;
$actualizados = 0;

foreach ($parqueaderos as $p) {
    $token = trim($p['qr_token'] ?? '');

    if ($token === '' || strlen($token) !== 32 || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        // Generar token nuevo
        $nuevo = bin2hex(random_bytes(16));
        try {
            $upd = $pdo->prepare("UPDATE parqueaderos SET qr_token = :t WHERE id = :id AND empresa_id = :eid");
            $upd->execute(['t' => $nuevo, 'id' => $p['id'], 'eid' => $p['empresa_id']]);
            echo "  [+] Parqueadero #{$p['id']} (empresa #{$p['empresa_id']}) '{$p['nombre']}' -> token generado: " . substr($nuevo, 0, 12) . "...\n";
            $generados++;
        } catch (\Throwable $e) {
            echo "  [!] Error al generar token para #{$p['id']}: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  [=] Parqueadero #{$p['id']} (empresa #{$p['empresa_id']}) '{$p['nombre']}' -> token OK\n";
        $yaTienen++;
    }
}

echo "\n=== RESUMEN ===\n";
echo "Tokens nuevos generados: $generados\n";
echo "Ya tenian token valido: $yaTienen\n";
echo "Total: " . count($parqueaderos) . "\n\n";

// Verificacion final
$stmt = $pdo->query("
    SELECT e.nombre AS empresa, p.id, p.nombre, p.qr_token
    FROM parqueaderos p
    JOIN empresas e ON e.id = p.empresa_id
    WHERE p.estado = 'activo'
    ORDER BY e.nombre, p.id
");
echo "=== VERIFICACION FINAL ===\n";
foreach ($stmt->fetchAll() as $p) {
    echo "  Empresa: " . $p['empresa'] . " | Parqueadero #" . $p['id'] . " '" . $p['nombre'] . "'\n";
    echo "    Token: " . $p['qr_token'] . "\n";
    echo "    URL Entrada:   entrada_qr.php?token=" . $p['qr_token'] . "\n";
    echo "    URL Recepcion: mi_auto.php?token=" . $p['qr_token'] . "\n";
    echo "    URL Celda:     entrada_qr.php?token=" . $p['qr_token'] . "&modo=celda\n";
    echo "\n";
}

echo "=== FIN ===\n";