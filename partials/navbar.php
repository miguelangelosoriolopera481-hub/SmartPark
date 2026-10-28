<?php
/**
 * partials/navbar.php
 * --------------------
 * Navbar compartido. Se incluye con: include __DIR__ . '/partials/navbar.php';
 * Variables opcionales antes del include:
 *   $navbar_title  (string) - texto junto al logo (por defecto "SmartPark Pro")
 *   $navbar_home   (string) - href del logo/marca (por defecto "index.php")
 * Usa $_SESSION para saber qué mostrar según el rol.
 */
$navbar_title = $navbar_title ?? 'SmartPark Pro';
$navbar_home  = $navbar_home ?? 'index.php';
$rol_actual   = $_SESSION['role'] ?? null;
?>
<nav class="cyber-navbar">
    <div class="nav-container">
        <a href="<?= htmlspecialchars($navbar_home) ?>" class="cyber-brand">
            <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark Logo" class="nav-logo">
            <span class="brand-title"><?= htmlspecialchars($navbar_title) ?></span>
        </a>
        <div class="cyber-nav-links">
            <?php if (isset($_SESSION['usuario_id'])): ?>
                <span style="color: var(--text-muted);">Hola, <strong><?= htmlspecialchars($_SESSION['nombre'] ?? '') ?></strong></span>

                <?php if (in_array($rol_actual, ['admin', 'operador'], true)): ?>
                    <a href="admin.php" class="cyber-btn-outline">🗺️ Panel</a>
                    <a href="stats.php" class="cyber-btn-outline">📊 Estadísticas</a>
                    <?php if ($rol_actual === 'admin'): ?>
                        <a href="config.php" class="cyber-btn-outline">⚙️ Configuración</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="mi_parqueadero.php" class="cyber-btn-outline">🅿️ Mi Parqueadero</a>
                    <a href="mi_cuenta.php" class="cyber-btn-outline">👤 Mi Cuenta</a>
                <?php endif; ?>

                <a href="registro_carro.php" class="cyber-btn-outline">📝 Registrar Vehículo</a>
                <a href="logout.php" class="cyber-btn-outline" style="border-color: var(--danger); color: var(--danger);">🚪 Salir</a>
            <?php else: ?>
                <a href="registro_carro.php" class="cyber-btn-outline">📝 Registrar Vehículo</a>
                <a href="mi_parqueadero.php" class="cyber-btn-outline">🅿️ Mi Parqueadero</a>
                <a href="login.php?modo=login" class="cyber-btn-outline">Iniciar Sesión</a>
                <a href="login.php?modo=registro" class="cyber-btn-primary">Crear Cuenta</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
