<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'mailer.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'helpers_url.php';
send_security_headers();

$msg = ''; 
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) { 
        $error = 'Sesión expirada. Recarga la página.'; 
    } else {
        $ident = trim($_POST['identificador'] ?? '');
        
        if ($ident === '') {
            $error = 'Ingresa tu usuario o correo electrónico.';
        } else {
            try {
                $es_correo = filter_var($ident, FILTER_VALIDATE_EMAIL);
                
                if ($es_correo) {
                    // Contar cuántos usuarios tienen este correo
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM user WHERE correo = :c");
                    $stmtCount->execute(['c' => $ident]);
                    $count = (int)$stmtCount->fetchColumn();

                    if ($count > 1) {
                        $error = 'Este correo electrónico está asociado a múltiples cuentas. Por favor, ingresa tu <strong>nombre de usuario</strong> para saber cuál recuperar.';
                    } elseif ($count === 1) {
                        $stmt = $pdo->prepare("SELECT id, nombre, correo FROM user WHERE correo = :c LIMIT 1");
                        $stmt->execute(['c' => $ident]);
                        $user = $stmt->fetch();
                    } else {
                        $msg = 'Si los datos coinciden, enviaremos las instrucciones a tu correo.';
                    }
                } else {
                    // Buscar por nombre de usuario
                    $stmt = $pdo->prepare("SELECT id, nombre, correo FROM user WHERE username = :u LIMIT 1");
                    $stmt->execute(['u' => $ident]);
                    $user = $stmt->fetch();
                    
                    if (!$user) {
                        $msg = 'Si los datos coinciden, enviaremos las instrucciones a tu correo.';
                    }
                }

                if (isset($user) && $user && $error === '') {
                    $destino = $user['correo'] ?? '';
                    if ($destino !== '') {
                        $token = bin2hex(random_bytes(32));
                        $hash = password_hash($token, PASSWORD_BCRYPT, ['cost'=>10]);
                        $expira = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                        
                        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, metodo, destino, expira_en, ip) VALUES (:u, :h, :m, :d, :exp, :ip)")
                            ->execute([
                                'u' => $user['id'], 
                                'h' => $hash, 
                                'm' => 'email', 
                                'd' => $destino, 
                                'exp' => $expira,
                                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
                            ]);
                            
                        $link = detectar_url_base() . 'reset_password.php?token=' . urlencode($token);
                        
                        $cuerpo = "<div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; background: #f8fafc; border-radius: 10px;'>"
                                . "<h2 style='color: #10b981;'>Recuperar Contraseña</h2>"
                                . "<p>Hola <strong>{$user['nombre']}</strong>,</p>"
                                . "<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta. Haz clic en el siguiente enlace (válido por 30 minutos):</p>"
                                . "<p style='text-align: center;'><a href='{$link}' style='display: inline-block; padding: 12px 24px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>Restablecer Contraseña</a></p>"
                                . "<p style='color: #64748b; font-size: 12px;'>Si no solicitaste esto, ignora este correo.</p>"
                                . "</div>";
                                
                        enviar_correo($pdo, $destino, 'Recuperación de Contraseña - SmartPark', $cuerpo);
                        $msg = 'Si los datos coinciden, enviaremos las instrucciones de recuperación a tu correo.';
                    }
                }
            } catch (\Throwable $e) {
                error_log('olvidar_password: ' . $e->getMessage());
                $error = 'Error interno al procesar la solicitud.';
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
<title>Recuperar Contraseña - SmartPark</title>
<link rel="stylesheet" href="assets/css/main.css?v=999">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
<style>
    html, body { height: 100%; margin: 0; padding: 0; background: radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.15) 0%, transparent 60%), #030712; display: flex !important; align-items: center !important; justify-content: center !important; box-sizing: border-box; }
    .login-card { width: 100%; max-width: 440px; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(20px); border: 1.5px solid rgba(16, 185, 129, 0.3); border-radius: 24px; padding: 2.5rem 2rem; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); text-align: center; margin: 1.5rem; }
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
        <h1>Recuperar Contraseña</h1>
        <p>Ingresa tu usuario o correo y te enviaremos un enlace para restablecerla.</p>

        <?php if ($error): ?>
            <div class="alert-danger">⚠️ <?= $error ?></div>
        <?php endif; ?>
        
        <?php if ($msg): ?>
            <div class="alert-success">✅ <?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Usuario o correo electrónico</label>
                <input type="text" name="identificador" required autofocus placeholder="Ej: admin_inem o correo@ejemplo.com">
                <p style="font-size: 0.7rem; color: #64748b; margin-top: 0.4rem; text-align: left;">Si compartes correo con otras cuentas, usa tu nombre de usuario.</p>
            </div>
            <button type="submit" class="btn-primary">Enviar instrucciones</button>
        </form>

        <div class="links" style="margin-top: 1rem;">
            <a href="login_cliente.php">← Volver al login de Cliente</a>
            <a href="login_empresa.php">← Volver al login de Empresa</a>
            <a href="login_developer.php">← Volver al login de Developer</a>
        </div>
    </div>
</body>
</html>