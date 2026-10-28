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

// Filtros
$fRol = $_GET['rol'] ?? '';
$fBuscar = trim($_GET['buscar'] ?? '');
$fEmpresa = (int)($_GET['empresa_id'] ?? 0);

$where = ['1=1'];
$params = [];

if ($fRol !== '') { $where[] = 'u.role = :rol'; $params['rol'] = $fRol; }
if ($fBuscar !== '') {
    $where[] = '(u.username LIKE :b OR u.nombre LIKE :b OR u.correo LIKE :b)';
    $params['b'] = '%' . $fBuscar . '%';
}

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.nombre, u.correo, u.role, u.estado, u.fecha_creacion,
               (SELECT COUNT(*) FROM usuarios_empresa ue WHERE ue.user_id = u.id) AS total_empresas
        FROM user u
        WHERE $whereSQL
        ORDER BY u.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();
} catch (\Throwable $e) { $usuarios = []; }

// Para cada usuario, obtener sus parqueaderos
$vinculosPorUsuario = [];
try {
    if (!empty($usuarios)) {
        $ids = array_column($usuarios, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            SELECT ue.user_id, ue.rol_en_empresa, ue.activo,
                   e.nombre AS empresa_nombre, e.id AS empresa_id,
                   p.nombre AS parqueadero_nombre, p.id AS parqueadero_id
            FROM usuarios_empresa ue
            JOIN empresas e ON e.id = ue.empresa_id
            LEFT JOIN parqueaderos p ON p.id = ue.parqueadero_id
            WHERE ue.user_id IN ($placeholders)
            ORDER BY e.nombre
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $vinculosPorUsuario[$row['user_id']][] = $row;
        }
    }
} catch (\Throwable $e) {}

