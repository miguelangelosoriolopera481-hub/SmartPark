<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();
require_once 'conexion.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'helpers_entrada.php';
require_once 'helpers_anti_fraude.php';
send_security_headers();

$token = trim($_GET['token'] ?? '');
$modo = $_GET['modo'] ?? '';

if ($token === '') {
    // Si no hay token, significa que entraron directo. Los mandamos al index.
    header('Location: index.php?error=qr_invalido');
    exit;
}
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(400);
    $errorQr = 'El código QR no tiene un formato válido.';
    include __DIR__ . '/partials/error_qr.php';
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$keyRl = 'qr_rl_' . md5($ip);
if (!isset($_SESSION[$keyRl])) $_SESSION[$keyRl] = [];
$_SESSION[$keyRl] = array_filter($_SESSION[$keyRl], fn($t) => (time() - $t) < 300);
$bloqueado = count($_SESSION[$keyRl]) >= 20;

try {
    $stmt = $pdo->prepare("
        SELECT p.id AS parqueadero_id, p.nombre AS parqueadero_nombre,
               p.ciudad, p.direccion, p.niveles,
               e.id AS empresa_id, e.nombre AS empresa_nombre,
               e.estado AS empresa_estado, e.estado_aprobacion,
               e.logo_url
        FROM parqueaderos p
        JOIN empresas e ON e.id = p.empresa_id
        WHERE p.qr_token = :t AND p.estado = 'activo'
        LIMIT 1
    ");
    $stmt->execute(['t' => $token]);
    $info = $stmt->fetch();
} catch (\Throwable $e) { $info = null; }

if (!$info) {
    http_response_code(404);
    $errorQr = 'El codigo QR no es valido o el parqueadero ya no esta activo.';
    include __DIR__ . '/partials/error_qr.php';
    exit;
}

if ($info['empresa_estado'] !== 'activa' || $info['estado_aprobacion'] !== 'aprobada') {
    http_response_code(403);
    $errorQr = 'Este parqueadero esta temporalmente cerrado. Intenta mas tarde.';
    include __DIR__ . '/partials/error_qr.php';
    exit;
}

$eid = (int)$info['empresa_id'];
$parqueaderoId = (int)$info['parqueadero_id'];

$mensaje = ''; $error = ''; $sugerencia = ''; $registroConfirmado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesion expirada. Recarga la pagina.';
    } else {
        $placaRaw = trim($_POST['placa'] ?? '');
        $modelo = trim($_POST['modelo'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $esBicicleta = !empty($_POST['es_bicicleta']);
        $esElectrico = !empty($_POST['es_electrico']);

        if ($esBicicleta) {
            $placaRaw = 'BICI-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        $validacion = validar_placa_detallada($placaRaw);

        if (!$validacion['valida']) {
            $error = $validacion['mensaje'];
            $sug = sugerir_placa_corregida(normalizar_placa_entrada($placaRaw));
            if ($sug) $sugerencia = "Quisiste decir: $sug?";
            registrar_intento_placa_invalida($pdo, $eid, $placaRaw, $validacion['mensaje']);
        } else {
            $placa = normalizar_placa_entrada($placaRaw);
            $tipo = $validacion['tipo'];

            $existente = detectar_vehiculo_dentro($pdo, $eid, $placa);
            if ($existente) {
                $mins = (int)$existente['minutos_dentro'];
                $h = floor($mins / 60); $m = $mins % 60;
                if ($existente['es_misma_empresa'] ?? false) {
                    $error = "Este vehiculo ya esta registrado en TU parqueadero, celda {$existente['cupo_asignado']} (hace {$h}h {$m}min). Debe salir antes de volver a entrar.";
                } else {
                    $error = "Este vehiculo ya esta activo en OTRO parqueadero ({$existente['empresa_nombre']}), celda {$existente['cupo_asignado']}. Una placa no puede estar en dos parqueaderos al mismo tiempo.";
                }
            } else {
                $analisis = analizar_registro_sospechoso($pdo, $eid, $placa, $esElectrico ? 'electrico' : $tipo, null);
                $suger = sugerir_celda_con_ia($pdo, $eid, $placa, $tipo);
                $celda = $suger ? $suger['celda'] : asignar_celda_disponible($pdo, $eid, $tipo, $parqueaderoId);
                $razon = $suger ? $suger['razon'] : 'Primera celda disponible';

                if (!$celda) {
                    $error = 'No hay celdas disponibles para ' . strtoupper($tipo) . '.';
                } else {
                    $res = crear_registro_entrada($pdo, $eid, $parqueaderoId, $placa, $modelo, $tipo, $celda, null, $correo, 'hora', $esElectrico);

                    if ($res['exito']) {
                        if ($analisis && $analisis['sospechoso']) {
                            $iaAnalisis = null;
                            $ia = analizar_con_ia($pdo, ['placa' => $placa, 'tipo_vehiculo' => $tipo, 'razones' => $analisis['razones']]);
                            if ($ia) $iaAnalisis = $ia['analisis'] ?? null;
                            marcar_sospechoso($pdo, $res['registro_id'], $analisis, $iaAnalisis);
                        }

                        $registroConfirmado = [
                            'placa' => $placa,
                            'modelo' => $modelo,
                            'tipo' => $esElectrico ? 'electrico' : $tipo,
                            'celda' => $res['celda'],
                            'token' => $res['token_qr'],
                            'razon' => $razon,
                            'hora' => date('Y-m-d H:i:s'),
                            'parqueadero' => $info['parqueadero_nombre'],
                        ];

                        try {
                            $pdo->prepare("INSERT INTO auditoria_admin (empresa_id, usuario_nombre, accion, detalle, ip, creado_en) VALUES (:eid, 'QR Publico', 'entrada_qr', :det, :ip, NOW())")
                                ->execute(['eid' => $eid, 'det' => "placa={$placa} celda={$res['celda']} ip=" . $ip, 'ip' => $ip]);
                        } catch (\Throwable $e) {}

                        $_SESSION[$keyRl][] = time();

                        // FIX #5: Email de confirmacion con nombre de empresa
                        
                        if (!empty($correo) && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                            try {
                                require_once __DIR__ . '/mailer.php';
                                require_once __DIR__ . '/helpers_url.php';

                                $urlConsulta = detectar_url_base() . 'mi_auto.php?token=' . urlencode($token);
                                $empresaNombre = $info['empresa_nombre'];
                                $parqNombre = $info['parqueadero_nombre'];
                                $celdaAsig = $res['celda'];
                                $horaActual = date('d/m/Y H:i');

                                $asunto = 'Confirmacion de parqueo - ' . $empresaNombre;
                                $cuerpo = "<div style='font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px; background: #f8fafc; border-radius: 14px;'>"
                                    . "<div style='background: linear-gradient(135deg, #10b981, #06b6d4); color: white; padding: 20px; border-radius: 12px; text-align: center; margin-bottom: 20px;'>"
                                    . "<h1 style='margin: 0 0 5px; font-size: 22px;'>SmartPark</h1>"
                                    . "<p style='margin: 0; opacity: 0.9; font-size: 13px;'>" . htmlspecialchars($empresaNombre) . "</p>"
                                    . "</div>"
                                    . "<h2 style='color: #0f172a; font-size: 18px; margin: 0 0 12px;'>Tu vehiculo fue registrado</h2>"
                                    . "<p style='color: #475569; font-size: 14px; line-height: 1.6;'>Tu vehiculo fue registrado correctamente en <strong>" . htmlspecialchars($parqNombre) . "</strong>.</p>"
                                    . "<div style='background: white; border-radius: 12px; padding: 16px; margin: 16px 0; border-left: 4px solid #10b981;'>"
                                    . "<table style='width: 100%; font-size: 14px; border-collapse: collapse;'>"
                                    . "<tr><td style='padding: 6px 0; color: #64748b;'>Placa:</td><td style='text-align: right; font-family: monospace; font-weight: 800; color: #10b981;'>" . htmlspecialchars($placa) . "</td></tr>"
                                    . "<tr><td style='padding: 6px 0; color: #64748b;'>Celda:</td><td style='text-align: right; font-weight: 800;'>" . htmlspecialchars($celdaAsig) . "</td></tr>"
                                    . "<tr><td style='padding: 6px 0; color: #64748b;'>Hora:</td><td style='text-align: right; font-weight: 700;'>" . $horaActual . "</td></tr>"
                                    . "<tr><td style='padding: 6px 0; color: #64748b;'>Empresa:</td><td style='text-align: right; font-weight: 700;'>" . htmlspecialchars($empresaNombre) . "</td></tr>"
                                    . "</table>"
                                    . "</div>"
                                    . "<p style='color: #475569; font-size: 14px;'>Guarda este correo. Cuando vayas a salir, consulta tu parqueo aqui:</p>"
                                    . "<div style='text-align: center; margin: 20px 0;'>"
                                    . "<a href='" . $urlConsulta . "' style='display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #10b981, #06b6d4); color: white; text-decoration: none; border-radius: 10px; font-weight: 800; font-size: 14px;'>Ver mi parqueo</a>"
                                    . "</div>"
                                    . "<p style='color: #94a3b8; font-size: 11px; text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0;'>SmartPark · Sistema de parqueo inteligente</p>"
                                    . "</div>";

                                enviar_correo($pdo, $correo, $asunto, $cuerpo);
                            } catch (\Throwable $e) {
                                error_log('entrada_qr email: ' . $e->getMessage());
                            }
                        }
                    } else {
                        $error = $res['mensaje'];
                    }
                }
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN tipo='carro' AND estado='disponible' THEN 1 ELSE 0 END) AS carros,
            SUM(CASE WHEN tipo='moto' AND estado='disponible' THEN 1 ELSE 0 END) AS motos,
            SUM(CASE WHEN tipo='bicicleta' AND estado='disponible' THEN 1 ELSE 0 END) AS bicis,
            SUM(CASE WHEN tipo='electrico' AND estado='disponible' THEN 1 ELSE 0 END) AS electricos
        FROM cupos WHERE empresa_id = :eid AND parqueadero_id = :pid
    ");
    $stmt->execute(['eid' => $eid, 'pid' => $parqueaderoId]);
    $disp = $stmt->fetch();
} catch (\Throwable $e) {
    $disp = ['carros' => 0, 'motos' => 0, 'bicis' => 0, 'electricos' => 0];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#030712">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="SmartPark">
<meta name="application-name" content="SmartPark">
<meta name="format-detection" content="telephone=no">
<link rel="apple-touch-icon" href="IMG/SMARTPARK_TRANSPARENTE.png">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
<title>SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=45">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --qr-bg: #030712;
        --qr-card: rgba(15, 23, 42, 0.85);
        --qr-border: rgba(16, 185, 129, 0.3);
    }

    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

    body {
        margin: 0;
        padding: 0;
        min-height: 100vh;
        background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.12) 0%, transparent 60%), var(--qr-bg);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        color: #f1f5f9;
        padding-top: 4rem;
        padding-bottom: 2rem;
    }

    /* Header del parqueadero */
    .qr-header {
        background: linear-gradient(180deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.4));
        backdrop-filter: blur(20px);
        border-bottom: 1px solid var(--qr-border);
        padding: 1rem 1.25rem;
        text-align: center;
        position: sticky;
        top: 0;
        z-index: 50;
    }
    .qr-header .logo-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 50px;
        height: 50px;
        border-radius: 14px;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        font-size: 1.5rem;
        color: #030712;
        margin-bottom: 0.6rem;
        box-shadow: 0 0 30px rgba(16, 185, 129, 0.5);
    }
    .qr-header h1 {
        font-size: 1.15rem;
        font-weight: 800;
        color: #f1f5f9;
        margin: 0 0 0.2rem;
        letter-spacing: -0.02em;
    }
    .qr-header .sub {
        font-size: 0.75rem;
        color: #94a3b8;
    }
    .qr-header .empresa {
        display: inline-block;
        padding: 0.2rem 0.7rem;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        font-size: 0.68rem;
        font-weight: 700;
        color: #10b981;
        margin-top: 0.4rem;
    }

    .qr-container {
        max-width: 480px;
        margin: 0 auto;
        padding: 1.25rem 1rem 2rem;
    }

    /* Disponibilidad */
    .disp-mini {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
        margin-bottom: 1.25rem;
    }
    .disp-mini-item {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(16, 185, 129, 0.2);
        border-radius: 12px;
        padding: 0.7rem 0.4rem;
        text-align: center;
    }
    .disp-mini-item .num {
        font-size: 1.4rem;
        font-weight: 900;
        color: #10b981;
        line-height: 1;
    }
    .disp-mini-item .lbl {
        font-size: 0.62rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 0.3rem;
        font-weight: 700;
    }
    .disp-mini-item.moto .num { color: #06b6d4; }
    .disp-mini-item.bici .num { color: #a78bfa; }

    /* Card del formulario */
    .qr-form-card {
        background: var(--qr-card);
        backdrop-filter: blur(20px);
        border: 1px solid var(--qr-border);
        border-radius: 20px;
        padding: 1.5rem 1.25rem;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    }

    /* Tabs */
    .qr-tabs {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.4rem;
        margin-bottom: 1.1rem;
    }
    .qr-tab {
        padding: 0.65rem 0.3rem;
        background: rgba(2, 6, 23, 0.5);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 12px;
        color: #94a3b8;
        font-weight: 700;
        font-size: 0.7rem;
        cursor: pointer;
        text-align: center;
        transition: all 0.25s ease;
        font-family: inherit;
    }
    .qr-tab:hover { border-color: rgba(16, 185, 129, 0.5); color: #10b981; }
    .qr-tab.active {
        background: rgba(16, 185, 129, 0.15);
        border-color: #10b981;
        color: #10b981;
    }
    .qr-tab .tab-icon {
        display: block;
        font-size: 1.1rem;
        margin-bottom: 0.15rem;
        color: inherit;
    }

    /* Input grande */
    .input-placa {
        width: 100%;
        padding: 1rem 0.75rem;
        background: rgba(2, 6, 23, 0.8);
        border: 2px solid rgba(16, 185, 129, 0.3);
        border-radius: 14px;
        color: #f1f5f9;
        font-size: 1.4rem;
        font-weight: 700;
        text-align: center;
        letter-spacing: 0.3rem;
        font-family: 'JetBrains Mono', monospace;
        text-transform: uppercase;
        outline: none;
        transition: all 0.3s ease;
    }
    .input-placa:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15), 0 0 30px rgba(16, 185, 129, 0.3);
    }
    .input-placa::placeholder {
        letter-spacing: 0.15rem;
        font-size: 0.9rem;
        opacity: 0.4;
    }

    .detectado {
        display: none;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.5rem 0.9rem;
        margin-top: 0.5rem;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.78rem;
    }
    .detectado.show { display: inline-flex; }
    .detectado.carro { background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.4); color: #6ee7b7; }
    .detectado.moto { background: rgba(6, 182, 212, 0.12); border: 1px solid rgba(6, 182, 212, 0.4); color: #67e8f9; }
    .detectado.invalido { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; }

    .input-normal {
        width: 100%;
        padding: 0.85rem 1rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 12px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.92rem;
        outline: none;
        transition: border 0.25s ease;
    }
    .input-normal:focus { border-color: #10b981; }

    .label-normal {
        display: block;
        font-size: 0.76rem;
        color: #cbd5e1;
        font-weight: 700;
        margin-bottom: 0.35rem;
    }
    .label-normal .opcional { color: #64748b; font-weight: 400; }

    .btn-registrar {
        width: 100%;
        margin-top: 1.25rem;
        padding: 1.05rem;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        color: #030712;
        font-weight: 900;
        font-size: 1rem;
        border: none;
        border-radius: 14px;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        font-family: inherit;
        box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
    }
    .btn-registrar:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.6); }
    .btn-registrar:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

    /* Confirmacion */
    .qr-confirmacion {
        background: linear-gradient(160deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05));
        border: 2px solid rgba(16, 185, 129, 0.5);
        border-radius: 22px;
        padding: 1.75rem 1.25rem;
        text-align: center;
    }
    .qr-ok-icon {
        width: 80px; height: 80px;
        border-radius: 50%;
        background: rgba(16, 185, 129, 0.15);
        border: 3px solid #10b981;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.15rem;
        font-size: 2.2rem;
        color: #10b981;
        box-shadow: 0 0 50px rgba(16, 185, 129, 0.6);
        animation: okPulse 2s ease-in-out infinite;
    }
    @keyframes okPulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 50px rgba(16, 185, 129, 0.6); }
        50%      { transform: scale(1.05); box-shadow: 0 0 70px rgba(16, 185, 129, 0.9); }
    }
    .qr-confirmacion h2 {
        font-size: 1.25rem;
        font-weight: 900;
        color: #f1f5f9;
        margin: 0 0 0.35rem;
    }
    .qr-confirmacion .sub {
        color: #94a3b8;
        font-size: 0.82rem;
        margin-bottom: 1.35rem;
    }

    .qr-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.55rem;
        margin: 1.1rem 0;
    }
    .qr-info-item {
        background: rgba(2, 6, 23, 0.6);
        padding: 0.8rem;
        border-radius: 12px;
        text-align: left;
    }
    .qr-info-item .lbl {
        font-size: 0.6rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        margin-bottom: 0.2rem;
    }
    .qr-info-item .val {
        font-size: 0.92rem;
        font-weight: 800;
        color: #10b981;
        word-break: break-all;
    }
    .qr-info-item.destacado {
        grid-column: span 2;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.35);
        text-align: center;
    }
    .qr-info-item.destacado .val { font-size: 1.35rem; }

    .qr-razon {
        display: inline-block;
        padding: 0.35rem 0.85rem;
        background: rgba(167, 139, 250, 0.12);
        border: 1px solid rgba(167, 139, 250, 0.4);
        border-radius: 100px;
        color: #c4b5fd;
        font-size: 0.72rem;
        font-weight: 700;
        margin: 0.35rem 0 0.9rem;
    }

    .qr-ticket-box {
        background: white;
        padding: 1.1rem;
        border-radius: 16px;
        display: inline-block;
        margin: 0.6rem 0;
    }
    .qr-ticket-box img { max-width: 160px; display: block; margin: 0 auto; }
    .qr-ticket-box .lbl {
        color: #1a2332;
        font-weight: 800;
        font-size: 0.7rem;
        margin-top: 0.4rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* Botones de accion final */
    .qr-actions-final {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.5rem;
        margin-top: 1.25rem;
    }
    .qr-btn-action {
        padding: 0.95rem 0.75rem;
        text-align: center;
        border-radius: 12px;
        font-weight: 800;
        font-size: 0.82rem;
        text-decoration: none;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
    }
    .qr-btn-action.outline {
        background: rgba(16, 185, 129, 0.15);
        border: 1.5px solid #10b981;
        color: #10b981;
    }
    .qr-btn-action.outline:hover { background: #10b981; color: #030712; }
    .qr-btn-action.primary {
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
    }
    .qr-btn-action.primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.5); }

    .qr-alert {
        padding: 0.8rem 1rem;
        border-radius: 12px;
        margin-bottom: 1rem;
        font-size: 0.82rem;
        display: flex;
        align-items: flex-start;
        gap: 0.55rem;
        line-height: 1.4;
    }
    .qr-alert.error { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; }
    .qr-alert.warning { background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.4); color: #fcd34d; }
    .qr-alert.info { background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6, 182, 212, 0.3); color: #67e8f9; }
    .qr-alert .ico { font-size: 1.1rem; flex-shrink: 0; line-height: 1; }

    .qr-footer {
        text-align: center;
        margin-top: 1.75rem;
        padding-top: 1.25rem;
        border-top: 1px solid rgba(148, 163, 184, 0.1);
        color: #64748b;
        font-size: 0.7rem;
    }
    .qr-footer strong { color: #10b981; }

    /* Bici */
    .bloque-bici {
        display: none;
        padding: 0.85rem 1rem;
        background: rgba(167, 139, 250, 0.08);
        border: 1px solid rgba(167, 139, 250, 0.3);
        border-radius: 12px;
        margin-bottom: 1rem;
    }
    .bloque-bici.visible { display: block; }
    .bloque-bici p { color: #c4b5fd; font-size: 0.78rem; margin: 0; line-height: 1.5; }

    /* Animacion de entrada */
    .qr-fade-in {
        animation: fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        opacity: 0;
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
    }
</style>
</head>
<body class="has-mobile-topbar">
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); $backUrl = 'javascript:history.back()'; include __DIR__ . '/partials/mobile_topbar.php'; ?>

<header class="qr-header">
    <div class="logo-badge">◈</div>
    <h1><?= htmlspecialchars($info['parqueadero_nombre']) ?></h1>
    <p class="sub"><?= htmlspecialchars($info['ciudad'] ?: 'Bienvenido') ?></p>
    <span class="empresa">◉ <?= htmlspecialchars($info['empresa_nombre']) ?></span>
</header>

<main class="qr-container">

<?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); if ($registroConfirmado): 
    $et = etiqueta_tipo_vehiculo($registroConfirmado['tipo']);
