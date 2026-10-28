<?php
/**
 * mt_core_v3.php - Funciones V3 (roles, aprobaciones, QR, Telegram)
 * Se carga automaticamente desde mt_core.php
 */

if (!function_exists('rol_global_actual')) {
    function rol_global_actual(): string { return $_SESSION['role'] ?? 'invitado'; }
}
if (!function_exists('es_developer')) {
    function es_developer(): bool { return rol_global_actual() === 'developer'; }
}
if (!function_exists('es_admin_empresa')) {
    function es_admin_empresa(): bool { return in_array($_SESSION['rol_en_empresa'] ?? '', ['admin','superadmin'], true); }
}
if (!function_exists('es_vigilante')) {
    function es_vigilante(): bool { return ($_SESSION['rol_en_empresa'] ?? '') === 'vigilante'; }
}
if (!function_exists('es_operador')) {
    function es_operador(): bool { return ($_SESSION['rol_en_empresa'] ?? '') === 'operador'; }
}
if (!function_exists('es_finanzas')) {
    function es_finanzas(): bool { return ($_SESSION['rol_en_empresa'] ?? '') === 'finanzas'; }
}

if (!function_exists('require_developer')) {
    function require_developer(): void {
        if (empty($_SESSION['usuario_id'])) { header('Location: login_developer.php'); exit; }
        if (!es_developer()) { http_response_code(403); die('Acceso denegado: solo developers.'); }
    }
}
if (!function_exists('require_admin_empresa')) {
    function require_admin_empresa(): void {
        require_login();
        if (!es_admin_empresa()) { http_response_code(403); die('Acceso denegado: solo admin de empresa.'); }
    }
}
if (!function_exists('require_vigilante_o_admin')) {
    function require_vigilante_o_admin(): void {
        require_login();
        if (!es_vigilante() && !es_admin_empresa() && !es_operador()) { http_response_code(403); die('Acceso denegado.'); }
    }
}

if (!function_exists('aprobar_empresa')) {
    function aprobar_empresa(PDO $pdo, int $empresaId, int $developerId, string $notas = ''): bool {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE empresas SET estado_aprobacion='aprobada', fecha_aprobacion=NOW(), aprobado_por=:dev, notas_admin=:n WHERE id=:id")
                ->execute(['dev'=>$developerId,'n'=>$notas,'id'=>$empresaId]);
            $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id,developer_id,accion,notas) VALUES (:e,:d,'aprobada',:n)")
                ->execute(['e'=>$empresaId,'d'=>$developerId,'n'=>$notas]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) { $pdo->rollBack(); error_log('aprobar_empresa: '.$e->getMessage()); return false; }
    }
}
if (!function_exists('rechazar_empresa')) {
    function rechazar_empresa(PDO $pdo, int $empresaId, int $developerId, string $notas = ''): bool {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE empresas SET estado_aprobacion='rechazada', notas_admin=:n WHERE id=:id")
                ->execute(['n'=>$notas,'id'=>$empresaId]);
            $pdo->prepare("INSERT INTO log_aprobaciones (empresa_id,developer_id,accion,notas) VALUES (:e,:d,'rechazada',:n)")
                ->execute(['e'=>$empresaId,'d'=>$developerId,'n'=>$notas]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) { $pdo->rollBack(); return false; }
    }
}

if (!function_exists('generar_qr_token_parqueadero')) {
    function generar_qr_token_parqueadero(PDO $pdo, int $parqueaderoId): string {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE parqueaderos SET qr_token=:t WHERE id=:id")->execute(['t'=>$token,'id'=>$parqueaderoId]);
        return $token;
    }
}
if (!function_exists('url_parqueadero_por_token')) {
    function url_parqueadero_por_token(PDO $pdo, string $token): ?array {
        $stmt = $pdo->prepare("SELECT p.*, e.nombre AS empresa_nombre, e.id AS empresa_id FROM parqueaderos p JOIN empresas e ON e.id=p.empresa_id WHERE p.qr_token=:t AND p.estado='activo' LIMIT 1");
        $stmt->execute(['t'=>$token]);
        $r = $stmt->fetch();
        return $r ?: null;
    }
}

