<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'validaciones.php';
require_once 'helpers_anti_fraude.php';
send_security_headers();

// 1. SEGURIDAD: Solo staff autorizado puede entrar aquí
require_login();
$rol = $_SESSION['rol_en_empresa'] ?? '';
if (!in_array($rol, ['vigilante', 'operador', 'admin', 'superadmin'], true)) {
    die('Acceso denegado: No tienes permisos para autorizar salidas.');
}

// 2. AISLAMIENTO: Capturamos la empresa del vigilante/operador
$eidGuardia = (int)($_SESSION['empresa_id'] ?? 0);
if ($eidGuardia <= 0) {
    die('Error de sesión: No tienes una empresa asignada.');
}

require_password_change_guard();

$estado_salida = 'espera';
$datos_vehiculo = null;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_verificar_salida'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $estado_salida = 'CSRF_INVALIDO';
    } else {
        $busqueda = normalizar_placa($_POST['identificador'] ?? '');

        if (!empty($busqueda)) {
            // 3. CONSULTA BLINDADA: Solo busca en la empresa del usuario logueado
            $stmt = $pdo->prepare("
                SELECT r.* FROM registros_parqueo r
                WHERE (r.placa = :b1 OR r.token_factura = :b2) 
                  AND r.estado = 'activo'
                  AND r.empresa_id = :eid_guardia
                ORDER BY r.id DESC LIMIT 1
            ");
            $stmt->execute([
                'b1' => $busqueda, 
                'b2' => $busqueda, 
                'eid_guardia' => $eidGuardia
            ]);
            $datos_vehiculo = $stmt->fetch();

            if ($datos_vehiculo) {
                $id_registro = $datos_vehiculo['id'];
                $cupo = $datos_vehiculo['cupo_asignado'];

                if ((int) $datos_vehiculo['estado_pago'] === 0) {
                    $estado_salida = 'ALERTA_ROBO';
                } else {
                    $recobro = recalcular_cobro_al_salir($pdo, $datos_vehiculo, $eidGuardia);

                    if ($recobro['horas_extra'] > 0) {
                        marcar_pago_sin_salida(
                            $pdo,
                            $id_registro,
                            "Pagó {$datos_vehiculo['horas']}h pero estuvo {$recobro['horas_extra']}h extra. Cobro adicional: $" . number_format($recobro['extra'], 0, ',', '.')
                        );
                        $estado_salida = 'ALERTA_FRAUDE'; 
                        $datos_vehiculo['recobro_extra'] = $recobro['extra'];
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $pdo->prepare("UPDATE cupos SET estado = 'disponible' WHERE codigo_cupo = :cupo AND empresa_id = :eid")->execute(['cupo' => $cupo, 'eid' => $eidGuardia]);
                            $pdo->prepare("UPDATE registros_parqueo SET estado = 'finalizado', fecha_salida = NOW() WHERE id = :id")->execute(['id' => $id_registro]);
                            $pdo->commit();
                            $estado_salida = 'AUTORIZADO';
                        } catch (\Exception $e) {
                            $pdo->rollBack();
                            $estado_salida = 'ERROR';
                        }
                    }
                }
            } else {
                $estado_salida = 'NO_ENCONTRADO_O_OTRA_EMPRESA';
            }
        } else {
            $estado_salida = 'NO_ENCONTRADO_O_OTRA_EMPRESA';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Salida - SmartPark</title>
    <link rel="stylesheet" href="assets/css/main.css?v=999">
    <link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="has-mobile-topbar" class="cyber-body">
<?php $backUrl = 'dashboard_vigilante.php'; include __DIR__ . '/partials/mobile_topbar.php'; ?>
<div class="container cyber-entrance" style="max-width: 520px; margin-top: 3rem;">
    <div class="cyber-card" style="text-align: center;">
        <h1 style="color: var(--primary-cyan);">Control de Salida</h1>
        <p class="subtext" style="color: var(--text-muted); margin-bottom: 1.5rem;">Ingrese placa o escanee el QR de la factura</p>

        <form action="salida.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action_verificar_salida" value="1">
            <input type="text" name="identificador" placeholder="PLACA O TOKEN" required autofocus style="width: 100%; padding: 0.8rem; background: var(--bg-dark); border: 1px solid var(--border-cyber); border-radius: 8px; color: var(--text-main); text-transform: uppercase; text-align: center; margin-bottom: 1rem;">
            <button type="submit" class="cyber-btn-primary" style="width: 100%;">Verificar y Autorizar</button>
        </form>

        <?php if ($estado_salida == 'ALERTA_ROBO'): ?>
            <div class="alert-box alert-robo" style="margin-top: 1.5rem;">
                <h2>ALERTA: PAGO PENDIENTE</h2>
                <p>El vehículo <strong><?= htmlspecialchars($datos_vehiculo['placa']) ?></strong> no ha pagado. No se autoriza la salida.</p>
            </div>
        <?php elseif ($estado_salida == 'AUTORIZADO'): ?>
            <div class="alert-box alert-ok" style="margin-top: 1.5rem;">
                <h2>BARRERA DESBLOQUEADA</h2>
                <p>Celda <?= htmlspecialchars($datos_vehiculo['cupo_asignado']) ?> liberada. ¡Buen viaje!</p>
            </div>
        <?php elseif ($estado_salida == 'NO_ENCONTRADO_O_OTRA_EMPRESA'): ?>
            <div class="alert-box alert-warn" style="margin-top: 1.5rem;">
                <h2>NO ENCONTRADO</h2>
                <p>No existe un registro activo con ese identificador en <strong>TU parqueadero</strong>.</p>
                <p style="font-size: 0.75rem; margin-top: 5px; color: #94a3b8;">(Si estás seguro de que el auto está aquí, verifica que hayas iniciado sesión en la empresa correcta).</p>
            </div>
        <?php elseif ($estado_salida == 'ALERTA_FRAUDE'): ?>
            <div class="alert-box alert-robo" style="margin-top: 1.5rem;">
                <h2>TIEMPO EXCEDIDO</h2>
                <p>El vehículo <strong><?= htmlspecialchars($datos_vehiculo['placa']) ?></strong> pagó por <?= htmlspecialchars($datos_vehiculo['horas']) ?> horas, pero excedió el tiempo permitido.</p>
                <p style="font-size: 1.2rem; margin-top: 10px;">Cobro adicional requerido: <strong>$<?= number_format($datos_vehiculo['recobro_extra'], 0, ',', '.') ?></strong></p>
                <p style="font-size: 0.8rem; margin-top: 10px; color: #94a3b8;">Por favor, cobre el excedente en caja antes de autorizar la salida.</p>
                <a href="modulo_pagos.php?placa=<?= urlencode($datos_vehiculo['placa']) ?>" class="cyber-btn-primary" style="display: block; margin-top: 1rem; text-decoration: none;">Ir a Cobrar Excedente</a>
            </div>
        <?php elseif ($estado_salida == 'CSRF_INVALIDO'): ?>
            <div class="alert-box alert-warn" style="margin-top: 1.5rem;">
                <h2>FORMULARIO EXPIRADO</h2>
                <p>Recarga la página e inténtalo de nuevo.</p>
            </div>
        <?php elseif ($estado_salida == 'ERROR'): ?>
            <div class="alert-box alert-warn" style="margin-top: 1.5rem;">
                <h2>ERROR TEMPORAL</h2>
                <p>No se pudo procesar la salida. Intenta nuevamente.</p>
            </div>
        <?php endif; ?>

        <div style="margin-top: 1.5rem;">
            <a href="dashboard_admin.php" style="color: var(--text-muted); text-decoration: none;">← Volver al panel</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>