<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'validaciones.php';

require_password_change_guard();

$estado_salida = 'espera';
$datos_vehiculo = null;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_verificar_salida'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $estado_salida = 'CSRF_INVALIDO';
    } else {
        $busqueda = normalizar_placa($_POST['identificador'] ?? '');

        if (!empty($busqueda)) {
            // CORRECCIÓN: antes se buscaba por placa/token SIN filtrar estado
            // ni ordenar, así que un registro antiguo ya finalizado podía
            // devolver una "alerta de robo" o autorización incorrectas. Ahora
            // solo se considera el registro ACTIVO más reciente de esa placa
            // o token (el token también solo pertenece a un registro activo
            // hasta que se retira el vehículo).
            $stmt = $pdo->prepare("
                SELECT * FROM registros_parqueo
                WHERE (placa = :busqueda OR token_factura = :busqueda) AND estado = 'activo'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute(['busqueda' => $busqueda]);
            $datos_vehiculo = $stmt->fetch();

            if ($datos_vehiculo) {
                $id_registro = $datos_vehiculo['id'];
                $cupo = $datos_vehiculo['cupo_asignado'];

                if ((int) $datos_vehiculo['estado_pago'] === 0) {
                    $estado_salida = 'ALERTA_ROBO';
                } else {
                    // Pagado: AQUÍ (y solo aquí) se libera la celda y se cierra el registro.
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare("UPDATE cupos SET estado = 'disponible' WHERE codigo_cupo = :cupo")->execute(['cupo' => $cupo]);
                        $pdo->prepare("UPDATE registros_parqueo SET estado = 'finalizado', fecha_salida = NOW() WHERE id = :id")->execute(['id' => $id_registro]);
                        $pdo->commit();
                        $estado_salida = 'AUTORIZADO';
                    } catch (\Exception $e) {
                        $pdo->rollBack();
                        error_log('salida.php: ' . $e->getMessage());
                        $estado_salida = 'ERROR';
                    }
                }
            } else {
                $estado_salida = 'NO_ENCONTRADO';
            }
        } else {
            $estado_salida = 'NO_ENCONTRADO';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark - Control de Salida</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<div class="container cyber-entrance" style="max-width: 520px; margin-top: 3rem;">
    <div class="cyber-card" style="text-align: center;">
        <h1 style="color: var(--primary-cyan);">Control de Salida</h1>
        <p class="subtext" style="color: var(--text-muted); margin-bottom: 1.5rem;">Ingrese placa o token de factura</p>

        <form action="salida.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action_verificar_salida" value="1">
            <input type="text" name="identificador" placeholder="PLACA O TOKEN" required autofocus style="width: 100%; padding: 0.8rem; background: var(--bg-dark); border: 1px solid var(--border-cyber); border-radius: 8px; color: var(--text-main); text-transform: uppercase; text-align: center;">
            <button type="submit" class="cyber-btn-primary" style="width: 100%; margin-top: 1rem;">Verificar y Autorizar</button>
        </form>

        <?php if ($estado_salida == 'ALERTA_ROBO'): ?>
            <div class="alert-box alert-robo" style="margin-top: 1.5rem;">
                <h2>ALERTA: PAGO PENDIENTE</h2>
                <p>El vehículo con placa <strong><?= htmlspecialchars($datos_vehiculo['placa']) ?></strong> no ha pagado. No se autoriza la salida.</p>
            </div>
        <?php elseif ($estado_salida == 'AUTORIZADO'): ?>
            <div class="alert-box alert-ok" style="margin-top: 1.5rem;">
                <h2>BARRERA DESBLOQUEADA</h2>
                <p>Celda <?= htmlspecialchars($datos_vehiculo['cupo_asignado']) ?> liberada. ¡Buen viaje!</p>
            </div>
        <?php elseif ($estado_salida == 'NO_ENCONTRADO'): ?>
            <div class="alert-box alert-warn" style="margin-top: 1.5rem;">
                <h2>NO ENCONTRADO</h2>
                <p>No existe un registro activo con ese identificador (verifica que no haya salido ya).</p>
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
            <a href="index.php" style="color: var(--text-muted); text-decoration: none;">← Volver al inicio</a>
        </div>
    </div>
</div>
</body>
</html>
