<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
require_once 'helpers.php';
require_once 'helpers_url.php';
send_security_headers();

require_role(['admin', 'superadmin']);
require_password_change_guard();

$eid = current_empresa_id();
if (!$eid) { header('Location: login_empresa.php'); exit; }

// Info empresa
$stmt = $pdo->prepare("SELECT nombre, nit FROM empresas WHERE id = :eid");
$stmt->execute(['eid' => $eid]);
$empresa = $stmt->fetch() ?: ['nombre' => 'Mi Empresa', 'nit' => ''];

$msg = ''; $error = '';

// Datos del formulario (por si hay error, repoblar)
$form = [
    'nombre' => '', 'ciudad' => '', 'direccion' => '', 'telefono' => '',
    'email_contacto' => '', 'nit_sede' => '', 'horario_apertura' => '06:00', 'horario_cierre' => '22:00',
    'niveles' => 1, 'tipo_parqueadero' => 'cubierto', 'acepta_reservas' => 0,
    'tiene_discapacitados' => 0, 'observaciones' => '',
    'celdas' => [
        1 => ['carro' => 10, 'moto' => 10, 'bici' => 0, 'electrico' => 0]
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        // Recoger datos
        $form['nombre']    = trim($_POST['nombre'] ?? '');
        $form['ciudad']    = trim($_POST['ciudad'] ?? '');
        $form['direccion'] = trim($_POST['direccion'] ?? '');
        $form['telefono']  = trim($_POST['telefono'] ?? '');
        $form['email_contacto'] = trim($_POST['email_contacto'] ?? '');
        $form['nit_sede']       = trim($_POST['nit_sede'] ?? '');
        $form['horario_apertura'] = trim($_POST['horario_apertura'] ?? '06:00');
        $form['horario_cierre']   = trim($_POST['horario_cierre'] ?? '22:00');
        $form['tipo_parqueadero'] = $_POST['tipo_parqueadero'] ?? 'cubierto';
        $form['acepta_reservas']  = !empty($_POST['acepta_reservas']) ? 1 : 0;
        $form['tiene_discapacitados'] = !empty($_POST['tiene_discapacitados']) ? 1 : 0;
        $form['observaciones']    = trim($_POST['observaciones'] ?? '');
        $form['niveles']   = max(1, min(20, (int)($_POST['niveles'] ?? 1)));

        $celdasPost = $_POST['celdas'] ?? [];
        $celdas = [];
        for ($n = 1; $n <= $form['niveles']; $n++) {
            $celdas[$n] = [
                'carro'     => max(0, (int)($celdasPost[$n]['carro'] ?? 0)),
                'moto'      => max(0, (int)($celdasPost[$n]['moto'] ?? 0)),
                'bici'      => max(0, (int)($celdasPost[$n]['bici'] ?? 0)),
                'electrico' => max(0, (int)($celdasPost[$n]['electrico'] ?? 0)),
            ];
        }
        $form['celdas'] = $celdas;

        if ($form['nombre'] === '') {
            $error = 'El nombre de la sede es obligatorio.';
        } elseif ($form['niveles'] < 1 || $form['niveles'] > 20) {
            $error = 'Los niveles deben estar entre 1 y 20.';
        } else {
            // Contar celdas totales
            $totalCeldas = 0;
            foreach ($celdas as $c) {
                $totalCeldas += $c['carro'] + $c['moto'] + $c['bici'] + $c['electrico'];
            }

            if ($totalCeldas === 0) {
                $error = 'Debes configurar al menos 1 celda.';
            } elseif ($totalCeldas > 500) {
                $error = 'Máximo 500 celdas por sede.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $qrToken = bin2hex(random_bytes(16));
                    $stmt = $pdo->prepare("
                        INSERT INTO parqueaderos 
                        (empresa_id, nombre, ciudad, direccion, telefono, niveles, capacidad_total, estado, qr_token, fecha_creacion)
                        VALUES (:eid, :nombre, :ciudad, :direccion, :telefono, :niveles, :capacidad, 'activo', :qr, NOW())
                    ");
                    $stmt->execute([
                        'eid' => $eid,
                        'nombre' => $form['nombre'],
                        'ciudad' => $form['ciudad'] ?: null,
                        'direccion' => $form['direccion'] ?: null,
                        'telefono' => $form['telefono'] ?: null,
                        'niveles' => $form['niveles'],
                        'capacidad' => $totalCeldas,
                        'qr' => $qrToken,
                    ]);

                    try {
                        $pdo->prepare("
                            UPDATE parqueaderos 
                            SET email_contacto = :em, nit_sede = :nit, horario_apertura = :ha, horario_cierre = :hc,
                                tipo_parqueadero = :tp, acepta_reservas = :ar, tiene_discapacitados = :td,
                                observaciones = :obs
                            WHERE id = :id
                        ")->execute([
                            'em' => $form['email_contacto'] ?: null,
                            'nit' => $form['nit_sede'] ?: null,
                            'ha' => $form['horario_apertura'],
                            'hc' => $form['horario_cierre'],
                            'tp' => $form['tipo_parqueadero'],
                            'ar' => $form['acepta_reservas'],
                            'td' => $form['tiene_discapacitados'],
                            'obs' => $form['observaciones'] ?: null,
                            'id' => $nuevoParqueaderoId,
                        ]);
                    } catch (\Throwable $eMeta) {
                        // Si las columnas no existen, seguimos sin metadata extendida
                        error_log('metadata sede: ' . $eMeta->getMessage());
                    }
                    $nuevoParqueaderoId = (int)$pdo->lastInsertId();
                    $stmtCupo = $pdo->prepare("
                        INSERT INTO cupos (empresa_id, parqueadero_id, codigo_cupo, tipo, estado)
                        VALUES (:eid, :pid, :codigo, :tipo, 'disponible')
                    ");

                    $contadorPorTipo = ['carro' => 0, 'moto' => 0, 'bicicleta' => 0, 'electrico' => 0];
                    $prefijos = ['carro' => 'A', 'moto' => 'M', 'bicicleta' => 'B', 'electrico' => 'E'];
                    $totalInsertados = 0;

                    for ($nivel = 1; $nivel <= $form['niveles']; $nivel++) {
                        foreach ($celdas[$nivel] as $tipo => $cantidad) {
                            if ($cantidad <= 0) continue;
                            $tipoCupo = ($tipo === 'bici') ? 'bicicleta' : $tipo;
                            $prefijo = $prefijos[$tipoCupo];

                            for ($i = 1; $i <= $cantidad; $i++) {
                                $contadorPorTipo[$tipoCupo]++;
                                $num = str_pad((string)$contadorPorTipo[$tipoCupo], 2, '0', STR_PAD_LEFT);
                                // Ej: N1A01, N1M01, N2A01
                                $codigo = 'N' . $nivel . $prefijo . $num;

                                $stmtCupo->execute([
                                    'eid' => $eid,
                                    'pid' => $nuevoParqueaderoId,
                                    'codigo' => $codigo,
                                    'tipo' => $tipoCupo,
                                ]);
                                $totalInsertados++;
                            }
                        }
                    }
                    // No se insertan tarifas nuevas por sede. Si la empresa no tiene
                    // ninguna, se crean las por defecto SIN parqueadero_id.
                    $stmtTar = $pdo->prepare("SELECT COUNT(*) FROM tarifas WHERE empresa_id = :eid");
                    $stmtTar->execute(['eid' => $eid]);
                    $tieneTarifas = (int)$stmtTar->fetchColumn() > 0;

                    if (!$tieneTarifas) {
                        
                        $tarifasBase = [
                            ['tipo_vehiculo' => 'carro',     'tipo_tarifa' => 'hora', 'precio' => 4000.00],
                            ['tipo_vehiculo' => 'moto',      'tipo_tarifa' => 'hora', 'precio' => 2000.00],
                            ['tipo_vehiculo' => 'bicicleta', 'tipo_tarifa' => 'hora', 'precio' => 1000.00],
                            ['tipo_vehiculo' => 'electrico', 'tipo_tarifa' => 'hora', 'precio' => 5000.00],
                        ];
                        $stmtInsTar = $pdo->prepare("
                            INSERT INTO tarifas (empresa_id, parqueadero_id, tipo_vehiculo, tipo_tarifa, precio, activo)
                            VALUES (:eid, NULL, :tv, :tt, :p, 1)
                        ");
                        foreach ($tarifasBase as $t) {
                            $stmtInsTar->execute([
                                'eid' => $eid,
                                'tv' => $t['tipo_vehiculo'],
                                'tt' => $t['tipo_tarifa'],
                                'p' => $t['precio'],
                            ]);
                        }
                    }

                    $pdo->commit();

                    registrarAuditoria($pdo, 'crear_sede', "sede={$form['nombre']} celdas=$totalInsertados");

                    $_SESSION['sede_creada'] = [
                        'nombre' => $form['nombre'],
                        'parqueadero_id' => $nuevoParqueaderoId,
                        'total_celdas' => $totalInsertados,
                        'qr_token' => $qrToken,
                    ];

                    header('Location: admin_sede_creada.php');
                    exit;

                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('admin_crear_sede: ' . $e->getMessage());
                    $error = 'Error al crear la sede: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Crear Sede - <?= htmlspecialchars($empresa['nombre']) ?></title>
<link rel="stylesheet" href="assets/css/main.css?v=52">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 900px; margin: 0 auto; padding: 0 1.5rem; }

    .page-header {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(6, 182, 212, 0.05));
        border: 1.5px solid rgba(16, 185, 129, 0.3);
        border-radius: 22px;
        padding: 1.75rem 2rem;
        margin-bottom: 2rem;
    }
    .page-header .badge {
        display: inline-block;
        padding: 0.3rem 0.85rem;
        background: rgba(16, 185, 129, 0.15);
        border: 1px solid rgba(16, 185, 129, 0.4);
        border-radius: 100px;
        color: #10b981;
        font-size: 0.72rem;
        font-weight: 800;
        margin-bottom: 0.6rem;
    }
    .page-header h1 {
        font-size: 2rem;
        font-weight: 900;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin: 0 0 0.3rem;
    }
    .page-header p { color: #94a3b8; font-size: 0.9rem; margin: 0; }

    .section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
    }
    .section h2 {
        font-size: 1.05rem;
        font-weight: 800;
        color: #f1f5f9;
        margin: 0 0 0.35rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .section .sub { color: #64748b; font-size: 0.82rem; margin-bottom: 1.25rem; }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1rem;
    }
    .form-group { margin-bottom: 0; }
    .form-group label {
        display: block;
        font-size: 0.82rem;
        color: #cbd5e1;
        font-weight: 700;
        margin-bottom: 0.4rem;
    }
    .form-group input, .form-group select {
        width: 100%;
        padding: 0.85rem 1rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 11px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.92rem;
        outline: none;
        box-sizing: border-box;
        transition: all 0.25s ease;
    }
    .form-group input:focus, .form-group select:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
    }

    /* Tabla de niveles */
    .niveles-tabla {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    .niveles-tabla th {
        padding: 0.75rem;
        text-align: center;
        color: #10b981;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 800;
        background: rgba(16, 185, 129, 0.05);
        border-bottom: 1px solid rgba(16, 185, 129, 0.2);
    }
    .niveles-tabla td {
        padding: 0.6rem 0.5rem;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        text-align: center;
    }
    .niveles-tabla td:first-child {
        color: #10b981;
        font-weight: 800;
        font-family: 'JetBrains Mono', monospace;
        text-align: left;
        padding-left: 1rem;
    }
    .niveles-tabla input {
        width: 70px;
        padding: 0.55rem 0.5rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 8px;
        color: #f1f5f9;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.9rem;
        font-weight: 700;
        text-align: center;
        outline: none;
    }
    .niveles-tabla input:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
    }

    .total-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.25rem;
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.3);
        border-radius: 12px;
        margin-top: 1rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .total-info .lbl { color: #94a3b8; font-size: 0.85rem; font-weight: 700; }
    .total-info .val { color: #10b981; font-size: 1.35rem; font-weight: 900; font-family: 'JetBrains Mono', monospace; }

    .btn-grande {
        width: 100%;
        padding: 1.15rem;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #030712;
        border: none;
        border-radius: 14px;
        font-weight: 900;
        font-size: 1rem;
        cursor: pointer;
        font-family: inherit;
        box-shadow: 0 10px 30px rgba(16, 185, 129, 0.4);
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        margin-top: 1.5rem;
    }
    .btn-grande:hover { transform: translateY(-3px); box-shadow: 0 15px 40px rgba(16, 185, 129, 0.55); }

    .aviso {
        padding: 0.9rem 1.1rem;
        border-radius: 12px;
        margin-bottom: 1.25rem;
        font-size: 0.85rem;
        line-height: 1.5;
    }
    .aviso.info { background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6, 182, 212, 0.3); color: #67e8f9; }

    @media (max-width: 640px) {
        .niveles-tabla th, .niveles-tabla td { padding: 0.4rem 0.2rem; font-size: 0.7rem; }
        .niveles-tabla input { width: 50px; padding: 0.45rem 0.3rem; font-size: 0.82rem; }
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="admin">

<canvas id="particles-canvas"></canvas>

<div class="container">

    <div class="page-header">
        <span class="badge">◉ <?= htmlspecialchars($empresa['nombre']) ?></span>
        <h1>Crear Nueva Sede</h1>
        <p>Configura un nuevo parqueadero con celdas personalizadas por nivel</p>
    </div>

    <?php if ($error): ?>
        <div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="aviso info">
        <strong>◆ Tip:</strong> Cada nivel es un piso, sótano o zona de tu parqueadero. Los códigos de celda se generan automáticamente como <strong>N1A01</strong>, <strong>N1M01</strong>, <strong>N2A01</strong>...
    </div>

    <form method="POST" autocomplete="off">
        <?= csrf_field() ?>

        <!-- DATOS BÁSICOS -->
        <div class="section">
            <h2>◆ Datos de la sede</h2>
            <p class="sub">Información básica del nuevo parqueadero</p>

            <div class="form-grid">
                <div class="form-group">
                    <label>Nombre de la sede *</label>
                    <input type="text" name="nombre" required maxlength="150" placeholder="Ej: Sede Poblado" value="<?= htmlspecialchars($form['nombre']) ?>" autofocus>
                </div>
                <div class="form-group">
                    <label>NIT de la sede <span style="color: #64748b; font-weight: 400;">(opcional)</span></label>
                    <input type="text" name="nit_sede" maxlength="30" placeholder="Ej: 900123456-7" value="<?= htmlspecialchars($form['nit_sede']) ?>">
                </div>
                <div class="form-group">
                    <label>Ciudad</label>
                    <input type="text" name="ciudad" maxlength="100" placeholder="Ej: Medellín" value="<?= htmlspecialchars($form['ciudad']) ?>">
                </div>
                <div class="form-group">
                    <label>Dirección</label>
                    <input type="text" name="direccion" maxlength="200" placeholder="Ej: Calle 10 #43-12" value="<?= htmlspecialchars($form['direccion']) ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" maxlength="30" placeholder="Ej: +57 300 1234567" value="<?= htmlspecialchars($form['telefono']) ?>">
                </div>
                <div class="form-group">
                    <label>Correo de la sede</label>
                    <input type="email" name="email_contacto" maxlength="150" placeholder="sede@ejemplo.com" value="<?= htmlspecialchars($form['email_contacto']) ?>">
                </div>
                <div class="form-group">
                    <label>Hora de apertura</label>
                    <input type="time" name="horario_apertura" value="<?= htmlspecialchars($form['horario_apertura']) ?>">
                </div>
                <div class="form-group">
                    <label>Hora de cierre</label>
                    <input type="time" name="horario_cierre" value="<?= htmlspecialchars($form['horario_cierre']) ?>">
                </div>
                <div class="form-group">
                    <label>Tipo de parqueadero</label>
                    <select name="tipo_parqueadero">
                        <option value="cubierto" <?= $form['tipo_parqueadero'] === 'cubierto' ? 'selected' : '' ?>>Cubierto</option>
                        <option value="descubierto" <?= $form['tipo_parqueadero'] === 'descubierto' ? 'selected' : '' ?>>Descubierto</option>
                        <option value="mixto" <?= $form['tipo_parqueadero'] === 'mixto' ? 'selected' : '' ?>>Mixto</option>
                        <option value="subterraneo" <?= $form['tipo_parqueadero'] === 'subterraneo' ? 'selected' : '' ?>>Subterráneo</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Número de niveles / sótanos *</label>
                    <input type="number" name="niveles" id="inputNiveles" min="1" max="20" value="<?= (int)$form['niveles'] ?>" required>
                </div>
            </div>

            <div class="form-grid" style="margin-top: 1rem;">
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                        <input type="checkbox" name="acepta_reservas" value="1" <?= $form['acepta_reservas'] ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #10b981;">
                        <span>Acepta reservas anticipadas</span>
                    </label>
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                        <input type="checkbox" name="tiene_discapacitados" value="1" <?= $form['tiene_discapacitados'] ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #10b981;">
                        <span>Tiene celdas para discapacitados</span>
                    </label>
                </div>
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <label>Observaciones / Notas internas</label>
                <textarea name="observaciones" rows="3" maxlength="500" placeholder="Ej: Acceso por la calle 10, timbre en la puerta azul..." style="width: 100%; padding: 0.85rem 1rem; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(148, 163, 184, 0.15); border-radius: 11px; color: #f1f5f9; font-family: inherit; font-size: 0.92rem; outline: none; resize: vertical;"><?= htmlspecialchars($form['observaciones']) ?></textarea>
            </div>
        </div>

        <!-- CONFIGURACIÓN DE CELDAS -->
        <div class="section">
            <h2>▢ Celdas por nivel</h2>
            <p class="sub">Define cuántas celdas de cada tipo quieres en cada nivel</p>

            <div style="overflow-x: auto;">
                <table class="niveles-tabla" id="tablaNiveles">
                    <thead>
                        <tr>
                            <th style="text-align: left; padding-left: 1rem;">Nivel</th>
                            <th>🚗 Carro</th>
                            <th>🏍️ Moto</th>
                            <th>🚲 Bici</th>
                            <th>⚡ Eléctrico</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyNiveles">
                        <!-- Se genera con JavaScript -->
                    </tbody>
                </table>
            </div>

            <div class="total-info">
                <span class="lbl">Total de celdas a crear:</span>
                <span class="val" id="totalCeldas">0</span>
            </div>
        </div>

        <button type="submit" class="btn-grande">✓ Crear Sede</button>
    </form>

</div>

<script>
(function() {
    'use strict';
    const inputNiveles = document.getElementById('inputNiveles');
    const tbody = document.getElementById('tbodyNiveles');
    const totalCeldas = document.getElementById('totalCeldas');

    const valoresIniciales = <?= json_encode($form['celdas'], JSON_UNESCAPED_UNICODE) ?>;

    function crearFila(nivel) {
        const c = valoresIniciales[nivel] || { carro: 0, moto: 0, bici: 0, electrico: 0 };
        return `
            <tr data-nivel="${nivel}">
                <td>Nivel ${nivel}</td>
                <td><input type="number" name="celdas[${nivel}][carro]" min="0" max="200" value="${c.carro}" data-tipo="carro"></td>
                <td><input type="number" name="celdas[${nivel}][moto]" min="0" max="200" value="${c.moto}" data-tipo="moto"></td>
                <td><input type="number" name="celdas[${nivel}][bici]" min="0" max="200" value="${c.bici}" data-tipo="bici"></td>
                <td><input type="number" name="celdas[${nivel}][electrico]" min="0" max="200" value="${c.electrico}" data-tipo="electrico"></td>
                <td class="subtotal" style="font-weight: 800; color: #10b981; font-family: 'JetBrains Mono', monospace;">0</td>
            </tr>
        `;
    }

    function renderNiveles() {
        const n = Math.max(1, Math.min(20, parseInt(inputNiveles.value) || 1));
        inputNiveles.value = n;

        // Preservar valores actuales
        const valoresActuales = {};
        tbody.querySelectorAll('tr').forEach(tr => {
            const niv = tr.dataset.nivel;
            valoresActuales[niv] = {
                carro: parseInt(tr.querySelector('[data-tipo="carro"]').value) || 0,
                moto: parseInt(tr.querySelector('[data-tipo="moto"]').value) || 0,
                bici: parseInt(tr.querySelector('[data-tipo="bici"]').value) || 0,
                electrico: parseInt(tr.querySelector('[data-tipo="electrico"]').value) || 0,
            };
        });

        let html = '';
        for (let i = 1; i <= n; i++) {
            const c = valoresActuales[i] || valoresIniciales[i] || { carro: 0, moto: 0, bici: 0, electrico: 0 };
            html += `
                <tr data-nivel="${i}">
                    <td>Nivel ${i}</td>
                    <td><input type="number" name="celdas[${i}][carro]" min="0" max="200" value="${c.carro}" data-tipo="carro"></td>
                    <td><input type="number" name="celdas[${i}][moto]" min="0" max="200" value="${c.moto}" data-tipo="moto"></td>
                    <td><input type="number" name="celdas[${i}][bici]" min="0" max="200" value="${c.bici}" data-tipo="bici"></td>
                    <td><input type="number" name="celdas[${i}][electrico]" min="0" max="200" value="${c.electrico}" data-tipo="electrico"></td>
                    <td class="subtotal" style="font-weight: 800; color: #10b981; font-family: 'JetBrains Mono', monospace;">0</td>
                </tr>
            `;
        }
        tbody.innerHTML = html;
        recalcular();
        tbody.querySelectorAll('input').forEach(inp => {
            inp.addEventListener('input', recalcular);
        });
    }

    function recalcular() {
        let total = 0;
        tbody.querySelectorAll('tr').forEach(tr => {
            let sub = 0;
            tr.querySelectorAll('input').forEach(inp => {
                sub += parseInt(inp.value) || 0;
            });
            tr.querySelector('.subtotal').textContent = sub;
            total += sub;
        });
        totalCeldas.textContent = total.toLocaleString('es-CO');
    }

    inputNiveles.addEventListener('input', renderNiveles);
    renderNiveles();
})();
</script>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
<?php include __DIR__ . '/partials/ia_widget.php'; ?>
<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>