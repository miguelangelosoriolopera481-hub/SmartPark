<?php
/**
 * partials/floor_plan.php - Plano visual de celdas (reemplaza la antigua
 * "Matriz de Celdas" en orden alfabético por una disposición tipo plano,
 * agrupada por zona física con leyenda y detalle al pasar el cursor).
 *
 * Variables esperadas en scope antes del include:
 *   array       $celdas             filas de la tabla `cupos` (con placa/fecha_ingreso via LEFT JOIN opcional)
 *   bool        $editable           true en admin.php (permite bloquear/habilitar con un clic)
 *   string|null $highlight_codigo   código de cupo a resaltar (vehículo del usuario actual)
 *
 * Si $editable es true, cada celda incluye un mini-form POST hacia admin.php
 * con action_celda + csrf_field() (ya definidos por el llamador).
 */
$editable         = $editable ?? false;
$highlight_codigo = $highlight_codigo ?? null;

// Agrupa por zona (tipo) y ordena numéricamente por el sufijo del código
$zonas = ['carro' => [], 'moto' => []];
foreach ($celdas as $c) {
    $zonas[$c['tipo']][] = $c;
}
foreach ($zonas as $tipo => &$lista) {
    usort($lista, function ($a, $b) {
        $na = (int) preg_replace('/\D/', '', $a['codigo_cupo']);
        $nb = (int) preg_replace('/\D/', '', $b['codigo_cupo']);
        return $na <=> $nb;
    });
}
unset($lista);

$etiquetas = ['carro' => '🚗 Zona Carros', 'moto' => '🏍️ Zona Motos'];
$colores   = [
    'disponible'    => '#16a34a',
    'ocupado'       => '#dc2626',
    'mantenimiento' => '#d97706',
];
?>
<style>
    .floor-plan-legend { display: flex; gap: 1.2rem; flex-wrap: wrap; margin-bottom: 1.5rem; font-size: 0.82rem; color: var(--text-muted); }
    .floor-plan-legend span.dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 0.4rem; vertical-align: middle; }
    .floor-plan-zone { margin-bottom: 2rem; }
    .floor-plan-zone h3 { color: var(--primary-cyan); font-size: 1rem; margin-bottom: 0.9rem; letter-spacing: 0.03em; }
    .floor-plan-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
        gap: 0.7rem;
    }
    .floor-plan-cell {
        position: relative;
        border-radius: 8px;
        padding: 0.6rem 0.4rem;
        text-align: center;
        font-size: 0.78rem;
        font-weight: 700;
        color: white;
        border: 2px solid transparent;
        cursor: default;
        transition: transform 0.15s ease;
    }
    .floor-plan-cell:hover { transform: translateY(-2px); }
    .floor-plan-cell.highlight { border-color: var(--primary-cyan); box-shadow: 0 0 14px rgba(0,242,254,0.8); }
    .floor-plan-cell .fp-codigo { display: block; font-size: 0.85rem; }
    .floor-plan-cell .fp-placa { display: block; font-size: 0.65rem; font-weight: 400; margin-top: 0.2rem; opacity: 0.9; }
    .floor-plan-cell form { margin-top: 0.35rem; }
    .floor-plan-cell button {
        font-size: 0.62rem; padding: 0.15rem 0.3rem; border-radius: 4px; border: 1px solid rgba(255,255,255,0.6);
        background: rgba(0,0,0,0.25); color: white; cursor: pointer; width: 100%;
    }
    .floor-plan-cell button:hover { background: rgba(0,0,0,0.45); }
</style>

<div class="floor-plan-legend">
    <span><span class="dot" style="background:<?= $colores['disponible'] ?>;"></span>Disponible</span>
    <span><span class="dot" style="background:<?= $colores['ocupado'] ?>;"></span>Ocupado</span>
    <span><span class="dot" style="background:<?= $colores['mantenimiento'] ?>;"></span>Mantenimiento</span>
    <?php if ($highlight_codigo): ?>
        <span><span class="dot" style="background:transparent;border:2px solid var(--primary-cyan);"></span>Tu vehículo</span>
    <?php endif; ?>
</div>

<?php foreach (['carro', 'moto'] as $tipo): ?>
    <?php if (empty($zonas[$tipo])) { continue; } ?>
    <div class="floor-plan-zone">
        <h3><?= $etiquetas[$tipo] ?></h3>
        <div class="floor-plan-grid">
            <?php foreach ($zonas[$tipo] as $celda): ?>
                <?php
                $esResaltada = $highlight_codigo && $celda['codigo_cupo'] === $highlight_codigo;
                $titulo = $celda['estado'] === 'ocupado' && !empty($celda['placa'])
                    ? 'Placa: ' . $celda['placa'] . ($celda['fecha_ingreso'] ?? '' ? ' | Ingreso: ' . $celda['fecha_ingreso'] : '')
                    : ucfirst($celda['estado']);
                ?>
                <div class="floor-plan-cell<?= $esResaltada ? ' highlight' : '' ?>"
                     style="background: <?= $colores[$celda['estado']] ?? '#334155' ?>;"
                     title="<?= htmlspecialchars($titulo) ?>">
                    <span class="fp-codigo"><?= htmlspecialchars($celda['codigo_cupo']) ?></span>
                    <?php if ($celda['estado'] === 'ocupado' && !empty($celda['placa'])): ?>
                        <span class="fp-placa"><?= htmlspecialchars($celda['placa']) ?></span>
                    <?php endif; ?>

                    <?php if ($editable): ?>
                        <form action="admin.php" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action_celda" value="1">
                            <input type="hidden" name="codigo_cupo" value="<?= htmlspecialchars($celda['codigo_cupo']) ?>">
                            <?php if ($celda['estado'] === 'mantenimiento'): ?>
                                <button type="submit" name="nuevo_estado" value="disponible">Habilitar</button>
                            <?php elseif ($celda['estado'] === 'disponible'): ?>
                                <button type="submit" name="nuevo_estado" value="mantenimiento">Bloquear</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
