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

// Cargar la sede (validar que pertenece a esta empresa)
try {
    $stmt = $pdo->prepare("SELECT * FROM parqueaderos WHERE id = :id AND empresa_id = :eid LIMIT 1");
    $stmt->execute(['id' => $parqueaderoId, 'eid' => $eid]);
    $sede = $stmt->fetch();
} catch (\Throwable $e) { $sede = null; }

if (!$sede) {
    header('Location: admin_sedes.php');
    exit;
}

// Procesar edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email_contacto = trim($_POST['email_contacto'] ?? '');
        $nit_sede = trim($_POST['nit_sede'] ?? '');
        $horario_apertura = $_POST['horario_apertura'] ?? '06:00';
        $horario_cierre = $_POST['horario_cierre'] ?? '22:00';
        $tipo_parqueadero = $_POST['tipo_parqueadero'] ?? 'cubierto';
        $acepta_reservas = !empty($_POST['acepta_reservas']) ? 1 : 0;
        $tiene_discapacitados = !empty($_POST['tiene_discapacitados']) ? 1 : 0;
        $observaciones = trim($_POST['observaciones'] ?? '');
        $estado = in_array($_POST['estado'] ?? '', ['activo','mantenimiento','inactivo'], true) ? $_POST['estado'] : 'activo';

        if ($nombre === '') {
            $error = 'El nombre es obligatorio.';
        } else {
            try {
                $pdo->prepare("
                    UPDATE parqueaderos 
                    SET nombre = :n, ciudad = :c, direccion = :d, telefono = :t, estado = :e,
                        email_contacto = :em, nit_sede = :nit,
                        horario_apertura = :ha, horario_cierre = :hc,
                        tipo_parqueadero = :tp, acepta_reservas = :ar,
                        tiene_discapacitados = :td, observaciones = :obs
                    WHERE id = :id AND empresa_id = :eid
                ")->execute([
                    'n' => $nombre, 'c' => $ciudad ?: null, 'd' => $direccion ?: null,
                    't' => $telefono ?: null, 'e' => $estado,
                    'em' => $email_contacto ?: null, 'nit' => $nit_sede ?: null,
                    'ha' => $horario_apertura, 'hc' => $horario_cierre,
                    'tp' => $tipo_parqueadero,
                    'ar' => $acepta_reservas, 'td' => $tiene_discapacitados,
                    'obs' => $observaciones ?: null,
                    'id' => $parqueaderoId, 'eid' => $eid,
                ]);

                registrarAuditoria($pdo, 'editar_sede', "sede_id=$parqueaderoId");
                $msg = '✓ Sede actualizada correctamente';

                // Recargar
                $stmt = $pdo->prepare("SELECT * FROM parqueaderos WHERE id = :id AND empresa_id = :eid LIMIT 1");
                $stmt->execute(['id' => $parqueaderoId, 'eid' => $eid]);
                $sede = $stmt->fetch();
            } catch (\Throwable $e) {
                error_log('editar_sede: ' . $e->getMessage());
                $error = 'Error al guardar los cambios.';
            }
        }
    }
}

