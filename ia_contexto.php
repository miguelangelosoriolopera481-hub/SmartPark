<?php
/**
 * ia_contexto.php - Contexto seguro por rol para la IA
 * =====================================================
 * REGLAS ESTRICTAS:
 *   - Cliente:    solo sus propios datos
 *   - Vigilante:  solo celdas + registros activos de su empresa
 *   - Finanzas:   solo datos financieros de su empresa
 *   - Admin:      todos los datos de SU empresa
 *   - Developer:  datos globales de TODAS las empresas
 *
 * NUNCA se expone:
 *   - Contraseñas
 *   - API keys de otros
 *   - Datos de empresas ajenas
 *   - Configuración global a no-developers
 */

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/helpers.php';

/* ============================================================
 * DETECCIÓN DE PREGUNTAS FUERA DE ALCANCE
 * ============================================================ */

if (!function_exists('ia_pregunta_fuera_de_alcance')) {
    /**
     * Detecta si la pregunta intenta acceder a info que el rol no debería ver.
     * Devuelve null si está OK, o un mensaje si está bloqueada.
     */
    function ia_pregunta_fuera_de_alcance(string $pregunta, string $rol): ?string {
        $p = mb_strtolower($pregunta);

        // Palabras sospechosas por rol
        $prohibidasPorRol = [
            'cliente' => [
                'password', 'contraseña', 'hash', 'admin', 'configuracion',
                'todos los usuarios', 'todas las empresas', 'otra empresa',
                'api key', 'token', 'developer', 'smtp', 'mysql', 'base de datos',
                'facturación global', 'recaudo total de todas', 'lista de usuarios',
                'cuántos usuarios hay', 'todos los clientes'
            ],
            'vigilante' => [
                'password', 'contraseña', 'finanzas', 'recaudo', 'ingresos',
                'api key', 'developer', 'smtp', 'otra empresa', 'configuracion global',
                'recaudo total', 'total de dinero', 'facturación'
            ],
            'finanzas' => [
                'password', 'contraseña', 'api key', 'developer', 'smtp',
                'otra empresa', 'configuracion global', 'usuarios de otra'
            ],
            'admin' => [
                'password', 'contraseña', 'api key de otros', 'otra empresa',
                'developer', 'configuracion global del sistema',
                'todos los usuarios del sistema', 'todas las empresas'
            ],
            'operador' => [
                'password', 'contraseña', 'api key', 'developer', 'smtp',
                'otra empresa', 'configuracion global'
            ],
        ];

        $lista = $prohibidasPorRol[$rol] ?? [];

        foreach ($lista as $palabra) {
            if (str_contains($p, $palabra)) {
                return "Tu rol ({$rol}) no tiene permisos para consultar ese tipo de información. "
                     . "Si necesitas ayuda sobre tu trabajo diario, pregúntame sobre "
                     . "tus celdas, tus registros o tus tareas del día.";
            }
        }

        return null;
    }
}

/* ============================================================
 * CONSTRUIR CONTEXTO SEGÚN ROL
 * ============================================================ */

if (!function_exists('ia_construir_contexto')) {
    /**
     * Devuelve un string con el contexto seguro para el rol.
     * TODO el contexto viene filtrado por empresa_id cuando aplica.
     */
    function ia_construir_contexto(PDO $pdo, string $rol, int $eid, int $uid, ?int $parqueaderoId = null): string {
        // Developer → contexto GLOBAL
        if ($rol === 'developer') {
            return ia_contexto_developer($pdo);
        }

        // Admin de empresa → contexto COMPLETO de su empresa
        if (in_array($rol, ['admin', 'superadmin'], true)) {
            return ia_contexto_admin($pdo, $eid);
        }

        // Finanzas → contexto FINANCIERO de su empresa
        if ($rol === 'finanzas') {
            return ia_contexto_finanzas($pdo, $eid);
        }

        // Vigilante → contexto OPERATIVO de su empresa
        if ($rol === 'vigilante') {
            return ia_contexto_vigilante($pdo, $eid, $parqueaderoId);
        }

        // Operador → similar a vigilante pero más amplio
        if ($rol === 'operador') {
            return ia_contexto_vigilante($pdo, $eid, $parqueaderoId);
        }

        // Cliente → SOLO sus propios datos
        if ($rol === 'cliente') {
            return ia_contexto_cliente($pdo, $eid, $uid);
        }

        // Rol desconocido → mínimo
        return "Eres SmartPark AI. El usuario tiene un rol no reconocido. "
             . "Solo puedes dar información general sobre cómo usar el sistema. "
             . "No intentes acceder a datos específicos.";
    }
}

