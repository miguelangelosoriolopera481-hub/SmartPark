<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'validaciones.php';

require_password_change_guard();

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página e inténtalo de nuevo.";
    } else {
        $placa = normalizar_placa($_POST['placa'] ?? '');

        if (!empty($placa)) {
            $stmt = $pdo->prepare("SELECT id FROM registros_parqueo WHERE placa = :placa AND estado = 'activo' ORDER BY id DESC LIMIT 1");
            $stmt->execute(['placa' => $placa]);

            if ($stmt->fetch()) {
                header("Location: estado_parqueo.php?placa=" . urlencode($placa));
                exit;
            } else {
                $error = "No hay un vehículo activo con esa placa.";
            }
        } else {
            $error = "Ingrese una placa válida.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SmartPark - Consulta Rápida</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<div class="container cyber-entrance" style="max-width: 400px; margin-top: 3rem;">
    <div class="cyber-card">
        <h2 style="color: var(--primary-cyan); text-align: center;">Consulta tu parqueo</h2>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-group">
                <label>Placa:</label>
                <input type="text" name="placa" required placeholder="ABC123" style="text-transform: uppercase; text-align: center;">
            </div>
            <button type="submit" class="cyber-btn-primary" style="width: 100%;">Buscar</button>
        </form>
        <?php if ($error): ?><div class="cyber-alert" style="margin-top: 1rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <p style="text-align:center; margin-top: 1rem;"><a href="index.php" style="color: var(--text-muted);">← Volver al inicio</a></p>
    </div>
</div>
</body>
</html>
