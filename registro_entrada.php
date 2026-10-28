<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'helpers_entrada.php';
require_once 'helpers_anti_fraude.php';
send_security_headers();

require_login();
$eid = require_empresa();
$rol = $_SESSION['rol_en_empresa'] ?? '';
if (!in_array($rol, ['admin', 'operador', 'vigilante', 'superadmin'], true)) {
    header('Location: dashboard_admin.php');
    exit;
}

$mensaje = '';
$error = '';
$sugerencia = '';
$registroConfirmado = null;
$analisisAplicado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $placaRaw = $_POST['placa'] ?? '';
        $modelo = trim($_POST['modelo'] ?? '');
        $esElectrico = !empty($_POST['es_electrico']);
        $esBicicleta = !empty($_POST['es_bicicleta']);
        $cedula = trim($_POST['cedula_ciclista'] ?? '');
        $nombreCiclista = trim($_POST['nombre_ciclista'] ?? '');
        $descripcion = trim($_POST['descripcion_vehiculo'] ?? '');
        $confirmoVigilante = !empty($_POST['confirmo_vigilante']);

        // Si es bicicleta: validar datos obligatorios
        if ($esBicicleta) {
            if (strlen($cedula) < 5) {
                $error = 'La cédula del ciclista es obligatoria (mínimo 5 caracteres).';
            } elseif (strlen($nombreCiclista) < 3) {
                $error = 'El nombre del ciclista es obligatorio.';
            } elseif (strlen($descripcion) < 3) {
                $error = 'Describe la bicicleta (marca, color). Ej: GW azul.';
            } elseif (!$confirmoVigilante) {
                $error = 'Debes confirmar visualmente que es una bicicleta.';
            } else {
                // Generar ID para bici
                $placaRaw = 'BICI-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            }
        }

        if (empty($error)) {
            $validacion = validar_placa_detallada($placaRaw);

            if (!$validacion['valida']) {
                $error = $validacion['mensaje'];
                $sugerencia = $validacion['sugerencia'];
                $sug = sugerir_placa_corregida(normalizar_placa_entrada($placaRaw));
                if ($sug) $sugerencia .= " ¿Quisiste decir: $sug?";
                registrar_intento_placa_invalida($pdo, $eid, $placaRaw, $validacion['mensaje']);
            } else {
                $placa = normalizar_placa_entrada($placaRaw);
                $tipo = $validacion['tipo'];

                $existente = detectar_vehiculo_dentro($pdo, $eid, $placa);
                if ($existente) {
                    $mins = (int)$existente['minutos_dentro'];
                    $h = floor($mins / 60);
                    $m = $mins % 60;
                    $error = "Este vehículo YA ESTÁ DENTRO en celda {$existente['cupo_asignado']} hace {$h}h {$m}min.";
                } else {
                    // Análisis anti-fraude ANTES de registrar
                    $analisisAplicado = analizar_registro_sospechoso(
                        $pdo, $eid, $placa, $esElectrico ? 'electrico' : $tipo, (int)($_SESSION['usuario_id'] ?? 0) ?: null
                    );

                    $suger = sugerir_celda_con_ia($pdo, $eid, $placa, $tipo);
                    $celda = $suger ? $suger['celda'] : asignar_celda_disponible($pdo, $eid, $tipo);
                    $razon = $suger ? $suger['razon'] : 'Primera celda disponible';

                    if (!$celda) {
                        $error = 'No hay celdas disponibles para ' . strtoupper($tipo) . ' en este momento.';
                    } else {
                        $res = crear_registro_entrada(
                            $pdo, $eid, current_parqueadero_id(),
                            $placa, $modelo, $tipo, $celda,
                            (int)($_SESSION['usuario_id'] ?? 0) ?: null,
                            $_SESSION['correo'] ?? '', 'hora', $esElectrico
                        );

                        if ($res['exito']) {
                            // Actualizar datos extra si es bici o si tiene cédula/nombre
                            if ($esBicicleta || $cedula !== '' || $nombreCiclista !== '') {
                                try {
                                    $pdo->prepare("
                                        UPDATE registros_parqueo
                                        SET cedula_ciclista = :cedula,
                                            nombre_ciclista = :nombre,
                                            descripcion_vehiculo = :desc,
                                            confirmado_vigilante = :conf
                                        WHERE id = :id
                                    ")->execute([
                                        'cedula' => $cedula ?: null,
                                        'nombre' => $nombreCiclista ?: null,
                                        'desc' => $descripcion ?: null,
                                        'conf' => $confirmoVigilante ? 1 : 0,
                                        'id' => $res['registro_id'],
                                    ]);
                                } catch (\Throwable $e) { error_log('extra: ' . $e->getMessage()); }
                            }

                            // Marcar sospechoso si aplica
                            if ($analisisAplicado && $analisisAplicado['sospechoso']) {
                                $iaAnalisis = null;
                                $ia = analizar_con_ia($pdo, [
                                    'placa' => $placa,
                                    'tipo_vehiculo' => $tipo,
                                    'razones' => $analisisAplicado['razones'],
                                ]);
                                if ($ia) $iaAnalisis = $ia['analisis'] ?? null;
                                marcar_sospechoso($pdo, $res['registro_id'], $analisisAplicado, $iaAnalisis);
                            }

                            $registroConfirmado = [
                                'placa' => $placa,
                                'modelo' => $modelo ?: $descripcion,
                                'tipo' => $esElectrico ? 'electrico' : $tipo,
                                'celda' => $res['celda'],
                                'token' => $res['token_qr'],
                                'razon' => $razon,
                                'hora' => date('Y-m-d H:i:s'),
                                'sospechoso' => $analisisAplicado['sospechoso'] ?? false,
                                'razones' => $analisisAplicado['razones'] ?? [],
                                'nivel' => $analisisAplicado['nivel'] ?? 'bajo',
                            ];
                            $mensaje = 'Vehículo registrado correctamente.';
                            if (function_exists('registrarAuditoria')) {
                                registrarAuditoria($pdo, 'entrada_vehiculo', 'placa=' . $placa . ' celda=' . $res['celda']);
                            }
                        } else {
                            $error = $res['mensaje'];
                        }
                    }
                }
            }
        }
    }
}

