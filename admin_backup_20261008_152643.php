<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';

// CORRECCIÓN CRÍTICA: antes solo se validaba isset($_SESSION['usuario_id']),
// así que cualquier cliente logueado podía entrar al panel admin completo.
// Ahora se exige rol admin/operador, igual que ya hacía correctamente stats.php.
require_role(['admin', 'operador']);
require_password_change_guard();

$mensaje = '';
$error = '';

// Modificación de estado de celdas (Bloquear/Desbloquear/Mantenimiento)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_celda'])) {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = "Tu sesión de formulario expiró. Recarga la página e inténtalo de nuevo.";
    } else {
        $codigo_cupo  = $_POST['codigo_cupo'] ?? '';
        $nuevo_estado = $_POST['nuevo_estado'] ?? '';

        if (!empty($codigo_cupo) && in_array($nuevo_estado, ['disponible', 'ocupado', 'mantenimiento'], true)) {
            $stmt_upd = $pdo->prepare("UPDATE cupos SET estado = :estado WHERE codigo_cupo = :codigo");
            $stmt_upd->execute(['estado' => $nuevo_estado, 'codigo' => $codigo_cupo]);

            registrarAuditoria(
                $pdo,
                $_SESSION['usuario_id'],
                $_SESSION['nombre'],
                'cambio_estado_celda',
                "Celda $codigo_cupo -> $nuevo_estado"
            );

            $mensaje = "La celda $codigo_cupo ha sido cambiada a estado: " . strtoupper($nuevo_estado);
        }
    }
}

