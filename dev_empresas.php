<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
send_security_headers();

if (empty($_SESSION['usuario_id']) || ($_SESSION['role'] ?? '') !== 'developer') {
    header('Location: login_developer.php');
    exit;
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';
        $eid = (int)($_POST['empresa_id'] ?? 0);

        if ($eid > 0) {
            try {
                if ($accion === 'aprobar') {
                    $pdo->prepare("UPDATE empresas SET estado_aprobacion='aprobada', fecha_aprobacion=NOW(), aprobado_por=:dev WHERE id=:id")
                        ->execute(['dev' => $_SESSION['usuario_id'], 'id' => $eid]);
                    $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id, developer_id, accion) VALUES (:e, :d, 'aprobada')")
                        ->execute(['e' => $eid, 'd' => $_SESSION['usuario_id']]);
                    $msg = 'Empresa aprobada.';
                } elseif ($accion === 'rechazar') {
                    $pdo->prepare("UPDATE empresas SET estado_aprobacion='rechazada' WHERE id=:id")->execute(['id' => $eid]);
                    $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id, developer_id, accion) VALUES (:e, :d, 'rechazada')")
                        ->execute(['e' => $eid, 'd' => $_SESSION['usuario_id']]);
                    $msg = 'Empresa rechazada.';
                } elseif ($accion === 'suspender') {
                    $pdo->prepare("UPDATE empresas SET estado='suspendida' WHERE id=:id")->execute(['id' => $eid]);
                    $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id, developer_id, accion) VALUES (:e, :d, 'suspendida')")
                        ->execute(['e' => $eid, 'd' => $_SESSION['usuario_id']]);
                    $msg = 'Empresa suspendida.';
                } elseif ($accion === 'reactivar') {
                    $pdo->prepare("UPDATE empresas SET estado='activa', estado_aprobacion='aprobada' WHERE id=:id")->execute(['id' => $eid]);
                    $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id, developer_id, accion) VALUES (:e, :d, 'reactivada')")
                        ->execute(['e' => $eid, 'd' => $_SESSION['usuario_id']]);
                    $msg = 'Empresa reactivada.';
                }
            } catch (\Throwable $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }
    }
}

$filtro = $_GET['filtro'] ?? 'todas';
$where = '';
if ($filtro === 'pendientes') $where = "WHERE e.estado_aprobacion = 'pendiente'";
elseif ($filtro === 'aprobadas') $where = "WHERE e.estado_aprobacion = 'aprobada'";
elseif ($filtro === 'rechazadas') $where = "WHERE e.estado_aprobacion = 'rechazada'";
elseif ($filtro === 'suspendidas') $where = "WHERE e.estado = 'suspendida'";

