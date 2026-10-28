<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

require_role(['admin', 'superadmin']);
require_password_change_guard();

$eid = current_empresa_id();
if (!$eid) { header('Location: login_empresa.php'); exit; }

$msg = ''; $error = '';

$stmt = $pdo->prepare("SELECT id, nombre, ciudad FROM parqueaderos WHERE empresa_id = :eid ORDER BY nombre");
$stmt->execute(['eid' => $eid]);
$sedes = $stmt->fetchAll();

// Procesar transferencia
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $origenId = (int)($_POST['origen_id'] ?? 0);
        $destinoId = (int)($_POST['destino_id'] ?? 0);
        $codigosCelda = trim($_POST['codigos_celda'] ?? '');

        if ($origenId <= 0 || $destinoId <= 0) {
            $error = 'Debes seleccionar sede origen y destino.';
        } elseif ($origenId === $destinoId) {
            $error = 'La sede origen y destino deben ser diferentes.';
        } elseif ($codigosCelda === '') {
            $error = 'Debes indicar al menos un código de celda.';
        } else {
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM parqueaderos WHERE id IN (:o, :d) AND empresa_id = :eid");
            $stmt->execute(['o' => $origenId, 'd' => $destinoId, 'eid' => $eid]);
            if ((int)$stmt->fetchColumn() !== 2) {
                $error = 'Las sedes no pertenecen a tu empresa.';
            } else {
                // Separar por comas o saltos de línea
                $codigos = preg_split('/[\s,\r\n]+/', $codigosCelda);
                $codigos = array_filter(array_map('trim', $codigos));
                $codigos = array_unique($codigos);

                if (empty($codigos)) {
                    $error = 'No se detectaron códigos de celda válidos.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        // y NO tienen registros activos
                        $placeholders = [];
                        $params = ['eid' => $eid, 'origen' => $origenId];
                        foreach ($codigos as $i => $cod) {
                            $key = "c$i";
                            $placeholders[] = ":$key";
                            $params[$key] = $cod;
                        }
                        $in = implode(',', $placeholders);

                        $stmt = $pdo->prepare("
                            SELECT codigo_cupo, estado 
                            FROM cupos 
                            WHERE empresa_id = :eid AND parqueadero_id = :origen AND codigo_cupo IN ($in)
                        ");
                        $stmt->execute($params);
                        $encontradas = $stmt->fetchAll();

                        if (count($encontradas) !== count($codigos)) {
                            $pdo->rollBack();
                            $error = 'Algunas celdas no existen en la sede origen.';
                        } else {
                            
                            $bloqueadas = [];
                            foreach ($encontradas as $e) {
                                if ($e['estado'] !== 'disponible') {
                                    $bloqueadas[] = $e['codigo_cupo'];
                                }
                            }

                            $stmt = $pdo->prepare("
                                SELECT DISTINCT cupo_asignado 
                                FROM registros_parqueo 
                                WHERE empresa_id = :eid AND parqueadero_id = :origen AND estado = 'activo' 
                                  AND cupo_asignado IN ($in)
                            ");
                            $stmt->execute($params);
                            foreach ($stmt->fetchAll() as $r) {
                                $bloqueadas[] = $r['cupo_asignado'];
                            }

                            if (!empty($bloqueadas)) {
                                $pdo->rollBack();
                                $error = 'No se pueden transferir celdas ocupadas o con registros activos: ' . implode(', ', array_unique($bloqueadas));
                            } else {
                                // Buscar códigos de cupo que ya existen en destino para evitar duplicados
                                $stmt = $pdo->prepare("
                                    SELECT codigo_cupo FROM cupos 
                                    WHERE empresa_id = :eid AND parqueadero_id = :destino AND codigo_cupo IN ($in)
                                ");
                                $stmt->execute(['eid' => $eid, 'destino' => $destinoId] + array_slice($params, 2, null, true));
                                $duplicados = array_column($stmt->fetchAll(), 'codigo_cupo');

                                if (!empty($duplicados)) {
                                    $pdo->rollBack();
                                    $error = 'Estos códigos ya existen en la sede destino: ' . implode(', ', $duplicados);
                                } else {
                                    // Ejecutar la transferencia
                                    $stmt = $pdo->prepare("
                                        UPDATE cupos 
                                        SET parqueadero_id = :destino 
                                        WHERE empresa_id = :eid AND parqueadero_id = :origen AND codigo_cupo IN ($in)
                                    ");
                                    $stmt->execute(['destino' => $destinoId, 'eid' => $eid, 'origen' => $origenId] + array_slice($params, 2, null, true));

                                    $afectadas = $stmt->rowCount();

                                    // Actualizar capacidad de ambas sedes
                                    $pdo->prepare("
                                        UPDATE parqueaderos SET capacidad_total = (
                                            SELECT COUNT(*) FROM cupos WHERE parqueadero_id = :pid AND empresa_id = :eid
                                        ) WHERE id = :pid AND empresa_id = :eid
                                    ")->execute(['pid' => $origenId, 'eid' => $eid]);

                                    $pdo->prepare("
                                        UPDATE parqueaderos SET capacidad_total = (
                                            SELECT COUNT(*) FROM cupos WHERE parqueadero_id = :pid AND empresa_id = :eid
                                        ) WHERE id = :pid AND empresa_id = :eid
                                    ")->execute(['pid' => $destinoId, 'eid' => $eid]);

                                    $pdo->commit();

                                    registrarAuditoria($pdo, 'transferir_celda', "origen=$origenId destino=$destinoId celdas=" . implode(',', $codigos));
                                    $msg = "✓ Se transfirieron $afectadas celdas correctamente.";
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log('transferir_celda: ' . $e->getMessage());
                        $error = 'Error al transferir: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Celdas disponibles de cada sede para mostrar info
$stmt = $pdo->prepare("
    SELECT p.id, p.nombre, 
        (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :e1) AS cupos,
        (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :e2 AND estado = 'disponible') AS libres
    FROM parqueaderos p WHERE p.empresa_id = :eid ORDER BY p.nombre
");
$stmt->execute(['e1' => $eid, 'e2' => $eid, 'eid' => $eid]);
$sedesInfo = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transferir Celdas - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=53">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 900px; margin: 0 auto; padding: 0 1.5rem; }

    .page-header { background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05)); border: 1.5px solid rgba(16, 185, 129, 0.3); border-radius: 22px; padding: 1.75rem 2rem; margin-bottom: 2rem; }
    .page-header h1 { font-size: 2rem; font-weight: 900; background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 0.3rem; }
    .page-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }

    .aviso-info { padding: 1rem 1.25rem; background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6, 182, 212, 0.3); border-radius: 14px; color: #67e8f9; margin-bottom: 1.5rem; font-size: 0.88rem; line-height: 1.5; }

    .section { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 18px; padding: 1.75rem; margin-bottom: 1.5rem; }
    .section h2 { font-size: 1.05rem; font-weight: 800; color: #f1f5f9; margin: 0 0 1rem; display: flex; align-items: center; gap: 0.6rem; }

    .flujo-grid { display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem; align-items: end; margin-bottom: 1.5rem; }
    .flujo-grid .arrow { font-size: 2rem; color: #10b981; text-align: center; padding-bottom: 0.4rem; }
    .form-group label { display: block; font-size: 0.82rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; }
    .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 0.85rem 1rem; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 11px; color: #f1f5f9; font-family: inherit; font-size: 0.92rem; outline: none; box-sizing: border-box; transition: all 0.25s ease; }
    .form-group select:focus, .form-group input:focus, .form-group textarea:focus { border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15); }

    .sede-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem; }
    .sede-card { padding: 1rem; background: rgba(2, 6, 23, 0.5); border-radius: 12px; border-left: 3px solid #10b981; }
    .sede-card h4 { font-size: 0.9rem; font-weight: 800; color: #f1f5f9; margin: 0 0 0.3rem; }
    .sede-card p { color: #64748b; font-size: 0.78rem; margin: 0; }
    .sede-card .stats { color: #10b981; font-weight: 800; font-size: 0.85rem; margin-top: 0.5rem; }

    .btn-grande { width: 100%; padding: 1.15rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; border: none; border-radius: 14px; font-weight: 900; font-size: 1rem; cursor: pointer; font-family: inherit; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4); transition: all 0.3s ease; margin-top: 1.5rem; }
    .btn-grande:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.55); }

    @media (max-width: 640px) {
        .flujo-grid { grid-template-columns: 1fr; }
        .flujo-grid .arrow { transform: rotate(90deg); }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="page-header">
        <h1>Transferir Celdas</h1>
        <p>Mueve celdas de una sede a otra manteniendo sus códigos</p>
    </div>

    <div class="aviso-info">
        <strong>◆ Importante:</strong> Solo se pueden transferir celdas <strong>disponibles</strong> (sin vehículos activos). La celda mantiene su código original.
    </div>

    <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>

        <div class="section">
            <h2>▢ Configurar transferencia</h2>

            <div class="flujo-grid">
                <div class="form-group">
                    <label>Sede de ORIGEN (de dónde salen)</label>
                    <select name="origen_id" required>
                        <option value="">— Selecciona sede —</option>
                        <?php foreach ($sedesInfo as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?> (<?= (int)$s['libres'] ?> libres de <?= (int)$s['cupos'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="arrow">→</div>
                <div class="form-group">
                    <label>Sede de DESTINO (a dónde van)</label>
                    <select name="destino_id" required>
                        <option value="">— Selecciona sede —</option>
                        <?php foreach ($sedesInfo as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Códigos de celda a transferir (separados por coma o salto de línea)</label>
                <textarea name="codigos_celda" rows="4" placeholder="Ej: N1A01, N1A02, N1M01&#10;o uno por línea:&#10;N1A01&#10;N1A02" required style="font-family: 'JetBrains Mono', monospace; font-size: 0.85rem;"></textarea>
            </div>
        </div>

        <div class="section">
            <h2>◉ Sedes actuales</h2>
            <div class="sede-info">
                <?php foreach ($sedesInfo as $s): ?>
                    <div class="sede-card">
                        <h4><?= htmlspecialchars($s['nombre']) ?></h4>
                        <p><?= (int)$s['cupos'] ?> celdas totales</p>
                        <div class="stats"><?= (int)$s['libres'] ?> disponibles para transferir</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="btn-grande" onclick="return confirm('¿Confirmar la transferencia de celdas? Esta acción no se puede deshacer.');">→ Transferir Celdas</button>
    </form>

    <div style="margin-top: 1rem; text-align: center;">
        <a href="admin_sedes.php" class="cyber-btn-outline">← Volver a mis sedes</a>
    </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>