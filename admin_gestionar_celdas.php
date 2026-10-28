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

$parqueaderoId = (int)($_GET['id'] ?? 0);
if ($parqueaderoId <= 0) { header('Location: admin_sedes.php'); exit; }

$msg = ''; $error = '';

// Cargar sede (validar propiedad)
$stmt = $pdo->prepare("SELECT * FROM parqueaderos WHERE id = :id AND empresa_id = :eid LIMIT 1");
$stmt->execute(['id' => $parqueaderoId, 'eid' => $eid]);
$sede = $stmt->fetch();
if (!$sede) { header('Location: admin_sedes.php'); exit; }

// AGREGAR celdas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $tipo = $_POST['tipo'] ?? '';
        $cantidad = max(0, min(200, (int)($_POST['cantidad'] ?? 0)));
        $prefijoCustom = trim($_POST['prefijo'] ?? '');

        $tiposValidos = ['carro' => 'A', 'moto' => 'M', 'bicicleta' => 'B', 'electrico' => 'E'];
        if (!isset($tiposValidos[$tipo])) {
            $error = 'Tipo de celda inválido.';
        } elseif ($cantidad <= 0) {
            $error = 'La cantidad debe ser mayor a 0.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM cupos WHERE empresa_id = :eid AND parqueadero_id = :pid AND tipo = :tipo");
                $stmt->execute(['eid' => $eid, 'pid' => $parqueaderoId, 'tipo' => $tipo]);
                $existentes = (int)$stmt->fetchColumn();

                $prefijo = $prefijoCustom !== '' ? strtoupper(substr($prefijoCustom, 0, 3)) : $tiposValidos[$tipo];
                $insertadas = 0;

                $stmtIns = $pdo->prepare("
                    INSERT INTO cupos (empresa_id, parqueadero_id, codigo_cupo, tipo, estado)
                    VALUES (:eid, :pid, :cod, :tipo, 'disponible')
                ");

                for ($i = 1; $i <= $cantidad; $i++) {
                    $numero = $existentes + $i;
                    $codigo = $prefijo . str_pad((string)$numero, 2, '0', STR_PAD_LEFT);

                    $check = $pdo->prepare("SELECT COUNT(*) FROM cupos WHERE empresa_id = :eid AND parqueadero_id = :pid AND codigo_cupo = :cod");
                    $check->execute(['eid' => $eid, 'pid' => $parqueaderoId, 'cod' => $codigo]);
                    if ((int)$check->fetchColumn() > 0) continue;

                    try {
                        $stmtIns->execute(['eid' => $eid, 'pid' => $parqueaderoId, 'cod' => $codigo, 'tipo' => $tipo]);
                        $insertadas++;
                    } catch (\Throwable $e) {}
                }

                // Actualizar capacidad
                $pdo->prepare("
                    UPDATE parqueaderos SET capacidad_total = (
                        SELECT COUNT(*) FROM cupos WHERE parqueadero_id = :pid AND empresa_id = :eid
                    ) WHERE id = :pid AND empresa_id = :eid
                ")->execute(['pid' => $parqueaderoId, 'eid' => $eid]);

                $pdo->commit();
                registrarAuditoria($pdo, 'agregar_celdas', "sede=$parqueaderoId tipo=$tipo cantidad=$insertadas");
                $msg = "✓ Se agregaron $insertadas celdas de tipo $tipo.";
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('agregar_celdas: ' . $e->getMessage());
                $error = 'Error al agregar celdas.';
            }
        }
    }
}