try {
    $empresas = $pdo->query("
        SELECT e.*,
               (SELECT COUNT(*) FROM usuarios_empresa ue WHERE ue.empresa_id = e.id) AS total_usuarios,
               (SELECT COUNT(*) FROM parqueaderos p WHERE p.empresa_id = e.id) AS total_parqueaderos,
               (SELECT COUNT(*) FROM cupos c WHERE c.empresa_id = e.id) AS total_cupos,
               (SELECT COUNT(*) FROM registros_parqueo rp WHERE rp.empresa_id = e.id) AS total_registros,
               (SELECT COALESCE(SUM(rp.total_pagar),0) FROM registros_parqueo rp WHERE rp.empresa_id = e.id AND rp.estado_pago=1) AS total_recaudo
        FROM empresas e
        $where
        ORDER BY CASE e.estado_aprobacion WHEN 'pendiente' THEN 1 WHEN 'aprobada' THEN 2 ELSE 3 END, e.fecha_creacion DESC
    ")->fetchAll();
} catch (\Throwable $e) { $empresas = []; }

$stats = ['todas'=>0,'pendientes'=>0,'aprobadas'=>0,'rechazadas'=>0,'suspendidas'=>0];
try {
    $stats['todas'] = (int)$pdo->query("SELECT COUNT(*) FROM empresas")->fetchColumn();
    $stats['pendientes'] = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado_aprobacion='pendiente'")->fetchColumn();
    $stats['aprobadas'] = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado_aprobacion='aprobada'")->fetchColumn();
    $stats['rechazadas'] = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado_aprobacion='rechazada'")->fetchColumn();
    $stats['suspendidas'] = (int)$pdo->query("SELECT COUNT(*) FROM empresas WHERE estado='suspendida'")->fetchColumn();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Empresas - Developer</title>
<link rel="stylesheet" href="assets/css/main.css?v=25">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1280px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { margin-bottom: 2rem; }
    .page-header .breadcrumb { color: #64748b; font-size: 0.82rem; margin-bottom: 0.75rem; }
    .page-header .breadcrumb a { color: #10b981; text-decoration: none; }
    .page-header h1 { font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 900; letter-spacing: -0.03em; background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.4rem; }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }

    .tabs { display: flex; gap: 0.5rem; margin: 1.5rem 0; flex-wrap: wrap; border-bottom: 1px solid rgba(16, 185, 129, 0.15); padding-bottom: 0.75rem; }
    .tab { padding: 0.6rem 1.15rem; border-radius: 10px; background: transparent; border: 1px solid transparent; color: #94a3b8; text-decoration: none; font-weight: 700; font-size: 0.85rem; transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 0.5rem; }
    .tab:hover { color: #10b981; background: rgba(16, 185, 129, 0.08); }
    .tab.active { color: #10b981; background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.4); }
    .tab .tab-count { background: rgba(16, 185, 129, 0.2); padding: 0.15rem 0.55rem; border-radius: 100px; font-size: 0.72rem; font-weight: 800; }
    .tab.active .tab-count { background: #10b981; color: #030712; }

    .empresa-card { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(16px); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 18px; padding: 1.5rem; margin-bottom: 1rem; transition: all 0.3s ease; position: relative; overflow: hidden; }
    .empresa-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: linear-gradient(180deg, #10b981, #06b6d4); }
    .empresa-card.pendiente::before { background: linear-gradient(180deg, #f59e0b, #f97316); }
    .empresa-card.rechazada::before { background: linear-gradient(180deg, #ef4444, #dc2626); }
    .empresa-card.suspendida::before { background: linear-gradient(180deg, #64748b, #475569); }
    .empresa-card:hover { border-color: rgba(16, 185, 129, 0.4); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.12); transform: translateY(-2px); }
    .empresa-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .empresa-info h3 { font-size: 1.15rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.35rem; }
    .empresa-meta { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.8rem; color: #64748b; }
    .badge-estado { padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; }
    .badge-estado.pendiente { background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.5); color: #fcd34d; }
    .badge-estado.aprobada { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.5); color: #6ee7b7; }
    .badge-estado.rechazada { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.5); color: #fca5a5; }
    .badge-estado.suspendida { background: rgba(100, 116, 139, 0.2); border: 1px solid rgba(100, 116, 139, 0.5); color: #cbd5e1; }
    .empresa-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 0.75rem; padding: 1rem 0; border-top: 1px solid rgba(255,255,255,0.05); border-bottom: 1px solid rgba(255,255,255,0.05); margin-bottom: 1rem; }
    .es-item { text-align: center; }
    .es-item .es-num { font-size: 1.3rem; font-weight: 800; color: #10b981; }
    .es-item .es-lbl { font-size: 0.65rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.15rem; }
    .empresa-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .btn-action { padding: 0.55rem 1rem; border-radius: 9px; font-size: 0.82rem; font-weight: 700; cursor: pointer; border: 1.5px solid; background: transparent; display: inline-flex; align-items: center; gap: 0.4rem; font-family: inherit; transition: all 0.25s ease; text-decoration: none; }
    .btn-aprobar { color: #10b981; border-color: #10b981; }
    .btn-aprobar:hover { background: #10b981; color: #030712; }
    .btn-rechazar { color: #ef4444; border-color: #ef4444; }
    .btn-rechazar:hover { background: #ef4444; color: #fff; }
    .btn-suspender { color: #f59e0b; border-color: #f59e0b; }
    .btn-suspender:hover { background: #f59e0b; color: #030712; }
    .btn-backup { color: #06b6d4; border-color: #06b6d4; }
    .btn-backup:hover { background: #06b6d4; color: #030712; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb"><a href="dashboard_developer.php">← Panel Developer</a></div>
    <h1>Empresas registradas</h1>
    <p class="sub">Administra el estado de cada parqueadero. Aprueba, rechaza o suspende según corresponda.</p>
  </div>

  <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="tabs">
    <a href="?filtro=todas" class="tab <?= $filtro === 'todas' ? 'active' : '' ?>">Todas <span class="tab-count"><?= $stats['todas'] ?></span></a>
    <a href="?filtro=pendientes" class="tab <?= $filtro === 'pendientes' ? 'active' : '' ?>">Pendientes <span class="tab-count"><?= $stats['pendientes'] ?></span></a>
    <a href="?filtro=aprobadas" class="tab <?= $filtro === 'aprobadas' ? 'active' : '' ?>">Aprobadas <span class="tab-count"><?= $stats['aprobadas'] ?></span></a>
    <a href="?filtro=rechazadas" class="tab <?= $filtro === 'rechazadas' ? 'active' : '' ?>">Rechazadas <span class="tab-count"><?= $stats['rechazadas'] ?></span></a>
    <a href="?filtro=suspendidas" class="tab <?= $filtro === 'suspendidas' ? 'active' : '' ?>">Suspendidas <span class="tab-count"><?= $stats['suspendidas'] ?></span></a>
  </div>

  <?php if (empty($empresas)): ?>
    <div class="empresa-card" style="text-align: center; padding: 3rem;">
      <p style="color: #64748b;">No hay empresas con ese filtro.</p>
    </div>
  <?php else: foreach ($empresas as $emp): ?>
    <?php
      $estadoAprob = $emp['estado_aprobacion'] ?? 'pendiente';
      $estadoGlobal = $emp['estado'] ?? 'activa';
      $claseEstado = $estadoGlobal === 'suspendida' ? 'suspendida' : $estadoAprob;
    ?>
    <div class="empresa-card <?= $claseEstado ?>">
      <div class="empresa-header">
        <div class="empresa-info">
          <h3><?= htmlspecialchars($emp['nombre']) ?></h3>
          <div class="empresa-meta">
            <?php if (!empty($emp['nit'])): ?><span>◆ NIT: <?= htmlspecialchars($emp['nit']) ?></span><?php endif; ?>
            <?php if (!empty($emp['telefono'])): ?><span>◆ <?= htmlspecialchars($emp['telefono']) ?></span><?php endif; ?>
            <?php if (!empty($emp['email_contacto'])): ?><span>◆ <?= htmlspecialchars($emp['email_contacto']) ?></span><?php endif; ?>
            <span>◆ Creada: <?= htmlspecialchars(substr($emp['fecha_creacion'] ?? '', 0, 10)) ?></span>
          </div>
        </div>
        <span class="badge-estado <?= $claseEstado ?>">
          <?php
            if ($estadoGlobal === 'suspendida') echo '⏸ Suspendida';
            elseif ($estadoAprob === 'pendiente') echo '⏳ Pendiente';
            elseif ($estadoAprob === 'aprobada') echo '✓ Aprobada';
            elseif ($estadoAprob === 'rechazada') echo '✕ Rechazada';
          ?>
        </span>
      </div>

      <div class="empresa-stats">
        <div class="es-item"><div class="es-num"><?= (int)($emp['total_usuarios'] ?? 0) ?></div><div class="es-lbl">Usuarios</div></div>
        <div class="es-item"><div class="es-num"><?= (int)($emp['total_parqueaderos'] ?? 0) ?></div><div class="es-lbl">Parqueaderos</div></div>
        <div class="es-item"><div class="es-num"><?= (int)($emp['total_cupos'] ?? 0) ?></div><div class="es-lbl">Celdas</div></div>
        <div class="es-item"><div class="es-num"><?= (int)($emp['total_registros'] ?? 0) ?></div><div class="es-lbl">Registros</div></div>
        <div class="es-item"><div class="es-num">$<?= number_format((float)($emp['total_recaudo'] ?? 0), 0, ',', '.') ?></div><div class="es-lbl">Recaudo</div></div>
      </div>

      <div class="empresa-actions">
        <?php if ($estadoAprob === 'pendiente'): ?>
          <form method="POST" style="display: inline;"><?= csrf_field() ?>
            <input type="hidden" name="accion" value="aprobar"><input type="hidden" name="empresa_id" value="<?= (int)$emp['id'] ?>">
            <button class="btn-action btn-aprobar" onclick="return confirm('¿Aprobar?');">✓ Aprobar</button>
          </form>
          <form method="POST" style="display: inline;"><?= csrf_field() ?>
            <input type="hidden" name="accion" value="rechazar"><input type="hidden" name="empresa_id" value="<?= (int)$emp['id'] ?>">
            <button class="btn-action btn-rechazar" onclick="return confirm('¿Rechazar?');">✕ Rechazar</button>
          </form>
        <?php elseif ($estadoAprob === 'aprobada' && $estadoGlobal === 'activa'): ?>
          <form method="POST" style="display: inline;"><?= csrf_field() ?>
            <input type="hidden" name="accion" value="suspender"><input type="hidden" name="empresa_id" value="<?= (int)$emp['id'] ?>">
            <button class="btn-action btn-suspender" onclick="return confirm('¿Suspender?');">⏸ Suspender</button>
          </form>
        <?php elseif ($estadoGlobal === 'suspendida'): ?>
          <form method="POST" style="display: inline;"><?= csrf_field() ?>
            <input type="hidden" name="accion" value="reactivar"><input type="hidden" name="empresa_id" value="<?= (int)$emp['id'] ?>">
            <button class="btn-action btn-aprobar">▶ Reactivar</button>
          </form>
        <?php endif; ?>

        <a href="dev_backups.php" class="btn-action btn-backup">⬇ Backup de esta empresa</a>
        <a href="exportar_reporte.php?tipo=empresa&empresa_id=<?= (int)$emp['id'] ?>" class="btn-action btn-backup" target="_blank">📄 Exportar informe</a>
      </div>
    </div>
  <?php endforeach; endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