?>
    <div class="qr-confirmacion qr-fade-in">
        <div class="qr-ok-icon">✓</div>
        <h2>Bienvenido</h2>
        <p class="sub">Tu vehiculo ha sido registrado</p>

        <div class="qr-info-grid">
            <div class="qr-info-item">
                <div class="lbl">Placa</div>
                <div class="val"><?= htmlspecialchars($registroConfirmado['placa']) ?></div>
            </div>
            <div class="qr-info-item">
                <div class="lbl">Tipo</div>
                <div class="val"><?= htmlspecialchars(ucfirst($registroConfirmado['tipo'])) ?></div>
            </div>
            <div class="qr-info-item">
                <div class="lbl">Hora ingreso</div>
                <div class="val" style="font-size: 0.85rem;"><?= htmlspecialchars(substr($registroConfirmado['hora'], 11, 5)) ?></div>
            </div>
            <div class="qr-info-item">
                <div class="lbl">Parqueadero</div>
                <div class="val" style="font-size: 0.72rem;"><?= htmlspecialchars($registroConfirmado['parqueadero']) ?></div>
            </div>
            <div class="qr-info-item destacado">
                <div class="lbl">Tu celda asignada</div>
                <div class="val"><?= htmlspecialchars($registroConfirmado['celda']) ?></div>
            </div>
        </div>

        <div class="qr-razon">✦ <?= htmlspecialchars($registroConfirmado['razon']) ?></div>

        <div class="qr-ticket-box">
            <img src="generar_qr_imagen.php?data=<?= urlencode(detectar_url_base() . 'ver_factura.php?token=' . $registroConfirmado['token']) ?>&size=180" alt="QR ticket">
            <div class="lbl">Ticket de salida</div>
        </div>

        <p style="color: #94a3b8; font-size: 0.75rem; margin-top: 0.6rem; line-height: 1.5;">
            Guarda este QR o tu placa. Al salir presenta este QR y realiza el pago.
        </p>

        <div class="qr-actions-final">
            <a href="mi_parqueadero.php?placa=<?= urlencode($registroConfirmado['placa']) ?>&token=<?= urlencode($token) ?>" class="qr-btn-action outline" style="grid-column: span 2;">
                ◉ Ver ubicacion
            </a>
        </div>

        <p style="color: #94a3b8; font-size: 0.72rem; margin-top: 1rem; text-align: center; line-height: 1.5;">
            Para pagar al salir, ingresa a <strong style="color: #10b981;">mi_auto.php</strong> con tu token.
        </p>

        <div class="qr-actions-final">
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:.5rem; margin-top:.5rem;">
            <a href="estado_parqueo.php?placa=<?= urlencode($registroConfirmado['placa']) ?>" class="qr-btn-action outline" style="background:rgba(6,182,212,.15);border-color:#06b6d4;color:#06b6d4;">
                ◐ Estado del vehiculo
            </a>
            <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); if (isset($_SESSION['usuario_id'])): ?>
                <a href="mi_cuenta.php" class="qr-btn-action outline" style="background:rgba(167,139,250,.15);border-color:#a78bfa;color:#a78bfa;">
                    ◈ Mi perfil
                </a>
            <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); else: ?>
                <a href="login_cliente.php" class="qr-btn-action outline" style="background:rgba(167,139,250,.15);border-color:#a78bfa;color:#a78bfa;">
                    ◈ Iniciar sesion
                </a>
            <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); endif; ?>
        </div>

        <div style="text-align:center; margin-top:1rem;">
            <a href="registro_empresa.php" style="color:#64748b; font-size:.75rem; text-decoration:none;">
                + Crear cuenta para guardar mi historial
            </a>
        </div>

        <p style="color: #64748b; font-size: 0.7rem; margin-top: 1rem; text-align: center; line-height: 1.5;">
            Para registrar otro vehiculo, escanea el QR nuevamente.
        </p>
    </div>