// ELIMINAR celda individual (solo disponible)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_una') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $codigo = trim($_POST['codigo_cupo'] ?? '');
        if ($codigo === '') {
            $error = 'Código inválido.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT estado FROM cupos WHERE codigo_cupo = :cod AND parqueadero_id = :pid AND empresa_id = :eid LIMIT 1");
                $stmt->execute(['cod' => $codigo, 'pid' => $parqueaderoId, 'eid' => $eid]);
                $estado = $stmt->fetchColumn();

                if ($estado === false) {
                    $error = 'Celda no encontrada.';
                } elseif ($estado !== 'disponible') {
                    $error = 'Solo se pueden eliminar celdas disponibles.';
                } else {
                    $pdo->prepare("DELETE FROM cupos WHERE codigo_cupo = :cod AND parqueadero_id = :pid AND empresa_id = :eid")
                        ->execute(['cod' => $codigo, 'pid' => $parqueaderoId, 'eid' => $eid]);

                    $pdo->prepare("
                        UPDATE parqueaderos SET capacidad_total = (
                            SELECT COUNT(*) FROM cupos WHERE parqueadero_id = :pid AND empresa_id = :eid
                        ) WHERE id = :pid AND empresa_id = :eid
                    ")->execute(['pid' => $parqueaderoId, 'eid' => $eid]);

                    $msg = "✓ Celda $codigo eliminada.";
                }
            } catch (\Throwable $e) {
                $error = 'Error al eliminar.';
            }
        }
    }
}

// ELIMINAR todas las celdas disponibles de un tipo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_todas') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $tipo = $_POST['tipo'] ?? '';
        if (!in_array($tipo, ['carro', 'moto', 'bicicleta', 'electrico'], true)) {
            $error = 'Tipo inválido.';
        } else {
            try {
                // Solo eliminar las que están disponibles y no tienen registros activos
                $stmt = $pdo->prepare("
                    DELETE FROM cupos 
                    WHERE empresa_id = :eid AND parqueadero_id = :pid AND tipo = :tipo 
                      AND estado = 'disponible'
                      AND codigo_cupo NOT IN (
                          SELECT cupo_asignado FROM registros_parqueo 
                          WHERE empresa_id = :eid AND estado = 'activo'
                      )
                ");
                $stmt->execute(['eid' => $eid, 'pid' => $parqueaderoId, 'tipo' => $tipo]);
                $eliminadas = $stmt->rowCount();

                $pdo->prepare("
                    UPDATE parqueaderos SET capacidad_total = (
                        SELECT COUNT(*) FROM cupos WHERE parqueadero_id = :pid AND empresa_id = :eid
                    ) WHERE id = :pid AND empresa_id = :eid
                ")->execute(['pid' => $parqueaderoId, 'eid' => $eid]);

                $msg = "✓ Se eliminaron $eliminadas celdas de tipo $tipo.";
            } catch (\Throwable $e) {
                $error = 'Error al eliminar.';
            }
        }
    }
}

