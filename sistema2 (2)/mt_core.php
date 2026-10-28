<?php
/**
 * mt_core.php - Funciones multi-tenant que faltaban en helpers.php y auth_handler.php
 * Se carga automáticamente desde conexion.php
 */

/* ============================================================
 * HELPERS DE CONTEXTO (empresa actual)
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
        if (empty($_SESSION['usuario_id'])) { header('Location: login_empresa.php'); exit; }
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

/* ============================================================
 * AUDITORÍA
 * ============================================================ */
if (!function_exists('registrarAuditoria')) {
    function registrarAuditoria(PDO $pdo, string $accion, string $detalle = ''): void {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO auditoria_admin (empresa_id, user_id, usuario_nombre, accion, detalle, ip, creado_en)
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
        } catch (\Throwable $e) { error_log('audit: ' . $e->getMessage()); }
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
        $result = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $result === false ? '' : $result;
    }
}

/* ============================================================
 * CONFIGURACIÓN DINÁMICA POR EMPRESA
 * ============================================================ */
if (!function_exists('get_config')) {
    function get_config(PDO $pdo, string $clave, $default = null) {
        try {
            $stmt = $pdo->prepare("SELECT valor, cifrado FROM configuracion WHERE empresa_id = :eid AND clave = :k LIMIT 1");
            $stmt->execute(['eid' => current_empresa_id(), 'k' => $clave]);
            $row = $stmt->fetch();
            if ($row) return $row['cifrado'] ? crypt_decrypt($row['valor']) : $row['valor'];
        } catch (\Throwable $e) {}
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
 * AUTENTICACIÓN MULTI-TENANT
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
        } catch (\Throwable $e) { return 0; }
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
                'i' => strtolower($ident), 'eid' => $empresaId, 'ip' => $ip,
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
                'ok' => $exito ? 1 : 0,
            ]);
        } catch (\Throwable $e) { error_log('login_registrar_intento: ' . $e->getMessage()); }
    }
}

if (!function_exists('autenticar_paso1')) {
    function autenticar_paso1(PDO $pdo, string $username, string $password, int $empresaId): array {
        $r = ['exito' => false, 'mensaje' => '', 'user' => null, 'rol_empresa' => null, 'necesita_2fa' => false];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($empresaId <= 0) { $r['mensaje'] = 'Selecciona una empresa valida.'; return $r; }
        if ($username === '' || $password === '') { $r['mensaje'] = 'Usuario y contrasena obligatorios.'; return $r; }

        $intentos = login_intentos_recientes($pdo, $username, $ip);
        if ($intentos >= 5) {
            $r['mensaje'] = 'Cuenta temporalmente bloqueada. Espera 15 minutos.';
            return $r;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT u.*, ue.rol_en_empresa, ue.parqueadero_id, ue.activo AS ue_activo
                FROM user u
                INNER JOIN usuarios_empresa ue ON ue.user_id = u.id AND ue.empresa_id = :eid
                WHERE (u.username = :u OR u.correo = :u) AND ue.activo = 1
                LIMIT 1
            ");
            $stmt->execute(['u' => $username, 'eid' => $empresaId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                login_registrar_intento($pdo, $username, $ip, $empresaId, false);
                $restantes = 5 - ($intentos + 1);
                $r['mensaje'] = 'Credenciales incorrectas.'
                    . ($restantes <= 2 && $restantes >= 0 ? " ($restantes intento(s) restantes)" : '');
                return $r;
            }

            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
                $nuevo = hash_password_seguro($password);
                $pdo->prepare("UPDATE user SET password = :h WHERE id = :id")
                    ->execute(['h' => $nuevo, 'id' => $user['id']]);
            }

            login_registrar_intento($pdo, $username, $ip, $empresaId, true);

            $r['exito']        = true;
            $r['user']         = $user;
            $r['rol_empresa']  = $user['rol_en_empresa'];
            $r['necesita_2fa'] = in_array($user['rol_en_empresa'], ['admin','finanzas','superadmin'], true);
            return $r;
        } catch (\Throwable $e) {
            error_log('autenticar_paso1: ' . $e->getMessage());
            $r['mensaje'] = 'Error interno. Intenta nuevamente.';
            return $r;
        }
    }
}

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
            'u' => $userId, 'e' => $empresaId, 'h' => $hash,
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

        if (!$row) return ['ok' => false, 'mensaje' => 'Codigo expirado o no solicitado.'];
        if ($row['intentos'] >= 5) return ['ok' => false, 'mensaje' => 'Demasiados intentos. Solicita un nuevo codigo.'];

        $pdo->prepare("UPDATE sesiones_2fa SET intentos = intentos + 1 WHERE id = :id")
            ->execute(['id' => $row['id']]);

        if (!password_verify($codigo, $row['codigo_hash'])) {
            return ['ok' => false, 'mensaje' => 'Codigo incorrecto.'];
        }
        $pdo->prepare("UPDATE sesiones_2fa SET verificado = 1 WHERE id = :id")->execute(['id' => $row['id']]);
        return ['ok' => true, 'mensaje' => 'Verificado.'];
    }
}

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