<?php
session_start();
require_once 'conexion.php';
require_once 'helpers.php';
require_once 'csrf.php';
send_security_headers();

require_role_empresa(['admin','operador','superadmin']);
$eid = current_empresa_id();

$msg=''; $error=''; $registro=null; $total=0; $horas=0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesion expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'cobrar' && !empty($_POST['registro_id'])) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE id=:id AND empresa_id=:eid FOR UPDATE");
                $stmt->execute(['id'=>(int)$_POST['registro_id'],'eid'=>$eid]);
                $reg = $stmt->fetch();
                if (!$reg) throw new RuntimeException('Registro no encontrado.');

                $tarifa = get_tarifa($pdo, $reg['tipo_vehiculo'], $reg['tipo_tarifa'] ?: 'hora');
                if ($tarifa <= 0) $tarifa = ($reg['tipo_vehiculo'] === 'moto') ? 2000 : 4000;

                $ing = new DateTime($reg['fecha_ingreso']);
                $horas = max(1, (int)ceil((time() - $ing->getTimestamp()) / 3600));
                $total = $horas * $tarifa;
                $metodo = $_POST['metodo'] ?? 'efectivo';
                $token = $reg['token_factura'] ?: bin2hex(random_bytes(16));

                $pdo->prepare("UPDATE registros_parqueo SET estado_pago=1, horas=:h, total_pagar=:t, token_factura=:tk, metodo_pago=:m WHERE id=:id AND empresa_id=:eid")
                    ->execute(['h'=>$horas,'t'=>$total,'tk'=>$token,'m'=>$metodo,'id'=>$reg['id'],'eid'=>$eid]);

                $pdo->prepare("INSERT INTO pagos (empresa_id, registro_id, monto, metodo, estado, referencia) VALUES (:eid,:rid,:monto,:metodo,'aprobado',:ref)")
                    ->execute(['eid'=>$eid,'rid'=>$reg['id'],'monto'=>$total,'metodo'=>$metodo,'ref'=>$token]);

                $pdo->commit();
                registrarAuditoria($pdo, 'cobro', "registro={$reg['id']} total=$total");
                $msg = "Cobro registrado: $" . number_format($total,0,',','.') . " - Token: $token";
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('modulo_pagos: ' . $e->getMessage());
                $error = 'Error al procesar el cobro.';
            }
        }

        if ($accion === 'buscar' && !empty($_POST['placa'])) {
            $placa = strtoupper(preg_replace('/[^A-Z0-9]/','', $_POST['placa']));
            $stmt = $pdo->prepare("SELECT * FROM registros_parqueo WHERE empresa_id=:eid AND placa=:p AND estado='activo' LIMIT 1");
            $stmt->execute(['eid'=>$eid,'p'=>$placa]);
            $registro = $stmt->fetch();
            if ($registro) {
                $tarifa = get_tarifa($pdo, $registro['tipo_vehiculo'], $registro['tipo_tarifa'] ?: 'hora');
                if ($tarifa <= 0) $tarifa = ($registro['tipo_vehiculo']==='moto') ? 2000 : 4000;
                $ing = new DateTime($registro['fecha_ingreso']);
                $horas = max(1, (int)ceil((time() - $ing->getTimestamp()) / 3600));
                $total = $horas * $tarifa;
            } else {
                $error = "No hay vehiculo activo con placa $placa.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Modulo de Pagos</title>
<link rel="stylesheet" href="assets/css/main.css?v=34">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="cyber-body">
<nav class="cyber-navbar"><div class="nav-container">
  <a href="dashboard_admin.php" class="cyber-btn-outline">Volver al Panel</a>
  <span style="color:var(--text-muted)">Caja - Empresa #<?= (int)$eid ?></span>
</div></nav>

<div class="container cyber-entrance" style="max-width:640px">
  <div class="cyber-card">
    <h2 style="color:var(--primary-cyan);text-align:center">Cobro y Facturacion Digital</h2>
    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="cyber-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <form method="POST" class="cyber-form" style="margin-bottom:1rem">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="buscar">
      <div class="form-group"><label>Placa o codigo QR</label>
        <input type="text" name="placa" required autofocus style="text-align:center;text-transform:uppercase"></div>
      <button type="submit" class="cyber-btn-outline" style="width:100%">Buscar</button>
    </form>

    <?php if ($registro): ?>
      <div style="background:rgba(0,0,0,.35);padding:1rem;border-radius:8px;margin:1rem 0">
        <p>Placa: <strong><?= htmlspecialchars($registro['placa']) ?></strong></p>
        <p>Tipo: <?= htmlspecialchars($registro['tipo_vehiculo']) ?> - Tarifa: <?= htmlspecialchars($registro['tipo_tarifa'] ?? 'hora') ?></p>
        <p>Horas: <?= (int)$horas ?></p>
        <p style="font-size:1.5rem;color:var(--primary-green)">Total: $<?= number_format($total,0,',','.') ?> COP</p>
      </div>
      <form method="POST" class="cyber-form">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="cobrar">
        <input type="hidden" name="registro_id" value="<?= (int)$registro['id'] ?>">
        <div class="form-group"><label>Metodo de pago</label>
          <select name="metodo">
            <option value="efectivo">Efectivo</option>
            <option value="tarjeta">Tarjeta</option>
            <option value="transferencia">Transferencia</option>
            <option value="qr">QR digital</option>
          </select>
        </div>
        <button type="submit" class="cyber-btn-primary" style="width:100%;background:var(--primary-green);color:#05070d">Procesar Cobro</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body></html>