// Cargar celdas
$stmt = $pdo->prepare("
    SELECT c.codigo_cupo, c.tipo, c.estado, 
           (SELECT placa FROM registros_parqueo r WHERE r.cupo_asignado = c.codigo_cupo AND r.empresa_id = c.empresa_id AND r.estado = 'activo' LIMIT 1) AS placa
    FROM cupos c
    WHERE c.empresa_id = :eid AND c.parqueadero_id = :pid
    ORDER BY c.tipo, LENGTH(c.codigo_cupo), c.codigo_cupo
");
$stmt->execute(['eid' => $eid, 'pid' => $parqueaderoId]);
$celdas = $stmt->fetchAll();

// Agrupar por tipo
$porTipo = ['carro' => [], 'moto' => [], 'bicicleta' => [], 'electrico' => []];
foreach ($celdas as $c) {
    if (isset($porTipo[$c['tipo']])) $porTipo[$c['tipo']][] = $c;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestionar Celdas - <?= htmlspecialchars($sede['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=53">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1300px; margin: 0 auto; padding: 0 1.5rem; }

    .page-header { background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05)); border: 1.5px solid rgba(16, 185, 129, 0.3); border-radius: 22px; padding: 1.75rem 2rem; margin-bottom: 2rem; }
    .page-header h1 { font-size: 2rem; font-weight: 900; background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 0.3rem; }
    .page-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }

    .section { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 18px; padding: 1.75rem; margin-bottom: 1.5rem; }
    .section h2 { font-size: 1.05rem; font-weight: 800; color: #f1f5f9; margin: 0 0 0.35rem; display: flex; align-items: center; gap: 0.6rem; }
    .section .sub { color: #64748b; font-size: 0.82rem; margin-bottom: 1.25rem; }

    .tipo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; }
    .tipo-card { padding: 1.25rem; background: rgba(2, 6, 23, 0.5); border-radius: 14px; border-left: 4px solid; }
    .tipo-card.carro { border-left-color: #10b981; }
    .tipo-card.moto { border-left-color: #06b6d4; }
    .tipo-card.bicicleta { border-left-color: #a78bfa; }
    .tipo-card.electrico { border-left-color: #f59e0b; }
    .tipo-card h3 { font-size: 0.95rem; font-weight: 800; color: #f1f5f9; margin: 0 0 0.4rem; }
    .tipo-card .num { font-size: 1.75rem; font-weight: 900; margin-bottom: 0.6rem; }
    .tipo-card.carro .num { color: #10b981; }
    .tipo-card.moto .num { color: #06b6d4; }
    .tipo-card.bicicleta .num { color: #a78bfa; }
    .tipo-card.electrico .num { color: #f59e0b; }

    .add-form { display: flex; gap: 0.5rem; margin-top: 0.75rem; align-items: center; flex-wrap: wrap; }
    .add-form input { width: 70px; padding: 0.5rem; background: rgba(15, 23, 42, 0.7); border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 8px; color: #f1f5f9; font-family: inherit; font-size: 0.85rem; text-align: center; }
    .btn-mini { padding: 0.5rem 0.85rem; background: rgba(16, 185, 129, 0.15); border: 1px solid #10b981; color: #10b981; border-radius: 8px; font-size: 0.75rem; font-weight: 700; cursor: pointer; font-family: inherit; transition: all 0.25s; }
    .btn-mini:hover { background: #10b981; color: #030712; }
    .btn-mini.peligro { border-color: #ef4444; color: #ef4444; }
    .btn-mini.peligro:hover { background: #ef4444; color: #fff; }

    .celdas-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 0.5rem; margin-top: 1rem; }
    .celda-item { padding: 0.6rem 0.4rem; border-radius: 10px; text-align: center; font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 0.78rem; position: relative; transition: all 0.25s; }
    .celda-item.disponible { background: rgba(16, 185, 129, 0.15); border: 1.5px solid rgba(16, 185, 129, 0.5); color: #6ee7b7; }
    .celda-item.ocupado { background: rgba(245, 158, 11, 0.15); border: 1.5px solid rgba(245, 158, 11, 0.6); color: #fcd34d; }
    .celda-item.mantenimiento { background: rgba(100, 116, 139, 0.15); border: 1.5px dashed rgba(100, 116, 139, 0.5); color: #cbd5e1; }
    .celda-item .placa-mini { display: block; font-size: 0.62rem; font-weight: 400; opacity: 0.85; margin-top: 0.15rem; }
    .celda-item .btn-x { position: absolute; top: 3px; right: 3px; width: 16px; height: 16px; border-radius: 50%; background: rgba(239,68,68,0.15); border: none; color: #fca5a5; font-size: 0.6rem; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; font-weight: 900; }
    .celda-item:hover .btn-x { opacity: 1; }
    .celda-item .btn-x:hover { background: #ef4444; color: white; }

    .btn-grande { width: 100%; padding: 1.15rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; border: none; border-radius: 14px; font-weight: 900; font-size: 1rem; cursor: pointer; font-family: inherit; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4); transition: all 0.3s ease; margin-top: 1.5rem; text-decoration: none; text-align: center; display: block; }

    .empty-mini { text-align: center; padding: 1.5rem; color: #64748b; font-size: 0.82rem; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="page-header">
        <h1>Gestionar Celdas</h1>
        <p><?= htmlspecialchars($sede['nombre']) ?> · Agrega o elimina celdas de esta sede</p>
    </div>

    <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="section">
        <h2>▢ Celdas por tipo</h2>
        <p class="sub">Agrega nuevas celdas o elimina las disponibles</p>

        <div class="tipo-grid">
            <?php foreach ($porTipo as $tipo => $lista): 
                $nombres = ['carro'=>'🚗 Carros','moto'=>'🏍 Motos','bicicleta'=>'🚲 Bicicletas','electrico'=>'⚡ Eléctricos'];
            ?>
                <div class="tipo-card <?= $tipo ?>">
                    <h3><?= $nombres[$tipo] ?></h3>
                    <div class="num"><?= count($lista) ?></div>

                    <form method="POST" class="add-form" onsubmit="return confirm('¿Agregar <?= $tipo ?>?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="agregar">
                        <input type="hidden" name="tipo" value="<?= $tipo ?>">
                        <input type="number" name="cantidad" value="5" min="1" max="200" placeholder="Cant.">
                        <input type="text" name="prefijo" placeholder="Prefijo" maxlength="3" style="width: 70px;" title="Opcional. Ej: A, M, B, E">
                        <button type="submit" class="btn-mini">+ Agregar</button>
                    </form>

                    <?php if (count($lista) > 0): ?>
                        <form method="POST" style="margin-top: 0.5rem;" onsubmit="return confirm('¿Eliminar TODAS las celdas disponibles de <?= $tipo ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="eliminar_todas">
                            <input type="hidden" name="tipo" value="<?= $tipo ?>">
                            <button type="submit" class="btn-mini peligro" style="width: 100%;">🗑 Eliminar todas las disponibles</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="section">
        <h2>◉ Celdas actuales (<?= count($celdas) ?>)</h2>
        <p class="sub">Pasa el mouse sobre una celda disponible para eliminarla</p>

        <?php foreach ($porTipo as $tipo => $lista): 
            if (empty($lista)) continue;
            $nombres = ['carro'=>'🚗 Carros','moto'=>'🏍 Motos','bicicleta'=>'🚲 Bicicletas','electrico'=>'⚡ Eléctricos'];
        ?>
            <div style="margin-bottom: 1.5rem;">
                <h3 style="font-size: 0.85rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem;"><?= $nombres[$tipo] ?> (<?= count($lista) ?>)</h3>
                <div class="celdas-grid">
                    <?php foreach ($lista as $c): ?>
                        <div class="celda-item <?= htmlspecialchars($c['estado']) ?>" title="<?= htmlspecialchars($c['codigo_cupo']) ?> - <?= ucfirst($c['estado']) ?><?= $c['placa'] ? ' (' . htmlspecialchars($c['placa']) . ')' : '' ?>">
                            <?= htmlspecialchars($c['codigo_cupo']) ?>
                            <?php if ($c['placa']): ?>
                                <span class="placa-mini"><?= htmlspecialchars($c['placa']) ?></span>
                            <?php endif; ?>
                            <?php if ($c['estado'] === 'disponible'): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('¿Eliminar celda <?= htmlspecialchars($c['codigo_cupo']) ?>?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="eliminar_una">
                                    <input type="hidden" name="codigo_cupo" value="<?= htmlspecialchars($c['codigo_cupo']) ?>">
                                    <button type="submit" class="btn-x" title="Eliminar">×</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (empty($celdas)): ?>
            <div class="empty-mini">No hay celdas configuradas. Usa los botones de arriba para agregar.</div>
        <?php endif; ?>
    </div>

    <a href="admin_editar_sede.php?id=<?= $parqueaderoId ?>" class="btn-grande">← Volver a editar sede</a>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>