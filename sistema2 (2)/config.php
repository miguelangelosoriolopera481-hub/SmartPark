<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'backup.php';

// Solo admin (no operador) puede entrar a Configuración, según el punto 6.
require_role(['admin']);
require_password_change_guard();

$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página e inténtalo de nuevo.";
    } else {

        // --- Guardar tarifas ---
        if (isset($_POST['action_tarifas'])) {
            $precio_carro = (float) ($_POST['precio_carro'] ?? 0);
            $precio_moto  = (float) ($_POST['precio_moto'] ?? 0);

            if ($precio_carro <= 0 || $precio_moto <= 0) {
                $error = "Las tarifas deben ser mayores a cero.";
            } else {
                actualizarTarifa($pdo, 'carro', $precio_carro);
                actualizarTarifa($pdo, 'moto', $precio_moto);
                registrarAuditoria($pdo, $_SESSION['usuario_id'], $_SESSION['nombre'], 'cambio_tarifa', "carro=$precio_carro, moto=$precio_moto");
                $mensaje = "Tarifas actualizadas correctamente.";
            }
        }

        // --- Guardar configuración SMTP ---
        if (isset($_POST['action_smtp'])) {
            guardarConfiguracion($pdo, 'MAIL_HOST', trim($_POST['mail_host'] ?? ''));
            guardarConfiguracion($pdo, 'MAIL_PORT', trim($_POST['mail_port'] ?? '587'));
            guardarConfiguracion($pdo, 'MAIL_SECURE', trim($_POST['mail_secure'] ?? 'tls'));
            guardarConfiguracion($pdo, 'MAIL_USER', trim($_POST['mail_user'] ?? ''));
            guardarConfiguracion($pdo, 'MAIL_FROM', trim($_POST['mail_from'] ?? ''));
            guardarConfiguracion($pdo, 'MAIL_FROM_NAME', trim($_POST['mail_from_name'] ?? 'SmartPark Pro'));

            // La contraseña solo se sobreescribe si el admin escribió una nueva
            // (así el campo puede mostrarse vacío en el formulario sin borrar
            // el valor real cada vez que se guardan los demás campos).
            if (!empty($_POST['mail_pass'])) {
                guardarConfiguracion($pdo, 'MAIL_PASS', $_POST['mail_pass']);
            }

            registrarAuditoria($pdo, $_SESSION['usuario_id'], $_SESSION['nombre'], 'cambio_config_smtp', 'Datos SMTP actualizados');
            $mensaje = "Configuración SMTP actualizada correctamente.";
        }

        // --- Cambiar rol de un usuario ---
        if (isset($_POST['action_rol'])) {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $nuevo_rol = $_POST['nuevo_rol'] ?? '';

            if (!in_array($nuevo_rol, ['admin', 'operador', 'cliente'], true)) {
                $error = "Rol inválido.";
            } elseif ($uid === (int) $_SESSION['usuario_id']) {
                $error = "No puedes cambiar tu propio rol desde aquí (evita bloquearte a ti mismo).";
            } else {
                $stmt = $pdo->prepare("UPDATE user SET role = :rol WHERE id = :id");
                $stmt->execute(['rol' => $nuevo_rol, 'id' => $uid]);
                registrarAuditoria($pdo, $_SESSION['usuario_id'], $_SESSION['nombre'], 'cambio_rol_usuario', "user_id=$uid -> $nuevo_rol");
                $mensaje = "Rol actualizado correctamente.";
            }
        }

        // --- Generar copia de seguridad ---
        if (isset($_POST['action_backup'])) {
            $nombreArchivo = 'smartpark_backup_' . date('Ymd_His') . '.sql';
            $rutaCompleta = __DIR__ . '/backups/' . $nombreArchivo;

            if (generarBackupSQL($pdo, $rutaCompleta)) {
                registrarAuditoria($pdo, $_SESSION['usuario_id'], $_SESSION['nombre'], 'backup_generado', $nombreArchivo);
                $mensaje = "Copia de seguridad generada: $nombreArchivo";
                $_SESSION['ultimo_backup'] = $nombreArchivo;
            } else {
                $error = "No se pudo generar la copia de seguridad. Verifica permisos de escritura en /backups.";
            }
        }
    }
}

$tarifas = obtenerTarifas($pdo);
$mail_host_actual      = obtenerConfiguracion($pdo, 'MAIL_HOST', '');
$mail_port_actual      = obtenerConfiguracion($pdo, 'MAIL_PORT', 587);
$mail_secure_actual    = obtenerConfiguracion($pdo, 'MAIL_SECURE', 'tls');
$mail_user_actual      = obtenerConfiguracion($pdo, 'MAIL_USER', '');
$mail_from_actual      = obtenerConfiguracion($pdo, 'MAIL_FROM', '');
$mail_from_name_actual = obtenerConfiguracion($pdo, 'MAIL_FROM_NAME', 'SmartPark Pro');

$usuarios = $pdo->query("SELECT id, nombre, username, correo, role FROM user ORDER BY id ASC")->fetchAll();

// Lista de backups existentes para poder descargarlos
$archivos_backup = [];
foreach (glob(__DIR__ . '/backups/*.sql') ?: [] as $ruta) {
    $archivos_backup[] = basename($ruta);
}
rsort($archivos_backup);

$navbar_title = 'Admin Terminal';
$navbar_home  = 'admin.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark - Configuración</title>
    <link rel="stylesheet" href="CSS/style.css">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>

