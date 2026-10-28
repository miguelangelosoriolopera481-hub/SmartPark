<?php
session_start();
require_once 'conexion.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$mensaje = '';
$token_valido = false;
$user_id = 0;

if ($token !== '') {
    try {
        $stmt = $pdo->prepare("SELECT id, user_id, token_hash FROM password_resets WHERE expira_en > NOW()");
        $stmt->execute();
        $resets = $stmt->fetchAll();
        
        foreach ($resets as $r) {
            if (password_verify($token, $r['token_hash'])) {
                $token_valido = true;
                $user_id = (int)$r['user_id'];
                break;
            }
        }
    } catch (\Throwable $e) {
        $error = 'Error de conexión: ' . $e->getMessage();
    }
}

if (!$token_valido && $error === '') {
    $error = 'El enlace es inválido o ya ha expirado.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valido) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');
        
        if (strlen($pass1) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // FORZAR BCRYPT PARA EVITAR ERRORES DE ARGON2
                $hash = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 12]);
                
                // 1. Actualizar contraseña
                $pdo->prepare("UPDATE user SET password = :p, must_change_password = 0 WHERE id = :id")
                    ->execute(['p' => $hash, 'id' => $user_id]);
                
                // VERIFICACIÓN: Comprobar que realmente se guardó en la BD
                $stmtCheck = $pdo->prepare("SELECT password FROM user WHERE id = :id");
                $stmtCheck->execute(['id' => $user_id]);
                $nuevoHash = $stmtCheck->fetchColumn();
                
                if (!password_verify($pass1, $nuevoHash)) {
                    throw new Exception("La base de datos no guardó la contraseña correctamente.");
                }
                
                // 2. Borrar token
                $pdo->prepare("DELETE FROM password_resets WHERE user_id = :id")
                    ->execute(['id' => $user_id]);

                // 3. Desbloquear cuenta
                $stmtUser = $pdo->prepare("SELECT username, correo FROM user WHERE id = :id");
                $stmtUser->execute(['id' => $user_id]);
                $u = $stmtUser->fetch();
                
                if ($u) {
                    $stmtDel = $pdo->prepare("DELETE FROM login_intentos WHERE identificador = :u1 OR identificador = :u2");
                    $stmtDel->execute(['u1' => $u['username'], 'u2' => $u['correo']]);
                }

                $pdo->commit();
                
                $mensaje = '¡Contraseña actualizada con éxito! Ya puedes iniciar sesión.';
                $token_valido = false; // Ocultar formulario
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Error real: ' . $e->getMessage();
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
<title>Restablecer Contraseña - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=999">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
<style>
    html, body { height: 100%; margin: 0; padding: 0; background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.15) 0%, transparent 60%), #030712; display: flex !important; align-items: center !important; justify-content: center !important; box-sizing: border-box; }
    .login-card { width: 100%; max-width: 420px; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(20px); border: 1.5px solid rgba(16, 185, 129, 0.3); border-radius: 24px; padding: 2.5rem 2rem; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); text-align: center; margin: 1.5rem; }
    .login-card img { height: 80px; margin-bottom: 1.25rem; filter: drop-shadow(0 0 20px rgba(16,185,129,0.5)); }
    .login-card h1 { font-size: 1.5rem; font-weight: 900; color: #f1f5f9; margin-bottom: 0.4rem; }
    .login-card p { color: #94a3b8; font-size: 0.88rem; margin-bottom: 1.75rem; line-height: 1.5; }
    .form-group { text-align: left; margin-bottom: 1.1rem; }
    .form-group label { display: block; font-size: 0.82rem; color: #cbd5e1; font-weight: 700; margin-bottom: 0.4rem; }
    .form-group input { width: 100%; padding: 0.9rem 1rem; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 11px; color: #f1f5f9; font-family: inherit; box-sizing: border-box; }
    .form-group input:focus { border-color: #10b981; outline: none; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
    .btn-primary { width: 100%; padding: 1rem; background: linear-gradient(135deg, #10b981, #06b6d4); color: #030712; border: none; border-radius: 12px; font-weight: 900; font-size: 1rem; cursor: pointer; margin-top: 0.5rem; font-family: inherit; transition: all 0.3s ease; }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4); }
    .links { margin-top: 1.5rem; font-size: 0.85rem; }
    .links a { color: #64748b; text-decoration: none; display: block; margin-bottom: 0.5rem; transition: color 0.2s; }
    .links a:hover { color: #10b981; }
    .alert-danger { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; padding: 1rem; border-radius: 11px; margin-bottom: 1.25rem; font-size: 0.85rem; text-align: left; line-height: 1.4; }
    .alert-success { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; padding: 1rem; border-radius: 11px; margin-bottom: 1.25rem; font-size: 0.85rem; text-align: left; line-height: 1.4; }
</style>
</head>
<body>
    <div class="login-card">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark" onerror="this.src='IMG/SMARTPARK.png'">
        <h1>Nueva Contraseña</h1>
        <p>Crea una nueva contraseña segura para tu cuenta.</p>

        <?php if ($error): ?>
            <div class="alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($mensaje): ?>
            <div class="alert-success">✅ <?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <?php if ($token_valido): ?>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <label>Nueva Contraseña</label>
                    <input type="password" name="password" required minlength="6" autofocus>
                </div>
                <div class="form-group">
                    <label>Confirmar Contraseña</label>
                    <input type="password" name="password2" required minlength="6">
                </div>
                <button type="submit" class="btn-primary">Guardar Contraseña</button>
            </form>
        <?php else: ?>
            <div class="links" style="margin-top: 1rem;">
                <a href="login_cliente.php" class="btn-primary" style="display:block; text-decoration:none; box-sizing:border-box;">Ir al Login Cliente</a>
                <a href="login_empresa.php" class="btn-primary" style="display:block; text-decoration:none; box-sizing:border-box; background: linear-gradient(135deg, #3b82f6, #06b6d4); margin-top: 0.5rem;">Ir al Login Empresa</a>
                <a href="olvidar_password.php" style="margin-top: 1rem;">Solicitar nuevo enlace</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>