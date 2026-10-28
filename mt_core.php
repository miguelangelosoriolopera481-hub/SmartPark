<?php
/**
 * mt_core.php - Núcleo multi-tenant de SmartPark Pro
 * =================================================
 * Incluye:
 *   - Contexto de empresa (empresa_id, parqueadero_id)
 *   - Roles (developer, admin_empresa, operador, vigilante, finanzas, cliente)
 *   - Auditoría
 *   - Cifrado simétrico
 *   - Configuración por empresa
 *   - Tarifas
 *   - Autenticación multi-tenant (con placeholders únicos PDO)
 *   - 2FA con OTP
 *   - Registro de usuarios
 *   - Aprobación de empresas
 *   - QR de parqueadero
 *   - Waitlist, reservas, factura QR
 *   - Telegram
 *   - Backups helpers
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/csrf.php';

/* ============================================================
 * CONTEXTO DE EMPRESA
 * ============================================================ */

if (!function_exists('current_empresa_id')) {
    function current_empresa_id(): ?int {
        return isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null;
    }
}

if (!function_exists('current_parqueadero_id')) {
    function current_parqueadero_id(): ?int {
        return isset($_SESSION['parqueadero_id']) ? (int)$_SESSION['parqueadero_id'] : null;
    }
}

if (!function_exists('require_empresa')) {
    function require_empresa(): int {
        $eid = current_empresa_id();
        if (!$eid) { header('Location: login_empresa.php'); exit; }
        return $eid;
    }
}

if (!function_exists('require_login')) {
    function require_login(): void {
        if (empty($_SESSION['usuario_id'])) {
            header('Location: login_empresa.php');
            exit;
        }
    }
}

if (!function_exists('require_role_empresa')) {
    function require_role_empresa(array $roles): void {
        require_login();
        $rol = $_SESSION['rol_en_empresa'] ?? '';
        if (!in_array($rol, $roles, true)) {
            http_response_code(403);
            die('Acceso denegado: rol insuficiente para esta empresa.');
        }
    }
}

if (!function_exists('require_role')) {
    function require_role(array $roles): void {
        require_login();
        $rol = $_SESSION['role'] ?? '';
        if (!in_array($rol, $roles, true)) {
            http_response_code(403);
            die('Acceso denegado: rol insuficiente.');
        }
    }
}

if (!function_exists('require_password_change_guard')) {
    function require_password_change_guard(): void {
        if (!empty($_SESSION['must_change_password'])) {
            $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
            if (!in_array($script, ['cambiar_password.php', 'logout.php'], true)) {
                header('Location: cambiar_password.php');
                exit;
            }
        }
    }
}

/* ============================================================
 * ROLES
 * ============================================================ */

if (!function_exists('rol_global_actual')) {
    function rol_global_actual(): string {
        return $_SESSION['role'] ?? 'invitado';
    }
}

if (!function_exists('es_developer')) {
    function es_developer(): bool {
        return rol_global_actual() === 'developer';
    }
}

if (!function_exists('es_admin_empresa')) {
    function es_admin_empresa(): bool {
        $rol = $_SESSION['rol_en_empresa'] ?? '';
        return in_array($rol, ['admin', 'superadmin'], true);
    }
}

if (!function_exists('es_vigilante')) {
    function es_vigilante(): bool {
        return ($_SESSION['rol_en_empresa'] ?? '') === 'vigilante';
    }
}

if (!function_exists('es_operador')) {
    function es_operador(): bool {
        return ($_SESSION['rol_en_empresa'] ?? '') === 'operador';
    }
}

if (!function_exists('es_finanzas')) {
    function es_finanzas(): bool {
        return ($_SESSION['rol_en_empresa'] ?? '') === 'finanzas';
    }
}

if (!function_exists('require_developer')) {
    function require_developer(): void {
        if (empty($_SESSION['usuario_id'])) {
            header('Location: login_developer.php');
            exit;
        }
        if (!es_developer()) {
            http_response_code(403);
            die('Acceso denegado: solo developers.');
        }
    }
}

