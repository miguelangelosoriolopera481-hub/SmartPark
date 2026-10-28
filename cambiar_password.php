<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

require_login();

$error = '';
$mensaje = '';
$es_obligatorio = !empty($_SESSION['must_change_password']);
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol_en_empresa'] ?? ($_SESSION['role'] ?? 'cliente');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Tu sesión de formulario expiró. Recarga la página.';
    } else {
        $actual   = (string)($_POST['password_actual'] ?? '');
        $nueva    = (string)($_POST['password_nueva'] ?? '');
        $confirma = (string)($_POST['password_confirma'] ?? '');

        if (strlen($nueva) < 8) {
            $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
        } elseif ($nueva !== $confirma) {
            $error = 'La confirmación no coincide con la nueva contraseña.';
        } elseif ($nueva === $actual) {
            $error = 'La nueva contraseña debe ser diferente a la actual.';
        } else {
            $stmt = $pdo->prepare("SELECT password FROM user WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $_SESSION['usuario_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($actual, $user['password'])) {
                $error = 'La contraseña actual no es correcta.';
            } else {
                $hash = hash_password_seguro($nueva);
                $upd = $pdo->prepare("UPDATE user SET password = :hash, must_change_password = 0 WHERE id = :id");
                $upd->execute(['hash' => $hash, 'id' => $_SESSION['usuario_id']]);

                $_SESSION['must_change_password'] = false;
                $es_obligatorio = false;
                $mensaje = '✓ Contraseña actualizada correctamente.';

                registrarAuditoria($pdo, 'cambio_password', 'Usuario cambió su contraseña');
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
<title>Cambiar Contraseña - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=51">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { margin: 0; padding: 0; background: #020617; min-height: 100vh; }
    .split-login { display: grid; grid-template-columns: 1.3fr 1fr; min-height: 100vh; align-items: stretch; }
    
    /* Columna izquierda: animación */
    .split-left {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 2rem;
        background: linear-gradient(135deg, #020617 0%, #071326 50%, #020617 100%);
        overflow: hidden;
    }
    
    .left-content { position: relative; z-index: 5; text-align: center; max-width: 460px; width: 100%; }
    
    /* Icono de candado animado */
    .lock-scene { position: relative; width: 240px; height: 240px; margin: 0 auto 2rem; }
    .lock-icon {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 120px; height: 120px;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(6, 182, 212, 0.1));
        border: 2px solid #10b981;
        display: flex; align-items: center; justify-content: center;
        font-size: 3.5rem;
        color: #10b981;
        box-shadow: 0 0 50px rgba(16, 185, 129, 0.5), inset 0 0 30px rgba(16, 185, 129, 0.2);
        animation: lockPulse 3s ease-in-out infinite;
        z-index: 10;
    }
    @keyframes lockPulse {
        0%, 100% {
            box-shadow: 0 0 50px rgba(16, 185, 129, 0.5), inset 0 0 30px rgba(16, 185, 129, 0.2);
            transform: translate(-50%, -50%) scale(1);
        }
        50% {
            box-shadow: 0 0 70px rgba(16, 185, 129, 0.8), inset 0 0 45px rgba(16, 185, 129, 0.35);
            transform: translate(-50%, -50%) scale(1.04);
        }
    }
    
    /* Anillos de radar */
    .lock-ring {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 120px; height: 120px;
        border: 2px solid rgba(6, 182, 212, 0.6);
        border-radius: 50%;
        animation: ringExpand 4s ease-out infinite;
        pointer-events: none;
        opacity: 0;
    }
    .lock-ring:nth-child(1) { animation-delay: 0s; }
    .lock-ring:nth-child(2) { animation-delay: 1.33s; }
    .lock-ring:nth-child(3) { animation-delay: 2.66s; }
    @keyframes ringExpand {
        0% { width: 120px; height: 120px; opacity: 0.9; border-width: 2px; }
        100% { width: 480px; height: 480px; opacity: 0; border-width: 0.5px; }
    }
    
    /* Línea de escaneo */
    .lock-scan {
        position: absolute;
        left: 15%; right: 15%;
        height: 2px;
        background: linear-gradient(90deg, transparent, #06b6d4, #10b981, #06b6d4, transparent);
        box-shadow: 0 0 20px #06b6d4;
        animation: scanMove 3.5s ease-in-out infinite;
        z-index: 8;
    }
    @keyframes scanMove {
        0%, 100% { top: 20%; opacity: 0.3; }
        50% { top: 80%; opacity: 0.9; }
    }
    
    /* Puntos orbitando */
    .lock-dot {
        position: absolute;
        width: 10px; height: 10px;
        border-radius: 50%;
        background: #10b981;
        box-shadow: 0 0 15px #10b981;
        top: 50%; left: 50%;
        animation: dotOrbit 8s linear infinite;
        z-index: 9;
    }
    .lock-dot.d1 { animation-delay: 0s; }
    .lock-dot.d2 { animation-delay: 2s; background: #06b6d4; box-shadow: 0 0 15px #06b6d4; }
    .lock-dot.d3 { animation-delay: 4s; }
    .lock-dot.d4 { animation-delay: 6s; background: #06b6d4; box-shadow: 0 0 15px #06b6d4; }
    @keyframes dotOrbit {
        from { transform: rotate(0deg) translateX(100px) translateY(0); }
        to   { transform: rotate(360deg) translateX(100px) translateY(0); }
    }
    
    .left-content h2 {
        font-size: clamp(1.5rem, 2.6vw, 2.1rem);
        font-weight: 900;
        letter-spacing: -0.03em;
        line-height: 1.15;
        margin-bottom: 0.75rem;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .left-content p {
        color: #94a3b8;
        font-size: 0.95rem;
        line-height: 1.6;
        max-width: 400px;
        margin: 0 auto 1.5rem;
    }
    
    /* Badges de seguridad */
    .security-badges {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.6rem;
        max-width: 440px;
        margin: 0 auto;
    }
    .sec-badge {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.65rem 0.85rem;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(16, 185, 129, 0.18);
        border-radius: 12px;
        text-align: left;
    }
    .sec-badge-icon {
        width: 26px; height: 26px;
        border-radius: 8px;
        background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.4);
        display: flex; align-items: center; justify-content: center;
        font-size: 0.75rem;
        color: #10b981;
        flex-shrink: 0;
    }
    .sec-badge-text { font-size: 0.75rem; color: #cbd5e1; line-height: 1.25; }
    .sec-badge-text small { color: #64748b; display: block; font-size: 0.68rem; margin-top: 0.1rem; }
    
    /* Columna derecha: formulario */
    .split-right {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 2rem;
        background: rgba(2, 6, 23, 0.6);
        backdrop-filter: blur(20px);
        border-left: 1px solid rgba(16, 185, 129, 0.1);
    }
    .form-card { width: 100%; max-width: 420px; }
    
    .form-header {
        margin-bottom: 1.75rem;
        text-align: left;
    }
    .user-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.35rem 0.9rem;
        background: rgba(16, 185, 129, 0.12);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        margin-bottom: 0.75rem;
        text-transform: uppercase;
    }
    .form-header h1 {
        font-size: 1.75rem;
        font-weight: 900;
        color: #f1f5f9;
        margin: 0 0 0.35rem;
        letter-spacing: -0.02em;
    }
    .form-header p { color: #64748b; font-size: 0.88rem; margin: 0; }
    
    .cyber-form { margin-top: 1.5rem; }
    .form-group { margin-bottom: 1.15rem; }
    .form-group label {
        display: block;
        font-size: 0.82rem;
        color: #cbd5e1;
        font-weight: 700;
        margin-bottom: 0.4rem;
    }
    .form-group input {
        width: 100%;
        padding: 0.9rem 1rem;
        background: rgba(2, 6, 23, 0.75);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 11px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.95rem;
        outline: none;
        box-sizing: border-box;
        transition: all 0.25s ease;
    }
    .form-group input:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
    }
    
    .btn-primario {
        width: 100%;
        padding: 1rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        border: none;
        border-radius: 12px;
        font-weight: 900;
        font-size: 1rem;
        cursor: pointer;
        font-family: inherit;
        margin-top: 0.5rem;
        transition: all 0.3s ease;
    }
    .btn-primario:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4); }
    
    .alert-info {
        padding: 0.9rem 1.1rem;
        border-radius: 12px;
        margin-bottom: 1.25rem;
        font-size: 0.85rem;
        line-height: 1.5;
    }
    .alert-warning {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid rgba(245, 158, 11, 0.4);
        color: #fcd34d;
    }
    
    .link-abajo {
        text-align: center;
        margin-top: 1.5rem;
        font-size: 0.85rem;
    }
    .link-abajo a { color: #64748b; text-decoration: none; }
    .link-abajo a:hover { color: #10b981; }
    
    @media (max-width: 900px) {
        .split-login { grid-template-columns: 1fr; }
        .split-left { min-height: 45vh; padding: 3rem 1.5rem 2rem; }
        .split-right { padding: 2rem 1.5rem 3rem; border-left: none; border-top: 1px solid rgba(16, 185, 129, 0.1); }
        .lock-scene { width: 180px; height: 180px; }
        .lock-icon { width: 90px; height: 90px; font-size: 2.5rem; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<canvas id="particles-canvas"></canvas>

<div class="split-login">

    <!-- IZQUIERDA: animación -->
    <div class="split-left">
        <div class="left-content">
            <div class="lock-scene">
                <div class="lock-ring"></div>
                <div class="lock-ring"></div>
                <div class="lock-ring"></div>
                <div class="lock-dot d1"></div>
                <div class="lock-dot d2"></div>
                <div class="lock-dot d3"></div>
                <div class="lock-dot d4"></div>
                <div class="lock-scan"></div>
                <div class="lock-icon">🔒</div>
            </div>
            
            <h2>Protege tu cuenta</h2>
            <p>Actualiza tu contraseña con una nueva que sea única y segura. Recomendamos usar al menos 8 caracteres con mayúsculas, números y símbolos.</p>
            
            <div class="security-badges">
                <div class="sec-badge">
                    <div class="sec-badge-icon">🔒</div>
                    <div class="sec-badge-text">Cifrado Argon2id<small>Estándar bancario</small></div>
                </div>
                <div class="sec-badge">
                    <div class="sec-badge-icon">✓</div>
                    <div class="sec-badge-text">Contraseña segura<small>Validación automática</small></div>
                </div>
                <div class="sec-badge">
                    <div class="sec-badge-icon">◎</div>
                    <div class="sec-badge-text">Auditoría<small>Cada cambio se registra</small></div>
                </div>
                <div class="sec-badge">
                    <div class="sec-badge-icon">🔐</div>
                    <div class="sec-badge-text">Sesión única<small>Solo tú tienes acceso</small></div>
                </div>
            </div>
        </div>
    </div>

    <!-- DERECHA: formulario -->
    <div class="split-right">
        <div class="form-card">
            
            <div class="form-header">
                <span class="user-badge">◉ <?= htmlspecialchars($rol) ?></span>
                <h1>Cambiar Contraseña</h1>
                <p>Hola <strong><?= htmlspecialchars($nombre) ?></strong>, ingresa tu contraseña actual y una nueva.</p>
            </div>

            <?php if ($es_obligatorio): ?>
                <div class="alert-info alert-warning">
                    ⚠️ Por seguridad, debes cambiar la contraseña de esta cuenta antes de continuar.
                </div>
            <?php endif; ?>

            <?php if ($mensaje): ?>
                <div class="cyber-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($mensaje) ?></div>
                <a href="<?= in_array($rol, ['admin', 'operador'], true) ? 'dashboard_admin.php' : 'index.php' ?>" class="cyber-btn-primary btn-block" style="text-decoration: none; text-align: center; display: block;">Continuar</a>
            <?php else: ?>
                <?php if ($error): ?><div class="cyber-alert">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
                
                <form method="POST" class="cyber-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label>Contraseña actual</label>
                        <input type="password" name="password_actual" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Nueva contraseña (mínimo 8 caracteres)</label>
                        <input type="password" name="password_nueva" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label>Confirmar nueva contraseña</label>
                        <input type="password" name="password_confirma" required minlength="8">
                    </div>
                    <button type="submit" class="btn-primario">Actualizar Contraseña</button>
                </form>
                
                <?php if (!$es_obligatorio): ?>
                    <p class="link-abajo"><a href="index.php">← Cancelar y volver al inicio</a></p>
                <?php endif; ?>
            <?php endif; ?>
            
        </div>
    </div>

</div>

<script src="assets/js/app.js?v=999"></script>
</body>
</html>