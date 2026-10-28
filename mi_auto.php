<?php
session_start();
require_once 'conexion.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'helpers_entrada.php';
send_security_headers();

$token = trim($_GET['token'] ?? '');
$error = '';
$registro = null;
$empresa = null;
$parqueadero = null;

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    $errorQr = 'Codigo QR no valido.';
    include __DIR__ . '/partials/error_qr.php';
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT p.id AS parqueadero_id, p.nombre AS parqueadero_nombre, p.ciudad, p.direccion,
               e.id AS empresa_id, e.nombre AS empresa_nombre,
               e.estado AS empresa_estado, e.estado_aprobacion
        FROM parqueaderos p
        JOIN empresas e ON e.id = p.empresa_id
        WHERE p.qr_token = :t AND p.estado = 'activo' LIMIT 1
    ");
    $stmt->execute(['t' => $token]);
    $info = $stmt->fetch();
} catch (\Throwable $e) { $info = null; }

if (!$info || $info['empresa_estado'] !== 'activa' || $info['estado_aprobacion'] !== 'aprobada') {
    $errorQr = 'Este parqueadero no esta disponible.';
    include __DIR__ . '/partials/error_qr.php';
    exit;
}

$eid = (int)$info['empresa_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesion expirada. Recarga la pagina.';
    } else {
        $placa = normalizar_placa_entrada($_POST['placa'] ?? '');
        $correoNuevo = trim($_POST['correo'] ?? '');

        if (strlen($placa) < 6) {
            $error = 'Ingresa una placa valida.';
        } else {
            $stmt = $pdo->prepare("
                SELECT r.*, p.nombre AS parqueadero_nombre
                FROM registros_parqueo r
                LEFT JOIN parqueaderos p ON p.id = r.parqueadero_id
                WHERE r.placa = :placa AND r.empresa_id = :eid AND r.estado = 'activo'
                ORDER BY r.id DESC LIMIT 1
            ");
            $stmt->execute(['placa' => $placa, 'eid' => $eid]);
            $registro = $stmt->fetch();

            if (!$registro) {
                $error = 'No encontramos un vehiculo activo con esa placa.';
            } else {
                $correoActual = trim($registro['correo'] ?? '');
                if ($correoActual === '' && $correoNuevo !== '' && filter_var($correoNuevo, FILTER_VALIDATE_EMAIL)) {
                    $pdo->prepare("UPDATE registros_parqueo SET correo = :c WHERE id = :id")
                        ->execute(['c' => $correoNuevo, 'id' => $registro['id']]);
                    $registro['correo'] = $correoNuevo;
                }
                $_SESSION['mi_auto_placa'] = $placa;
                $_SESSION['mi_auto_empresa'] = $eid;
            }
        }
    }
}

