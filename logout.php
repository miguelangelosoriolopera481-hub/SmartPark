<?php
session_start();

// Detectar si es "cambiar cuenta" o "cerrar sesión"
$esCambio = ($_GET['cambiar'] ?? '') === '1';
$rol      = $_SESSION['role'] ?? '';

session_unset();
session_destroy();

if ($esCambio) {
    // Redirigir al login según el rol
    $destinos = [
        'developer' => 'login_developer.php',
        'admin'     => 'login_empresa.php',
        'cliente'   => 'login_empresa.php',
    ];
    $destino = $destinos[$rol] ?? 'index.php';
    header("Location: $destino?cambiar=1");
} else {
    header('Location: index.php');
}
exit;