<div class="container cyber-entrance">
    <h2 style="color: var(--primary-cyan); margin-bottom: 1.5rem;">⚙️ Configuración</h2>

    <?php if ($mensaje): ?><div class="cyber-success" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Tarifas -->
    <div class="cyber-card" style="margin-bottom: 2rem;">
        <h3 style="color: var(--primary-cyan); margin-bottom: 1rem;">💲 Tarifas por hora</h3>
        <form method="POST" class="cyber-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:1rem; align-items:end;">
            <?= csrf_field() ?>
            <input type="hidden" name="action_tarifas" value="1">
            <div class="form-group">
                <label>Carro ($/hora):</label>
                <input type="number" name="precio_carro" min="1" step="1" value="<?= (int) $tarifas['carro'] ?>" required>
            </div>
            <div class="form-group">
                <label>Moto ($/hora):</label>
                <input type="number" name="precio_moto" min="1" step="1" value="<?= (int) $tarifas['moto'] ?>" required>
            </div>
            <button type="submit" class="cyber-btn-primary">Guardar Tarifas</button>
        </form>
    </div>

    <!-- SMTP -->
    <div class="cyber-card" style="margin-bottom: 2rem;">
        <h3 style="color: var(--primary-cyan); margin-bottom: 1rem;">✉️ Configuración SMTP (correo de facturas)</h3>
        <p style="color: var(--text-muted); font-size: 0.82rem; margin-bottom: 1rem;">Estos datos se guardan en la base de datos, nunca en el código fuente.</p>
        <form method="POST" class="cyber-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:1rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action_smtp" value="1">
            <div class="form-group"><label>Host SMTP:</label><input type="text" name="mail_host" value="<?= htmlspecialchars($mail_host_actual) ?>" placeholder="smtp.gmail.com"></div>
            <div class="form-group"><label>Puerto:</label><input type="number" name="mail_port" value="<?= htmlspecialchars((string) $mail_port_actual) ?>"></div>
            <div class="form-group">
                <label>Seguridad:</label>
                <select name="mail_secure">
                    <option value="tls" <?= $mail_secure_actual === 'tls' ? 'selected' : '' ?>>TLS</option>
                    <option value="ssl" <?= $mail_secure_actual === 'ssl' ? 'selected' : '' ?>>SSL</option>
                </select>
            </div>
            <div class="form-group"><label>Usuario / correo remitente:</label><input type="text" name="mail_user" value="<?= htmlspecialchars($mail_user_actual) ?>"></div>
            <div class="form-group"><label>Contraseña de aplicación:</label><input type="password" name="mail_pass" placeholder="Dejar en blanco para no cambiarla"></div>
            <div class="form-group"><label>Correo "From":</label><input type="text" name="mail_from" value="<?= htmlspecialchars($mail_from_actual) ?>"></div>
            <div class="form-group"><label>Nombre remitente:</label><input type="text" name="mail_from_name" value="<?= htmlspecialchars($mail_from_name_actual) ?>"></div>
            <div style="display:flex; align-items:end;"><button type="submit" class="cyber-btn-primary" style="width:100%;">Guardar SMTP</button></div>
        </form>
    </div>

    <!-- Usuarios y roles -->
    <div class="cyber-card" style="margin-bottom: 2rem;">
        <h3 style="color: var(--primary-cyan); margin-bottom: 1rem;">👥 Usuarios y roles</h3>
        <div class="table-wrapper">
            <table>
                <thead><tr><th>ID</th><th>Nombre</th><th>Usuario</th><th>Correo</th><th>Rol</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?= (int) $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['nombre']) ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['correo'] ?? '—') ?></td>
                        <td>
                            <form method="POST" style="display:flex; gap:0.4rem; align-items:center;" onsubmit="return confirm('¿Confirmas el cambio de rol de <?= htmlspecialchars(addslashes($u['username'])) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action_rol" value="1">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <select name="nuevo_rol" <?= ((int) $u['id'] === (int) $_SESSION['usuario_id']) ? 'disabled' : '' ?>>
                                    <option value="cliente" <?= $u['role'] === 'cliente' ? 'selected' : '' ?>>Cliente</option>
                                    <option value="operador" <?= $u['role'] === 'operador' ? 'selected' : '' ?>>Operador</option>
                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                                <?php if ((int) $u['id'] !== (int) $_SESSION['usuario_id']): ?>
                                    <button type="submit" class="cyber-btn-outline" style="padding:0.3rem 0.7rem; font-size:0.78rem;">Guardar</button>
                                <?php else: ?>
                                    <span style="font-size:0.75rem; color: var(--text-muted);">(tú)</span>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Backup -->
    <div class="cyber-card">
        <h3 style="color: var(--primary-cyan); margin-bottom: 1rem;">💾 Copia de seguridad de la base de datos</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
            Genera un archivo .sql descargable con la estructura y los datos actuales. Es una operación de solo lectura: nunca borra ni modifica información.
        </p>
        <form method="POST" onsubmit="return confirm('¿Generar una nueva copia de seguridad ahora?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action_backup" value="1">
            <button type="submit" class="cyber-btn-primary">Generar copia de seguridad</button>
        </form>

        <?php if (!empty($archivos_backup)): ?>
            <h4 style="margin-top: 1.5rem; color: var(--text-muted); font-size: 0.85rem;">Backups disponibles:</h4>
            <ul style="margin-top: 0.5rem; list-style: none;">
                <?php foreach (array_slice($archivos_backup, 0, 10) as $archivo): ?>
                    <li style="padding: 0.4rem 0; border-bottom: 1px solid rgba(255,255,255,0.06);">
                        <a href="descargar_backup.php?file=<?= urlencode($archivo) ?>" style="color: var(--primary-cyan);">⬇️ <?= htmlspecialchars($archivo) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