// Disponibilidad y stats
try {
    $stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN tipo='carro' AND estado='disponible' THEN 1 ELSE 0 END) AS carros_libres,
        SUM(CASE WHEN tipo='moto' AND estado='disponible' THEN 1 ELSE 0 END) AS motos_libres,
        SUM(CASE WHEN tipo='bicicleta' AND estado='disponible' THEN 1 ELSE 0 END) AS bicis_libres,
        SUM(CASE WHEN tipo='electrico' AND estado='disponible' THEN 1 ELSE 0 END) AS electricos_libres,
        COUNT(*) AS total_cupos,
        SUM(CASE WHEN estado='ocupado' THEN 1 ELSE 0 END) AS ocupados
        FROM cupos WHERE empresa_id = :eid");
    $stmt->execute(['eid' => $eid]);
    $disp = $stmt->fetch();
} catch (\Throwable $e) {
    $disp = ['carros_libres'=>0,'motos_libres'=>0,'bicis_libres'=>0,'electricos_libres'=>0,'total_cupos'=>0,'ocupados'=>0];
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado='activo'");
    $stmt->execute(['eid' => $eid]);
    $activos = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND DATE(fecha_ingreso) = CURDATE()");
    $stmt->execute(['eid' => $eid]);
    $ingresosHoy = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND es_sospechoso=1 AND fecha_ingreso > (NOW() - INTERVAL 30 DAY)");
    $stmt->execute(['eid' => $eid]);
    $alertas = (int)$stmt->fetchColumn();
} catch (\Throwable $e) {
    $activos = $ingresosHoy = $alertas = 0;
}