$tiempoInfo = null;
if ($registro) {
    $ingreso = new DateTime($registro['fecha_ingreso']);
    $ahora = new DateTime();
    $diff = $ingreso->diff($ahora);
    $minutos = ($diff->days * 1440) + ($diff->h * 60) + $diff->i;
    $horas = max(1, ceil($minutos / 60));

    $stmt = $pdo->prepare("
        SELECT precio FROM tarifas
        WHERE empresa_id = :eid AND tipo_vehiculo = :tv AND tipo_tarifa = 'hora' AND activo = 1
        ORDER BY vigente_desde DESC LIMIT 1
    ");
    $stmt->execute(['eid' => $eid, 'tv' => $registro['tipo_vehiculo']]);
    $tarifa = (float)($stmt->fetchColumn() ?: ($registro['tipo_vehiculo'] === 'moto' ? 2000 : 4000));

    $tiempoInfo = [
        'minutos' => $minutos,
        'horas' => $horas,
        'tarifa_hora' => $tarifa,
        'estimado' => $horas * $tarifa,
        'horas_texto' => floor($minutos / 60) . 'h ' . ($minutos % 60) . 'min',
    ];
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
<title>Mi Vehiculo - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=42">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    body {
        margin: 0;
        padding: 0;
        min-height: 100vh;
        background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.12) 0%, transparent 60%), #030712;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        color: #f1f5f9;
        padding-top: 4rem;
        padding-bottom: 2rem;
    }
    .wrap { max-width: 460px; margin: 0 auto; padding: 1.5rem 1.25rem 3rem; }

    .head { text-align: center; margin-bottom: 1.5rem; }
    .head .ico {
        display: inline-flex;
        width: 56px; height: 56px;
        border-radius: 16px;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        align-items: center; justify-content: center;
        font-size: 1.75rem; color: #030712;
        margin-bottom: 0.85rem;
        box-shadow: 0 0 35px rgba(16, 185, 129, 0.55);
    }
    .head h1 {
        font-size: 1.25rem;
        font-weight: 900;
        color: #f1f5f9;
        margin: 0 0 0.3rem;
        letter-spacing: -0.02em;
    }
    .head p { color: #94a3b8; font-size: 0.8rem; margin: 0; }
    .head .badge {
        display: inline-block;
        margin-top: 0.55rem;
        padding: 0.25rem 0.75rem;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.68rem;
        font-weight: 700;
    }

    .card {
        background: rgba(15, 23, 42, 0.8);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(16, 185, 129, 0.25);
        border-radius: 20px;
        padding: 1.6rem 1.35rem;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    }

    .lbl {
        display: block;
        font-size: 0.76rem;
        color: #cbd5e1;
        font-weight: 700;
        margin-bottom: 0.4rem;
    }
    .lbl .opcional { color: #64748b; font-weight: 400; }

    .inp {
        width: 100%;
        padding: 1rem 0.9rem;
        background: rgba(2, 6, 23, 0.85);
        border: 2px solid rgba(16, 185, 129, 0.3);
        border-radius: 12px;
        color: #f1f5f9;
        font-size: 1.3rem;
        font-weight: 700;
        text-align: center;
        letter-spacing: 0.3rem;
        font-family: 'JetBrains Mono', monospace;
        text-transform: uppercase;
        outline: none;
        transition: all 0.3s ease;
    }
    .inp:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15), 0 0 25px rgba(16, 185, 129, 0.3);
    }
    .inp::placeholder { letter-spacing: 0.15rem; font-size: 0.85rem; opacity: 0.4; font-weight: 400; }

    .inp-normal {
        width: 100%;
        padding: 0.85rem 1rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 11px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.92rem;
        outline: none;
        box-sizing: border-box;
    }
    .inp-normal:focus { border-color: #10b981; }

    .btn {
        width: 100%;
        margin-top: 1.2rem;
        padding: 1.05rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        font-weight: 900;
        font-size: 0.98rem;
        border: none;
        border-radius: 13px;
        cursor: pointer;
        font-family: inherit;
        box-shadow: 0 10px 30px rgba(16, 185, 129, 0.35);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.55); }

    .alert {
        padding: 0.85rem 1rem;
        border-radius: 12px;
        margin-bottom: 1rem;
        font-size: 0.82rem;
        line-height: 1.4;
    }
    .alert.err { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; }

    .info-block {
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.3);
        border-radius: 14px;
        padding: 1.15rem;
        margin-bottom: 1rem;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        font-size: 0.85rem;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row span { color: #94a3b8; }
    .info-row strong { color: #f1f5f9; }
    .info-row.total {
        padding-top: 0.85rem;
        margin-top: 0.4rem;
        border-top: 1px solid rgba(16, 185, 129, 0.3);
    }
    .info-row.total strong { color: #10b981; font-size: 1.35rem; font-weight: 900; }

    .celda-badge {
        display: inline-block;
        padding: 0.4rem 1.1rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        border-radius: 10px;
        font-weight: 900;
        font-size: 1.1rem;
        letter-spacing: 0.05em;
        box-shadow: 0 0 30px rgba(16, 185, 129, 0.5);
    }

    .btn-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.55rem;
        margin-top: 1.15rem;
    }
    .btn-sec {
        padding: 0.95rem 0.5rem;
        text-align: center;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.82rem;
        text-decoration: none;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
    }
    .btn-sec.outline { background: rgba(16, 185, 129, 0.15); border: 1.5px solid #10b981; color: #10b981; }
    .btn-sec.outline:hover { background: #10b981; color: #030712; }
    .btn-sec.primary { background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; }
    .btn-sec.primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.5); }

    .footer {
        text-align: center;
        margin-top: 1.5rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(148, 163, 184, 0.1);
        color: #64748b;
        font-size: 0.7rem;
    }
    .footer strong { color: #10b981; }

    .email-banner {
        background: rgba(59, 130, 246, 0.08);
        border: 1px solid rgba(59, 130, 246, 0.35);
        border-radius: 12px;
        padding: 0.85rem 1rem;
        margin-bottom: 1rem;
    }
    .email-banner strong { color: #93c5fd; display: block; margin-bottom: 0.35rem; font-size: 0.8rem; }
    .email-banner p { color: #94a3b8; font-size: 0.74rem; margin: 0 0 0.6rem; line-height: 1.4; }
</style>
</head>
<body class="has-mobile-topbar">
<?php $backUrl = 'javascript:history.back()'; include __DIR__ . '/partials/mobile_topbar.php'; ?>
<div class="wrap">

    <div class="head">
        <div class="ico">◈</div>
        <h1><?= htmlspecialchars($info['parqueadero_nombre']) ?></h1>
        <p><?= htmlspecialchars($info['ciudad'] ?: 'Consulta tu vehiculo') ?></p>
        <span class="badge">◉ <?= htmlspecialchars($info['empresa_nombre']) ?></span>
    </div>

    <?php if ($error): ?>
        <div class="alert err">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($registro && $tiempoInfo): ?>
        <div class="card">
            <div class="info-block">
                <div style="text-align:center; margin-bottom: 1rem;">
                    <div style="font-size: .68rem; color: #64748b; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; margin-bottom: .3rem;">Tu celda</div>
                    <div class="celda-badge"><?= htmlspecialchars($registro['cupo_asignado']) ?></div>
                </div>

                <div class="info-row">
                    <span>Placa</span>
                    <strong style="font-family: 'JetBrains Mono', monospace;"><?= htmlspecialchars($registro['placa']) ?></strong>
                </div>
                <div class="info-row">
                    <span>Tipo</span>
                    <strong><?= htmlspecialchars(ucfirst($registro['tipo_vehiculo'])) ?></strong>
                </div>
                <div class="info-row">
                    <span>Ingreso</span>
                    <strong><?= htmlspecialchars(substr($registro['fecha_ingreso'], 11, 5)) ?></strong>
                </div>
                <div class="info-row">
                    <span>Tiempo dentro</span>
                    <strong style="color: #fbbf24;"><?= $tiempoInfo['horas_texto'] ?></strong>
                </div>
                <div class="info-row">
                    <span>Tarifa por hora</span>
                    <strong>$<?= number_format($tiempoInfo['tarifa_hora'], 0, ',', '.') ?></strong>
                </div>
                <?php if ((int)$registro['estado_pago'] === 1): ?>
                <div class="info-row">
                    <span>Estado</span>
                    <strong style="color: #fbbf24;">● Pagado · Esperando salida</strong>
                </div>
                <?php endif; ?>
                <div class="info-row total">
                    <span style="color: #10b981; font-weight: 700;">Total estimado</span>
                    <strong>$<?= number_format($tiempoInfo['estimado'], 0, ',', '.') ?></strong>
                </div>
            </div>

            <?php if (empty($registro['correo'])): ?>
            <div class="email-banner">
                <strong>Guarda tu correo</strong>
                <p>Te enviaremos el ticket digital y podras recuperar tu parqueo mas rapido.</p>
                <form method="POST" style="display: flex; gap: .5rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="placa" value="<?= htmlspecialchars($registro['placa']) ?>">
                    <input type="email" name="correo" class="inp-normal" placeholder="tu@correo.com" style="flex: 1;">
                    <button type="submit" class="btn" style="width: auto; padding: .85rem 1.1rem; margin: 0; font-size: .82rem;">Guardar</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ((int)$registro['estado_pago'] === 1): ?>
                <!-- Ya pago: mostrar boton de confirmar salida -->
                <div class="btn-grid">
                    <a href="mi_parqueadero.php?placa=<?= urlencode($registro['placa']) ?>&token=<?= urlencode($token) ?>" class="btn-sec outline">
                        ◉ Ver ubicacion
                    </a>
                    <a href="salida_cliente.php?token=<?= urlencode($registro['token_factura']) ?>" class="btn-sec primary">
                        ✓ Confirmar salida
                    </a>
                </div>
                <p style="color: #fcd34d; font-size: 0.72rem; margin-top: 0.75rem; text-align: center; line-height: 1.5;">
                    Al confirmar la salida, tu celda sera liberada.
                </p>
            <?php else: ?>
                <!-- No ha pagado: mostrar boton de pagar -->
                <div class="btn-grid">
                    <a href="mi_parqueadero.php?placa=<?= urlencode($registro['placa']) ?>&token=<?= urlencode($token) ?>" class="btn-sec outline">
                        ◉ Ver ubicacion
                    </a>
                    <a href="pagar.php?placa=<?= urlencode($registro['placa']) ?>" class="btn-sec primary">
                        $ Ir a pagar
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin-top: 1rem;">
            <a href="mi_auto.php?token=<?= urlencode($token) ?>" style="color: #64748b; font-size: .78rem; text-decoration: none;">← Consultar otra placa</a>
        </div>

    <?php else: ?>
        <div class="card">
            <h2 style="font-size: 1.05rem; font-weight: 800; color: #f1f5f9; margin: 0 0 .35rem; text-align: center;">Consulta tu vehiculo</h2>
            <p style="color: #64748b; font-size: .8rem; text-align: center; margin: 0 0 1.35rem;">Ingresa tu placa para ver el estado de tu parqueo</p>

            <form method="POST">
                <?= csrf_field() ?>
                <div style="margin-bottom: 1.1rem;">
                    <label class="lbl">Placa del vehiculo</label>
                    <input type="text" name="placa" class="inp" maxlength="6" placeholder="ABC123" inputmode="text" autocapitalize="characters" autocomplete="off" autofocus required>
                </div>

                <div style="margin-bottom: 1rem;">
                    <label class="lbl">Correo <span class="opcional">(si no lo registraste antes)</span></label>
                    <input type="email" name="correo" class="inp-normal" placeholder="tu@correo.com">
                </div>

                <button type="submit" class="btn">Consultar mi vehiculo</button>
            </form>
        </div>

        <div style="text-align: center; margin-top: 1rem;">
            <a href="entrada_qr.php?token=<?= urlencode($token) ?>" style="color: #10b981; font-size: .85rem; font-weight: 700; text-decoration: none;">+ No estas parqueado aun? Registra tu entrada</a>
        </div>
    <?php endif; ?>

    <div class="footer">
        <strong>SmartPark</strong><br>
        Sistema de parqueo inteligente · <?= date('Y') ?>
    </div>

</div>
</body>
</html>