/* ============================================================
 * AUDITORÍA
 * ============================================================ */

if (!function_exists('registrarAuditoria')) {
    function registrarAuditoria(PDO $pdo, string $accion, string $detalle = ''): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO auditoria_admin (empresa_id, usuario_id, usuario_nombre, accion, detalle, ip, creado_en)
                VALUES (:eid, :uid, :nombre, :accion, :detalle, :ip, NOW())
            ");
            $stmt->execute([
                'eid'     => current_empresa_id(),
                'uid'     => $_SESSION['usuario_id'] ?? null,
                'nombre'  => $_SESSION['nombre'] ?? 'sistema',
                'accion'  => $accion,
                'detalle' => $detalle,
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('registrarAuditoria: ' . $e->getMessage());
        }
    }
}

/* ============================================================
 * CIFRADO SIMÉTRICO
 * ============================================================ */

if (!function_exists('crypt_encrypt')) {
    function crypt_encrypt(string $plain): string {
        $key = base64_decode(env('APP_CRYPTO_KEY', ''));
        if (strlen($key) !== 32) return '';
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }
}

if (!function_exists('crypt_decrypt')) {
    function crypt_decrypt(string $payload): string {
        $key = base64_decode(env('APP_CRYPTO_KEY', ''));
        if (strlen($key) !== 32) return '';
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $r = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $r === false ? '' : $r;
    }
}

/* ============================================================
 * CONFIGURACIÓN
 * ============================================================ */

if (!function_exists('get_config')) {
    function get_config(PDO $pdo, string $clave, $default = null) {
        try {
            $eid = current_empresa_id();
            
            if ($eid !== null) {
                $stmt = $pdo->prepare("SELECT valor, cifrado FROM configuracion WHERE empresa_id = :eid AND clave = :k LIMIT 1");
                $stmt->execute(['eid' => $eid, 'k' => $clave]);
                $row = $stmt->fetch();
                if ($row) return $row['cifrado'] ? crypt_decrypt($row['valor']) : $row['valor'];
            }
            
            $stmt = $pdo->prepare("SELECT valor, cifrado FROM configuracion WHERE empresa_id IS NULL AND clave = :k LIMIT 1");
            $stmt->execute(['k' => $clave]);
            $row = $stmt->fetch();
            if ($row) return $row['cifrado'] ? crypt_decrypt($row['valor']) : $row['valor'];
        } catch (\Throwable $e) {
            error_log('get_config: ' . $e->getMessage());
        }
        return env($clave, $default);
    }
}

if (!function_exists('set_config')) {
    function set_config(PDO $pdo, string $clave, string $valor, bool $cifrar = false): bool {
        $valorF = $cifrar ? crypt_encrypt($valor) : $valor;
        $stmt = $pdo->prepare("
            INSERT INTO configuracion (empresa_id, clave, valor, cifrado)
            VALUES (:eid, :k, :v, :c)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), cifrado = VALUES(cifrado)
        ");
        return $stmt->execute(['eid' => current_empresa_id(), 'k' => $clave, 'v' => $valorF, 'c' => $cifrar ? 1 : 0]);
    }
}

/* ============================================================
 * TARIFAS
 * ============================================================ */