$totalCupos = max(1, (int)$disp['total_cupos']);
$pctOcupacion = round(((int)$disp['ocupados'] / $totalCupos) * 100);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registrar Entrada - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=34">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    /* Ajustes específicos de esta página sin tocar main.css */
    body { padding-top: 0; padding-bottom: 0; }
    .split-login { padding-top: 4rem; }

    .entrada-tabs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; margin-bottom: 1.5rem; }
    .entrada-tab {
        padding: 0.75rem 0.4rem;
        background: rgba(2,6,23,0.5);
        border: 1.5px solid rgba(148,163,184,0.15);
        border-radius: 12px;
        color: #94a3b8;
        font-weight: 700; font-size: 0.72rem;
        cursor: pointer; text-align: center;
        transition: all 0.25s ease;
        font-family: inherit;
    }
    .entrada-tab:hover { border-color: rgba(16,185,129,0.5); color: #10b981; }
    .entrada-tab.active { background: rgba(16,185,129,0.12); border-color: #10b981; color: #10b981; }
    .entrada-tab .et-icon { font-size: 1.35rem; display: block; margin-bottom: 0.15rem; }

    .input-grande {
        width: 100%;
        padding: 1.1rem 1.2rem;
        background: rgba(2, 6, 23, 0.7);
        border: 2px solid rgba(16, 185, 129, 0.3);
        border-radius: 12px;
        color: #f1f5f9;
        font-size: 1.4rem;
        font-weight: 700;
        text-align: center;
        letter-spacing: 0.35rem;
        font-family: "JetBrains Mono", monospace;
        text-transform: uppercase;
        outline: none;
        transition: all 0.3s ease;
    }
    .input-grande:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
    }
    .input-grande::placeholder { letter-spacing: 0.15rem; font-size: 0.95rem; opacity: 0.4; }

    .detectado {
        display: none;
        align-items: center; justify-content: center; gap: 0.5rem;
        padding: 0.65rem 0.9rem;
        margin-top: 0.75rem;
        border-radius: 10px;
        font-weight: 700; font-size: 0.85rem;
    }
    .detectado.show { display: inline-flex; }
    .detectado.carro { background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.4); color: #6ee7b7; }
    .detectado.moto { background: rgba(6, 182, 212, 0.12); border: 1px solid rgba(6, 182, 212, 0.4); color: #67e8f9; }
    .detectado.invalido { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; }

    .bloque-bici {
        background: rgba(167, 139, 250, 0.06);
        border: 1.5px solid rgba(167, 139, 250, 0.3);
        border-radius: 14px;
        padding: 1.25rem;
        margin-bottom: 1.25rem;
        display: none;
    }
    .bloque-bici.visible { display: block; }
    .bloque-bici h4 {
        font-size: 0.85rem; color: #c4b5fd; font-weight: 800;
        margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;
    }
    .aviso-anti-fraude {
        background: rgba(245, 158, 11, 0.08);
        border: 1.5px solid rgba(245, 158, 11, 0.4);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        color: #fcd34d;
        font-size: 0.8rem;
        line-height: 1.5;
    }
    .aviso-anti-fraude strong { color: #fbbf24; }

    .checkbox-wrap {
        display: flex; align-items: flex-start; gap: 0.7rem;
        padding: 0.9rem;
        background: rgba(245, 158, 11, 0.08);
        border: 1.5px solid rgba(245, 158, 11, 0.35);
        border-radius: 10px;
        cursor: pointer;
        margin-bottom: 1rem;
        transition: all 0.25s ease;
    }
    .checkbox-wrap:hover { background: rgba(245, 158, 11, 0.12); }
    .checkbox-wrap input[type="checkbox"] {
        margin-top: 0.15rem;
        width: 18px; height: 18px;
        accent-color: #f59e0b;
        cursor: pointer;
        flex-shrink: 0;
    }
    .checkbox-wrap span { font-size: 0.82rem; color: #fcd34d; line-height: 1.4; }

    .sospechoso-box {
        background: rgba(239, 68, 68, 0.1);
        border: 2px solid rgba(239, 68, 68, 0.5);
        border-radius: 14px;
        padding: 1.25rem;
        margin: 1rem 0;
        color: #fca5a5;
    }
    .sospechoso-box h4 { color: #f87171; font-size: 1rem; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; }
    .sospechoso-box ul { margin-left: 1.25rem; margin-bottom: 0.75rem; }
    .sospechoso-box li { font-size: 0.85rem; margin-bottom: 0.35rem; line-height: 1.4; }

    .confirmacion-card {
        background: linear-gradient(160deg, rgba(16, 185, 129, 0.08), rgba(6, 182, 212, 0.05));
        border: 2px solid rgba(16, 185, 129, 0.4);
        border-radius: 22px;
        padding: 2.5rem 2rem;
        text-align: center;
    }
    .ok-icon {
        width: 84px; height: 84px;
        border-radius: 50%;
        background: rgba(16, 185, 129, 0.15);
        border: 3px solid #10b981;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1.25rem;
        font-size: 2.3rem; color: #10b981;
        box-shadow: 0 0 40px rgba(16, 185, 129, 0.5);
        animation: pulseOk 2s ease-in-out infinite;
    }
    @keyframes pulseOk {
        0%, 100% { transform: scale(1); box-shadow: 0 0 40px rgba(16, 185, 129, 0.5); }
        50% { transform: scale(1.05); box-shadow: 0 0 60px rgba(16, 185, 129, 0.8); }
    }
    .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.7rem; margin: 1.25rem 0; }
    .info-item { background: rgba(2, 6, 23, 0.6); padding: 0.9rem; border-radius: 12px; text-align: left; }
    .info-item .lbl { font-size: 0.65rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; margin-bottom: 0.25rem; }
    .info-item .val { font-size: 1rem; font-weight: 800; color: #10b981; word-break: break-all; }
    .info-item.destacado { grid-column: span 2; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); }
    .info-item.destacado .val { font-size: 1.4rem; text-align: center; }
    .razon-asignacion {
        display: inline-block;
        padding: 0.4rem 0.9rem;
        background: rgba(167, 139, 250, 0.12);
        border: 1px solid rgba(167, 139, 250, 0.4);
        border-radius: 100px;
        color: #c4b5fd; font-size: 0.78rem; font-weight: 700;
        margin: 0.4rem 0 1rem;
    }
    .qr-block { background: white; padding: 1.25rem; border-radius: 16px; display: inline-block; margin: 0.75rem 0; }
    .qr-block img { max-width: 190px; display: block; }
    .qr-block .qr-label { color: #1a2332; font-weight: 800; font-size: 0.75rem; margin-top: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .acciones-finales { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; margin-top: 1.25rem; }
    .acciones-finales a {
        padding: 0.95rem; border-radius: 12px;
        font-weight: 700; font-size: 0.92rem;
        text-decoration: none; text-align: center;
        transition: all 0.3s ease;
    }
    .btn-primary-final { background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; }
    .btn-outline-final { color: #10b981; border: 1.5px solid #10b981; }
    .btn-outline-final:hover { background: #10b981; color: #030712; }

    @media (max-width: 900px) {
        .split-login { padding-top: 4rem; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<canvas id="particles-canvas"></canvas>

<div class="split-login">

  <!-- ============ IZQUIERDA: Marca + animación ============ -->
  <div class="split-left">
    <div class="split-left-content">

      <div class="scene-header">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark">
        <h2>Registrar entrada<br>en segundos</h2>
        <p>Solo placa. El sistema asigna celda automáticamente y protege contra fraudes.</p>
      </div>

      <div class="scene-middle">
        <div class="scene-parking">
          <div class="parking-grid">
            <?php for ($i = 1; $i <= 12; $i++): ?>
              <div class="park-cell" data-cell="<?= $i ?>">
                <span class="cell-dot"></span>
                <svg class="car-svg" viewBox="0 0 40 80" xmlns="http://www.w3.org/2000/svg">
                  <rect x="6" y="8" width="28" height="64" rx="10" fill="#06b6d4" opacity="0.85"/>
                  <rect x="12" y="16" width="16" height="10" rx="3" fill="#020617" opacity="0.35"/>
                  <rect x="12" y="54" width="16" height="10" rx="3" fill="#020617" opacity="0.35"/>
                </svg>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <div class="scene-footer">
        <div class="scene-footer-item">
          <span class="sfi-icon">◎</span>
          <div class="sfi-text">Asignación automática<small>Historial + IA</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">⚡</span>
          <div class="sfi-text">Carros y eléctricos<small>Soportados</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">🚲</span>
          <div class="sfi-text">Bicicletas<small>Con datos</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">🛡️</span>
          <div class="sfi-text">Anti-fraude<small>Activo</small></div>
        </div>
      </div>

      <div class="scene-stats">
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= (int)$activos ?>">0</div>
          <div class="ss-lbl">Activos</div>
        </div>
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= (int)$ingresosHoy ?>">0</div>
          <div class="ss-lbl">Hoy</div>
        </div>
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= (int)$disp['carros_libres'] ?>">0</div>
          <div class="ss-lbl">Carros libres</div>
        </div>
        <div class="scene-stat">
          <div class="ss-num" data-counter="<?= (int)$alertas ?>">0</div>
          <div class="ss-lbl">Alertas</div>
        </div>
      </div>

      <div class="scene-why">
        <h3>Protección activa</h3>
        <ul class="scene-why-list">
          <li><span class="chk">✓</span> Detección de placas inválidas</li>
          <li><span class="chk">✓</span> Bloqueo de duplicados activos</li>
          <li><span class="chk">✓</span> Datos obligatorios en bicicletas</li>
          <li><span class="chk">✓</span> Análisis con IA en cada registro</li>
          <li><span class="chk">✓</span> Auditoría de intentos fallidos</li>
        </ul>
      </div>

    </div>
  </div>

  <!-- ============ DERECHA: Formulario o confirmación ============ -->
  <div class="split-right">
    <div class="split-form">

<?php if ($registroConfirmado): ?>
    <!-- CONFIRMACIÓN -->
    <div class="confirmacion-card">
        <div class="ok-icon">✓</div>
        <h2 style="font-size: 1.4rem; font-weight: 900; color: #f1f5f9; margin-bottom: 0.35rem;">¡Vehículo registrado!</h2>
        <p style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 1.25rem;">Ticket digital generado</p>

        <?php if ($registroConfirmado['sospechoso']): ?>
        <div class="sospechoso-box">
            <h4>⚠️ Registro marcado para revisión</h4>
            <ul>
                <?php foreach ($registroConfirmado['razones'] as $razon): ?>
                    <li><?= htmlspecialchars($razon) ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="font-size: 0.78rem; margin: 0;">El administrador lo verá en el panel. Nivel: <strong><?= strtoupper($registroConfirmado['nivel']) ?></strong></p>
        </div>
        <?php endif; ?>

        <?php $et = etiqueta_tipo_vehiculo($registroConfirmado['tipo']); ?>
        <div class="info-grid">
            <div class="info-item">
                <div class="lbl">Placa</div>
                <div class="val"><?= htmlspecialchars($registroConfirmado['placa']) ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">Tipo</div>
                <div class="val"><?= $et['icono'] ?> <?= htmlspecialchars($et['nombre']) ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">Modelo</div>
                <div class="val"><?= htmlspecialchars($registroConfirmado['modelo'] ?: 'N/A') ?></div>
            </div>
            <div class="info-item">
                <div class="lbl">Hora</div>
                <div class="val" style="font-size: 0.85rem;"><?= htmlspecialchars(substr($registroConfirmado['hora'], 11, 5)) ?></div>
            </div>
            <div class="info-item destacado">
                <div class="lbl">Celda asignada</div>
                <div class="val"><?= htmlspecialchars($registroConfirmado['celda']) ?></div>
            </div>
        </div>

        <div class="razon-asignacion">✦ <?= htmlspecialchars($registroConfirmado['razon']) ?></div>

        <div class="qr-block">
            <img src="generar_qr_imagen.php?data=<?= urlencode($registroConfirmado['token']) ?>&size=200" alt="QR ticket" onerror="this.onerror=null;this.src='https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($registroConfirmado['token']) ?>';">
            <div class="qr-label">Ticket de salida</div>
        </div>

        <p style="color: #94a3b8; font-size: 0.78rem; margin-top: 0.75rem;">Presenta este QR al salir o guarda tu placa.</p>

        <div class="acciones-finales">
            <a href="registro_entrada.php" class="btn-primary-final">➕ Registrar otro</a>
            <a href="modulo_pagos.php" class="btn-outline-final">💳 Ir a cobrar</a>
        </div>
    </div>

<?php else: ?>
    <!-- FORMULARIO -->
    <div style="margin-bottom: 1.5rem;">
        <span class="cyber-badge" style="background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.5); color: #10b981;">◉ Registrar entrada</span>
        <h2 style="font-size: 1.65rem; font-weight: 800; margin-top: 0.75rem; letter-spacing: -0.02em;">Nuevo vehículo</h2>
        <p style="color: #64748b; font-size: 0.85rem; margin-top: 0.3rem;">Escribe la placa. El sistema hace el resto.</p>
    </div>

    <!-- Disponibilidad -->
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-bottom: 1.25rem;">
        <div style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.3); border-radius: 10px; padding: 0.6rem; text-align: center;">
            <div style="font-size: 1.2rem; font-weight: 900; color: #10b981;"><?= (int)$disp['carros_libres'] ?></div>
            <div style="font-size: 0.62rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.15rem;">🚗 Carros</div>
        </div>
        <div style="background: rgba(6,182,212,0.08); border: 1px solid rgba(6,182,212,0.3); border-radius: 10px; padding: 0.6rem; text-align: center;">
            <div style="font-size: 1.2rem; font-weight: 900; color: #06b6d4;"><?= (int)$disp['motos_libres'] ?></div>
            <div style="font-size: 0.62rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.15rem;">🏍️ Motos</div>
        </div>
        <div style="background: rgba(167,139,250,0.08); border: 1px solid rgba(167,139,250,0.3); border-radius: 10px; padding: 0.6rem; text-align: center;">
            <div style="font-size: 1.2rem; font-weight: 900; color: #a78bfa;"><?= (int)$disp['bicis_libres'] ?></div>
            <div style="font-size: 0.62rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.15rem;">🚲 Bicis</div>
        </div>
    </div>

    <?php if ($mensaje): ?><div class="cyber-success" style="margin-bottom: 1rem;">✓ <?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($sugerencia): ?>
        <div class="aviso-anti-fraude" style="text-align: center;">💡 <?= htmlspecialchars($sugerencia) ?></div>
    <?php endif; ?>

    <form method="POST" id="formEntrada" autocomplete="off">
        <?= csrf_field() ?>

        <!-- Tabs de tipo -->
        <div class="entrada-tabs" id="tipoTabs">
            <button type="button" class="entrada-tab active" data-tipo="auto">
                <span class="et-icon">🔍</span>Auto
            </button>
            <button type="button" class="entrada-tab" data-tipo="electrico">
                <span class="et-icon">⚡</span>Eléctrico
            </button>
            
            <button type="button" class="entrada-tab" data-tipo="manual">
                <span class="et-icon">✎</span>Manual
            </button>
        </div>

        <input type="hidden" name="es_electrico" id="esElectrico" value="0">
        <input type="hidden" name="es_bicicleta" id="esBicicleta" value="0">

        <!-- Placa (oculto si bici) -->
        <div style="margin-bottom: 1.1rem;" id="wrapPlaca">
            <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 700;">Placa del vehículo</label>
            <input type="text" name="placa" id="inputPlaca" class="input-grande" maxlength="7" placeholder="ABC123" autofocus>
            <div id="detectado" class="detectado"></div>
        </div>

        <!-- Modelo (siempre visible) -->
        <div style="margin-bottom: 1.1rem;">
            <label style="display: block; font-size: 0.82rem; color: #cbd5e1; margin-bottom: 0.4rem; font-weight: 700;">Modelo <span style="color: #64748b; font-weight: 400;">(opcional)</span></label>
            <input type="text" name="modelo" class="cyber-form input" style="width:100%; padding:0.85rem 1rem; background:rgba(2,6,23,0.7); border:1.5px solid rgba(148,163,184,0.15); border-radius:10px; color:#f1f5f9; font-family:inherit; font-size:0.95rem;" placeholder="Ej: Mazda 3" maxlength="50">
        </div>

        <!-- Bloque especial bici -->
        <div class="bloque-bici" id="bloqueBici">
            <h4>🚲 Datos obligatorios de la bicicleta</h4>

            <div class="aviso-anti-fraude">
                <strong>⚠️ Registro sujeto a verificación</strong><br>
                Para prevenir fraudes, se requiere identificación del ciclista. El vigilante debe confirmar visualmente antes de autorizar.
            </div>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-size: 0.78rem; color: #cbd5e1; margin-bottom: 0.3rem; font-weight: 700;">Cédula del ciclista *</label>
                <input type="text" name="cedula_ciclista" id="inputCedula" maxlength="20" placeholder="Ej: 1234567890" style="width:100%; padding:0.75rem 0.9rem; background:rgba(2,6,23,0.7); border:1.5px solid rgba(148,163,184,0.15); border-radius:10px; color:#f1f5f9; font-family:inherit;">
            </div>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-size: 0.78rem; color: #cbd5e1; margin-bottom: 0.3rem; font-weight: 700;">Nombre completo *</label>
                <input type="text" name="nombre_ciclista" id="inputNombre" maxlength="100" placeholder="Ej: Juan Pérez" style="width:100%; padding:0.75rem 0.9rem; background:rgba(2,6,23,0.7); border:1.5px solid rgba(148,163,184,0.15); border-radius:10px; color:#f1f5f9; font-family:inherit;">
            </div>

            <div style="margin-bottom: 0.75rem;">
                <label style="display: block; font-size: 0.78rem; color: #cbd5e1; margin-bottom: 0.3rem; font-weight: 700;">Descripción de la bicicleta *</label>
                <input type="text" name="descripcion_vehiculo" id="inputDesc" maxlength="255" placeholder="Ej: GW azul, rin 29" style="width:100%; padding:0.75rem 0.9rem; background:rgba(2,6,23,0.7); border:1.5px solid rgba(148,163,184,0.15); border-radius:10px; color:#f1f5f9; font-family:inherit;">
            </div>

            <label class="checkbox-wrap">
                <input type="checkbox" name="confirmo_vigilante" id="checkVigilante" value="1">
                <span><strong>Confirmo visualmente</strong> que es una bicicleta real y no un vehículo a motor disfrazado. Asumo la responsabilidad.</span>
            </label>
        </div>

        <button type="submit" class="cyber-btn-primary btn-block btn-lg" id="btnRegistrar" style="width:100%; margin-top:0.5rem;">
            ➕ Asignar celda y registrar
        </button>
    </form>

    <div style="background: rgba(2,6,23,0.5); border-radius: 12px; padding: 0.9rem; margin-top: 1rem; font-size: 0.75rem; color: #94a3b8; line-height: 1.6;">
        <strong style="color: #10b981;">Reglas de placas Colombia:</strong><br>
        🚗 Carro: ABC123 · 🏍️ Moto: ABC12D · 
    </div>
<?php endif; ?>

    </div>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script>
(function() {
    'use strict';
    const inputPlaca = document.getElementById('inputPlaca');
    const detectado = document.getElementById('detectado');
    const wrapPlaca = document.getElementById('wrapPlaca');
    const bloqueBici = document.getElementById('bloqueBici');
    const tipoTabs = document.querySelectorAll('.entrada-tab');
    const esElectrico = document.getElementById('esElectrico');
    const esBicicleta = document.getElementById('esBicicleta');
    const btnRegistrar = document.getElementById('btnRegistrar');
    const inputCedula = document.getElementById('inputCedula');
    const inputNombre = document.getElementById('inputNombre');
    const inputDesc = document.getElementById('inputDesc');
    const checkVigilante = document.getElementById('checkVigilante');

    let modoActual = 'auto';

    tipoTabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            tipoTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            modoActual = this.dataset.tipo;

            if (modoActual === 'bicicleta') {
                wrapPlaca.style.display = 'none';
                bloqueBici.classList.add('visible');
                esBicicleta.value = '1';
                esElectrico.value = '0';
                inputPlaca.removeAttribute('required');
                inputCedula.setAttribute('required','required');
                inputNombre.setAttribute('required','required');
                inputDesc.setAttribute('required','required');
                checkVigilante.setAttribute('required','required');
                btnRegistrar.textContent = '🚲 Registrar bicicleta';
            } else {
                wrapPlaca.style.display = 'block';
                bloqueBici.classList.remove('visible');
                esBicicleta.value = '0';
                esElectrico.value = (modoActual === 'electrico') ? '1' : '0';
                inputPlaca.setAttribute('required','required');
                inputCedula.removeAttribute('required');
                inputNombre.removeAttribute('required');
                inputDesc.removeAttribute('required');
                checkVigilante.removeAttribute('required');
                btnRegistrar.textContent = '➕ Asignar celda y registrar';

                if (modoActual === 'electrico') {
                    detectado.className = 'detectado show carro';
                    detectado.textContent = '⚡ Carro eléctrico';
                } else {
                    detectado.className = 'detectado';
                }
            }
        });
    });

    if (inputPlaca) {
        inputPlaca.addEventListener('input', function() {
            let v = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (v.length > 6) v = v.substring(0, 6);
            this.value = v;

            detectado.className = 'detectado';
            if (v.length === 6) {
                if (/^[A-Z]{3}[0-9]{3}$/.test(v)) {
                    detectado.className = 'detectado show carro';
                    detectado.textContent = '🚗 Carro';
                } else if (/^[A-Z]{3}[0-9]{2}[A-Z]$/.test(v)) {
                    detectado.className = 'detectado show moto';
                    detectado.textContent = '🏍️ Moto';
                } else {
                    detectado.className = 'detectado show invalido';
                    detectado.textContent = '❌ Formato inválido';
                }
            } else if (v.length > 0) {
                detectado.className = 'detectado show';
                detectado.textContent = 'Faltan ' + (6 - v.length) + ' caracteres';
            }
        });
    }

    const form = document.getElementById('formEntrada');
    if (form) {
        form.addEventListener('submit', function() {
            btnRegistrar.disabled = true;
            btnRegistrar.textContent = '⏳ Procesando...';
        });
    }
})();
</script>

<script>
/* ============ ANIMACIÓN DE AUTOS EN CELDAS ============ */
(function() {
    'use strict';
    function initParkingAnimation() {
        const cells = document.querySelectorAll('.park-cell');
        if (!cells.length) return;

        // Estado inicial aleatorio (40% ocupadas)
        cells.forEach(function(cell) {
            if (Math.random() < 0.4) {
                cell.classList.add('occupied');
            } else {
                cell.classList.add('free');
            }
        });

        // Cambiar una celda aleatoria cada 1.8 segundos
        setInterval(function() {
            const cell = cells[Math.floor(Math.random() * cells.length)];
            cell.classList.toggle('occupied');
            cell.classList.toggle('free');
        }, 1800);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initParkingAnimation);
    } else {
        initParkingAnimation();
    }
})();
</script>

<?php include __DIR__ . '/partials/ia_widget.php'; ?>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>