// Estadísticas generales de ingresos y celdas
$stmt_totales = $pdo->query("
    SELECT 
        COUNT(*) AS total_cupos,
        SUM(CASE WHEN estado = 'disponible' THEN 1 ELSE 0 END) AS disponibles,
        SUM(CASE WHEN estado = 'ocupado' THEN 1 ELSE 0 END) AS ocupados,
        SUM(CASE WHEN estado = 'mantenimiento' THEN 1 ELSE 0 END) AS bloqueados
    FROM cupos
");
$stats_cupos = $stmt_totales->fetch();

// Total de registros históricos de ingreso
$stmt_ingresos = $pdo->query("SELECT COUNT(*) AS total_ingresos FROM registros_parqueo");
$total_ingresos = $stmt_ingresos->fetch()['total_ingresos'] ?? 0;

// Obtención de todas las celdas (para el plano visual)
$stmt_celdas = $pdo->query("
    SELECT c.codigo_cupo, c.tipo, c.estado, r.placa, r.fecha_ingreso
    FROM cupos c
    LEFT JOIN registros_parqueo r ON c.codigo_cupo = r.cupo_asignado AND r.estado = 'activo'
    ORDER BY c.tipo ASC, LENGTH(c.codigo_cupo) ASC, c.codigo_cupo ASC
");
$celdas = $stmt_celdas->fetchAll();

// Filtros de búsqueda para historial de registros
$buscar_placa  = trim($_GET['buscar_placa'] ?? '');
$buscar_correo = trim($_GET['buscar_correo'] ?? '');
$buscar_fecha  = trim($_GET['buscar_fecha'] ?? '');

$query_historial = "SELECT * FROM registros_parqueo WHERE 1=1";
$params = [];

if (!empty($buscar_placa)) {
    $query_historial .= " AND placa LIKE :placa";
    $params['placa'] = "%$buscar_placa%";
}
if (!empty($buscar_correo)) {
    $query_historial .= " AND correo LIKE :correo";
    $params['correo'] = "%$buscar_correo%";
}
if (!empty($buscar_fecha)) {
    $query_historial .= " AND DATE(fecha_ingreso) = :fecha";
    $params['fecha'] = $buscar_fecha;
}

$query_historial .= " ORDER BY id DESC LIMIT 50";
$stmt_historial = $pdo->prepare($query_historial);
$stmt_historial->execute($params);
$registros_filtrados = $stmt_historial->fetchAll();

$navbar_title = 'Admin Terminal';
$navbar_home  = 'admin.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartPark Pro - Admin Control</title>
    <link rel="stylesheet" href="assets/css/main.css?v=34">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body class="cyber-body">
<?php include 'partials/navbar.php'; ?>

<div class="container cyber-entrance" style="padding-top: 2rem;">

    <?php if (!empty($mensaje)): ?>
        <div class="pill-cyber" style="border-color: var(--primary-green); color: var(--primary-green); margin-bottom: 1rem;">
            <?= htmlspecialchars($mensaje) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="cyber-alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Tarjetas de Métricas -->
    <div class="grid-3" style="margin-bottom: 2rem;">
        <div class="cyber-card">
            <h4>📊 Total Registros Históricos</h4>
            <p style="font-size: 2.2rem; font-weight: 700; color: var(--primary-cyan);"><?= (int) $total_ingresos ?></p>
        </div>
        <div class="cyber-card">
            <h4>🟩 Celdas Libres / Ocupadas</h4>
            <p style="font-size: 1.8rem; font-weight: 700; color: var(--primary-green);">
                <?= (int) $stats_cupos['disponibles'] ?> Libres <span style="font-size: 1rem; color: var(--text-muted);">/ <?= (int) $stats_cupos['ocupados'] ?> Ocupadas</span>
            </p>
        </div>
        <div class="cyber-card">
            <h4>🛠️ Celdas Bloqueadas</h4>
            <p style="font-size: 2.2rem; font-weight: 700; color: #ef4444;"><?= (int) $stats_cupos['bloqueados'] ?></p>
        </div>
    </div>

    <!-- Plano visual y Gestión de Celdas -->
    <div class="cyber-card" style="margin-bottom: 3rem;">
        <h2 style="color: var(--primary-cyan); margin-bottom: 1rem;">Plano del Parqueadero (Modificación en Tiempo Real)</h2>
        <?php
        $editable = true;
        $highlight_codigo = null;
        include 'partials/floor_plan.php';
        ?>
    </div>

    <!-- Historial y Buscador de Registros -->
    <div class="cyber-card">
        <h2 style="color: var(--primary-cyan); margin-bottom: 1rem;">Buscador de Registros</h2>

        <form action="admin.php" method="GET" class="cyber-form" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
            <div>
                <label>Buscar por Placa:</label>
                <input type="text" name="buscar_placa" value="<?= htmlspecialchars($buscar_placa) ?>" placeholder="EJ: ABC123" style="text-transform: uppercase;">
            </div>
            <div>
                <label>Buscar por Correo:</label>
                <input type="text" name="buscar_correo" value="<?= htmlspecialchars($buscar_correo) ?>" placeholder="correo@domain.com">
            </div>
            <div>
                <label>Buscar por Fecha:</label>
                <input type="date" name="buscar_fecha" value="<?= htmlspecialchars($buscar_fecha) ?>">
            </div>
            <div style="display: flex; align-items: flex-end;">
                <button type="submit" class="cyber-btn-primary" style="width: 100%;">Filtrar</button>
            </div>
        </form>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;">
                <thead>
                <tr style="border-bottom: 1px solid var(--border-cyber); color: var(--primary-cyan);">
                    <th style="padding: 0.8rem;">ID</th>
                    <th>Placa</th>
                    <th>Modelo</th>
                    <th>Correo</th>
                    <th>Tipo</th>
                    <th>Celda</th>
                    <th>Fecha Ingreso</th>
                    <th>Estado</th>
                    <th>Pago</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registros_filtrados as $reg): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <td style="padding: 0.8rem;"><?= (int) $reg['id'] ?></td>
                        <td><strong><?= htmlspecialchars($reg['placa']) ?></strong></td>
                        <td><?= htmlspecialchars($reg['modelo'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($reg['correo'] ?? 'N/A') ?></td>
                        <td><?= ucfirst($reg['tipo_vehiculo']) ?></td>
                        <td><span class="pill-cyber" style="padding: 0.1rem 0.4rem; font-size: 0.75rem;"><?= htmlspecialchars($reg['cupo_asignado']) ?></span></td>
                        <td><?= htmlspecialchars($reg['fecha_ingreso']) ?></td>
                        <td><?= strtoupper($reg['estado']) ?></td>
                        <td><?= ((int) $reg['estado_pago'] === 1) ? '✅' : '⏳' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($registros_filtrados)): ?>
                    <tr>
                        <td colspan="9" style="padding: 1.5rem; text-align: center; color: var(--text-muted);">No se encontraron registros con los criterios de búsqueda.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
