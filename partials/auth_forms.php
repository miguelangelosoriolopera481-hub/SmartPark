<?php
/**
 * partials/auth_forms.php
 * -------------------------
 * Marcado de los formularios de login/registro. Antes este HTML estaba
 * copiado y pegado en index.php, login.php y registro_usuario.php; ahora
 * es una única plantilla incluida por index.php y login.php.
 *
 * Variables esperadas en scope antes del include:
 *   string $modo           'login' | 'registro'
 *   string $error
 *   string $mensaje_exito
 *   string $form_action    URL base del formulario (ej. 'index.php' o 'login.php')
 */
$form_action = $form_action ?? 'login.php';
?>
<div class="cyber-login-wrapper" style="margin-top: 2rem;">
    <div class="cyber-card cyber-entrance" style="max-width: 450px; width: 100%;">
        <div style="display: flex; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-cyber);">
            <a href="<?= htmlspecialchars($form_action) ?>?modo=login" style="flex: 1; text-align: center; padding: 0.8rem; text-decoration: none; font-weight: 600; color: <?= $modo === 'login' ? 'var(--primary-cyan)' : 'var(--text-muted)' ?>; border-bottom: 2px solid <?= $modo === 'login' ? 'var(--primary-cyan)' : 'transparent' ?>;">Iniciar Sesión</a>
            <a href="<?= htmlspecialchars($form_action) ?>?modo=registro" style="flex: 1; text-align: center; padding: 0.8rem; text-decoration: none; font-weight: 600; color: <?= $modo === 'registro' ? 'var(--primary-cyan)' : 'var(--text-muted)' ?>; border-bottom: 2px solid <?= $modo === 'registro' ? 'var(--primary-cyan)' : 'transparent' ?>;">Crear Cuenta</a>
        </div>

        <?php if (!empty($mensaje_exito)): ?>
            <div class="cyber-success" style="text-align: center;"><?= htmlspecialchars($mensaje_exito) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="cyber-alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($modo === 'login'): ?>
            <form action="<?= htmlspecialchars($form_action) ?>?modo=login" method="POST" class="cyber-form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action_login" value="1">
                <div class="form-group">
                    <label>Usuario o Correo:</label>
                    <input type="text" name="username" placeholder="Ej. admin o tu_usuario" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Contraseña:</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="cyber-btn-primary" style="width: 100%; margin-top: 1rem;">Ingresar al Sistema</button>
            </form>
        <?php else: ?>
            <form action="<?= htmlspecialchars($form_action) ?>?modo=registro" method="POST" class="cyber-form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action_register" value="1">
                <div class="form-group">
                    <label>Nombre Completo:</label>
                    <input type="text" name="nombre" placeholder="Ej. Juan Pérez" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Nombre de Usuario:</label>
                    <input type="text" name="username" placeholder="Ej. juanperez" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Correo Electrónico (Opcional):</label>
                    <input type="email" name="correo" placeholder="correo@ejemplo.com" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Contraseña (mínimo 6 caracteres):</label>
                    <input type="password" name="password" placeholder="••••••••" required minlength="6">
                </div>
                <button type="submit" class="cyber-btn-primary" style="width: 100%; margin-top: 1rem; background: #22c55e; color: black; font-weight: bold;">Registrar Cuenta</button>
            </form>
        <?php endif; ?>
    </div>
</div>
