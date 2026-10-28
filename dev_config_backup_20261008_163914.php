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

// Guardar configuración
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'guardar') {
            try {
                $claves = [
                    'MODO_MANTENIMIENTO', 'PERMITIR_REGISTRO_EMPRESAS',
                    'REQUIERE_APROBACION_EMPRESA', 'IA_HABILITADA',
                    'IA_PROVEEDOR', 'IA_MAX_TOKENS_POR_DIA',
                    'COLOR_PRIMARIO', 'IDIOMA_DEFAULT', 'MONEDA_DEFAULT'
                ];
                foreach ($claves as $c) {
                    if (isset($_POST['cfg_' . $c])) {
                        $stmt = $pdo->prepare("
                            INSERT INTO configuracion (empresa_id, clave, valor, cifrado)
                            VALUES (NULL, :k, :v, 0)
                            ON DUPLICATE KEY UPDATE valor=VALUES(valor)
                        ");
                        $stmt->execute(['k' => $c, 'v' => trim($_POST['cfg_' . $c])]);
                    }
                }
                registrarAuditoria($pdo, 'config_global_actualizada', 'Developer actualizó configuración global');
                $msg = 'Configuración guardada correctamente.';
            } catch (\Throwable $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }

        if ($accion === 'reset') {
            try {
                $pdo->prepare("DELETE FROM configuracion WHERE empresa_id IS NULL")->execute();
                $msg = 'Configuración reseteada a valores por defecto.';
            } catch (\Throwable $e) {
                $error = 'Error al resetear.';
            }
        }
    }
}

// Leer configuración actual
$config = [];
try {
    $rows = $pdo->query("SELECT clave, valor FROM configuracion WHERE empresa_id IS NULL")->fetchAll();
    foreach ($rows as $r) $config[$r['clave']] = $r['valor'];
} catch (\Throwable $e) {}

