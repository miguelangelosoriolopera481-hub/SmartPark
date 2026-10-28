<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';

$mensaje_exito = '';
$error = '';
$modo = $_GET['modo'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['action_register']) || isset($_POST['action_login']))) {
    $resultado = procesarAuthPost($pdo, $modo);
    $modo          = $resultado['modo'];
    $error         = $resultado['error'];
    $mensaje_exito = $resultado['mensaje_exito'];
    if ($resultado['redirect']) {
        header("Location: " . $resultado['redirect']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark Pro - Acceso y Registro</title>
    <link rel="stylesheet" href="CSS/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>
<body class="cyber-body">

<!-- NAVBAR SIMPLE (solo volver al inicio, sin duplicar el navbar completo) -->
<nav class="cyber-navbar">
    <div class="nav-container">
        <a href="index.php" class="cyber-brand">
            <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark Logo" class="nav-logo">
        </a>
        <a href="index.php" class="cyber-btn-outline">Volver al Terminal</a>
    </div>
</nav>

<?php
$form_action = 'login.php';
include 'partials/auth_forms.php';
?>
</body>
</html>
