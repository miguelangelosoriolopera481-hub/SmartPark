<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'validaciones.php';

require_password_change_guard();

$mensaje = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página e inténtalo de nuevo.";
    } else {
        $placa  = normalizar_placa($_POST['placa'] ?? '');
        $modelo = trim($_POST['modelo'] ?? '');

        if (empty($placa)) {
            $error = "Debe ingresar una placa de vehículo.";
        } elseif (empty($modelo)) {
            $error = "Debe ingresar el modelo del vehículo.";
        } else {
            // Validación de formato REAL (antes solo se miraba el último carácter)
            $tipo_vehiculo = detectar_tipo_placa($placa);

            if ($tipo_vehiculo === null) {
                $error = mensaje_formato_placa_invalido();
            } else {
                try {
                    $pdo->beginTransaction();

                    // Evita doble ingreso: si la placa ya tiene un registro activo, no se duplica.
                    $stmt_activo = $pdo->prepare("SELECT id FROM registros_parqueo WHERE placa = :placa AND estado = 'activo' LIMIT 1");
                    $stmt_activo->execute(['placa' => $placa]);
                    if ($stmt_activo->fetch()) {
                        $pdo->rollBack();
                        $error = "Este vehículo ya tiene un ingreso activo. Debe salir antes de registrar uno nuevo.";
                    } else {
                        $stmt_cupo = $pdo->prepare("
                            SELECT codigo_cupo
                            FROM cupos
                            WHERE tipo = :tipo AND estado = 'disponible'
                            ORDER BY LENGTH(codigo_cupo) ASC, codigo_cupo ASC
                            LIMIT 1
                            FOR UPDATE
                        ");
                        $stmt_cupo->execute(['tipo' => $tipo_vehiculo]);
                        $celda_libre = $stmt_cupo->fetch();

                        if (!$celda_libre) {
                            $pdo->rollBack();
                            $error = "No hay celdas disponibles en este momento para " . strtoupper($tipo_vehiculo) . "S.";
                        } else {
                            $cupo_asignado = $celda_libre['codigo_cupo'];
                            $usuario_id = $_SESSION['usuario_id'] ?? null;
                            $correo     = $_SESSION['correo'] ?? '';

                            $stmt_ins = $pdo->prepare("
                                INSERT INTO registros_parqueo (placa, modelo, correo, tipo_vehiculo, cupo_asignado, fecha_ingreso, estado, user_id)
                                VALUES (:placa, :modelo, :correo, :tipo, :cupo, NOW(), 'activo', :user_id)
                            ");
                            $stmt_ins->execute([
                                'placa'   => $placa,
                                'modelo'  => $modelo,
                                'correo'  => $correo,
                                'tipo'    => $tipo_vehiculo,
                                'cupo'    => $cupo_asignado,
                                'user_id' => $usuario_id,
                            ]);

                            $stmt_upd = $pdo->prepare("UPDATE cupos SET estado = 'ocupado' WHERE codigo_cupo = :cupo");
                            $stmt_upd->execute(['cupo' => $cupo_asignado]);

                            // Relación vehículo-propietario (tabla user_vehiculo), clave natural = placa
                            $stmt_veh = $pdo->prepare("
                                INSERT INTO user_vehiculo (placa, modelo, correo, tipo_vehiculo, user_id)
                                VALUES (:placa, :modelo, :correo, :tipo, :user_id)
                                ON DUPLICATE KEY UPDATE
                                    modelo = VALUES(modelo),
                                    correo = IF(VALUES(correo) <> '', VALUES(correo), correo),
                                    user_id = COALESCE(VALUES(user_id), user_id)
                            ");
                            $stmt_veh->execute([
                                'placa'   => $placa,
                                'modelo'  => $modelo,
                                'correo'  => $correo,
                                'tipo'    => $tipo_vehiculo,
                                'user_id' => $usuario_id,
                            ]);

                            $pdo->commit();
                            $mensaje = "¡Vehículo ($placa) detectado como " . strtoupper($tipo_vehiculo) . " y asignado a la Celda: " . $cupo_asignado . "!";
                        }
                    }
                } catch (\Exception $e) {
                    $pdo->rollBack();
                    error_log('registro_carro: ' . $e->getMessage());
                    $error = "Error al procesar el registro. Intenta nuevamente.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark Pro - Registro Automático</title>
    <link rel="stylesheet" href="CSS/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>

<div class="container cyber-entrance" style="max-width: 500px; margin-top: 2.5rem;">
    <div class="cyber-card">
        <h2 style="color: var(--primary-cyan); margin-bottom: 0.5rem; text-align: center;">Ingreso Inteligente</h2>
        <p style="color: var(--text-muted); text-align: center; font-size: 0.85rem; margin-bottom: 1.5rem;">
            Ingresa placa y modelo. El tipo de vehículo (carro/moto) se detecta automáticamente por el formato de la placa.
        </p>

        <?php if (!empty($mensaje)): ?>
            <div class="pill-cyber" style="border-color: var(--primary-green); color: var(--primary-green); margin-bottom: 1.5rem; text-align: center; padding: 1rem;">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="cyber-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="registro_carro.php" method="POST" class="cyber-form">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Placa del Vehículo:</label>
                <input type="text" name="placa" maxlength="6" placeholder="EJ: ABC123 (Carro) o ABC12D (Moto)" required autocomplete="off" style="text-transform: uppercase; font-size: 1.1rem; letter-spacing: 2px; text-align: center;">
            </div>

            <div class="form-group">
                <label>Modelo del Vehículo:</label>
                <input type="text" name="modelo" placeholder="Ej. Mazda 3, Yamaha FZ..." required autocomplete="off">
            </div>

            <button type="submit" class="cyber-btn-primary" style="width: 100%; margin-top: 0.5rem;">Procesar y Asignar Celda</button>
        </form>

        <div style="margin-top: 1.8rem; padding-top: 1.2rem; border-top: 1px solid rgba(255,255,255,0.1); display: flex; gap: 0.8rem; justify-content: space-between;">
            <a href="ver_factura.php" class="cyber-btn-outline" style="flex: 1; text-align: center; text-decoration: none; border-color: #3b82f6; color: #3b82f6; font-size: 0.85rem; padding: 0.6rem 0.3rem;">📄 Consulta Factura</a>
            <?php if (!isset($_SESSION['usuario_id'])): ?>
                <a href="login.php" class="cyber-btn-outline" style="flex: 1; text-align: center; text-decoration: none; font-size: 0.85rem; padding: 0.6rem 0.3rem;">🔐 Iniciar Sesión</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
