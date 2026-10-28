<?php
session_start();
require_once 'conexion.php';
require_once 'helpers.php';
require_once 'csrf.php';
require_once 'validaciones.php';
send_security_headers();

require_login();
$eid = require_empresa();

$msg=''; $error='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) { $error = 'Sesion expirada.'; }
    else {
        $nombre   = trim($_POST['nombre'] ?? '');
        $placa    = strtoupper(preg_replace('/[^A-Z0-9]/','', $_POST['placa'] ?? ''));
        $modelo   = trim($_POST['modelo'] ?? '');
        $tipoVeh  = $_POST['tipo_vehiculo'] ?? 'carro';
        $tipoTar  = $_POST['tipo_tarifa'] ?? 'hora';
        $correo   = trim($_POST['correo'] ?? '');

        $tiposValidos = ['carro','moto','electrico','bicicleta','camioneta'];
        $tarValidos   = ['hora','fraccion','dia','mensualidad','prepago','convenio'];

        if ($nombre === '' || strlen($placa) < 6) {
            $error = 'Nombre y placa (minimo 6 caracteres) son obligatorios.';
        } elseif (!in_array($tipoVeh, $tiposValidos, true)) {
            $error = 'Tipo de vehiculo no valido.';
        } elseif (!in_array($tipoTar, $tarValidos, true)) {
            $error = 'Tipo de tarifa no valido.';
        } else {
            try {
                $pdo->beginTransaction();

                $chk = $pdo->prepare("SELECT id FROM registros_parqueo WHERE placa=:p AND estado='activo' AND empresa_id=:eid LIMIT 1");
                $chk->execute(['p'=>$placa,'eid'=>$eid]);
                if ($chk->fetch()) {
                    $pdo->rollBack();
                    $error = 'Este vehiculo ya tiene un ingreso activo en esta empresa.';
                } else {
                    $tipoBase = in_array($tipoVeh, ['carro','electrico','camioneta'], true) ? 'carro' : 'moto';

                    $stmt = $pdo->prepare("SELECT codigo_cupo FROM cupos WHERE tipo=:t AND estado='disponible' AND empresa_id=:eid ORDER BY LENGTH(codigo_cupo), codigo_cupo LIMIT 1 FOR UPDATE");
                    $stmt->execute(['t'=>$tipoBase,'eid'=>$eid]);
                    $cupo = $stmt->fetchColumn();

                    if (!$cupo) {
                        $pdo->rollBack();
                        $error = 'No hay celdas disponibles para ' . $tipoBase . ' en esta empresa.';
                    } else {
                        $token = bin2hex(random_bytes(16));

                        $ins = $pdo->prepare("
                            INSERT INTO registros_parqueo
                            (empresa_id, parqueadero_id, placa, modelo, correo, tipo_vehiculo, cupo_asignado,
                             fecha_ingreso, estado, user_id, tipo_tarifa, token_factura)
                            VALUES (:eid, :pid, :p, :m, :c, :tv, :cu, NOW(), 'activo', :uid, :tt, :tk)
                        ");
                        $ins->execute([
                            'eid'=>$eid, 'pid'=>$_SESSION['parqueadero_id'] ?? 1,
                            'p'=>$placa, 'm'=>$modelo, 'c'=>$correo, 'tv'=>$tipoBase, 'cu'=>$cupo,
                            'uid'=>(int)$_SESSION['usuario_id'], 'tt'=>$tipoTar, 'tk'=>$token,
                        ]);

                        $pdo->prepare("UPDATE cupos SET estado='ocupado' WHERE codigo_cupo=:c AND empresa_id=:eid")
                            ->execute(['c'=>$cupo,'eid'=>$eid]);

                        $pdo->commit();
                        registrarAuditoria($pdo, 'registro_vehiculo', "placa=$placa cupo=$cupo");
                        $msg = "Vehiculo $placa registrado en celda $cupo. Token: $token";
                    }
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('registro_vehiculo_usuario: ' . $e->getMessage());
                $error = 'Error al procesar el registro.';
            }
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registrar Vehiculo - SmartPark</title>
<link rel="stylesheet" href="style.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head><body class="cyber-body">
<nav class="cyber-navbar"><div class="nav-container">
  <a href="dashboard_admin.php" class="cyber-btn-outline">Volver al Panel</a>
  <span style="color:var(--text-muted)">Registrar Vehiculo - Empresa #<?= (int)$eid ?></span>
</div></nav>

<div class="container cyber-entrance" style="max-width:620px">
  <div class="cyber-card">
    <h2 style="color:var(--primary-cyan);text-align:center">Registrar Vehiculo / Conductor</h2>
    <p style="color:var(--text-muted);text-align:center;font-size:.85rem;margin-bottom:1.5rem">Cero papel - Asignacion automatica de celda + ticket digital</p>

    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="cyber-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <form method="POST" class="cyber-form">
      <?= csrf_field() ?>
      <div class="form-group"><label>Nombre conductor</label><input type="text" name="nombre" required></div>
      <div class="form-group"><label>Placa (sin guiones)</label>
        <input type="text" name="placa" maxlength="6" required placeholder="ABC123 / ABC12D"
               style="text-transform:uppercase;text-align:center;letter-spacing:2px"></div>
      <div class="form-group"><label>Modelo</label><input type="text" name="modelo" placeholder="Mazda 3, Yamaha FZ..."></div>
      <div class="form-group"><label>Tipo de vehiculo</label>
        <select name="tipo_vehiculo">
          <option value="carro">Carro</option>
          <option value="moto">Moto</option>
          <option value="electrico">Electrico</option>
          <option value="bicicleta">Bicicleta</option>
          <option value="camioneta">Camioneta</option>
        </select>
      </div>
      <div class="form-group"><label>Tipo de tarifa</label>
        <select name="tipo_tarifa">
          <option value="hora">Por hora</option>
          <option value="fraccion">Por fraccion</option>
          <option value="dia">Por dia</option>
          <option value="mensualidad">Mensualidad</option>
          <option value="prepago">Prepago</option>
          <option value="convenio">Convenio</option>
        </select>
      </div>
      <div class="form-group"><label>Correo (opcional)</label><input type="email" name="correo"></div>
      <button type="submit" class="cyber-btn-primary" style="width:100%">Registrar y asignar celda</button>
    </form>
  </div>
</div>
</body></html>
