<?php
session_start();
require_once 'conexion.php';
require_once 'helpers.php';
require_once 'csrf.php';
require_once 'config/ai_config.php';
send_security_headers();

require_role_empresa(['admin','operador','superadmin']);
$eid = current_empresa_id();

$respuesta = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) { $error = 'Sesion expirada.'; }
    else {
        $prompt = trim($_POST['prompt'] ?? '');
        if ($prompt !== '') {
            $ctx = $pdo->prepare("SELECT codigo_cupo, tipo, estado FROM cupos WHERE empresa_id = :eid ORDER BY codigo_cupo LIMIT 200");
            $ctx->execute(['eid' => $eid]);
            $cupos = $ctx->fetchAll();

            $contexto = "Empresa ID: $eid. Cupos actuales: " . json_encode($cupos, JSON_UNESCAPED_UNICODE);
            $r = ai_chat($prompt, ['system' => "Eres SmartPark AI. Ayudas a administradores de parqueaderos. Contexto: $contexto. Si el usuario pide modificar espacios, responde con un JSON: {\"accion\":\"agregar|bloquear\",\"cupo\":\"A1\",\"nivel\":\"Sotano1\"}"]);
            if ($r['ok']) $respuesta = $r['text'];
            else $error = $r['error'] ?? 'Error de IA.';
        }
    }
}
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SmartPark AI</title>
<link rel="stylesheet" href="assets/css/main.css?v=34">
<link rel="stylesheet" href="assets/css/legacy-compat.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head><body class="cyber-body">
<nav class="cyber-navbar"><div class="nav-container">
  <a href="dashboard_admin.php" class="cyber-btn-outline">Volver al Panel</a>
  <span style="color:var(--text-muted)">SmartPark AI - Empresa #<?= (int)$eid ?></span>
</div></nav>

<div class="container cyber-entrance" style="max-width:780px">
  <div class="cyber-card">
    <h2 style="color:var(--primary-cyan);text-align:center">SmartPark AI Engine</h2>
    <p style="color:var(--text-muted);text-align:center;font-size:.85rem;margin-bottom:1rem">
      Pregunta en lenguaje natural. Ej: <em>"Agrega 15 espacios para vehiculos electricos en el Sotano 1"</em>.
    </p>
    <?php if ($error): ?><div class="cyber-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST" class="cyber-form">
      <?= csrf_field() ?>
      <div class="form-group">
        <textarea name="prompt" rows="3" required placeholder="Escribe tu consulta o instruccion..."
                  style="width:100%;padding:.8rem;background:#070a12;border:1px solid var(--border-cyber);border-radius:8px;color:var(--text-main)"></textarea>
      </div>
      <button type="submit" class="cyber-btn-primary" style="width:100%">Enviar a SmartPark AI</button>
    </form>

    <?php if ($respuesta): ?>
      <div class="cyber-card" style="margin-top:1.5rem;background:#0b1220">
        <h4 style="color:var(--primary-green);margin-bottom:.5rem">Respuesta IA</h4>
        <pre style="white-space:pre-wrap;color:var(--text-main);font-size:.9rem"><?= htmlspecialchars($respuesta) ?></pre>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body></html>