<?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); else: ?>

    <div class="disp-mini qr-fade-in">
        <div class="disp-mini-item">
            <div class="num"><?= (int)$disp['carros'] ?></div>
            <div class="lbl">Carros</div>
        </div>
        <div class="disp-mini-item moto">
            <div class="num"><?= (int)$disp['motos'] ?></div>
            <div class="lbl">Motos</div>
        </div>
        <div class="disp-mini-item bici">
            <div class="num"><?= (int)$disp['bicis'] ?></div>
            <div class="lbl">Bicis</div>
        </div>
    </div>

    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); if ($bloqueado): ?>
        <div class="qr-alert error"><span class="ico">⚠</span><div><strong>Demasiados intentos.</strong> Espera unos minutos.</div></div>
    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); endif; ?>

    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); if ($error): ?>
        <div class="qr-alert error"><span class="ico">⚠</span><div><?= htmlspecialchars($error) ?></div></div>
    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); endif; ?>

    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); if ($sugerencia): ?>
        <div class="qr-alert warning"><span class="ico">◈</span><div><?= htmlspecialchars($sugerencia) ?></div></div>
    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); endif; ?>

    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); if ($modo === 'recepcion'): ?>
    <div class="qr-alert info">
        <span class="ico">◈</span>
        <div>
            <strong>Recepcion</strong><br>
            Bienvenido a <?= htmlspecialchars($info['parqueadero_nombre']) ?>. Registra tu vehiculo desde aqui. El pago se realiza al salir.
        </div>
    </div>
    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); endif; ?>

    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); if ($modo === 'celda'): ?>
    <div class="qr-alert info">
        <span class="ico">▢</span>
        <div>
            <strong>Modo celda</strong><br>
            Registra tu placa y el sistema te asignara la celda mas cercana disponible.
        </div>
    </div>
    <?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); endif; ?>

    <form method="POST" id="formQr" class="qr-form-card qr-fade-in" autocomplete="off" novalidate>
        <?= csrf_field() ?>

        <div class="qr-tabs" id="qrTabs">
            <button type="button" class="qr-tab active" data-tipo="auto">
                <span class="tab-icon">◉</span>Auto
            </button>
            <button type="button" class="qr-tab" data-tipo="electrico">
                <span class="tab-icon">◈</span>Electrico
            </button>
            
        </div>

        <input type="hidden" name="es_electrico" id="esElectrico" value="0">
        <input type="hidden" name="es_bicicleta" id="esBicicleta" value="0">

        <div style="margin-bottom: 1rem;" id="wrapPlaca">
            <label class="label-normal">Placa del vehiculo</label>
            <input type="text" name="placa" id="inputPlaca" class="input-placa" maxlength="6" placeholder="ABC123" inputmode="text" autocapitalize="characters" autocomplete="off" autofocus>
            <div id="detectado" class="detectado"></div>
        </div>

        

        <div style="margin-bottom: 1rem;">
            <label class="label-normal">Modelo <span class="opcional">(opcional)</span></label>
            <input type="text" name="modelo" class="input-normal" placeholder="Ej: Mazda 3, Yamaha FZ..." maxlength="50">
        </div>

        <div style="margin-bottom: 1rem;">
            <label class="label-normal">Correo <span class="opcional">(para enviarte el ticket)</span></label>
            <input type="email" name="correo" class="input-normal" placeholder="tu@correo.com" maxlength="150">
        </div>

        <button type="submit" class="btn-registrar" id="btnQr" <?= $bloqueado ? 'disabled' : '' ?>>
            + Asignar celda y registrar
        </button>

        <div style="text-align: center; margin-top: 0.85rem;">
            <span style="font-size: 0.68rem; color: #64748b; line-height: 1.5;">
                ◈ Tu placa se usa solo para gestionar tu parqueo.
            </span>
        </div>
    </form>

    <div class="qr-footer">
        <strong>SmartPark</strong><br>
        Sistema de parqueo inteligente · <?= date('Y') ?>
    </div>

