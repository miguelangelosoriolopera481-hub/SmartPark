# CONTEXTO MAESTRO — SmartPark Pro V3
# Documento de continuidad para el siguiente chat de IA
# Última actualización: 2026-10-16

Copia y pega este documento COMPLETO al inicio del siguiente chat.

==============================================================================
INSTRUCCIONES PARA LA IA
==============================================================================
Estás continuando un proyecto de software ya avanzado. Lee TODO antes de responder.
NO empieces de cero.

REGLAS:
- Usuario: Miguel Ángel Osorio Lopera (llámalo "Miguel")
- Trabaja en Windows + XAMPP + PowerShell
- Comandos PHP: & "C:\xampp\php\php.exe"
- Comandos MySQL: & "C:\xampp\mysql\bin\mysql.exe" -u root
- Archivos con: [System.IO.File]::WriteAllText("ruta", $content, (New-Object System.Text.UTF8Encoding $false))
- NUNCA uses Set-Content -Encoding UTF8 (agrega BOM)
- Usa here-strings @'...'@ para archivos con comillas
- Después de cada PHP: & "C:\xampp\php\php.exe" -l archivo.php
- Antes de escribir código, confirma el plan
- SIEMPRE respeta multi-tenant (WHERE empresa_id = :eid)
- SIEMPRE usa placeholders PDO únicos (:eid1, :eid2, :eid3...) si se repite el mismo valor
- NUNCA expongas API keys ni contraseñas
- El código debe guardarse SIN BOM
- Pide al usuario que ejecute y pegue el resultado al final de cada bloque

==============================================================================
IDENTIDAD DEL PROYECTO
==============================================================================
Nombre: SmartPark Pro V3
Tipo: SaaS multi-tenant para gestión de parqueaderos
Ubicación: C:\xampp\htdocs\sistema2\
Estado: ~88% completado
Próximo hito: Exposición del proyecto el lunes (10 minutos)

STACK:
- Backend: PHP 8.2
- Base de datos: MariaDB 10.4.32 (XAMPP)
- Frontend: HTML5 + CSS3 + JavaScript vanilla
- IA: Google Gemini 3.1-flash-lite
- Email: PHPMailer + Gmail SMTP
- QR: Endroid QR Code
- Túnel: ngrok (para demo desde cualquier red)

==============================================================================
CUENTAS DE ACCESO
==============================================================================
| Usuario        | Contraseña      | Rol        | URL login                |
|----------------|-----------------|------------|--------------------------|
| developer      | Dev2026!        | Developer  | login_developer.php      |
| admin          | Admin2026!      | Admin      | login_empresa.php        |
| cliente1       | Admin2026!      | Cliente    | login_empresa.php        |
| vigilante1     | Vigilante2026!  | Vigilante  | login_empresa.php        |

BD: smartpark | Host: 127.0.0.1 | User: root (sin password)

==============================================================================
ARQUITECTURA
==============================================================================
Regla #1 — Multi-tenant: TODAS las queries filtran por empresa_id
Regla #2 — CSRF: Todo formulario POST usa csrf_field()
Regla #3 — Roles: require_role() antes de cada acción
Regla #4 — Sin BOM: Todos los archivos PHP sin BOM
Regla #5 — PDO: Nunca concatenar en SQL, placeholders ÚNICOS si se repite

6 ROLES:
1. developer: acceso global, aprueba empresas
2. admin: dueño, gestiona su empresa
3. operador: registra y cobra
4. vigilante: control entrada/salida
5. finanzas: solo reportes
6. cliente: ve sus vehículos, paga

ESTRUCTURA:
- Raíz: archivos PHP principales
- config/: ai_config.php
- partials/: ia_widget.php, navbar.php, floor_plan.php, error_qr.php, auth_forms.php, role_context.php, mobile_topbar.php
- assets/css/: main.css, legacy-compat.css, ia_widget.css, dev-theme.css
- assets/js/: app.js, nav_developer.js, ia_widget.js
- vendor/: composer packages
- backups/: .sql protegidos