// Stats por rol
$statsRoles = ['total'=>0, 'admin'=>0, 'cliente'=>0, 'operador'=>0, 'developer'=>0, 'vigilante'=>0, 'finanzas'=>0];
try {
    $statsRoles['total'] = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
    foreach (['admin','cliente','operador','developer','vigilante','finanzas'] as $r) {
        $statsRoles[$r] = (int)$pdo->query("SELECT COUNT(*) FROM user WHERE role='$r'")->fetchColumn();
    }
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Usuarios - Developer</title>
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

    .roles-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }
    .rol-card {
        background: rgba(15, 23, 42, 0.7);
        border: 1px solid rgba(16, 185, 129, 0.18);
        border-radius: 12px;
        padding: 0.9rem 0.75rem;
        text-align: center;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.25s ease;
    }
    .rol-card:hover { border-color: rgba(16, 185, 129, 0.5); transform: translateY(-3px); }
    .rol-card.active { border-color: #10b981; background: rgba(16, 185, 129, 0.12); }
    .rol-card .rn { font-size: 1.4rem; font-weight: 900; color: #10b981; line-height: 1; }
    .rol-card .rl { font-size: 0.68rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.35rem; font-weight: 700; }

    .filters-card {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }
    .filters-grid {
        display: grid;
        grid-template-columns: 1fr 2fr auto;
        gap: 1rem;
        align-items: end;
    }
    .filter-item label { display: block; font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; margin-bottom: 0.4rem; }
    .filter-item input, .filter-item select { width: 100%; padding: 0.7rem 0.9rem; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 9px; color: #f1f5f9; font-family: inherit; font-size: 0.88rem; }
    .filter-item input:focus, .filter-item select:focus { border-color: #10b981; outline: none; }

    .user-card {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }
    .user-card:hover { border-color: rgba(16, 185, 129, 0.4); box-shadow: 0 10px 30px rgba(16, 185, 129, 0.1); }

    .user-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .user-info { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .user-avatar {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #030712;
        font-weight: 900;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .user-info h3 { font-size: 1.05rem; font-weight: 800; color: #f1f5f9; margin-bottom: 0.15rem; }
    .user-info .username { color: #10b981; font-family: monospace; font-size: 0.85rem; }
    .user-info .email { color: #94a3b8; font-size: 0.82rem; }

    .role-badge {
        display: inline-block;
        padding: 0.3rem 0.75rem;
        border-radius: 100px;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .role-badge.developer { background: rgba(167, 139, 250, 0.15); color: #a78bfa; border: 1px solid rgba(167, 139, 250, 0.4); }
    .role-badge.admin { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.4); }
    .role-badge.cliente { background: rgba(100, 116, 139, 0.15); color: #cbd5e1; border: 1px solid rgba(100, 116, 139, 0.4); }
    .role-badge.operador { background: rgba(6, 182, 212, 0.15); color: #67e8f9; border: 1px solid rgba(6, 182, 212, 0.4); }
    .role-badge.vigilante { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.4); }
    .role-badge.finanzas { background: rgba(59, 130, 246, 0.15); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.4); }

    .vinculos-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
    }
    .vinculo-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.6rem 0.9rem;
        background: rgba(2, 6, 23, 0.5);
        border-radius: 10px;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .vinculo-item .v-info { display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; font-size: 0.82rem; color: #cbd5e1; }
    .vinculo-item .v-emp { font-weight: 700; color: #10b981; }
    .vinculo-item .v-parq { color: #94a3b8; }
    .vinculo-item .v-rol { font-size: 0.7rem; padding: 0.15rem 0.5rem; border-radius: 6px; background: rgba(16, 185, 129, 0.12); color: #10b981; font-weight: 700; }
    .no-vinculos { color: #64748b; font-size: 0.82rem; font-style: italic; }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb"><a href="dashboard_developer.php">← Panel Developer</a></div>
    <h1>Usuarios del sistema</h1>
    <p class="sub">Todas las cuentas registradas con sus parqueaderos y roles asociados.</p>
  </div>

  <!-- STATS POR ROL -->
  <div class="roles-row">
    <a href="?rol=" class="rol-card <?= $fRol === '' ? 'active' : '' ?>">
      <div class="rn"><?= $statsRoles['total'] ?></div>
      <div class="rl">Todos</div>
    </a>
    <a href="?rol=admin" class="rol-card <?= $fRol === 'admin' ? 'active' : '' ?>">
      <div class="rn"><?= $statsRoles['admin'] ?></div>
      <div class="rl">Admins</div>
    </a>
    <a href="?rol=cliente" class="rol-card <?= $fRol === 'cliente' ? 'active' : '' ?>">
      <div class="rn"><?= $statsRoles['cliente'] ?></div>
      <div class="rl">Clientes</div>
    </a>
    <a href="?rol=operador" class="rol-card <?= $fRol === 'operador' ? 'active' : '' ?>">
      <div class="rn"><?= $statsRoles['operador'] ?></div>
      <div class="rl">Operadores</div>
    </a>
    <a href="?rol=vigilante" class="rol-card <?= $fRol === 'vigilante' ? 'active' : '' ?>">
      <div class="rn"><?= $statsRoles['vigilante'] ?></div>
      <div class="rl">Vigilantes</div>
    </a>
    <a href="?rol=finanzas" class="rol-card <?= $fRol === 'finanzas' ? 'active' : '' ?>">
      <div class="rn"><?= $statsRoles['finanzas'] ?></div>
      <div class="rl">Finanzas</div>
    </a>
    <a href="?rol=developer" class="rol-card <?= $fRol === 'developer' ? 'active' : '' ?>">
      <div class="rn"><?= $statsRoles['developer'] ?></div>
      <div class="rl">Developers</div>
    </a>
  </div>

  <!-- FILTROS -->
  <div class="filters-card">
    <form method="GET" class="filters-grid">
      <div class="filter-item">
        <label>Buscar</label>
        <input type="text" name="buscar" value="<?= htmlspecialchars($fBuscar) ?>" placeholder="Usuario, nombre o correo...">
      </div>
      <div class="filter-item">
        <label>Rol actual</label>
        <select name="rol">
          <option value="">Todos los roles</option>
          <option value="admin" <?= $fRol === 'admin' ? 'selected' : '' ?>>Admin</option>
          <option value="cliente" <?= $fRol === 'cliente' ? 'selected' : '' ?>>Cliente</option>
          <option value="operador" <?= $fRol === 'operador' ? 'selected' : '' ?>>Operador</option>
          <option value="vigilante" <?= $fRol === 'vigilante' ? 'selected' : '' ?>>Vigilante</option>
          <option value="finanzas" <?= $fRol === 'finanzas' ? 'selected' : '' ?>>Finanzas</option>
          <option value="developer" <?= $fRol === 'developer' ? 'selected' : '' ?>>Developer</option>
        </select>
      </div>
      <div class="filter-actions" style="display: flex; gap: 0.5rem;">
        <button type="submit" class="cyber-btn-primary">🔍 Buscar</button>
        <a href="dev_usuarios.php" class="cyber-btn-outline">Limpiar</a>
      </div>
    </form>
  </div>

  <!-- LISTA DE USUARIOS -->
  <?php if (empty($usuarios)): ?>
    <div class="user-card" style="text-align: center; padding: 3rem;">
      <p style="color: #64748b;">No hay usuarios que coincidan con los filtros.</p>
    </div>
  <?php else: foreach ($usuarios as $u): ?>
    <div class="user-card">
      <div class="user-header">
        <div class="user-info">
          <div class="user-avatar"><?= strtoupper(substr($u['nombre'] ?? $u['username'], 0, 1)) ?></div>
          <div>
            <h3><?= htmlspecialchars($u['nombre']) ?></h3>
            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
              <span class="username">@<?= htmlspecialchars($u['username']) ?></span>
              <?php if (!empty($u['correo'])): ?><span class="email"><?= htmlspecialchars($u['correo']) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
          <span class="role-badge <?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
          <span style="color: #64748b; font-size: 0.78rem;">#<?= (int)$u['id'] ?></span>
        </div>
      </div>

      <?php $vinculos = $vinculosPorUsuario[$u['id']] ?? []; ?>
      <?php if (empty($vinculos)): ?>
        <div class="vinculos-list">
          <p class="no-vinculos">Sin parqueaderos asociados</p>
        </div>
      <?php else: ?>
        <div class="vinculos-list">
          <?php foreach ($vinculos as $v): ?>
            <div class="vinculo-item">
              <div class="v-info">
                <span class="v-emp">◉ <?= htmlspecialchars($v['empresa_nombre']) ?></span>
                <?php if (!empty($v['parqueadero_nombre'])): ?>
                  <span class="v-parq">→ ⬚ <?= htmlspecialchars($v['parqueadero_nombre']) ?></span>
                <?php else: ?>
                  <span class="v-parq">→ (todas las sedes)</span>
                <?php endif; ?>
              </div>
              <span class="v-rol"><?= htmlspecialchars($v['rol_en_empresa']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