<?php
error_reporting(E_ALL);
ini_set('display_errors', '1'); endif; ?>

</main>

<script>
(function() {
    'use strict';
    const tabs = document.querySelectorAll('.qr-tab');
    const inputPlaca = document.getElementById('inputPlaca');
    const wrapPlaca = document.getElementById('wrapPlaca');
    const bloqueBici = document.getElementById('bloqueBici');
    const esBicicleta = document.getElementById('esBicicleta');
    const esElectrico = document.getElementById('esElectrico');
    const detectado = document.getElementById('detectado');
    const btnQr = document.getElementById('btnQr');

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            const tipo = this.dataset.tipo;

            if (tipo === 'bicicleta') {
                wrapPlaca.style.display = 'none';
                bloqueBici.classList.add('visible');
                esBicicleta.value = '1';
                esElectrico.value = '0';
                inputPlaca.removeAttribute('required');
                btnQr.textContent = '+ Registrar bicicleta';
            } else {
                wrapPlaca.style.display = 'block';
                bloqueBici.classList.remove('visible');
                esBicicleta.value = '0';
                esElectrico.value = (tipo === 'electrico') ? '1' : '0';
                inputPlaca.setAttribute('required', 'required');
                btnQr.textContent = '+ Asignar celda y registrar';

                if (tipo === 'electrico') {
                    detectado.className = 'detectado show carro';
                    detectado.textContent = '◈ Carro electrico';
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
                    detectado.textContent = '◉ Carro detectado';
                } else if (/^[A-Z]{3}[0-9]{2}[A-Z]$/.test(v)) {
                    detectado.className = 'detectado show moto';
                    detectado.textContent = '◈ Moto detectada';
                } else {
                    detectado.className = 'detectado show invalido';
                    detectado.textContent = '✕ Formato invalido (ABC123 / ABC12D)';
                }
            } else if (v.length > 0) {
                detectado.className = 'detectado show';
                detectado.textContent = 'Faltan ' + (6 - v.length) + ' caracteres';
            }
        });
    }

    const form = document.getElementById('formQr');
    if (form) {
        form.addEventListener('submit', function() {
            if (btnQr) {
                btnQr.disabled = true;
                btnQr.textContent = 'Procesando...';
            }
        });
    }
})();
</script>

</body>
</html>