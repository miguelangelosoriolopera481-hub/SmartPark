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

/* ============================================================
 * HELPERS LOCALES
 * ============================================================ */

if (!function_exists('mask_api_key')) {
    function mask_api_key(string $key): string {
        $len = strlen($key);
        if ($len < 12) return str_repeat('•', $len);
        return substr($key, 0, 6) . str_repeat('•', 20) . substr($key, -4);
    }
}

if (!function_exists('guardar_clave_segura')) {
    /**
     * Guarda una clave cifrándola (si crypt_encrypt está disponible).
     * Devuelve [ok, cifrado_real]
     */
    function guardar_clave_segura(PDO $pdo, string $clave, string $valor, bool $intentarCifrar = true): array {
        $cifrado = 0;
        $valorFinal = $valor;

        if ($intentarCifrar && function_exists('crypt_encrypt')) {
            $enc = crypt_encrypt($valor);
            if (!empty($enc)) {
                $valorFinal = $enc;
                $cifrado = 1;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO configuracion (empresa_id, clave, valor, cifrado)
            VALUES (NULL, :k, :v, :c)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), cifrado = VALUES(cifrado)
        ");
        $stmt->execute(['k' => $clave, 'v' => $valorFinal, 'c' => $cifrado]);
        return ['ok' => true, 'cifrado_real' => $cifrado];
    }
}

$msg = '';
$error = '';
$info_ia = '';

/* ============================================================
 * ACCIONES POST
 * ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada.';
    } else {
        $accion = $_POST['accion'] ?? '';

        // -------- GUARDAR CONFIG --------
        if ($accion === 'guardar') {
            try {
                // Claves NO sensibles (guardado directo)
                $clavesPublicas = [
                    'MODO_MANTENIMIENTO', 'PERMITIR_REGISTRO_EMPRESAS',
                    'REQUIERE_APROBACION_EMPRESA', 'IA_HABILITADA',
                    'IA_PROVEEDOR', 'IA_MAX_TOKENS_POR_DIA',
                    'IA_MODELO',
                    'COLOR_PRIMARIO', 'IDIOMA_DEFAULT', 'MONEDA_DEFAULT'
                ];

                foreach ($clavesPublicas as $c) {
                    if (isset($_POST['cfg_' . $c])) {
                        $valor = trim($_POST['cfg_' . $c]);
                        // IA_MODELO vacío → borrar para que use default
                        if ($c === 'IA_MODELO' && $valor === '') {
                            $pdo->prepare("DELETE FROM configuracion WHERE empresa_id IS NULL AND clave = 'IA_MODELO'")->execute();
                            continue;
                        }
                        $stmt = $pdo->prepare("
                            INSERT INTO configuracion (empresa_id, clave, valor, cifrado)
                            VALUES (NULL, :k, :v, 0)
                            ON DUPLICATE KEY UPDATE valor = VALUES(valor), cifrado = 0
                        ");
                        $stmt->execute(['k' => $c, 'v' => $valor]);
                    }
                }

                // IA_API_KEY: solo si el usuario escribió algo (para no sobreescribir con vacío)
                $apiKeyNueva = trim($_POST['cfg_IA_API_KEY'] ?? '');
                if ($apiKeyNueva !== '' && !str_contains($apiKeyNueva, '••')) {
                    $r = guardar_clave_segura($pdo, 'IA_API_KEY', $apiKeyNueva, true);
                    $info_ia = $r['cifrado_real']
                        ? '✓ API key guardada cifrada en la base de datos'
                        : '⚠ API key guardada en texto plano (configura APP_CRYPTO_KEY en .env para cifrarla)';
                }

                registrarAuditoria($pdo, 'config_global_actualizada', 'Developer actualizó configuración global');
                $msg = 'Configuración guardada correctamente.' . ($info_ia ? ' ' . $info_ia : '');
            } catch (\Throwable $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        }

        // -------- PROBAR IA --------
        if ($accion === 'probar_ia') {
            try {
                require_once __DIR__ . '/config/ai_config.php';
                if (!function_exists('ai_chat')) {
                    $error = 'La función ai_chat() no está definida.';
                } else {
                    $inicio = microtime(true);
                    $r = ai_chat('Responde SOLO con la palabra: FUNCIONA');
                    $tiempo = round(microtime(true) - $inicio, 2);

                    if (!empty($r['ok'])) {
                        $msg = "✅ IA conectada correctamente ({$tiempo}s). Respuesta: " . trim($r['text']);
                    } else {
                        $error = "❌ IA falló: " . ($r['error'] ?? 'desconocido');
                    }
                }
            } catch (\Throwable $e) {
                $error = 'Error al probar IA: ' . $e->getMessage();
            }
        }

        // -------- RESET --------
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

/* ============================================================
 * LEER CONFIG ACTUAL
 * ============================================================ */

$config = [];
try {
    $rows = $pdo->query("SELECT clave, valor, cifrado FROM configuracion WHERE empresa_id IS NULL")->fetchAll();
    foreach ($rows as $r) {
        // Descifrar si aplica
        if ((int)$r['cifrado'] === 1 && function_exists('crypt_decrypt')) {
            $descifrado = crypt_decrypt($r['valor']);
            $config[$r['clave']] = $descifrado !== '' ? $descifrado : $r['valor'];
        } else {
            $config[$r['clave']] = $r['valor'];
        }
    }
} catch (\Throwable $e) {}

$get = fn($k, $d = '') => $config[$k] ?? $d;

// Estado de la API key
$apiKeyActual = $get('IA_API_KEY', '');
$apiKeyCargada = !empty($apiKeyActual);
$apiKeyMasked = $apiKeyCargada ? mask_api_key($apiKeyActual) : '';

// Estado de cifrado
$cryptoDisponible = false;
try {
    if (function_exists('crypt_encrypt')) {
        $t = crypt_encrypt('test');
        $cryptoDisponible = !empty($t);
    }
} catch (\Throwable $e) {}

// Estado real (cifrado o no) desde BD
$apiKeyCifrada = false;
try {
    $stmt = $pdo->prepare("SELECT cifrado FROM configuracion WHERE empresa_id IS NULL AND clave = 'IA_API_KEY' LIMIT 1");
    $stmt->execute();
    $apiKeyCifrada = ((int)$stmt->fetchColumn()) === 1;
} catch (\Throwable $e) {}

$reportesIA = [];
$statsReportes = ['pendientes' => 0, 'total' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT id, empresa_id, user_nombre, rol_en_empresa, pregunta,
               respuesta_ia, motivo, detalle, estado, creado_en
        FROM ia_reportes
        ORDER BY 
            CASE estado WHEN 'pendiente' THEN 1 WHEN 'revisado' THEN 2 ELSE 3 END,
            creado_en DESC
        LIMIT 30
    ");
    $stmt->execute();
    $reportesIA = $stmt->fetchAll();

    $statsReportes['total'] = (int)$pdo->query("SELECT COUNT(*) FROM ia_reportes")->fetchColumn();
    $statsReportes['pendientes'] = (int)$pdo->query("SELECT COUNT(*) FROM ia_reportes WHERE estado='pendiente'")->fetchColumn();
} catch (\Throwable $e) { $reportesIA = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'marcar_reporte') {
    if (csrf_verificar($_POST['csrf_token'] ?? null)) {
        $rid = (int)($_POST['reporte_id'] ?? 0);
        $nuevoEstado = in_array($_POST['nuevo_estado'] ?? '', ['pendiente','revisado','resuelto'], true)
                    ? $_POST['nuevo_estado'] : 'revisado';
        if ($rid > 0) {
            try {
                $pdo->prepare("UPDATE ia_reportes SET estado = :e WHERE id = :id")
                    ->execute(['e' => $nuevoEstado, 'id' => $rid]);
                $msg = "✓ Reporte #$rid marcado como $nuevoEstado";
                // Refrescar lista
                $stmt = $pdo->prepare("
                    SELECT id, empresa_id, user_nombre, rol_en_empresa, pregunta,
                           respuesta_ia, motivo, detalle, estado, creado_en
                    FROM ia_reportes
                    ORDER BY 
                        CASE estado WHEN 'pendiente' THEN 1 WHEN 'revisado' THEN 2 ELSE 3 END,
                        creado_en DESC
                    LIMIT 30
                ");
                $stmt->execute();
                $reportesIA = $stmt->fetchAll();
            } catch (\Throwable $e) {
                $error = 'No se pudo actualizar el reporte.';
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
<title>Configuración Global - Developer</title>
<link rel="stylesheet" href="assets/css/main.css?v=36">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
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
    .config-item input[type="password"] {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.8rem;
        letter-spacing: 0.05em;
    }

    .status-box {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.9rem 1.1rem;
        border-radius: 12px;
        margin-top: 1rem;
        font-size: 0.85rem;
    }
    .status-box.ok {
        background: rgba(16, 185, 129, 0.08);
        border: 1px solid rgba(16, 185, 129, 0.4);
        color: #6ee7b7;
    }
    .status-box.warn {
        background: rgba(245, 158, 11, 0.08);
        border: 1px solid rgba(245, 158, 11, 0.4);
        color: #fcd34d;
    }
    .status-box .s-icon {
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .status-box .s-text { flex: 1; }
    .status-box .s-text strong { display: block; margin-bottom: 0.15rem; }

    .key-badge {
        display: inline-block;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.72rem;
        background: rgba(2, 6, 23, 0.8);
        border: 1px solid rgba(16, 185, 129, 0.3);
        padding: 0.2rem 0.6rem;
        border-radius: 6px;
        color: #6ee7b7;
        margin-top: 0.4rem;
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
    .btn-test-ia {
        background: rgba(59, 130, 246, 0.15);
        border: 1.5px solid rgba(59, 130, 246, 0.5);
        color: #93c5fd;
        padding: 0.7rem 1.4rem;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .btn-test-ia:hover { background: rgba(59, 130, 246, 0.25); border-color: #3b82f6; }
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
            <small>Bloquea el sistema para todos excepto developers.</small>
          </label>
          <select name="cfg_MODO_MANTENIMIENTO">
            <option value="0" <?= $get('MODO_MANTENIMIENTO', '0') === '0' ? 'selected' : '' ?>>Desactivado (sistema operativo)</option>
            <option value="1" <?= $get('MODO_MANTENIMIENTO') === '1' ? 'selected' : '' ?>>Activado (solo developers)</option>
          </select>
        </div>
        <div class="config-item">
          <label>Permitir registro de empresas
            <small>Si está en No, nadie puede crear cuentas nuevas.</small>
          </label>
          <select name="cfg_PERMITIR_REGISTRO_EMPRESAS">
            <option value="1" <?= $get('PERMITIR_REGISTRO_EMPRESAS', '1') === '1' ? 'selected' : '' ?>>Sí (registro abierto)</option>
            <option value="0" <?= $get('PERMITIR_REGISTRO_EMPRESAS', '1') === '0' ? 'selected' : '' ?>>No (registro cerrado)</option>
          </select>
        </div>
        <div class="config-item">
          <label>Requiere aprobación manual
            <small>Apruebas cada empresa antes de que pueda operar.</small>
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
      <p class="section-desc">Configura el asistente inteligente que ayuda a administradores, analiza fraudes y optimiza celdas.</p>

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
            <small>Modelo que usan los asistentes.</small>
          </label>
          <select name="cfg_IA_PROVEEDOR">
            <option value="gemini" <?= $get('IA_PROVEEDOR', 'gemini') === 'gemini' ? 'selected' : '' ?>>Google Gemini (gratis)</option>
            <option value="openai" <?= $get('IA_PROVEEDOR') === 'openai' ? 'selected' : '' ?>>OpenAI GPT (pago)</option>
            <option value="anthropic" <?= $get('IA_PROVEEDOR') === 'anthropic' ? 'selected' : '' ?>>Anthropic Claude (pago)</option>
          </select>
        </div>
        <div class="config-item">
          <label>Modelo específico
            <small>Dejar vacío para usar el default (Gemini: gemini-3.8-flash).</small>
          </label>
          <input type="text" name="cfg_IA_MODELO"
                 value="<?= htmlspecialchars($get('IA_MODELO', '')) ?>"
                 placeholder="gemini-3.8-flash"
                 style="font-family: 'JetBrains Mono', monospace; font-size: 0.82rem;">
        </div>
        <div class="config-item">
          <label>Límite tokens IA por día
            <small>Máximo que cada usuario puede consumir diariamente.</small>
          </label>
          <input type="number" name="cfg_IA_MAX_TOKENS_POR_DIA" value="<?= (int)$get('IA_MAX_TOKENS_POR_DIA', 1000) ?>" min="100" max="100000">
        </div>

        <!-- API KEY -->
        <div class="config-item" style="grid-column: 1 / -1;">
          <label>🔑 API Key del proveedor
            <small>
              Pega aquí la nueva key (Gemini: comienza con <code>AQ.</code> o <code>AIzaSy</code>).
              <?php if ($apiKeyCargada): ?>
                Déjala en blanco para mantener la actual.
              <?php endif; ?>
            </small>
          </label>
          <input type="password" name="cfg_IA_API_KEY"
                 value=""
                 placeholder="<?= $apiKeyCargada ? $apiKeyMasked : 'Sin configurar' ?>"
                 autocomplete="off">
          <?php if ($apiKeyCargada): ?>
            <div class="key-badge">
              <?= htmlspecialchars($apiKeyMasked) ?>
              <?= $apiKeyCifrada ? ' 🔒 cifrada' : ' ⚠ texto plano' ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ESTADO IA -->
      <div class="status-box <?= $apiKeyCargada ? 'ok' : 'warn' ?>">
        <span class="s-icon"><?= $apiKeyCargada ? '✅' : '⚠️' ?></span>
        <div class="s-text">
          <?php if ($apiKeyCargada): ?>
            <strong>IA configurada</strong>
            Proveedor: <?= htmlspecialchars($get('IA_PROVEEDOR', 'gemini')) ?> ·
            Modelo: <?= htmlspecialchars($get('IA_MODELO', 'default')) ?> ·
            Cifrado en BD: <?= $apiKeyCifrada ? 'Sí 🔒' : 'No ⚠️' ?>
          <?php else: ?>
            <strong>Sin configurar</strong>
            Agrega una API key para activar los asistentes de IA.
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$cryptoDisponible): ?>
      <div class="status-box warn" style="margin-top: 0.6rem;">
        <span class="s-icon">🔐</span>
        <div class="s-text">
          <strong>Cifrado de clave no disponible</strong>
          Para cifrar la API key en la BD, agrega <code>APP_CRYPTO_KEY</code> en tu archivo <code>.env</code>
          (32 caracteres en base64). Se generará automáticamente la primera vez que se necesite.
        </div>
      </div>
      <?php endif; ?>

      <div class="button-row">
        <button type="submit" class="cyber-btn-primary">💾 Guardar configuración</button>
        <button type="submit" name="accion" value="probar_ia" class="btn-test-ia" formnovalidate>🧪 Probar conexión IA</button>
      </div>
    </div>

    <!-- LOCALIZACIÓN -->
    <div class="config-section">
      <h2>◐ Localización</h2>
      <p class="section-desc">Ajustes de idioma, moneda y apariencia para toda la plataforma.</p>
      <div class="config-grid">
        <div class="config-item">
          <label>Idioma por defecto</label>
          <select name="cfg_IDIOMA_DEFAULT">
            <option value="es" <?= $get('IDIOMA_DEFAULT', 'es') === 'es' ? 'selected' : '' ?>>Español</option>
            <option value="en" <?= $get('IDIOMA_DEFAULT') === 'en' ? 'selected' : '' ?>>English</option>
          </select>
        </div>
        <div class="config-item">
          <label>Moneda por defecto</label>
          <select name="cfg_MONEDA_DEFAULT">
            <option value="COP" <?= $get('MONEDA_DEFAULT', 'COP') === 'COP' ? 'selected' : '' ?>>Peso colombiano (COP)</option>
            <option value="USD" <?= $get('MONEDA_DEFAULT') === 'USD' ? 'selected' : '' ?>>Dólar (USD)</option>
            <option value="EUR" <?= $get('MONEDA_DEFAULT') === 'EUR' ? 'selected' : '' ?>>Euro (EUR)</option>
          </select>
        </div>
      </div>
    </div>
  </form>

  <!-- REPORTES IA -->
  <?php if (!empty($reportesIA) || $statsReportes['total'] > 0): ?>
  <div class="config-section" style="border-color: rgba(239, 68, 68, 0.3); background: linear-gradient(160deg, rgba(127, 29, 29, 0.08), rgba(15, 23, 42, 0.6));">
    <h2 style="color: #fca5a5;">⚠️ Reportes del asistente IA</h2>
    <p class="section-desc">
      Preguntas que fallaron, intentos fuera de alcance o respuestas malas reportadas por usuarios.
      <strong style="color: #f87171;">Total: <?= (int)$statsReportes['total'] ?></strong> ·
      <strong style="color: #fcd34d;">Pendientes: <?= (int)$statsReportes['pendientes'] ?></strong>
    </p>

    <?php if (empty($reportesIA)): ?>
      <p style="color: #64748b; text-align: center; padding: 1.5rem;">✅ No hay reportes pendientes</p>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 500px; overflow-y: auto;">
        <?php foreach ($reportesIA as $r): 
          $colorEstado = match($r['estado']) {
              'pendiente' => '#f59e0b',
              'revisado' => '#3b82f6',
              'resuelto' => '#10b981',
              default => '#64748b',
          };
          $colorMotivo = match($r['motivo']) {
              'ia_caida' => '#ef4444',
              'fuera_de_alcance' => '#f59e0b',
              'respuesta_mala' => '#f97316',
              'error_interno' => '#a855f7',
              default => '#64748b',
          };
        ?>
          <div style="background: rgba(2, 6, 23, 0.6); border-radius: 12px; padding: 1rem; border-left: 3px solid <?= $colorMotivo ?>;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
              <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                <span style="background: <?= $colorEstado ?>22; color: <?= $colorEstado ?>; padding: 0.15rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; border: 1px solid <?= $colorEstado ?>55;"><?= htmlspecialchars($r['estado']) ?></span>
                <span style="background: <?= $colorMotivo ?>22; color: <?= $colorMotivo ?>; padding: 0.15rem 0.5rem; border-radius: 6px; font-size: 0.7rem; font-weight: 700;"><?= htmlspecialchars(str_replace('_', ' ', $r['motivo'])) ?></span>
                <span style="color: #94a3b8; font-size: 0.72rem;"><?= htmlspecialchars($r['user_nombre']) ?> · <?= htmlspecialchars($r['rol_en_empresa'] ?? '—') ?></span>
              </div>
              <span style="color: #64748b; font-size: 0.68rem;"><?= htmlspecialchars(substr($r['creado_en'] ?? '', 0, 16)) ?></span>
            </div>

            <div style="font-size: 0.8rem; color: #cbd5e1; margin-bottom: 0.4rem;">
              <strong style="color: #94a3b8;">Pregunta:</strong> <?= htmlspecialchars(mb_substr($r['pregunta'], 0, 200)) ?><?= mb_strlen($r['pregunta']) > 200 ? '...' : '' ?>
            </div>

            <?php if (!empty($r['detalle'])): ?>
              <div style="font-size: 0.72rem; color: #64748b; margin-bottom: 0.5rem;">
                <strong>Detalle:</strong> <?= htmlspecialchars(mb_substr($r['detalle'], 0, 200)) ?>
              </div>
            <?php endif; ?>

            <form method="POST" style="display: flex; gap: 0.4rem; align-items: center; margin-top: 0.5rem;">
              <?= csrf_field() ?>
              <input type="hidden" name="accion" value="marcar_reporte">
              <input type="hidden" name="reporte_id" value="<?= (int)$r['id'] ?>">
              <select name="nuevo_estado" style="padding: 0.35rem 0.6rem; background: rgba(2,6,23,0.7); border: 1px solid rgba(148,163,184,0.2); border-radius: 6px; color: #f1f5f9; font-size: 0.75rem; font-family: inherit;">
                <option value="pendiente" <?= $r['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                <option value="revisado"  <?= $r['estado'] === 'revisado' ? 'selected' : '' ?>>Revisado</option>
                <option value="resuelto"  <?= $r['estado'] === 'resuelto' ? 'selected' : '' ?>>Resuelto</option>
              </select>
              <button type="submit" style="padding: 0.35rem 0.75rem; background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.5); color: #10b981; border-radius: 6px; font-size: 0.72rem; font-weight: 700; cursor: pointer; font-family: inherit;">Actualizar</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ZONA DE PELIGRO -->
  <div class="config-section danger-zone">
    <h2>⚠ Zona de peligro</h2>
    <p class="section-desc">Acciones irreversibles. Úsalas solo si sabes lo que haces.</p>
    <form method="POST" onsubmit="return confirm('¿Resetear TODA la configuración global? Esta acción no se puede deshacer.');">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="reset">
      <button type="submit" class="cyber-btn-outline" style="border-color: #ef4444; color: #ef4444;">🗑 Resetear configuración global</button>
    </form>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>
<script src="assets/js/nav_developer.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>