$get = fn($k, $d = '') => $config[$k] ?? $d;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configuración Global - Developer</title>
<link rel="stylesheet" href="assets/css/main.css?v=25">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
    body { padding-top: 6rem; padding-bottom: 4rem; }
    .container { max-width: 1100px; margin: 0 auto; padding: 0 1.5rem; }
    .page-header { margin-bottom: 2rem; }
    .page-header .breadcrumb { color: #64748b; font-size: 0.82rem; margin-bottom: 0.75rem; }
    .page-header .breadcrumb a { color: #10b981; text-decoration: none; }
    .page-header h1 { font-size: clamp(1.8rem, 4vw, 2.5rem); font-weight: 900; letter-spacing: -0.03em; background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 0.4rem; }
    .page-header .sub { color: #64748b; font-size: 0.92rem; }

    .config-section {
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(16px);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: 18px;
        padding: 1.75rem;
        margin-bottom: 1.5rem;
    }
    .config-section h2 {
        font-size: 1.1rem;
        font-weight: 800;
        color: #f1f5f9;
        margin-bottom: 0.4rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .config-section .section-desc {
        color: #64748b;
        font-size: 0.85rem;
        margin-bottom: 1.5rem;
        line-height: 1.5;
    }
    .config-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1.25rem;
    }
    .config-item label {
        display: block;
        font-size: 0.85rem;
        color: #cbd5e1;
        margin-bottom: 0.5rem;
        font-weight: 700;
    }
    .config-item label small {
        display: block;
        color: #64748b;
        font-weight: 400;
        font-size: 0.75rem;
        margin-top: 0.3rem;
        line-height: 1.4;
    }
    .config-item select, .config-item input {
        width: 100%;
        padding: 0.75rem 0.95rem;
        background: rgba(2, 6, 23, 0.7);
        border: 1.5px solid rgba(148, 163, 184, 0.15);
        border-radius: 10px;
        color: #f1f5f9;
        font-family: inherit;
        font-size: 0.88rem;
    }
    .config-item select:focus, .config-item input:focus {
        border-color: #10b981;
        outline: none;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
    }

    .danger-zone {
        border-color: rgba(239, 68, 68, 0.3) !important;
        background: linear-gradient(160deg, rgba(127, 29, 29, 0.15), rgba(15, 23, 42, 0.6));
    }
    .danger-zone h2 { color: #fca5a5; }

    .button-row {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-top: 1.5rem;
    }
</style>
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body data-nav="developer">

<canvas id="particles-canvas"></canvas>

<div class="container">

  <div class="page-header">
    <div class="breadcrumb"><a href="dashboard_developer.php">← Panel Developer</a></div>
    <h1>Configuración global</h1>
    <p class="sub">Interruptores maestros que afectan toda la plataforma SmartPark Pro.</p>
  </div>

  <?php if ($msg): ?><div class="cyber-success" style="margin-bottom: 1.5rem;">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="cyber-alert" style="margin-bottom: 1.5rem;">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">

    <!-- OPERACIÓN -->
    <div class="config-section">
      <h2>◈ Operación del sistema</h2>
      <p class="section-desc">Controla el comportamiento general y la disponibilidad de la plataforma.</p>
      <div class="config-grid">
        <div class="config-item">
          <label>Modo mantenimiento
            <small>Bloquea el sistema para todos excepto developers. Útil durante actualizaciones.</small>
          </label>
          <select name="cfg_MODO_MANTENIMIENTO">
            <option value="0" <?= $get('MODO_MANTENIMIENTO', '0') === '0' ? 'selected' : '' ?>>Desactivado (sistema operativo)</option>
            <option value="1" <?= $get('MODO_MANTENIMIENTO') === '1' ? 'selected' : '' ?>>Activado (solo developers)</option>
          </select>
        </div>
        <div class="config-item">
          <label>Permitir registro de empresas
            <small>Si está en No, nadie puede crear cuentas nuevas desde el formulario público.</small>
          </label>
          <select name="cfg_PERMITIR_REGISTRO_EMPRESAS">
            <option value="1" <?= $get('PERMITIR_REGISTRO_EMPRESAS', '1') === '1' ? 'selected' : '' ?>>Sí (registro abierto)</option>
            <option value="0" <?= $get('PERMITIR_REGISTRO_EMPRESAS', '1') === '0' ? 'selected' : '' ?>>No (registro cerrado)</option>
          </select>
        </div>
        <div class="config-item">
          <label>Requiere aprobación manual
            <small>Apruebas cada empresa antes de que pueda operar. Evita spam y estafas.</small>
          </label>
          <select name="cfg_REQUIERE_APROBACION_EMPRESA">
            <option value="1" <?= $get('REQUIERE_APROBACION_EMPRESA', '1') === '1' ? 'selected' : '' ?>>Sí (recomendado)</option>
            <option value="0" <?= $get('REQUIERE_APROBACION_EMPRESA', '1') === '0' ? 'selected' : '' ?>>No (auto-aprobar)</option>
          </select>
        </div>
      </div>
    </div>

    <!-- IA -->
    <div class="config-section">
      <h2>✦ Inteligencia artificial</h2>
      <p class="section-desc">Configura el asistente inteligente que ayuda a administradores y conductores.</p>
      <div class="config-grid">
        <div class="config-item">
          <label>IA habilitada
            <small>Activa o desactiva los asistentes de IA en todos los portales.</small>
          </label>
          <select name="cfg_IA_HABILITADA">
            <option value="1" <?= $get('IA_HABILITADA', '1') === '1' ? 'selected' : '' ?>>Sí (asistentes activos)</option>
            <option value="0" <?= $get('IA_HABILITADA', '1') === '0' ? 'selected' : '' ?>>No (desactivada)</option>
          </select>
        </div>
        <div class="config-item">
          <label>Proveedor de IA
            <small>Modelo que usan los asistentes. Gemini es gratis, los demás requieren API de pago.</small>
          </label>
          <select name="cfg_IA_PROVEEDOR">
            <option value="gemini" <?= $get('IA_PROVEEDOR', 'gemini') === 'gemini' ? 'selected' : '' ?>>Google Gemini (gratis)</option>
            <option value="openai" <?= $get('IA_PROVEEDOR') === 'openai' ? 'selected' : '' ?>>OpenAI GPT (pago)</option>
            <option value="anthropic" <?= $get('IA_PROVEEDOR') === 'anthropic' ? 'selected' : '' ?>>Anthropic Claude (pago)</option>
          </select>
        </div>
        <div class="config-item">
          <label>Límite tokens IA por día
            <small>Máximo que cada usuario puede consumir diariamente. 1000 tokens ≈ 750 palabras.</small>
          </label>
          <input type="number" name="cfg_IA_MAX_TOKENS_POR_DIA" value="<?= (int)$get('IA_MAX_TOKENS_POR_DIA', 1000) ?>" min="100" max="100000">
        </div>
      </div>
    </div>

    <!-- LOCALIZACIÓN -->
    <div class="config-section">
      <h2>◐ Localización</h2>
      <p class="section-desc">Ajustes de idioma, moneda y apariencia para toda la plataforma.</p>
      <div class="config-grid">
        <div class="config-item">
          <label>Idioma por defecto
            <small>El idioma base del sistema antes de personalizaciones.</small>
          </label>
          <select name="cfg_IDIOMA_DEFAULT">
            <option value="es" <?= $get('IDIOMA_DEFAULT', 'es') === 'es' ? 'selected' : '' ?>>Español</option>
            <option value="en" <?= $get('IDIOMA_DEFAULT') === 'en' ? 'selected' : '' ?>>English</option>
          </select>
        </div>
        <div class="config-item">
          <label>Moneda por defecto
            <small>Se usa en facturación y reportes.</small>
          </label>
          <select name="cfg_MONEDA_DEFAULT">
            <option value="COP" <?= $get('MONEDA_DEFAULT', 'COP') === 'COP' ? 'selected' : '' ?>>Peso colombiano (COP)</option>
            <option value="USD" <?= $get('MONEDA_DEFAULT') === 'USD' ? 'selected' : '' ?>>Dólar (USD)</option>
            <option value="EUR" <?= $get('MONEDA_DEFAULT') === 'EUR' ? 'selected' : '' ?>>Euro (EUR)</option>
          </select>
        </div>
      </div>
    </div>

    <div class="button-row">
      <button type="submit" class="cyber-btn-primary btn-lg">💾 Guardar configuración</button>
    </div>
  </form>

  <!-- ZONA DE PELIGRO -->
  <div class="config-section danger-zone">
    <h2>⚠ Zona de peligro</h2>
    <p class="section-desc">Acciones irreversibles. Úsalas solo si sabes lo que haces.</p>
    <form method="POST" onsubmit="return confirm('¿Resetear TODA la configuración global a valores por defecto? Esta acción no se puede deshacer.');">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="reset">
      <button type="submit" class="cyber-btn-outline" style="border-color: #ef4444; color: #ef4444;">🗑 Resetear configuración global</button>
    </form>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>
</body>
</html>