==============================================================================
LO QUE YA ESTÁ COMPLETO
==============================================================================
BLOQUES 1-6 (base):
- BD reparada (ENUMs, PK, columnas, AUTO_INCREMENT)
- Sistema entrada inteligente
- CSS unificado
- IA Gemini funcionando
- Sistema QR completo (3 tipos)
- IA por roles + widget + reportes

SESIÓN 2026-10-14/15:
- Multi-tenant fixes (registro_carro, stats, pagar, BOM)
- Web móvil (nav sin emojis, PWA, título SmartPark)
- ngrok + helpers_url con auto-detección
- Módulo de invitaciones (equipo.php, aceptar_invitacion.php)
- Email de confirmación al entrar
- Opción C (pagar → confirmar salida, salida_cliente.php)
- PDF factura (imprimir_factura.php)
- Botón Volver móvil (partials/mobile_topbar.php)

SESIÓN 2026-10-16 (BLOQUE 9 - EN PROGRESO):
- admin_sedes.php (vista consolidada de todas las sedes) ✅
- admin_crear_sede.php (crear sedes con celdas por nivel) ✅
- admin_sede_creada.php (pantalla de éxito con URLs QR) ✅
- admin_editar_sede.php (editar propiedades de sede) ✅
- SQL: 8 columnas nuevas en parqueaderos (email_contacto, nit_sede, horario_apertura, horario_cierre, tipo_parqueadero, acepta_reservas, tiene_discapacitados, observaciones) ✅

==============================================================================
LO QUE ESTÁ PENDIENTE (BLOQUE 9 — Continuación)
==============================================================================
🔴 INMEDIATO:
- [ ] admin_comparar.php (comparativa entre sedes)
- [ ] transferir_celda.php (mover celda entre sedes)
- [ ] Modificar dashboard_admin.php para selector de sede
- [ ] admin_gestionar_celdas.php (agregar/eliminar celdas por nivel)

🟡 SIGUIENTE (esta semana):
- [ ] Bloque 8: Telegram Bot (notificaciones)
- [ ] Modo oscuro/claro conmutables
- [ ] Notificaciones push en navegador
- [ ] Dashboard estadísticas avanzadas

🟢 PRÓXIMA SEMANA (después de la expo):
- [ ] Parqueadero 3D (ver especificaciones abajo)
- [ ] Sistema de reservas
- [ ] Limpieza quirúrgica del código (despersonalizar IA)

==============================================================================
PARQUEADERO 3D — ESPECIFICACIONES COMPLETAS
==============================================================================
DECISIONES TOMADAS POR MIGUEL:

P1 (perspectiva): D y A — Cambiable + isométrico clásico
P2 (sótanos): Vista apilada (uno debajo del otro). Al hacer clic en un piso,
              se ve en grande y se puede editar.
P3 (zoom/arrastrar): SÍ
P4 (vehículo entrando): C — Entra por puerta, se detiene, va a celda (realista)
P5 (vehículo saliendo): SÍ — recorrido inverso (celda → puerta → desaparece)
P6 (sonidos): NO
P7 (al hacer clic en celda): D — Todos los formatos según contexto
                             (tooltip + panel lateral + modal)
P8 (vigilante bloquea desde 3D): SÍ
P9 (cliente ve): Su celda resaltada + el nivel donde está
P10 (200+ celdas): B — Cargar por nivel bajo demanda
P11 (móvil): B y C — Optimizado con pan táctil + alternativa 2D en móvil
P12 (solicitud de 3D con comentario): SÍ