/* ============================================================
 * CONTEXTOS POR ROL
 * ============================================================ */

if (!function_exists('ia_contexto_developer')) {
    function ia_contexto_developer(PDO $pdo): string {
        try {
            $empresas = (int)$pdo->query("SELECT COUNT(*) FROM empresas")->fetchColumn();
            $usuarios = (int)$pdo->query("SELECT COUNT(*) FROM user")->fetchColumn();
            $parqueaderos = (int)$pdo->query("SELECT COUNT(*) FROM parqueaderos")->fetchColumn();
            $cupos = (int)$pdo->query("SELECT COUNT(*) FROM cupos")->fetchColumn();
            $activos = (int)$pdo->query("SELECT COUNT(*) FROM registros_parqueo WHERE estado='activo'")->fetchColumn();
            $recaudo = (float)$pdo->query("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE estado_pago=1")->fetchColumn();

            $topEmpresas = $pdo->query("
                SELECT e.nombre, COUNT(rp.id) AS total
                FROM empresas e
                LEFT JOIN registros_parqueo rp ON rp.empresa_id = e.id
                GROUP BY e.id
                ORDER BY total DESC
                LIMIT 5
            ")->fetchAll();

            $topTxt = '';
            foreach ($topEmpresas as $e) {
                $topTxt .= "  · {$e['nombre']}: {$e['total']} registros\n";
            }

            return "Eres SmartPark AI en MODO DEVELOPER (acceso global).\n"
                 . "Contexto del sistema completo:\n"
                 . "- Empresas registradas: $empresas\n"
                 . "- Usuarios totales: $usuarios\n"
                 . "- Parqueaderos totales: $parqueaderos\n"
                 . "- Celdas totales: $cupos\n"
                 . "- Vehículos activos ahora: $activos\n"
                 . "- Recaudo histórico global: $" . number_format($recaudo, 0, ',', '.') . " COP\n"
                 . "Top 5 empresas por actividad:\n$topTxt\n"
                 . "Puedes responder sobre CUALQUIER empresa, usuario o el sistema en general.";
        } catch (\Throwable $e) {
            return "Eres SmartPark AI en MODO DEVELOPER. No se pudo cargar el contexto global.";
        }
    }
}

if (!function_exists('ia_contexto_admin')) {
    function ia_contexto_admin(PDO $pdo, int $eid): string {
        try {
            $emp = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :eid");
            $emp->execute(['eid' => $eid]);
            $nombreEmp = $emp->fetchColumn() ?: 'Mi Empresa';

            $cupos = $pdo->prepare("
                SELECT 
                    COUNT(*) AS total,
                    SUM(CASE WHEN estado='disponible' THEN 1 ELSE 0 END) AS libres,
                    SUM(CASE WHEN estado='ocupado' THEN 1 ELSE 0 END) AS ocupados,
                    SUM(CASE WHEN estado='mantenimiento' THEN 1 ELSE 0 END) AS mant
                FROM cupos WHERE empresa_id = :eid
            ");
            $cupos->execute(['eid' => $eid]);
            $c = $cupos->fetch();

            $activos = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado='activo'");
            $activos->execute(['eid' => $eid]);
            $act = (int)$activos->fetchColumn();

            $hoy = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND DATE(fecha_ingreso)=CURDATE()");
            $hoy->execute(['eid' => $eid]);
            $hoyN = (int)$hoy->fetchColumn();

            $recaudoHoy = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND DATE(fecha_salida)=CURDATE()");
            $recaudoHoy->execute(['eid' => $eid]);
            $recHoy = (float)$recaudoHoy->fetchColumn();

            $recaudoMes = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND MONTH(fecha_salida)=MONTH(CURDATE())");
            $recaudoMes->execute(['eid' => $eid]);
            $recMes = (float)$recaudoMes->fetchColumn();

            $empleados = $pdo->prepare("SELECT COUNT(*) FROM usuarios_empresa WHERE empresa_id = :eid AND activo=1");
            $empleados->execute(['eid' => $eid]);
            $empN = (int)$empleados->fetchColumn();

            $parqN = $pdo->prepare("SELECT COUNT(*) FROM parqueaderos WHERE empresa_id = :eid AND estado='activo'");
            $parqN->execute(['eid' => $eid]);
            $parq = (int)$parqN->fetchColumn();

            return "Eres SmartPark AI en MODO ADMINISTRADOR de la empresa '$nombreEmp'.\n"
                 . "Contexto de TU empresa (no otras):\n"
                 . "- Parqueaderos activos: $parq\n"
                 . "- Celdas: {$c['total']} total · {$c['libres']} libres · {$c['ocupados']} ocupadas · {$c['mant']} en mantenimiento\n"
                 . "- Vehículos activos ahora: $act\n"
                 . "- Ingresos hoy: $hoyN registros nuevos\n"
                 . "- Recaudo hoy: $" . number_format($recHoy, 0, ',', '.') . " COP\n"
                 . "- Recaudo mes actual: $" . number_format($recMes, 0, ',', '.') . " COP\n"
                 . "- Usuarios activos de tu empresa: $empN\n"
                 . "\nSOLO responde sobre datos de TU empresa. NUNCA menciones otras empresas.";
        } catch (\Throwable $e) {
            return "Eres SmartPark AI en MODO ADMINISTRADOR. No se pudo cargar el contexto.";
        }
    }
}

if (!function_exists('ia_contexto_finanzas')) {
    function ia_contexto_finanzas(PDO $pdo, int $eid): string {
        try {
            $emp = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :eid");
            $emp->execute(['eid' => $eid]);
            $nombreEmp = $emp->fetchColumn() ?: 'Mi Empresa';

            $recHoy = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND DATE(fecha_salida)=CURDATE()");
            $recHoy->execute(['eid' => $eid]);
            $rHoy = (float)$recHoy->fetchColumn();

            $recMes = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND MONTH(fecha_salida)=MONTH(CURDATE()) AND YEAR(fecha_salida)=YEAR(CURDATE())");
            $recMes->execute(['eid' => $eid]);
            $rMes = (float)$recMes->fetchColumn();

            $rec30 = $pdo->prepare("SELECT COALESCE(SUM(total_pagar),0) FROM registros_parqueo WHERE empresa_id = :eid AND estado_pago=1 AND fecha_salida >= (NOW() - INTERVAL 30 DAY)");
            $rec30->execute(['eid' => $eid]);
            $r30 = (float)$rec30->fetchColumn();

            $porMetodo = $pdo->prepare("
                SELECT metodo_pago, COUNT(*) AS cnt, SUM(total_pagar) AS total
                FROM registros_parqueo
                WHERE empresa_id = :eid AND estado_pago=1 AND fecha_salida >= (NOW() - INTERVAL 30 DAY)
                GROUP BY metodo_pago
            ");
            $porMetodo->execute(['eid' => $eid]);
            $met = $porMetodo->fetchAll();

            $metTxt = '';
            foreach ($met as $m) {
                $metodo = $m['metodo_pago'] ?: 'sin especificar';
                $metTxt .= "  · {$metodo}: {$m['cnt']} pagos · $" . number_format((float)$m['total'], 0, ',', '.') . "\n";
            }

            return "Eres SmartPark AI en MODO FINANZAS de la empresa '$nombreEmp'.\n"
                 . "Contexto financiero de TU empresa:\n"
                 . "- Recaudo hoy: $" . number_format($rHoy, 0, ',', '.') . " COP\n"
                 . "- Recaudo mes actual: $" . number_format($rMes, 0, ',', '.') . " COP\n"
                 . "- Recaudo últimos 30 días: $" . number_format($r30, 0, ',', '.') . " COP\n"
                 . "- Desglose por método de pago (30d):\n$metTxt\n"
                 . "\nEres experto en análisis financiero de parqueaderos. NO tienes acceso a datos personales de usuarios ni a otras empresas.";
        } catch (\Throwable $e) {
            return "Eres SmartPark AI en MODO FINANZAS. No se pudo cargar el contexto.";
        }
    }
}

if (!function_exists('ia_contexto_vigilante')) {
    function ia_contexto_vigilante(PDO $pdo, int $eid, ?int $parqueaderoId): string {
        try {
            $cupos = $pdo->prepare("
                SELECT 
                    COUNT(*) AS total,
                    SUM(CASE WHEN estado='disponible' THEN 1 ELSE 0 END) AS libres,
                    SUM(CASE WHEN estado='ocupado' THEN 1 ELSE 0 END) AS ocupados
                FROM cupos WHERE empresa_id = :eid
            ");
            $cupos->execute(['eid' => $eid]);
            $c = $cupos->fetch();

            $activos = $pdo->prepare("SELECT COUNT(*) FROM registros_parqueo WHERE empresa_id = :eid AND estado='activo'");
            $activos->execute(['eid' => $eid]);
            $act = (int)$activos->fetchColumn();

            return "Eres SmartPark AI en MODO VIGILANTE/OPERADOR.\n"
                 . "Contexto operativo:\n"
                 . "- Celdas: {$c['total']} total · {$c['libres']} libres · {$c['ocupados']} ocupadas\n"
                 . "- Vehículos activos ahora: $act\n"
                 . "\nAyudas con: cómo registrar entradas, cómo verificar salidas, "
                 . "cómo manejar clientes, qué hacer si un vehículo no puede salir. "
                 . "NO tienes acceso a información financiera ni de administración.";
        } catch (\Throwable $e) {
            return "Eres SmartPark AI en MODO VIGILANTE. No se pudo cargar el contexto.";
        }
    }
}

if (!function_exists('ia_contexto_cliente')) {
    function ia_contexto_cliente(PDO $pdo, int $eid, int $uid): string {
        try {
            $stmt = $pdo->prepare("
                SELECT placa, modelo, tipo_vehiculo, cupo_asignado, fecha_ingreso
                FROM registros_parqueo
                WHERE user_id = :uid AND empresa_id = :eid AND estado = 'activo'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute(['uid' => $uid, 'eid' => $eid]);
            $actual = $stmt->fetch();

            $hist = $pdo->prepare("
                SELECT COUNT(*) FROM registros_parqueo
                WHERE user_id = :uid AND empresa_id = :eid
            ");
            $hist->execute(['uid' => $uid, 'eid' => $eid]);
            $total = (int)$hist->fetchColumn();

            $contexto = "Eres SmartPark AI en MODO CLIENTE. Ayudas al conductor con su parqueo.\n";

            if ($actual) {
                $minutos = round((time() - strtotime($actual['fecha_ingreso'])) / 60);
                $contexto .= "El cliente tiene un vehículo ACTIVO:\n"
                          . "- Placa: {$actual['placa']}\n"
                          . "- Modelo: {$actual['modelo']}\n"
                          . "- Tipo: {$actual['tipo_vehiculo']}\n"
                          . "- Celda: {$actual['cupo_asignado']}\n"
                          . "- Tiempo dentro: {$minutos} minutos\n";
            } else {
                $contexto .= "El cliente NO tiene vehículo activo ahora.\n";
            }

            $contexto .= "- Vehículos históricos registrados por este cliente: $total\n"
                       . "\nAyudas con: cómo registrar entrada, cómo pagar, cómo salir, "
                       . "cómo usar el QR, cuánto cuesta el parqueo. "
                       . "NUNCA menciones datos de otros clientes, celdas específicas de otros, "
                       . "montos totales del parqueadero, ni configuración del sistema.";

            return $contexto;
        } catch (\Throwable $e) {
            return "Eres SmartPark AI en MODO CLIENTE. Solo ayudas al conductor con su parqueo.";
        }
    }
}

/* ============================================================
 * REGISTRO DE REPORTES (cuando la IA falla o respuesta mala)
 * ============================================================ */

if (!function_exists('ia_guardar_reporte')) {
    function ia_guardar_reporte(
        PDO $pdo,
        ?int $eid,
        ?int $uid,
        string $pregunta,
        ?string $respuesta,
        string $motivo,
        ?string $detalle = null
    ): bool {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ia_reportes
                (empresa_id, user_id, user_nombre, rol_en_empresa, pregunta, respuesta_ia, motivo, detalle)
                VALUES (:eid, :uid, :nom, :rol, :p, :r, :m, :d)
            ");
            return $stmt->execute([
                'eid' => $eid,
                'uid' => $uid,
                'nom' => $_SESSION['nombre'] ?? 'Desconocido',
                'rol' => $_SESSION['rol_en_empresa'] ?? 'desconocido',
                'p'   => $pregunta,
                'r'   => $respuesta,
                'm'   => $motivo,
                'd'   => $detalle,
            ]);
        } catch (\Throwable $e) {
            error_log('ia_guardar_reporte: ' . $e->getMessage());
            return false;
        }
    }
}