<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';

require_login();

$error = '';
$mensaje = '';
$es_obligatorio = !empty($_SESSION['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página e inténtalo de nuevo.";
    } else {
        $actual   = (string) ($_POST['password_actual'] ?? '');
        $nueva    = (string) ($_POST['password_nueva'] ?? '');
        $confirma = (string) ($_POST['password_confirma'] ?? '');

        if (strlen($nueva) < 6) {
            $error = "La nueva contraseña debe tener al menos 6 caracteres.";
        } elseif ($nueva !== $confirma) {
            $error = "La confirmación no coincide con la nueva contraseña.";
        } else {
            $stmt = $pdo->prepare("SELECT password FROM user WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $_SESSION['usuario_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($actual, $user['password'])) {
                $error = "La contraseña actual no es correcta.";
            } else {
                $hash = password_hash($nueva, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE user SET password = :hash, must_change_password = 0 WHERE id = :id");
                $upd->execute(['hash' => $hash, 'id' => $_SESSION['usuario_id']]);

                $_SESSION['must_change_password'] = false;
                $es_obligatorio = false;
                $mensaje = "Contraseña actualizada correctamente.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SmartPark - Cambiar Contraseña</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<?php if (!$es_obligatorio) { include 'partials/navbar.php'; } ?>
<div class="cyber-login-wrapper" style="margin-top: 2rem;">
    <div class="cyber-card cyber-entrance" style="max-width: 440px; width: 100%;">
        <h2 style="color: var(--primary-cyan); text-align: center; margin-bottom: 0.5rem;">Cambiar Contraseña</h2>

        <?php if ($es_obligatorio): ?>
            <div class="cyber-alert" style="text-align:center;">
                Por seguridad, debes cambiar la contraseña de esta cuenta de ejemplo antes de continuar.
            </div>
        <?php endif; ?>

        <?php if ($mensaje): ?>
            <div class="cyber-success" style="text-align:center;"><?= htmlspecialchars($mensaje) ?></div>
            <a href="<?= in_array($_SESSION['role'], ['admin', 'operador'], true) ? 'admin.php' : 'index.php' ?>" class="cyber-btn-primary btn-block" style="margin-top: 1rem;">Continuar</a>
        <?php else: ?>
            <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST" class="cyber-form">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label>Contraseña actual:</label>
                    <input type="password" name="password_actual" required>
                </div>
                <div class="form-group">
                    <label>Nueva contraseña (mínimo 6 caracteres):</label>
                    <input type="password" name="password_nueva" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirmar nueva contraseña:</label>
                    <input type="password" name="password_confirma" required minlength="6">
                </div>
                <button type="submit" class="cyber-btn-primary" style="width: 100%;">Actualizar Contraseña</button>
            </form>
            <?php if (!$es_obligatorio): ?>
                <p style="text-align:center; margin-top: 1rem;"><a href="index.php" style="color: var(--text-muted);">← Cancelar</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