if (!function_exists('get_tarifa')) {
    function get_tarifa(PDO $pdo, string $tipoVehiculo, string $tipoTarifa = 'hora'): float {
        try {
            $stmt = $pdo->prepare("
                SELECT precio FROM tarifas
                WHERE empresa_id = :eid AND tipo_vehiculo = :tv AND tipo_tarifa = :tt AND activo = 1
                ORDER BY vigente_desde DESC LIMIT 1
            ");
            $stmt->execute(['eid' => current_empresa_id(), 'tv' => $tipoVehiculo, 'tt' => $tipoTarifa]);
            $r = $stmt->fetch();
            return $r ? (float)$r['precio'] : 0.0;
        } catch (\Throwable $e) {
            return ($tipoVehiculo === 'moto') ? 2000.0 : 4000.0;
        }
    }
}

/* ============================================================
 * HASH SEGURO
 * ============================================================ */

if (!function_exists('hash_password_seguro')) {
    function hash_password_seguro(string $plain): string {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2,
            ]);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

/* ============================================================
 * RATE LIMITING
 * ============================================================ */

if (!function_exists('login_intentos_recientes')) {
    function login_intentos_recientes(PDO $pdo, string $ident, string $ip): int {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS t FROM login_intentos
                WHERE (identificador = :i OR ip = :ip)
                  AND exito = 0
                  AND creado_en > (NOW() - INTERVAL 15 MINUTE)
            ");
            $stmt->execute(['i' => strtolower($ident), 'ip' => $ip]);
            return (int)($stmt->fetch()['t'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('login_registrar_intento')) {
    function login_registrar_intento(PDO $pdo, string $ident, string $ip, ?int $empresaId, bool $exito): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO login_intentos (identificador, empresa_id, ip, user_agent, exito, creado_en)
                VALUES (:i, :eid, :ip, :ua, :ok, NOW())
            ");
            $stmt->execute([
                'i' => strtolower($ident),
                'eid' => $empresaId,
                'ip' => $ip,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
                'ok' => $exito ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            error_log('login_registrar_intento: ' . $e->getMessage());
        }
    }
}

/* ============================================================
 * AUTENTICACIÓN MULTI-TENANT (PASO 1)
 * ============================================================ */

if (!function_exists('autenticar_paso1')) {
    function autenticar_paso1(PDO $pdo, string $username, string $password, int $empresaId): array {
        $r = ['exito' => false, 'mensaje' => '', 'user' => null, 'rol_empresa' => null, 'necesita_2fa' => false];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($empresaId <= 0) {
            $r['mensaje'] = 'Selecciona una empresa válida.';
            return $r;
        }
        if ($username === '' || $password === '') {
            $r['mensaje'] = 'Usuario y contraseña son obligatorios.';
            return $r;
        }

        $intentos = login_intentos_recientes($pdo, $username, $ip);
        if ($intentos >= 5) {
            $r['mensaje'] = 'Cuenta temporalmente bloqueada. Espera 15 minutos.';
            return $r;
        }

        try {
            // FIX: placeholders únicos (u1, u2) para evitar "Invalid parameter number"
            // SEGURIDAD MULTI-TENANT: Verificar que la empresa esté aprobada
            $stmtEmpCheck = $pdo->prepare("SELECT estado_aprobacion FROM empresas WHERE id = :eid");
            $stmtEmpCheck->execute(['eid' => $empresaId]);
            $estadoAprob = $stmtEmpCheck->fetchColumn();
            
            if ($estadoAprob !== 'aprobada') {
                $r['mensaje'] = 'Tu empresa aún está pendiente de aprobación o fue rechazada. Contacta al administrador.';
                return $r;
            }

            $stmt = $pdo->prepare("
                SELECT u.*, ue.rol_en_empresa, ue.parqueadero_id, ue.activo AS ue_activo
                FROM user u
                INNER JOIN usuarios_empresa ue ON ue.user_id = u.id AND ue.empresa_id = :eid
                WHERE (u.username = :u1 OR u.correo = :u2) AND ue.activo = 1
                LIMIT 1
            ");
            $stmt->execute([
                'u1' => $username,
                'u2' => $username,
                'eid' => $empresaId,
            ]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                login_registrar_intento($pdo, $username, $ip, $empresaId, false);
                $restantes = 5 - ($intentos + 1);
                $r['mensaje'] = 'Credenciales incorrectas.'
                    . ($restantes <= 2 && $restantes >= 0 ? " ($restantes intento(s) restantes)" : '');
                return $r;
            }

            // Rehash transparente a Argon2id/Bcrypt
            if (password_needs_rehash($user['password'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT, ['cost' => 12])) {
                $nuevo = hash_password_seguro($password);
                $pdo->prepare("UPDATE user SET password = :h WHERE id = :id")
                    ->execute(['h' => $nuevo, 'id' => $user['id']]);
            }

            login_registrar_intento($pdo, $username, $ip, $empresaId, true);

            $r['exito']        = true;
            $r['user']         = $user;
            $r['rol_empresa']  = $user['rol_en_empresa'];
            $r['necesita_2fa'] = in_array($user['rol_en_empresa'], ['admin', 'superadmin', 'finanzas'], true);
            return $r;
        } catch (\Throwable $e) {
            error_log('autenticar_paso1: ' . $e->getMessage());
            $r['mensaje'] = 'Error interno. Intenta nuevamente.';
            return $r;
        }
    }
}

/* ============================================================
 * 2FA - OTP
 * ============================================================ */

if (!function_exists('crear_otp_2fa')) {
    function crear_otp_2fa(PDO $pdo, int $userId, int $empresaId, string $metodo, string $destino): string {
        $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash   = password_hash($codigo, PASSWORD_BCRYPT, ['cost' => 10]);

        $pdo->prepare("UPDATE sesiones_2fa SET verificado = 1 WHERE user_id = :u AND verificado = 0")
            ->execute(['u' => $userId]);

        $pdo->prepare("
            INSERT INTO sesiones_2fa (user_id, empresa_id, codigo_hash, metodo, destino, expira_en, ip)
            VALUES (:u, :e, :h, :m, :d, (NOW() + INTERVAL 5 MINUTE), :ip)
        ")->execute([
            'u' => $userId, 'e' => ($empresaId > 0) ? $empresaId : null, 'h' => $hash,
            'm' => $metodo, 'd' => $destino,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        return $codigo;
    }
}

if (!function_exists('verificar_otp_2fa')) {
    function verificar_otp_2fa(PDO $pdo, int $userId, string $codigo): array {
        $stmt = $pdo->prepare("
            SELECT * FROM sesiones_2fa
            WHERE user_id = :u AND verificado = 0 AND expira_en > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch();

        if (!$row) return ['ok' => false, 'mensaje' => 'Código expirado o no solicitado.'];
        if ($row['intentos'] >= 5) return ['ok' => false, 'mensaje' => 'Demasiados intentos. Solicita un nuevo código.'];

        $pdo->prepare("UPDATE sesiones_2fa SET intentos = intentos + 1 WHERE id = :id")
            ->execute(['id' => $row['id']]);

        if (!password_verify($codigo, $row['codigo_hash'])) {
            return ['ok' => false, 'mensaje' => 'Código incorrecto.'];
        }
        $pdo->prepare("UPDATE sesiones_2fa SET verificado = 1 WHERE id = :id")->execute(['id' => $row['id']]);
        return ['ok' => true, 'mensaje' => 'Verificado.'];
    }
}

/* ============================================================
 * SESIÓN COMPLETA
 * ============================================================ */

if (!function_exists('establecer_sesion_completa')) {
    function establecer_sesion_completa(array $user, int $empresaId): void {
        session_regenerate_id(true);
        $_SESSION['usuario_id']     = (int)$user['id'];
        $_SESSION['username']       = $user['username'];
        $_SESSION['nombre']         = $user['nombre'];
        $_SESSION['correo']         = $user['correo'] ?? '';
        $_SESSION['role']           = $user['role'] ?? 'cliente';
        $_SESSION['empresa_id']     = $empresaId;
        $_SESSION['parqueadero_id'] = (int)($user['parqueadero_id'] ?? 1);
        $_SESSION['rol_en_empresa'] = $user['rol_en_empresa'] ?? 'cliente';
        $_SESSION['login_time']     = time();
        $_SESSION['csrf_token']     = bin2hex(random_bytes(32));
    }
}

/* ============================================================
 * REGISTRO DE USUARIO (compatibilidad)
 * ============================================================ */

if (!function_exists('registrarUsuario')) {
    function registrarUsuario(PDO $pdo, string $nombre, string $username, ?string $correo, string $password): array {
        $r = ['exito' => false, 'mensaje' => ''];

        if (empty($nombre) || empty($username) || empty($password)) {
            $r['mensaje'] = 'Nombre, usuario y contraseña son obligatorios.';
            return $r;
        }
        if (strlen($password) < 6) {
            $r['mensaje'] = 'La contraseña debe tener al menos 6 caracteres.';
            return $r;
        }
        if (!empty($correo) && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $r['mensaje'] = 'El correo electrónico no es válido.';
            return $r;
        }

        try {
            // FIX: placeholders únicos
            $check = $pdo->prepare("
                SELECT id FROM user 
                WHERE username = :u1 OR (correo IS NOT NULL AND correo <> '' AND correo = :c1) 
                LIMIT 1
            ");
            $check->execute([
                'u1' => $username,
                'c1' => $correo ?? '',
            ]);
            if ($check->fetch()) {
                $r['mensaje'] = 'El usuario o correo ya está registrado.';
                return $r;
            }

            $hash = hash_password_seguro($password);
            $ins = $pdo->prepare("
                INSERT INTO user (nombre, username, correo, password, role, must_change_password)
                VALUES (:n, :u, :c, :p, 'cliente', 0)
            ");
            $ins->execute([
                'n' => $nombre,
                'u' => $username,
                'c' => !empty($correo) ? $correo : null,
                'p' => $hash,
            ]);

            $r['exito'] = true;
            $r['mensaje'] = '¡Registro exitoso! Ya puedes iniciar sesión.';
        } catch (\Throwable $e) {
            error_log('registrarUsuario: ' . $e->getMessage());
            $r['mensaje'] = 'No se pudo completar el registro.';
        }

        return $r;
    }
}

/* ============================================================
 * INICIAR SESIÓN (compatibilidad legacy)
 * ============================================================ */

if (!function_exists('iniciarSesion')) {
    function iniciarSesion(PDO $pdo, string $username, string $password): array {
        $r = ['exito' => false, 'mensaje' => '', 'role' => ''];

        if (empty($username) || empty($password)) {
            $r['mensaje'] = 'Por favor ingresa usuario y contraseña.';
            return $r;
        }

        try {
            // FIX: placeholders únicos
            $stmt = $pdo->prepare("SELECT * FROM user WHERE username = :u1 OR correo = :u2 LIMIT 1");
            $stmt->execute(['u1' => $username, 'u2' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id'] = (int)$user['id'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['nombre']     = $user['nombre'];
                $_SESSION['correo']     = $user['correo'] ?? '';
                $_SESSION['role']       = $user['role'] ?? 'cliente';
                $_SESSION['must_change_password'] = (bool)($user['must_change_password'] ?? 0);

                $r['exito'] = true;
                $r['role']  = $user['role'];
                $r['mensaje'] = 'Sesión iniciada.';
            } else {
                $r['mensaje'] = 'Usuario o contraseña incorrectos.';
            }
        } catch (\Throwable $e) {
            error_log('iniciarSesion: ' . $e->getMessage());
            $r['mensaje'] = 'Error al iniciar sesión.';
        }
        return $r;
    }
}

/* ============================================================
 * PROCESAR AUTH POST (unificado)
 * ============================================================ */

if (!function_exists('procesarAuthPost')) {
    function procesarAuthPost(PDO $pdo, string $modoActual): array {
        $res = ['modo' => $modoActual, 'error' => '', 'mensaje_exito' => '', 'redirect' => null];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return $res;

        if (isset($_POST['action_register'])) {
            $res['modo'] = 'registro';
            if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
                $res['error'] = 'Sesión de formulario expirada.';
                return $res;
            }
            $r = registrarUsuario(
                $pdo,
                trim($_POST['nombre'] ?? ''),
                trim($_POST['username'] ?? ''),
                trim($_POST['correo'] ?? ''),
                (string)($_POST['password'] ?? '')
            );
            if ($r['exito']) {
                $res['mensaje_exito'] = $r['mensaje'];
                $res['modo'] = 'login';
            } else {
                $res['error'] = $r['mensaje'];
            }
            return $res;
        }

        if (isset($_POST['action_login'])) {
            $res['modo'] = 'login';
            if (!csrf_verificar($_POST['csrf_token'] ?? null)) {
                $res['error'] = 'Sesión de formulario expirada.';
                return $res;
            }
            $r = iniciarSesion($pdo, trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            if ($r['exito']) {
                if (!empty($_SESSION['must_change_password'])) {
                    $res['redirect'] = 'cambiar_password.php';
                } elseif (in_array($r['role'], ['admin', 'operador'], true)) {
                    $res['redirect'] = 'admin.php';
                } else {
                    $res['redirect'] = 'index.php';
                }
            } else {
                $res['error'] = $r['mensaje'];
            }
        }
        return $res;
    }
}

/* ============================================================
 * APROBACIÓN DE EMPRESAS
 * ============================================================ */

if (!function_exists('aprobar_empresa')) {
    function aprobar_empresa(PDO $pdo, int $empresaId, int $developerId, string $notas = ''): bool {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("
                UPDATE empresas 
                SET estado_aprobacion = 'aprobada', 
                    fecha_aprobacion = NOW(),
                    aprobado_por = :dev,
                    notas_admin = :n
                WHERE id = :id
            ")->execute(['dev' => $developerId, 'n' => $notas, 'id' => $empresaId]);
            $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id, developer_id, accion, notas) VALUES (:e, :d, 'aprobada', :n)")
                ->execute(['e' => $empresaId, 'd' => $developerId, 'n' => $notas]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('aprobar_empresa: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('rechazar_empresa')) {
    function rechazar_empresa(PDO $pdo, int $empresaId, int $developerId, string $notas = ''): bool {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE empresas SET estado_aprobacion = 'rechazada', notas_admin = :n WHERE id = :id")
                ->execute(['n' => $notas, 'id' => $empresaId]);
            $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id, developer_id, accion, notas) VALUES (:e, :d, 'rechazada', :n)")
                ->execute(['e' => $empresaId, 'd' => $developerId, 'n' => $notas]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return false;
        }
    }
}

/* ============================================================
 * QR PARQUEADERO
 * ============================================================ */

if (!function_exists('generar_qr_token_parqueadero')) {
    function generar_qr_token_parqueadero(PDO $pdo, int $parqueaderoId): string {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE parqueaderos SET qr_token = :t WHERE id = :id")
            ->execute(['t' => $token, 'id' => $parqueaderoId]);
        return $token;
    }
}

if (!function_exists('url_parqueadero_por_token')) {
    function url_parqueadero_por_token(PDO $pdo, string $token): ?array {
        $stmt = $pdo->prepare("
            SELECT p.*, e.nombre AS empresa_nombre, e.id AS empresa_id
            FROM parqueaderos p
            JOIN empresas e ON e.id = p.empresa_id
            WHERE p.qr_token = :t AND p.estado = 'activo'
            LIMIT 1
        ");
        $stmt->execute(['t' => $token]);
        $r = $stmt->fetch();
        return $r ?: null;
    }
}

/* ============================================================
 * WAITLIST
 * ============================================================ */

if (!function_exists('agregar_a_waitlist')) {
    function agregar_a_waitlist(PDO $pdo, int $empresaId, int $parqueaderoId, string $placa, string $tipo = 'carro', ?int $userId = null): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM waitlist WHERE parqueadero_id = :p AND estado = 'esperando'");
        $stmt->execute(['p' => $parqueaderoId]);
        $pos = (int)$stmt->fetchColumn() + 1;

        $pdo->prepare("
            INSERT INTO waitlist (empresa_id, parqueadero_id, user_id, placa, tipo_vehiculo, posicion)
            VALUES (:e, :p, :u, :pl, :t, :pos)
        ")->execute([
            'e' => $empresaId, 'p' => $parqueaderoId, 'u' => $userId,
            'pl' => $placa, 't' => $tipo, 'pos' => $pos,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

/* ============================================================
 * RESERVAS
 * ============================================================ */

if (!function_exists('crear_reserva')) {
    function crear_reserva(PDO $pdo, int $empresaId, int $parqueaderoId, ?int $userId, string $placa, string $inicio, string $fin): ?array {
        try {
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("
                INSERT INTO reservas (empresa_id, parqueadero_id, user_id, placa, fecha_inicio, fecha_fin, qr_token, estado)
                VALUES (:e, :p, :u, :pl, :fi, :ff, :tk, 'pendiente')
            ")->execute([
                'e' => $empresaId, 'p' => $parqueaderoId, 'u' => $userId,
                'pl' => $placa, 'fi' => $inicio, 'ff' => $fin, 'tk' => $token,
            ]);
            return ['id' => (int)$pdo->lastInsertId(), 'token' => $token];
        } catch (\Throwable $e) {
            error_log('crear_reserva: ' . $e->getMessage());
            return null;
        }
    }
}

/* ============================================================
 * FACTURA QR
 * ============================================================ */

if (!function_exists('generar_qr_factura')) {
    function generar_qr_factura(PDO $pdo, int $empresaId, int $registroId, float $monto): ?string {
        try {
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("
                INSERT INTO facturas_qr (empresa_id, registro_id, qr_token, monto, estado)
                VALUES (:e, :r, :tk, :m, 'pendiente')
            ")->execute(['e' => $empresaId, 'r' => $registroId, 'tk' => $token, 'm' => $monto]);
            return $token;
        } catch (\Throwable $e) {
            error_log('generar_qr_factura: ' . $e->getMessage());
            return null;
        }
    }
}

/* ============================================================
 * CONFIG AVANZADA
 * ============================================================ */

if (!function_exists('guardar_config_avanzada')) {
    function guardar_config_avanzada(PDO $pdo, string $clave, string $valor, ?int $empresaId = null, bool $cifrado = false): bool {
        $v = $cifrado ? crypt_encrypt($valor) : $valor;
        $stmt = $pdo->prepare("
            INSERT INTO configuracion (empresa_id, clave, valor, cifrado)
            VALUES (:e, :k, :v, :c)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), cifrado = VALUES(cifrado)
        ");
        return $stmt->execute(['e' => $empresaId, 'k' => $clave, 'v' => $v, 'c' => $cifrado ? 1 : 0]);
    }
}

if (!function_exists('obtener_config_avanzada')) {
    function obtener_config_avanzada(PDO $pdo, string $clave, $default = null, ?int $empresaId = null) {
        $stmt = $pdo->prepare("SELECT valor, cifrado FROM configuracion WHERE empresa_id = :e AND clave = :k LIMIT 1");
        $stmt->execute(['e' => $empresaId, 'k' => $clave]);
        $r = $stmt->fetch();
        if (!$r) {
            $stmt = $pdo->prepare("SELECT valor, cifrado FROM configuracion WHERE empresa_id IS NULL AND clave = :k LIMIT 1");
            $stmt->execute(['k' => $clave]);
            $r = $stmt->fetch();
        }
        if (!$r) return $default;
        return $r['cifrado'] ? crypt_decrypt($r['valor']) : $r['valor'];
    }
}

/* ============================================================
 * CARGAR MÓDULO V3 (Backups, aprobaciones, QR)
 * ============================================================ */
if (file_exists(__DIR__ . '/mt_core_v3.php')) {
    require_once __DIR__ . '/mt_core_v3.php';
}

/* ============================================================
 * ALIAS DE COMPATIBILIDAD (nombres viejos → nombres nuevos)
 * ============================================================ */

if (!function_exists('obtenerConfiguracion')) {
    function obtenerConfiguracion(PDO $pdo, string $clave, $default = null) {
        return get_config($pdo, $clave, $default);
    }
}

if (!function_exists('guardarConfiguracion')) {
    function guardarConfiguracion(PDO $pdo, string $clave, string $valor): bool {
        $stmt = $pdo->prepare("
            INSERT INTO configuracion (empresa_id, clave, valor, cifrado)
            VALUES (:eid, :k, :v, 0)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)
        ");
        return $stmt->execute(['eid' => current_empresa_id(), 'k' => $clave, 'v' => $valor]);
    }
}