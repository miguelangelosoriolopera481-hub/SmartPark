<?php
if (empty($_SESSION['usuario_id'])) return;

$__esDeveloper = ($_SESSION['role'] ?? '') === 'developer';
$__rolEmpresa = $_SESSION['rol_en_empresa'] ?? '';
$__esAdmin = $__esDeveloper || in_array($__rolEmpresa, ['admin', 'superadmin'], true);
if (!$__esAdmin) return;
if (!isset($pdo)) return;

$__sedeActualId = (int)($_GET['parqueadero_id'] ?? 0);

if ($__esDeveloper) {
    $__stmt = $pdo->query("
        SELECT p.id, p.nombre, p.ciudad, p.empresa_id, e.nombre AS empresa_nombre,
               (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND estado = 'disponible') AS libres,
               (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id) AS total
        FROM parqueaderos p
        LEFT JOIN empresas e ON e.id = p.empresa_id
        WHERE p.estado = 'activo'
        ORDER BY e.nombre, p.nombre LIMIT 30
    ");
    $__sedes = $__stmt->fetchAll();
} else {
    $__eid = (int)($_SESSION['empresa_id'] ?? 0);
    $__stmt = $pdo->prepare("
        SELECT p.id, p.nombre, p.ciudad, p.empresa_id, NULL AS empresa_nombre,
               (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :e1 AND estado = 'disponible') AS libres,
               (SELECT COUNT(*) FROM cupos WHERE parqueadero_id = p.id AND empresa_id = :e2) AS total
        FROM parqueaderos p
        WHERE p.empresa_id = :eid AND p.estado = 'activo' ORDER BY p.nombre
    ");
    $__stmt->execute(['e1' => $__eid, 'e2' => $__eid, 'eid' => $__eid]);
    $__sedes = $__stmt->fetchAll();
}

if (empty($__sedes)) return;
?>
<style>
    .sede-selector { display: flex; gap: 0.75rem; overflow-x: auto; padding: 0.5rem 0.25rem 1rem; scrollbar-width: thin; scrollbar-color: rgba(16,185,129,0.4) transparent; scroll-behavior: smooth; }
    .sede-selector::-webkit-scrollbar { height: 6px; }
    .sede-selector::-webkit-scrollbar-thumb { background: rgba(16,185,129,0.4); border-radius: 3px; }
    .sede-chip { flex-shrink: 0; display: flex; flex-direction: column; padding: 0.85rem 1.1rem; min-width: 200px; background: rgba(15,23,42,0.7); border: 1.5px solid rgba(16,185,129,0.18); border-radius: 14px; text-decoration: none; color: #f1f5f9; transition: all 0.3s cubic-bezier(0.34,1.56,0.64,1); position: relative; overflow: hidden; cursor: pointer; }
    .sede-chip::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 3px; background: linear-gradient(180deg, #10b981, #06b6d4); opacity: 0.4; transition: opacity 0.3s; }
    .sede-chip:hover { border-color: rgba(16,185,129,0.55); transform: translateY(-3px); box-shadow: 0 10px 25px rgba(16,185,129,0.15); }
    .sede-chip:hover::before { opacity: 1; }
    .sede-chip.activa { background: linear-gradient(160deg, rgba(16,185,129,0.15), rgba(6,182,212,0.08)); border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
    .sede-chip.activa::before { opacity: 1; }
    .sede-chip.all-chip::before { background: linear-gradient(180deg, #a78bfa, #06b6d4); }
    .sede-chip.all-chip.activa { background: linear-gradient(160deg, rgba(167,139,250,0.15), rgba(6,182,212,0.08)); border-color: #a78bfa; box-shadow: 0 0 0 3px rgba(167,139,250,0.15); }
    .sede-chip .chip-empresa { font-size: 0.65rem; color: #a78bfa; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sede-chip .chip-nombre { font-size: 0.92rem; font-weight: 800; margin-bottom: 0.3rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sede-chip .chip-meta { font-size: 0.72rem; color: #94a3b8; display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    .sede-chip .chip-meta .libres { color: #6ee7b7; font-weight: 800; font-family: 'JetBrains Mono', monospace; }
    .sede-chip.activa .chip-meta .libres { color: #10b981; }
    .sede-chip .chip-ver { position: absolute; top: 0.5rem; right: 0.5rem; font-size: 0.6rem; color: #10b981; font-weight: 800; opacity: 0; transition: opacity 0.3s; }
    .sede-chip.activa .chip-ver { opacity: 1; }
    @media (max-width: 640px) { .sede-chip { min-width: 170px; padding: 0.7rem 0.85rem; } .sede-chip .chip-nombre { font-size: 0.85rem; } }
</style>

<div style="margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.4rem;">
        <span style="font-size: 0.72rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em;">◉ Selecciona una sede</span>
    </div>
    <div class="sede-selector">
        <?php if ($__esDeveloper): 
            $urlAll = '?parqueadero_id=0';
            $activaAll = ($__sedeActualId === 0) ? 'activa' : '';
        ?>
            <a href="<?= htmlspecialchars($urlAll) ?>" class="sede-chip all-chip <?= $activaAll ?>" onclick="seleccionarSede(this, 0); return false;">
                <span class="chip-empresa">◇ DEVELOPER</span>
                <span class="chip-nombre">Todas las sedes</span>
                <span class="chip-meta"><span class="libres"><?= count($__sedes) ?></span> parqueaderos</span>
                <span class="chip-ver">✓</span>
            </a>
        <?php endif; ?>

        <?php foreach ($__sedes as $s): 
            $activa = ($__sedeActualId === (int)$s['id']) ? 'activa' : '';
            $url = '?parqueadero_id=' . (int)$s['id'];
            $libres = (int)$s['libres'];
            $total = (int)$s['total'];
            $sinCeldas = ($total === 0);
        ?>
            <a href="<?= htmlspecialchars($url) ?>" class="sede-chip <?= $activa ?>" onclick="seleccionarSede(this, <?= (int)$s['id'] ?>); return false;" style="<?= $sinCeldas ? 'opacity: 0.7;' : '' ?>">
                <?php if ($__esDeveloper && !empty($s['empresa_nombre'])): ?>
                    <span class="chip-empresa"><?= htmlspecialchars($s['empresa_nombre']) ?></span>
                <?php endif; ?>
                <span class="chip-nombre"><?= htmlspecialchars($s['nombre']) ?></span>
                <span class="chip-meta">
                    <?php if (!empty($s['ciudad'])): ?><?= htmlspecialchars($s['ciudad']) ?> · <?php endif; ?>
                    <span class="libres"><?= $libres ?></span>/<?= $total ?> libres
                    <?php if ($sinCeldas): ?><span style="color: #f59e0b; font-weight: 800;">⚠ sin celdas</span><?php endif; ?>
                </span>
                <span class="chip-ver">✓</span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<script>
function seleccionarSede(el, sedeId) {
    // Marcar visualmente todas las tarjetas
    document.querySelectorAll('.sede-chip').forEach(c => c.classList.remove('activa'));
    el.classList.add('activa');

    // Actualizar URL sin recargar
    const url = new URL(window.location.href);
    if (sedeId === 0) {
        url.searchParams.delete('parqueadero_id');
    } else {
        url.searchParams.set('parqueadero_id', sedeId);
    }
    window.history.pushState({}, '', url);

    // Recargar la página para aplicar el filtro
    window.location.href = url.toString();
}
</script>