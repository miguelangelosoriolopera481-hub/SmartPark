<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'validaciones.php';
require_once 'helpers_entrada.php';

require_password_change_guard();

$mensaje = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página e inténtalo de nuevo.";
    } else {
        $placa  = normalizar_placa($_POST['placa'] ?? '');
        $modelo = trim($_POST['modelo'] ?? '');

        // Empresa activa (multi-tenant obligatorio)
        $eid = (int)($_SESSION['empresa_id'] ?? 0);
        $pid = (int)($_SESSION['parqueadero_id'] ?? 0);
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        if ($eid <= 0) {
            $error = "Debes iniciar sesión en una empresa para registrar un vehículo. (Error: Empresa ID vacío)";
        } elseif (empty($placa)) {
            $error = "Debe ingresar una placa de vehículo.";
        } elseif (empty($modelo)) {
            $error = "Debe ingresar el modelo del vehículo.";
        } else {
            $tipo_vehiculo = detectar_tipo_placa($placa);

            if ($tipo_vehiculo === null) {
                $error = mensaje_formato_placa_invalido();
            } else {
                try {
                    // 🛡️ SEGURIDAD ANTI-SPAM: Límite de 10 registros por IP en 10 minutos
                    $ip_cliente = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $stmt_spam = $pdo->prepare("
                        SELECT COUNT(*) FROM auditoria_admin 
                        WHERE accion IN ('entrada_vehiculo', 'registro_vehiculo') 
                          AND ip = :ip 
                          AND creado_en > (NOW() - INTERVAL 10 MINUTE)
                    ");
                    $stmt_spam->execute(['ip' => $ip_cliente]);
                    if ((int)$stmt_spam->fetchColumn() >= 10) {
                        throw new Exception('Límite de seguridad alcanzado. Has registrado demasiados vehículos en poco tiempo. Espera 10 minutos (Protección Anti-Spam).');
                    }

                    $pdo->beginTransaction();

                    // FIX CRÍTICO: Usar la función que sí trae los datos completos
                    $existente = detectar_vehiculo_dentro($pdo, $eid, $placa);

                    if ($existente) {
                        $pdo->rollBack();
                        if ($existente['es_misma_empresa'] ?? false) {
                            $error = "Este vehículo ya está activo en tu parqueadero (Celda {$existente['cupo_asignado']}). Debe salir antes de registrar uno nuevo.";
                        } else {
                            $error = "Esta placa ya está activa en otro parqueadero ({$existente['empresa_nombre']}). Una placa no puede estar en dos parqueaderos al mismo tiempo.";
                        }
                    } else {
                        
                        $stmt_cupo = $pdo->prepare("
                            SELECT codigo_cupo
                            FROM cupos
                            WHERE tipo = :tipo AND estado = 'disponible' AND empresa_id = :eid
                            ORDER BY LENGTH(codigo_cupo) ASC, codigo_cupo ASC
                            LIMIT 1
                            FOR UPDATE
                        ");
                        $stmt_cupo->execute(['tipo' => $tipo_vehiculo, 'eid' => $eid]);
                        $celda_libre = $stmt_cupo->fetch();

                        if (!$celda_libre) {
                            $pdo->rollBack();
                            $error = "No hay celdas disponibles en este momento para " . strtoupper($tipo_vehiculo) . "S.";
                        } else {
                            $cupo_asignado = $celda_libre['codigo_cupo'];
                            $correo = $_SESSION['correo'] ?? '';

                            $stmt_ins = $pdo->prepare("
                                INSERT INTO registros_parqueo
                                (empresa_id, parqueadero_id, placa, modelo, correo, tipo_vehiculo, cupo_asignado, fecha_ingreso, estado, user_id)
                                VALUES (:eid, :pid, :placa, :modelo, :correo, :tipo, :cupo, NOW(), 'activo', :user_id)
                            ");
                            $stmt_ins->execute([
                                'eid'     => $eid,
                                'pid'     => $pid ?: null,
                                'placa'   => $placa,
                                'modelo'  => $modelo,
                                'correo'  => $correo,
                                'tipo'    => $tipo_vehiculo,
                                'cupo'    => $cupo_asignado,
                                'user_id' => $uid ?: null,
                            ]);

                            $stmt_upd = $pdo->prepare("UPDATE cupos SET estado = 'ocupado' WHERE codigo_cupo = :cupo AND empresa_id = :eid");
                            $stmt_upd->execute(['cupo' => $cupo_asignado, 'eid' => $eid]);

                            $stmt_veh = $pdo->prepare("
                                INSERT INTO user_vehiculo (empresa_id, placa, modelo, correo, tipo_vehiculo, user_id)
                                VALUES (:eid, :placa, :modelo, :correo, :tipo, :user_id)
                                ON DUPLICATE KEY UPDATE
                                    modelo = VALUES(modelo),
                                    correo = IF(VALUES(correo) <> '', VALUES(correo), correo),
                                    user_id = COALESCE(VALUES(user_id), user_id),
                                    empresa_id = VALUES(empresa_id)
                            ");
                            $stmt_veh->execute([
                                'eid'     => $eid,
                                'placa'   => $placa,
                                'modelo'  => $modelo,
                                'correo'  => $correo,
                                'tipo'    => $tipo_vehiculo,
                                'user_id' => $uid ?: null,
                            ]);

                            $pdo->commit();
                            $mensaje = "¡Vehículo ($placa) registrado en la celda $cupo_asignado!";
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
    <link rel="stylesheet" href="assets/css/main.css?v=34">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>

<div class="container cyber-entrance" style="max-width: 500px; margin-top: 2.5rem;">
    <div class="cyber-card">
        <h2 style="color: var(--primary-cyan); margin-bottom: 0.5rem; text-align: center;">Ingreso Inteligente</h2>
        <p style="color: var(--text-muted); text-align: center; font-size: 0.85rem; margin-bottom: 1.5rem;">
            Ingresa placa y modelo. El tipo de vehículo (carro/moto) se detecta automáticamente.
        </p>

        <?php if (!empty($mensaje)): ?>
            <div class="pill-cyber" style="border-color: var(--primary-green); color: var(--primary-green); margin-bottom: 1.5rem; text-align: center; padding: 1rem;">
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="cyber-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="registro_carro.php" method="POST" class="cyber-form" autocomplete="off">
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