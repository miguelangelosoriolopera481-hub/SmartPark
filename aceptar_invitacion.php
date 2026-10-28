<?php
session_start();
require_once 'conexion.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$msg = ''; $error = '';
$invitacion = null;

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Enlace de invitación inválido.';
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT ie.*, e.nombre AS empresa_nombre
            FROM invitaciones_equipo ie
            JOIN empresas e ON e.id = ie.empresa_id
            WHERE ie.token = :t AND ie.usado = 0 AND ie.expira_en > NOW()
            LIMIT 1
        ");
        $stmt->execute(['t' => $token]);
        $invitacion = $stmt->fetch();
    } catch (\Throwable $e) {}

    if (!$invitacion) {
        $error = 'Esta invitación ya fue usada o ha expirado.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invitacion) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if (strlen($pass1) < 8) {
            $error = 'La contraseña debe tener al menos 8 caracteres.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{3,19}$/', $username)) {
            $error = 'El usuario debe tener 4-20 caracteres (solo letras, números y _).';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id FROM user WHERE username = :u LIMIT 1");
                $stmt->execute(['u' => $username]);
                if ($stmt->fetch()) {
                    $error = 'Ese nombre de usuario ya está tomado. Elige otro.';
                } else {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("SELECT id FROM user WHERE correo = :e LIMIT 1");
                    $stmt->execute(['e' => $invitacion['email']]);
                    $existente = $stmt->fetch();

                    if ($existente) {
                        $userId = (int)$existente['id'];
                        $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
                        $pdo->prepare("UPDATE user SET password = :p, username = :u WHERE id = :id")
                            ->execute(['p' => $hash, 'u' => $username, 'id' => $userId]);
                    } else {
                        $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
                        $stmt = $pdo->prepare("
                            INSERT INTO user (username, password, nombre, correo, role, must_change_password)
                            VALUES (:u, :p, :n, :e, 'cliente', 0)
                        ");
                        $stmt->execute([
                            'u' => $username,
                            'p' => $hash,
                            'n' => $invitacion['nombre'],
                            'e' => $invitacion['email'],
                        ]);
                        $userId = (int)$pdo->lastInsertId();
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios_empresa (user_id, empresa_id, parqueadero_id, rol_en_empresa, activo)
                        VALUES (:u, :e, 1, :r, 1)
                        ON DUPLICATE KEY UPDATE rol_en_empresa = VALUES(rol_en_empresa), activo = 1
                    ");
                    $stmt->execute([
                        'u' => $userId,
                        'e' => $invitacion['empresa_id'],
                        'r' => $invitacion['rol'],
                    ]);

                    $pdo->prepare("UPDATE invitaciones_equipo SET usado = 1 WHERE id = :id")
                        ->execute(['id' => $invitacion['id']]);

                    $pdo->commit();

                    $msg = '¡Cuenta creada! Ya puedes iniciar sesión.';
                    $invitacion = null;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('aceptar_invitacion: ' . $e->getMessage());
                $error = 'Error al crear la cuenta. Intenta de nuevo.';
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
<title>Aceptar Invitación - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=50">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; background: radial-gradient(circle at 50% 0%, rgba(16,185,129,0.1) 0%, transparent 60%), #030712; }
    .invit-card { width: 100%; max-width: 480px; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(24px); border: 1.5px solid rgba(16, 185, 129, 0.3); border-radius: 24px; padding: 2.5rem 2rem; box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5); }
    .invit-card h1 { font-size: 1.5rem; font-weight: 900; color: #f1f5f9; margin: 0 0 0.5rem; }
    .invit-card .sub { color: #94a3b8; font-size: 0.88rem; margin-bottom: 1.75rem; line-height: 1.5; }
    .form-group { margin-bottom: 1.1rem; }
    .form-group label { display: block; font-size: 0.82rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; }
    .form-group input { width: 100%; padding: 0.9rem 1rem; background: rgba(2, 6, 23, 0.75); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 11px; color: #f1f5f9; font-family: inherit; font-size: 0.95rem; outline: none; box-sizing: border-box; }
    .form-group input:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
    .btn-primario { width: 100%; padding: 1rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; border: none; border-radius: 12px; font-weight: 900; font-size: 1rem; cursor: pointer; font-family: inherit; margin-top: 1.5rem; transition: all 0.3s ease; }
    .btn-primario:hover { transform: translateY(-2px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.4); }
    .empresa-badge { display: inline-block; padding: 0.35rem 0.9rem; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 100px; color: #10b981; font-size: 0.72rem; font-weight: 800; margin-bottom: 1rem; letter-spacing: 0.04em; }
    .rol-badge { display: inline-block; padding: 0.25rem 0.75rem; background: rgba(6, 182, 212, 0.15); border-radius: 100px; color: #67e8f9; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; margin-left: 0.4rem; }
    .btn-login { display: block; text-align: center; margin-top: 1.5rem; padding: 1rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; text-decoration: none; border-radius: 12px; font-weight: 800; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>
<canvas id="particles-canvas"></canvas>

<div class="invit-card">

<?php if ($msg): ?>
    <div style="text-align: center;">
        <div style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 80px; border-radius: 50%; background: rgba(16,185,129,0.15); border: 3px solid #10b981; font-size: 2.5rem; color: #10b981; margin-bottom: 1.25rem;">✓</div>
        <h1>¡Cuenta creada!</h1>
        <p class="sub"><?= htmlspecialchars($msg) ?></p>
        <a href="login_empresa.php" class="btn-login">Iniciar sesión</a>
    </div>

<?php elseif ($invitacion): ?>

    <span class="empresa-badge">◉ <?= htmlspecialchars($invitacion['empresa_nombre']) ?></span>
    <h1>¡Bienvenido, <?= htmlspecialchars($invitacion['nombre']) ?>!</h1>
    <p class="sub">Has sido invitado como <span class="rol-badge"><?= htmlspecialchars($invitacion['rol']) ?></span><br>Crea tu contraseña para activar tu cuenta</p>

    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="form-group">
            <label>Nombre de usuario</label>
            <input type="text" name="username" placeholder="juanperez" required pattern="[a-zA-Z][a-zA-Z0-9_]{3,19}" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus>
        </div>
        <div class="form-group">
            <label>Contraseña (mínimo 8 caracteres)</label>
            <input type="password" name="password" required minlength="8">
        </div>
        <div class="form-group">
            <label>Confirmar contraseña</label>
            <input type="password" name="password2" required minlength="8">
        </div>
        <button type="submit" class="btn-primario">Crear mi cuenta</button>
    </form>

<?php else: ?>

    <div style="text-align: center;">
        <div style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 80px; border-radius: 50%; background: rgba(239,68,68,0.15); border: 3px solid #ef4444; font-size: 2rem; color: #ef4444; margin-bottom: 1.25rem;">✕</div>
        <h1>Invitación no válida</h1>
        <p class="sub"><?= htmlspecialchars($error ?: 'Este enlace ya fue usado o expiró.') ?></p>
        <a href="index.php" class="btn-login">Volver al inicio</a>
    </div>

<?php endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
</body>
</html>