if (!function_exists('agregar_a_waitlist')) {
    function agregar_a_waitlist(PDO $pdo, int $empresaId, int $parqueaderoId, string $placa, string $tipo = 'carro', ?int $userId = null): int {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM waitlist WHERE parqueadero_id=:p AND estado='esperando'");
        $stmt->execute(['p'=>$parqueaderoId]);
        $pos = (int)$stmt->fetchColumn() + 1;
        $pdo->prepare("INSERT INTO waitlist (empresa_id,parqueadero_id,user_id,placa,tipo_vehiculo,posicion) VALUES (:e,:p,:u,:pl,:t,:pos)")
            ->execute(['e'=>$empresaId,'p'=>$parqueaderoId,'u'=>$userId,'pl'=>$placa,'t'=>$tipo,'pos'=>$pos]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('crear_reserva')) {
    function crear_reserva(PDO $pdo, int $empresaId, int $parqueaderoId, ?int $userId, string $placa, string $inicio, string $fin): ?array {
        try {
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO reservas (empresa_id,parqueadero_id,user_id,placa,fecha_inicio,fecha_fin,qr_token,estado) VALUES (:e,:p,:u,:pl,:fi,:ff,:tk,'pendiente')")
                ->execute(['e'=>$empresaId,'p'=>$parqueaderoId,'u'=>$userId,'pl'=>$placa,'fi'=>$inicio,'ff'=>$fin,'tk'=>$token]);
            return ['id'=>(int)$pdo->lastInsertId(),'token'=>$token];
        } catch (\Throwable $e) { error_log('crear_reserva: '.$e->getMessage()); return null; }
    }
}

if (!function_exists('generar_qr_factura')) {
    function generar_qr_factura(PDO $pdo, int $empresaId, int $registroId, float $monto): ?string {
        try {
            $token = bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO facturas_qr (empresa_id,registro_id,qr_token,monto,estado) VALUES (:e,:r,:tk,:m,'pendiente')")
                ->execute(['e'=>$empresaId,'r'=>$registroId,'tk'=>$token,'m'=>$monto]);
            return $token;
        } catch (\Throwable $e) { error_log('generar_qr_factura: '.$e->getMessage()); return null; }
    }
}

if (!function_exists('generar_codigo_vinculacion_telegram')) {
    function generar_codigo_vinculacion_telegram(PDO $pdo, int $userId): string {
        $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $pdo->prepare("INSERT INTO telegram_usuarios (user_id,chat_id,codigo_vinculacion,autorizado) VALUES (:u,'',:c,0) ON DUPLICATE KEY UPDATE codigo_vinculacion=:c2, autorizado=0")
            ->execute(['u'=>$userId,'c'=>$codigo,'c2'=>$codigo]);
        return $codigo;
    }
}
if (!function_exists('enviar_telegram')) {
    function enviar_telegram(PDO $pdo, string $chatId, string $mensaje): bool {
        $token = get_config($pdo, 'TELEGRAM_BOT_TOKEN', '');
        if (empty($token) || empty($chatId)) return false;
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id'=>$chatId,'text'=>$mensaje,'parse_mode'=>'HTML']),
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
}

if (!function_exists('guardar_config_avanzada')) {
    function guardar_config_avanzada(PDO $pdo, string $clave, string $valor, ?int $empresaId = null, bool $cifrado = false): bool {
        $v = $cifrado ? crypt_encrypt($valor) : $valor;
        $stmt = $pdo->prepare("INSERT INTO configuracion (empresa_id,clave,valor,cifrado) VALUES (:e,:k,:v,:c) ON DUPLICATE KEY UPDATE valor=VALUES(valor), cifrado=VALUES(cifrado)");
        return $stmt->execute(['e'=>$empresaId,'k'=>$clave,'v'=>$v,'c'=>$cifrado?1:0]);
    }
}
if (!function_exists('obtener_config_avanzada')) {
    function obtener_config_avanzada(PDO $pdo, string $clave, $default = null, ?int $empresaId = null) {
        $stmt = $pdo->prepare("SELECT valor,cifrado FROM configuracion WHERE empresa_id=:e AND clave=:k LIMIT 1");
        $stmt->execute(['e'=>$empresaId,'k'=>$clave]);
        $r = $stmt->fetch();
        if (!$r) {
            $stmt = $pdo->prepare("SELECT valor,cifrado FROM configuracion WHERE empresa_id IS NULL AND clave=:k LIMIT 1");
            $stmt->execute(['k'=>$clave]);
            $r = $stmt->fetch();
        }
        if (!$r) return $default;
        return $r['cifrado'] ? crypt_decrypt($r['valor']) : $r['valor'];
    }
}