TECNOLOGÍA:
- Opción C: CSS 3D transforms (isométrico)
- Estilo de la página (verde esmeralda #10b981 + cyan #06b6d4)
- Configurable por empresa: niveles, celdas por nivel, tipos
- Tipos de celda: carro, moto, bici, electrico, reservado, bloqueado
- Botón "Solicitar vista 3D" (gratis para todos)
- Developer puede desactivar (con advertencia)
- Vehículos moviéndose en tiempo real (entrada/salida)

==============================================================================
BUGS CONOCIDOS Y CÓMO SE ARREGLARON
==============================================================================
Bug #1: PDO placeholder duplicado → usar :u1, :u2
Bug #2: .env con localhost → usar 127.0.0.1
Bug #3: BOM en archivos PHP → guardar con UTF8 sin BOM
Bug #4: AUTO_INCREMENT ausente → ALTER TABLE x MODIFY id INT AUTO_INCREMENT
Bug #5: Parqueadero con ID 0 → mover registro + activar AUTO_INCREMENT
Bug #6: QR con URL incorrecta → usar detectar_url_base()
Bug #7: URL base agarra gateway → filtrar IPs .1 + APP_LAN_IP del .env
Bug #8: ngrok URL con flecha → regex extrae solo la URL
Bug #9: Duplicate entry en tarifas → tarifas son a nivel empresa, no sede

==============================================================================
PATRONES DE CÓDIGO ESTABLECIDOS
==============================================================================
Escribir archivo:
  $contenido = @'
  <?php
  // código
  '@
  [System.IO.File]::WriteAllText("$PWD\archivo.php", $contenido, (New-Object System.Text.UTF8Encoding $false))
  & "C:\xampp\php\php.exe" -l archivo.php

Consulta con placeholders únicos:
  $stmt = $pdo->prepare("
      SELECT * FROM tabla 
      WHERE (col1 = :v1 OR col2 = :v2) AND empresa_id = :eid
      LIMIT 1
  ");
  $stmt->execute(['v1' => $x, 'v2' => $x, 'eid' => $eid]);

Multi-tenant:
  $eid = (int)($_SESSION['empresa_id'] ?? 0);
  if ($eid <= 0) { header('Location: login_empresa.php'); exit; }
  $stmt = $pdo->prepare("SELECT * FROM x WHERE empresa_id = :eid");

Detectar URL base:
  require_once __DIR__ . '/helpers_url.php';
  $url = detectar_url_base() . 'ver_factura.php?token=' . urlencode($token);

==============================================================================
NGROK + DEMO
==============================================================================
Antes de la demo:
1. & "C:\xampp\ngrok.exe" http 80 (en su propia terminal, NO CERRAR)
2. Copiar la URL que aparece
3. Actualizar .env: APP_URL=https://xxx.ngrok-free.dev/sistema2
4. Probar desde celular con datos móviles

URL cambia cada reinicio. Script iniciar_demo.ps1 automatiza.

==============================================================================
ESTRUCTURA DE LA EXPOSICIÓN (10 min)
==============================================================================
Reparto:
- Miguel (4:30): Intro + Arquitectura + Cierre
- Sebastián (3:00): Frontend y UX
- Alison (2:30): IA y Análisis de Riesgo

Preguntas probables del jurado (PREPARADAS):
1. ¿Cómo funciona multi-tenant? → Filtro por empresa_id en TODAS las queries
2. ¿Cómo conecta XAMPP con PHP/MySQL? → Apache 80, MySQL 3306, PDO
3. Frontend vs Backend → Frontend HTML/CSS/JS, Backend PHP/SQL
4. ¿Qué pasa si modifico X? → Depende del archivo
5. ¿Cómo funciona la IA? → Gemini con contexto por rol

DEFENSA SOBRE IA:
Si preguntan "¿esto lo hizo IA?" responder:
"Usamos IA como herramienta de productividad, igual que un ingeniero usa
Stack Overflow o Copilot. La arquitectura, el diseño de BD, las decisiones
de seguridad y el flujo del negocio fueron decisiones nuestras. Cada línea
fue revisada, probada y ajustada. Puedo explicar cualquier función."

==============================================================================
REGLAS DE ORO
==============================================================================
- NUNCA subir .env a GitHub
- SIEMPRE respetar multi-tenant
- SIEMPRE usar csrf_field() en POST
- SIEMPRE validar roles
- SIEMPRE sin BOM
- NUNCA usar localhost en .env (usar 127.0.0.1)
- SIEMPRE placeholders PDO únicos

==============================================================================
INFORMACIÓN DEL USUARIO
==============================================================================
- Nombre: Miguel Ángel Osorio Lopera
- Rol: Desarrollador principal (Backend) + Líder
- Institución: INEM José Celestino Mutis
- Equipo: Sebastián Rua (Frontend), Alison Mesa (Frontend + Análisis)
- Sistema: Windows + XAMPP
- Próximo evento: Exposición lunes (10 minutos)

==============================================================================
ESTADO ACTUAL AL CERRAR ESTE CHAT
==============================================================================
Última acción: Crear admin_sedes.php, admin_crear_sede.php,
               admin_editar_sede.php, admin_sede_creada.php
               + 8 columnas nuevas en tabla parqueaderos

Siguiente paso: admin_comparar.php y transferir_celda.php
                (Bloque 9 - Multi-parqueadero avanzado)

==============================================================================
FIN DEL DOCUMENTO MAESTRO
Versión 2.0

==============================================================================
🔴 TAREA CRÍTICA FINAL — LIMPIEZA DE 'IA' EN EL CÓDIGO
==============================================================================
IMPORTANTE: Hacer esto AL FINAL, cuando TODO el proyecto esté completo y probado.
Los jurados sospechan de código 'hecho con IA'. Hay que eliminar/renombrar
todo lo que delate el uso de IA, PERO manteniendo la funcionalidad 100%.

ARCHIVOS A RENOMBRAR:
- ia_chat_api.php         -> asistente_api.php
- ia_contexto.php         -> asistente_contexto.php
- ia_reportar.php         -> asistente_reportar.php
- smartpark_ai.php        -> smartpark_chat.php
- config/ai_config.php    -> config/chat_config.php
- partials/ia_widget.php  -> partials/asistente_widget.php
- assets/css/ia_widget.css -> assets/css/asistente_widget.css
- assets/js/ia_widget.js  -> assets/js/asistente_widget.js

TABLAS BD A RENOMBRAR:
- ia_reportes  -> reportes_asistente
- ai_config    -> chat_config (si existe)

CODIGO A CAMBIAR (en TODOS los .php, .js, .css):
- 'IA_API_KEY'      -> 'ASISTENTE_API_KEY'
- 'IA_PROVEEDOR'    -> 'ASISTENTE_PROVEEDOR'
- 'IA_MODELO'       -> 'ASISTENTE_MODELO'
- 'IA_HABILITADA'   -> 'ASISTENTE_HABILITADO'
- 'ia_reportes'     -> 'reportes_asistente'
- 'ia_chat_api'     -> 'asistente_api'
- 'ia_contexto'     -> 'asistente_contexto'
- 'ia_reportar'     -> 'asistente_reportar'
- 'ia_widget'       -> 'asistente_widget'
- 'ai_config'       -> 'chat_config'
- 'smartpark_ai'    -> 'smartpark_chat'

NOMBRES DE FUNCIONES:
- ai_chat() -> asistente_chat()
- ai_get_config() -> asistente_get_config()
- ia_construir_contexto() -> asistente_contexto()

STRINGS VISIBLES AL USUARIO:
- 'Eres SmartPark AI' -> 'Eres el Asistente Virtual de SmartPark'
- 'asistente de IA' -> 'asistente virtual'
- 'IA Gemini' -> 'asistente virtual'
- 'Motor de IA Gemini' -> 'Motor de asistencia'

SQL PARA RENOMBRAR TABLAS:
RENAME TABLE ia_reportes TO reportes_asistente;
RENAME TABLE ai_config TO chat_config;
UPDATE configuracion SET clave='ASISTENTE_API_KEY' WHERE clave='IA_API_KEY';
UPDATE configuracion SET clave='ASISTENTE_PROVEEDOR' WHERE clave='IA_PROVEEDOR';
UPDATE configuracion SET clave='ASISTENTE_MODELO' WHERE clave='IA_MODELO';
UPDATE configuracion SET clave='ASISTENTE_HABILITADO' WHERE clave='IA_HABILITADA';

NOTA: Esta limpieza se hace SOLO AL FINAL. Antes, todo debe funcionar.
==============================================================================


==============================================================================
LIMPIEZA DE IA EN EL CODIGO (HACER AL FINAL)
==============================================================================
IMPORTANTE: Hacer esto SOLO al final, cuando TODO este completo y probado.

ARCHIVOS A RENOMBRAR:
- ia_chat_api.php         -> asistente_api.php
- ia_contexto.php         -> asistente_contexto.php
- ia_reportar.php         -> asistente_reportar.php
- smartpark_ai.php        -> smartpark_chat.php
- config/ai_config.php    -> config/chat_config.php
- partials/ia_widget.php  -> partials/asistente_widget.php
- assets/css/ia_widget.css -> assets/css/asistente_widget.css
- assets/js/ia_widget.js  -> assets/js/asistente_widget.js

TABLAS BD A RENOMBRAR:
- ia_reportes  -> reportes_asistente
- ai_config    -> chat_config

CODIGO A CAMBIAR (todos los .php, .js, .css):
- IA_API_KEY     -> ASISTENTE_API_KEY
- IA_PROVEEDOR   -> ASISTENTE_PROVEEDOR
- IA_MODELO      -> ASISTENTE_MODELO
- IA_HABILITADA  -> ASISTENTE_HABILITADO
- ia_reportes    -> reportes_asistente
- ia_chat_api    -> asistente_api
- ia_contexto    -> asistente_contexto
- ia_reportar    -> asistente_reportar
- ia_widget      -> asistente_widget
- ai_config      -> chat_config
- smartpark_ai   -> smartpark_chat

FUNCIONES:
- ai_chat() -> asistente_chat()
- ai_get_config() -> asistente_get_config()
- ia_construir_contexto() -> asistente_contexto()

STRINGS VISIBLES:
- 'Eres SmartPark AI' -> 'Eres el Asistente Virtual de SmartPark'
- 'asistente de IA' -> 'asistente virtual'
- 'IA Gemini' -> 'asistente virtual'

SQL:
RENAME TABLE ia_reportes TO reportes_asistente;
RENAME TABLE ai_config TO chat_config;
UPDATE configuracion SET clave='ASISTENTE_API_KEY' WHERE clave='IA_API_KEY';
UPDATE configuracion SET clave='ASISTENTE_PROVEEDOR' WHERE clave='IA_PROVEEDOR';
UPDATE configuracion SET clave='ASISTENTE_MODELO' WHERE clave='IA_MODELO';
UPDATE configuracion SET clave='ASISTENTE_HABILITADO' WHERE clave='IA_HABILITADA';

==============================================================================



=== ACTUALIZACION 2026-10-16 (SESION BLOQUE 9) ===
BLOQUE 9 COMPLETO:
- admin_sedes.php (vista consolidada) OK
- admin_crear_sede.php (crear sedes con celdas por nivel) OK
- admin_sede_creada.php (pantalla exito + URLs QR) OK
- admin_editar_sede.php (editar propiedades) OK
- admin_comparar.php (comparativa entre sedes) OK
- transferir_celda.php (mover celdas entre sedes) OK
- admin_gestionar_celdas.php (agregar/eliminar celdas) OK
- 8 columnas nuevas en parqueaderos (email_contacto, nit_sede, horario_apertura, horario_cierre, tipo_parqueadero, acepta_reservas, tiene_discapacitados, observaciones) OK

PROXIMO PASO:
- Selector de sede en dashboard_admin.php
- Bloque 8 Telegram Bot
- Modo oscuro/claro
- Notificaciones push
- Dashboard estadisticas avanzadas
- Parqueadero 3D
- Reservas
================================================


[SELECCION DE SEDE] partials/sede_selector.php creado e integrado en dashboard_admin.php. Selector visual con tarjetas (chips). Developer ve 'Todas las sedes'.

