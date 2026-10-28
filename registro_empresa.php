<?php
session_start();
require_once 'conexion.php';
require_once 'auth_handler.php';
require_once 'csrf.php';
send_security_headers();

if (!empty($_SESSION['usuario_id']) && !empty($_SESSION['empresa_id'])) {
    $rol = $_SESSION['rol_en_empresa'] ?? '';
    if (in_array($rol, ['admin', 'operador'], true)) { header('Location: dashboard_admin.php'); exit; }
    header('Location: dashboard_cliente.php'); exit;
}

$error = '';
$paso = (int)($_GET['paso'] ?? 1);

if (!isset($_SESSION['reg_empresa'])) {
    $_SESSION['reg_empresa'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página.';
    } else {
        $accion = $_POST['accion'] ?? '';

        if ($accion === 'paso1') {
            $nombre = trim($_POST['nombre'] ?? '');
            $nit    = trim($_POST['nit'] ?? '');
            $tel    = trim($_POST['telefono'] ?? '');
            $dir    = trim($_POST['direccion'] ?? '');
            $ciudad = trim($_POST['ciudad'] ?? '');
            $email  = trim($_POST['email_contacto'] ?? '');

            if ($nombre === '') {
                $error = 'El nombre de la empresa es obligatorio.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'El correo de contacto no es válido.';
            } else {
                $_SESSION['reg_empresa']['empresa'] = [
                    'nombre' => $nombre, 'nit' => $nit, 'telefono' => $tel,
                    'direccion' => $dir, 'ciudad' => $ciudad, 'email_contacto' => $email,
                ];
                header('Location: registro_empresa.php?paso=2'); exit;
            }
            $paso = 1;
        }

        if ($accion === 'paso2') {
            $nombre_parq = trim($_POST['nombre_parqueadero'] ?? '');
            $ciudad_parq = trim($_POST['ciudad_parqueadero'] ?? '');
            $direccion_parq = trim($_POST['direccion_parqueadero'] ?? '');
            $niveles = (int)($_POST['niveles'] ?? 1);

            if ($nombre_parq === '') {
                $error = 'El nombre del parqueadero es obligatorio.';
            } else {
                $_SESSION['reg_empresa']['parqueadero'] = [
                    'nombre' => $nombre_parq, 'ciudad' => $ciudad_parq,
                    'direccion' => $direccion_parq, 'niveles' => max(1, $niveles),
                ];
                header('Location: registro_empresa.php?paso=3'); exit;
            }
            $paso = 2;
        }

        if ($accion === 'paso3') {
            $admin_nombre = trim($_POST['admin_nombre'] ?? '');
            $admin_user   = trim($_POST['admin_username'] ?? '');
            $admin_email  = trim($_POST['admin_email'] ?? '');
            $admin_pass   = (string)($_POST['admin_password'] ?? '');
            $admin_pass2  = (string)($_POST['admin_password2'] ?? '');

            $empresaData = $_SESSION['reg_empresa']['empresa'] ?? null;
            $parqData    = $_SESSION['reg_empresa']['parqueadero'] ?? null;

            if (!$empresaData || !$parqData) {
                $error = 'Faltan datos de pasos anteriores. Reinicia el registro.';
            } elseif ($admin_nombre === '' || $admin_user === '' || $admin_pass === '') {
                $error = 'Todos los campos del administrador son obligatorios.';
            } elseif (strlen($admin_pass) < 6) {
                $error = 'La contraseña debe tener al menos 6 caracteres.';
            } elseif ($admin_pass !== $admin_pass2) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmtEmp = $pdo->prepare("
                        INSERT INTO empresas (nombre, nit, telefono, direccion, email_contacto, estado, estado_aprobacion)
                        VALUES (:n, :nit, :tel, :dir, :email, 'activa', 'pendiente')
                    ");
                    $stmtEmp->execute([
                        'n' => $empresaData['nombre'],
                        'nit' => $empresaData['nit'] ?: null,
                        'tel' => $empresaData['telefono'],
                        'dir' => $empresaData['direccion'],
                        'email' => $empresaData['email_contacto'],
                    ]);
                    $empresaId = (int)$pdo->lastInsertId();

                    $qrToken = bin2hex(random_bytes(16));
                    $stmtParq = $pdo->prepare("
                        INSERT INTO parqueaderos (empresa_id, nombre, ciudad, direccion, niveles, estado, qr_token)
                        VALUES (:emp, :n, :ciudad, :dir, :niv, 'activo', :qr)
                    ");
                    $stmtParq->execute([
                        'emp' => $empresaId,
                        'n' => $parqData['nombre'],
                        'ciudad' => $parqData['ciudad'],
                        'dir' => $parqData['direccion'],
                        'niv' => $parqData['niveles'],
                        'qr' => $qrToken,
                    ]);
                    $parqueaderoId = (int)$pdo->lastInsertId();

                    $hash = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmtUser = $pdo->prepare("
                        INSERT INTO user (username, password, nombre, correo, role, empresa_default_id, must_change_password)
                        VALUES (:u, :p, :n, :e, 'admin', :emp, 0)
                    ");
                    $stmtUser->execute([
                        'u' => $admin_user, 'p' => $hash, 'n' => $admin_nombre,
                        'e' => $admin_email ?: null, 'emp' => $empresaId,
                    ]);
                    $adminId = (int)$pdo->lastInsertId();

                    $stmtUE = $pdo->prepare("
                        INSERT INTO usuarios_empresa (user_id, empresa_id, parqueadero_id, rol_en_empresa, activo)
                        VALUES (:u, :emp, :parq, 'admin', 1)
                    ");
                    $stmtUE->execute(['u' => $adminId, 'emp' => $empresaId, 'parq' => $parqueaderoId]);

                    $stmtCupos = $pdo->prepare("
                        INSERT INTO cupos (empresa_id, parqueadero_id, codigo_cupo, tipo, estado)
                        VALUES (:emp, :parq, :cod, :tipo, 'disponible')
                    ");
                    for ($i = 1; $i <= 10; $i++) {
                        $stmtCupos->execute(['emp'=>$empresaId, 'parq'=>$parqueaderoId, 'cod'=>'A'.str_pad($i, 2, '0', STR_PAD_LEFT), 'tipo'=>'carro']);
                    }
                    for ($i = 1; $i <= 10; $i++) {
                        $stmtCupos->execute(['emp'=>$empresaId, 'parq'=>$parqueaderoId, 'cod'=>'M'.str_pad($i, 2, '0', STR_PAD_LEFT), 'tipo'=>'moto']);
                    }

                    $stmtTar = $pdo->prepare("
                        INSERT INTO tarifas (empresa_id, parqueadero_id, tipo_vehiculo, tipo_tarifa, precio, activo)
                        VALUES (:emp, :parq, :tv, 'hora', :p, 1)
                    ");
                    $stmtTar->execute(['emp'=>$empresaId, 'parq'=>$parqueaderoId, 'tv'=>'carro', 'p'=>4000]);
                    $stmtTar->execute(['emp'=>$empresaId, 'parq'=>$parqueaderoId, 'tv'=>'moto', 'p'=>2000]);

                    $pdo->commit();

                    unset($_SESSION['reg_empresa']);

                    $_SESSION['reg_empresa_success'] = [
                        'empresa' => $empresaData['nombre'],
                        'parqueadero' => $parqData['nombre'],
                        'qr_token' => $qrToken,
                        'admin_user' => $admin_user,
                        'empresa_id' => $empresaId,
                    ];

                    header('Location: registro_empresa.php?paso=4'); exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    error_log('registro_empresa: ' . $e->getMessage());
                    $error = 'Error al crear la empresa. Puede que el NIT o usuario ya existan.';
                }
            }
            $paso = 3;
        }
    }
}

$successData = null;
if ($paso === 4) {
    $successData = $_SESSION['reg_empresa_success'] ?? null;
    if (!$successData) {
        header('Location: registro_empresa.php'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registrar Empresa - SmartPark Pro</title>
<link rel="stylesheet" href="assets/css/main.css?v=19">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="IMG/SMARTPARK_TRANSPARENTE.png">
</head>
<body>

<div class="split-login">

  <!-- IZQUIERDA: Animación de CONSTRUCCIÓN -->
  <div class="split-left">
    <div class="split-left-content">

      <div class="scene-header">
        <img src="IMG/SMARTPARK_TRANSPARENTE.png" alt="SmartPark">
        <?php if ($paso === 4): ?>
          <h2>¡Tu parqueadero<br>está listo!</h2>
          <p>Todo ha sido configurado automáticamente. Ya puedes empezar a operar.</p>
        <?php else: ?>
          <h2>Construye tu parqueadero<br>paso a paso</h2>
          <p>En menos de 3 minutos tendrás tu sistema listo para operar.</p>
        <?php endif; ?>
      </div>

      <div class="scene-middle">
        <div class="scene-build">
          <div class="build-spark s1"></div>
          <div class="build-spark s2"></div>
          <div class="build-spark s3"></div>
          <div class="build-spark s4"></div>
          <div class="build-spark s5"></div>
          <div class="build-tower">
            <div class="build-block b-green k1"><span class="b-icon">◆</span> Datos de la empresa</div>
            <div class="build-block b-cyan k2"><span class="b-icon">◆</span> Parqueadero creado</div>
            <div class="build-block b-blue k3"><span class="b-icon">◆</span> 20 celdas auto-generadas</div>
            <div class="build-block b-cyan k4"><span class="b-icon">◆</span> Tarifas configuradas</div>
            <div class="build-block b-green k5"><span class="b-icon">◆</span> QR único generado</div>
            <div class="build-block b-amber k6"><span class="b-icon">✓</span> ¡Listo para operar!</div>
          </div>
        </div>
      </div>

      <div class="scene-footer">
        <div class="scene-footer-item">
          <span class="sfi-icon">◈</span>
          <div class="sfi-text">Registro gratis<small>Durante fase demo</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">✓</span>
          <div class="sfi-text">Aprobación rápida<small>24h o menos</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">▤</span>
          <div class="sfi-text">Migración incluida<small>Sin costo</small></div>
        </div>
        <div class="scene-footer-item">
          <span class="sfi-icon">◆</span>
          <div class="sfi-text">Soporte humano<small>24/7</small></div>
        </div>
      </div>

      <div class="scene-why">
        <h3>¿Qué incluye tu registro?</h3>
        <ul class="scene-why-list">
          <li><span class="chk">✓</span> Panel de administración completo</li>
          <li><span class="chk">✓</span> 20 celdas iniciales gratis</li>
          <li><span class="chk">✓</span> IA Gemini sin costo</li>
          <li><span class="chk">✓</span> QR único de tu parqueadero</li>
          <li><span class="chk">✓</span> Notificaciones Telegram + email</li>
          <li><span class="chk">✓</span> Reportes de finanzas</li>
        </ul>
      </div>

      <div class="scene-support">
        <span class="sup-icon">◈</span>
        <div class="sup-text">
          <strong>¿Necesitas ayuda con el registro?</strong>
          <span>Te acompañamos paso a paso</span>
        </div>
        <a href="mailto:soporte@smartpark.com" class="sup-link">Contactar →</a>
      </div>

    </div>
  </div>

  <!-- DERECHA: Wizard o Confirmación -->
  <div class="split-right">
    <div class="split-form">

      <?php if ($paso === 4 && $successData): ?>
        <!-- ============ PASO 4: ÉXITO ============ -->
        <div style="margin-bottom: 1.75rem; text-align: center;">
          <div style="display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, rgba(16,185,129,0.2), rgba(6,182,212,0.15)); border: 2px solid #10b981; font-size: 2.5rem; color: #10b981; box-shadow: 0 0 40px rgba(16,185,129,0.5); margin-bottom: 1rem;">✓</div>
          <h2 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em;">¡Registro exitoso!</h2>
          <p style="color: #64748b; font-size: 0.88rem; margin-top: 0.5rem;">Tu parqueadero ya está en línea</p>
        </div>

        <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem;">
          <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.06);">
            <span style="color: #94a3b8; font-size: 0.82rem;">Empresa:</span>
            <strong style="color: #f1f5f9; font-size: 0.85rem;"><?= htmlspecialchars($successData['empresa']) ?></strong>
          </div>
          <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.06);">
            <span style="color: #94a3b8; font-size: 0.82rem;">Parqueadero:</span>
            <strong style="color: #f1f5f9; font-size: 0.85rem;"><?= htmlspecialchars($successData['parqueadero']) ?></strong>
          </div>
          <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid rgba(255,255,255,0.06);">
            <span style="color: #94a3b8; font-size: 0.82rem;">Usuario admin:</span>
            <strong style="color: #10b981; font-size: 0.85rem;"><?= htmlspecialchars($successData['admin_user']) ?></strong>
          </div>
          <div style="display: flex; justify-content: space-between; padding: 0.5rem 0;">
            <span style="color: #94a3b8; font-size: 0.82rem;">Estado:</span>
            <strong style="color: #f59e0b; font-size: 0.82rem;">⏳ Pendiente de aprobación</strong>
          </div>
        </div>

        <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 12px; padding: 1rem; margin-bottom: 1.25rem;">
          <p style="color: #fcd34d; font-size: 0.8rem; margin: 0;">
            <strong>◆ Importante:</strong> Tu cuenta quedará activa una vez un developer apruebe el registro (normalmente en menos de 24h). Mientras tanto puedes explorar el sistema.
          </p>
        </div>

        <div style="background: rgba(6, 182, 212, 0.06); border: 1px solid rgba(6, 182, 212, 0.25); border-radius: 12px; padding: 1rem; margin-bottom: 1.25rem; text-align: center;">
          <p style="color: #67e8f9; font-size: 0.78rem; margin-bottom: 0.5rem;">◆ QR único de tu parqueadero</p>
          <div style="font-family: 'JetBrains Mono', monospace; font-size: 0.7rem; color: #94a3b8; word-break: break-all;">
            <?= htmlspecialchars($successData['qr_token']) ?>
          </div>
        </div>

        <a href="login_empresa.php" class="cyber-btn-primary btn-block btn-lg">
          Iniciar sesión ahora →
        </a>

        <p style="text-align: center; margin-top: 1rem;">
          <a href="index.php" style="font-size: 0.82rem; color: #94a3b8;">← Volver al inicio</a>
        </p>

      <?php else: ?>

        <?php if ($paso >= 1 && $paso <= 3): ?>
        <div style="margin-bottom: 1.75rem;">
          <span class="cyber-badge" style="background: rgba(245, 158, 11, 0.15); border-color: rgba(245, 158, 11, 0.5); color: #f59e0b;">
            ◆ Registro de Empresa
          </span>
          <h2 style="font-size: 1.75rem; font-weight: 800; margin-top: 1rem; letter-spacing: -0.02em;">
            <?php
              if ($paso === 1) echo 'Datos de la empresa';
              elseif ($paso === 2) echo 'Datos del parqueadero';
              else echo 'Cuenta del administrador';
            ?>
          </h2>

          <div style="display: flex; gap: 0.5rem; margin-top: 1.25rem;">
            <?php for ($i = 1; $i <= 3; $i++): ?>
              <div style="flex: 1; height: 4px; border-radius: 4px; background: <?= $i <= $paso ? 'linear-gradient(90deg, #10b981, #06b6d4)' : 'rgba(16, 185, 129, 0.15)' ?>; transition: all 0.3s;"></div>
            <?php endfor; ?>
          </div>
          <p style="color: #64748b; font-size: 0.78rem; margin-top: 0.6rem;">Paso <?= $paso ?> de 3</p>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="cyber-alert">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($paso === 1): $d = $_SESSION['reg_empresa']['empresa'] ?? []; ?>
          <form method="POST" class="cyber-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="paso1">
            <div class="form-group">
              <label for="nombre">Nombre de la empresa *</label>
              <input type="text" id="nombre" name="nombre" placeholder="Ej. Parqueadero El Centro" required autofocus value="<?= htmlspecialchars($d['nombre'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="nit">NIT (opcional)</label>
              <input type="text" id="nit" name="nit" placeholder="Ej. 900123456-7" value="<?= htmlspecialchars($d['nit'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="telefono">Teléfono</label>
              <input type="tel" id="telefono" name="telefono" placeholder="+57 300 1234567" value="<?= htmlspecialchars($d['telefono'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="direccion">Dirección</label>
              <input type="text" id="direccion" name="direccion" placeholder="Calle 100 # 15-20" value="<?= htmlspecialchars($d['direccion'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="ciudad">Ciudad</label>
              <input type="text" id="ciudad" name="ciudad" placeholder="Bogotá" value="<?= htmlspecialchars($d['ciudad'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="email_contacto">Correo de contacto</label>
              <input type="email" id="email_contacto" name="email_contacto" placeholder="contacto@tuempresa.com" value="<?= htmlspecialchars($d['email_contacto'] ?? '') ?>">
            </div>
            <button type="submit" class="cyber-btn-primary btn-block btn-lg" style="margin-top: 0.75rem;">
              Continuar → Parqueadero
            </button>
          </form>

        <?php elseif ($paso === 2): $d = $_SESSION['reg_empresa']['parqueadero'] ?? []; ?>
          <form method="POST" class="cyber-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="paso2">
            <div class="form-group">
              <label for="nombre_parqueadero">Nombre del parqueadero *</label>
              <input type="text" id="nombre_parqueadero" name="nombre_parqueadero" placeholder="Ej. Sede Principal" required autofocus value="<?= htmlspecialchars($d['nombre'] ?? 'Sede Principal') ?>">
            </div>
            <div class="form-group">
              <label for="ciudad_parqueadero">Ciudad</label>
              <input type="text" id="ciudad_parqueadero" name="ciudad_parqueadero" placeholder="Bogotá" value="<?= htmlspecialchars($d['ciudad'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="direccion_parqueadero">Dirección</label>
              <input type="text" id="direccion_parqueadero" name="direccion_parqueadero" placeholder="Calle 100 # 15-20" value="<?= htmlspecialchars($d['direccion'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label for="niveles">Número de niveles/sótanos</label>
              <input type="number" id="niveles" name="niveles" min="1" max="10" value="<?= (int)($d['niveles'] ?? 1) ?>">
            </div>
            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 12px; padding: 1rem; margin: 1rem 0;">
              <p style="color: #6ee7b7; font-size: 0.82rem; margin: 0;">
                ✓ Al terminar este paso crearemos automáticamente 20 celdas (10 carros + 10 motos), tarifas por defecto y un QR único.
              </p>
            </div>
            <div style="display: flex; gap: 0.5rem;">
              <a href="registro_empresa.php?paso=1" class="cyber-btn-outline" style="flex: 1; text-align: center;">← Atrás</a>
              <button type="submit" class="cyber-btn-primary" style="flex: 2;">Continuar → Admin</button>
            </div>
          </form>

        <?php elseif ($paso === 3): $d = $_SESSION['reg_empresa'] ?? []; ?>
          <div style="background: rgba(6, 182, 212, 0.08); border: 1px solid rgba(6, 182, 212, 0.3); border-radius: 12px; padding: 1rem; margin-bottom: 1.25rem;">
            <p style="color: #67e8f9; font-size: 0.82rem; margin: 0;">
              ◆ Empresa: <strong><?= htmlspecialchars($d['empresa']['nombre'] ?? '') ?></strong><br>
              ◆ Parqueadero: <strong><?= htmlspecialchars($d['parqueadero']['nombre'] ?? '') ?></strong>
            </p>
          </div>
          <form method="POST" class="cyber-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="paso3">
            <div class="form-group">
              <label for="admin_nombre">Nombre completo del admin *</label>
              <input type="text" id="admin_nombre" name="admin_nombre" placeholder="Ej. Juan Pérez" required autofocus>
            </div>
            <div class="form-group">
              <label for="admin_username">Usuario para iniciar sesión *</label>
              <input type="text" id="admin_username" name="admin_username" placeholder="admin_miempresa" required pattern="[a-zA-Z][a-zA-Z0-9_]{3,19}">
            </div>
            <div class="form-group">
              <label for="admin_email">Correo electrónico</label>
              <input type="email" id="admin_email" name="admin_email" placeholder="admin@tuempresa.com">
            </div>
            <div class="form-group">
              <label for="admin_password">Contraseña *</label>
              <input type="password" id="admin_password" name="admin_password" placeholder="Mínimo 6 caracteres" required minlength="6">
            </div>
            <div class="form-group">
              <label for="admin_password2">Confirmar contraseña *</label>
              <input type="password" id="admin_password2" name="admin_password2" placeholder="Repite la contraseña" required minlength="6">
            </div>
            <div style="display: flex; gap: 0.5rem;">
              <a href="registro_empresa.php?paso=2" class="cyber-btn-outline" style="flex: 1; text-align: center;">← Atrás</a>
              <button type="submit" class="cyber-btn-primary" style="flex: 2;">Crear mi empresa ◆</button>
            </div>
          </form>
        <?php endif; ?>

        <div class="login-help" style="margin-top: 1.5rem;">
          <a href="login_empresa.php">¿Ya tienes cuenta? Inicia sesión</a>
          <div style="display: flex; gap: 0.75rem; justify-content: center; margin-top: 0.5rem; flex-wrap: wrap;">
            
            <a href="login_developer.php" style="font-size: 0.8rem;">Developer</a>
            <a href="index.php" style="font-size: 0.8rem;">Inicio</a>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </div>

</div>

<script src="assets/js/app.js?v=999"></script>

<?php include __DIR__ . '/partials/role_context.php'; ?>
</body>
</html>