// Contar celdas actuales
$stmt = $pdo->prepare("
    SELECT tipo, COUNT(*) AS cantidad 
    FROM cupos WHERE parqueadero_id = :pid AND empresa_id = :eid 
    GROUP BY tipo
");
$stmt->execute(['pid' => $parqueaderoId, 'eid' => $eid]);
$celdasPorTipo = ['carro' => 0, 'moto' => 0, 'bicicleta' => 0, 'electrico' => 0];
foreach ($stmt->fetchAll() as $row) {
    $celdasPorTipo[$row['tipo']] = (int)$row['cantidad'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Editar Sede - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=52">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 900px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05)); border: 1.5px solid rgba(16, 185, 129, 0.3); border-radius: 22px; padding: 1.75rem 2rem; margin-bottom: 2rem; }
    .page-header h1 { font-size: 2rem; font-weight: 900; background: linear-gradient(135deg, #10b981, #06b6d4); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 0.3rem; }
    .page-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }
    .section { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 18px; padding: 1.75rem; margin-bottom: 1.5rem; }
    .section h2 { font-size: 1.05rem; font-weight: 800; color: #f1f5f9; margin: 0 0 1rem; display: flex; align-items: center; gap: 0.6rem; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
    .form-group label { display: block; font-size: 0.82rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 0.85rem 1rem; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 11px; color: #f1f5f9; font-family: inherit; font-size: 0.92rem; outline: none; box-sizing: border-box; transition: all 0.25s ease; }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15); }
    .celdas-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 0.75rem; margin-top: 1rem; }
    .celda-stat { background: rgba(2, 6, 23, 0.5); padding: 1rem; border-radius: 12px; text-align: center; border-left: 3px solid #10b981; }
    .celda-stat .num { font-size: 1.5rem; font-weight: 900; color: #10b981; }
    .celda-stat .lbl { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.2rem; font-weight: 700; }
    .btn-grande { width: 100%; padding: 1.15rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; border: none; border-radius: 14px; font-weight: 900; font-size: 1rem; cursor: pointer; font-family: inherit; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4); transition: all 0.3s ease; margin-top: 1.5rem; }
    .btn-grande:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.55); }
    .acciones-extra { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; margin-top: 1rem; }
    .btn-accion { padding: 0.9rem; border-radius: 12px; font-weight: 700; font-size: 0.88rem; text-align: center; text-decoration: none; border: 1.5px solid; transition: all 0.25s ease; }
    .btn-accion.outline { background: rgba(16, 185, 129, 0.12); border-color: #10b981; color: #10b981; }
    .btn-accion.outline:hover { background: #10b981; color: #030712; }
    .btn-accion.danger { background: rgba(239, 68, 68, 0.12); border-color: #ef4444; color: #ef4444; }
    .btn-accion.danger:hover { background: #ef4444; color: #fff; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="page-header">
        <h1>Editar Sede</h1>
        <p><?= htmlspecialchars($sede['nombre']) ?> · Actualiza las propiedades de tu parqueadero</p>
    </div>

    <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>

        <div class="section">
            <h2>◆ Datos básicos</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label>Nombre *</label>
                    <input type="text" name="nombre" required maxlength="150" value="<?= htmlspecialchars($sede['nombre']) ?>">
                </div>
                <div class="form-group">
                    <label>NIT de la sede</label>
                    <input type="text" name="nit_sede" maxlength="30" value="<?= htmlspecialchars($sede['nit_sede'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Ciudad</label>
                    <input type="text" name="ciudad" maxlength="100" value="<?= htmlspecialchars($sede['ciudad'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Dirección</label>
                    <input type="text" name="direccion" maxlength="200" value="<?= htmlspecialchars($sede['direccion'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" maxlength="30" value="<?= htmlspecialchars($sede['telefono'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Correo</label>
                    <input type="email" name="email_contacto" maxlength="150" value="<?= htmlspecialchars($sede['email_contacto'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Hora de apertura</label>
                    <input type="time" name="horario_apertura" value="<?= htmlspecialchars(substr($sede['horario_apertura'] ?? '06:00:00', 0, 5)) ?>">
                </div>
                <div class="form-group">
                    <label>Hora de cierre</label>
                    <input type="time" name="horario_cierre" value="<?= htmlspecialchars(substr($sede['horario_cierre'] ?? '22:00:00', 0, 5)) ?>">
                </div>
                <div class="form-group">
                    <label>Tipo</label>
                    <select name="tipo_parqueadero">
                        <?php foreach (['cubierto'=>'Cubierto','descubierto'=>'Descubierto','mixto'=>'Mixto','subterraneo'=>'Subterráneo'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($sede['tipo_parqueadero'] ?? 'cubierto') === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="activo" <?= $sede['estado'] === 'activo' ? 'selected' : '' ?>>Activo</option>
                        <option value="mantenimiento" <?= $sede['estado'] === 'mantenimiento' ? 'selected' : '' ?>>En mantenimiento</option>
                        <option value="inactivo" <?= $sede['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
            </div>

            <div class="form-grid" style="margin-top: 1rem;">
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                        <input type="checkbox" name="acepta_reservas" value="1" <?= !empty($sede['acepta_reservas']) ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #10b981;">
                        <span>Acepta reservas</span>
                    </label>
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                        <input type="checkbox" name="tiene_discapacitados" value="1" <?= !empty($sede['tiene_discapacitados']) ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #10b981;">
                        <span>Celdas para discapacitados</span>
                    </label>
                </div>
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <label>Observaciones</label>
                <textarea name="observaciones" rows="3" maxlength="500"><?= htmlspecialchars($sede['observaciones'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="section">
            <h2>▢ Celdas actuales (<?= array_sum($celdasPorTipo) ?> total)</h2>
            <p style="color: #64748b; font-size: 0.82rem; margin-bottom: 1rem;">Para modificar la cantidad de celdas, ve a <a href="admin_gestionar_celdas.php?id=<?= $parqueaderoId ?>" style="color: #10b981;">Gestión de Celdas</a></p>
            <div class="celdas-info">
                <div class="celda-stat">
                    <div class="num"><?= $celdasPorTipo['carro'] ?></div>
                    <div class="lbl">Carros</div>
                </div>
                <div class="celda-stat" style="border-left-color: #06b6d4;">
                    <div class="num" style="color: #06b6d4;"><?= $celdasPorTipo['moto'] ?></div>
                    <div class="lbl">Motos</div>
                </div>
                <div class="celda-stat" style="border-left-color: #a78bfa;">
                    <div class="num" style="color: #a78bfa;"><?= $celdasPorTipo['bicicleta'] ?></div>
                    <div class="lbl">Bicis</div>
                </div>
                <div class="celda-stat" style="border-left-color: #f59e0b;">
                    <div class="num" style="color: #f59e0b;"><?= $celdasPorTipo['electrico'] ?></div>
                    <div class="lbl">Eléctricos</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-grande">💾 Guardar cambios</button>
    </form>

    <div class="acciones-extra">
        <a href="admin_sedes.php" class="btn-accion outline">← Volver a mis sedes</a>
        <a href="ver_qr.php?parqueadero_id=<?= $parqueaderoId ?>" class="btn-accion outline">▦ Ver QRs de esta sede</a>
    </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>