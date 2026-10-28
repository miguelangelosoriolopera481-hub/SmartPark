# SmartPark Pro — Resumen de cambios

Este documento resume todo lo corregido/agregado, en el mismo orden del
prompt maestro, para que puedas verificarlo punto por punto.

## 0. Estructura y esquema de base de datos
- Se detectó que `composer.json`/`composer.lock`/`composer.phar` estaban un
  nivel por encima de la carpeta real del sitio (`sistema1/sistema1/` en vez
  de `sistema1/sistema1/sistema1/`), lo que hacía que `composer install`
  nunca generara `vendor/` en el lugar donde el código lo busca. Se movieron
  a la raíz real del proyecto.
- Los 4 esquemas SQL sueltos (`sistema.sql`, `smartpark.sql`,
  `smartpark_definitivo.sql`, `migracion_adicional.sql`) se unificaron en un
  único script idempotente y aditivo: **`sql/instalacion_smartpark.sql`**.
  Los originales quedaron archivados en `sql_legacy/` con un `LEEME` que
  explica por qué no deben ejecutarse.
- Nuevas tablas (aditivas, sin tocar datos existentes): `tarifas`,
  `auditoria_admin`, `login_intentos`, `configuracion`.
- Nuevas columnas aditivas: `user.correo`, `user.must_change_password`,
  `user_vehiculo.user_id`, `registros_parqueo.modelo`,
  `registros_parqueo.user_id`.
- Índices agregados en `placa`, `estado`, `fecha_ingreso`, `user_id`,
  `token_factura` de `registros_parqueo`.
- Backfill no destructivo: se vinculan por correo los registros históricos
  con su usuario real donde sea posible.

## 1. Bugs corregidos
| Bug | Corrección |
|---|---|
| `vendor/` no validado | `pagar.php` y `ver_factura.php` verifican `file_exists()` antes de requerir `vendor/autoload.php` |
| Credenciales SMTP de ejemplo hardcodeadas | Ahora viven en la tabla `configuracion` (editable desde **Configuración**), con `.env` como respaldo inicial. Cero credenciales en el código. |
| `registro_carro.php` no guardaba `modelo` | Se guarda en el INSERT y se agregó la columna |
| Enlaces rotos a `factura.php` | Corregidos a `ver_factura.php` en todo el sitio |
| `salida.php` sin filtro `estado='activo'` ni orden | Corregido: filtra `estado='activo'` y ordena por `id DESC` |
| `estado_parqueo.php` no vinculaba con el usuario real | Se agregó `registros_parqueo.user_id` y la consulta ahora correlaciona `r.user_id = :uid` (antes mostraba el vehículo activo de cualquier otro usuario — bug de privacidad) |
| `admin.php` sin validar rol | Ahora exige `admin`/`operador` con `require_role()`, igual que `stats.php` |
| Login/registro duplicado (`index.php`, `login.php`, `registro_usuario.php`) | Unificado en `auth_handler.php::procesarAuthPost()` + `partials/auth_forms.php`. `registro_usuario.php` quedó como redirect de compatibilidad |
| `role` sin `'cliente'` en algunos esquemas | El instalador único fuerza `ENUM('admin','operador','cliente')` |
| Validación de placa (solo último carácter) | `validaciones.php::detectar_tipo_placa()` valida el formato COMPLETO (`^[A-Z]{3}[0-9]{3}$` carro, `^[A-Z]{3}[0-9]{2}[A-Z]$` moto). Si no coincide, se rechaza el registro sin asumir un tipo por defecto |
| Sin CSRF / sin límite de intentos / sin forzar cambio de contraseña | CSRF en TODOS los formularios POST del sitio (`csrf.php`); límite de 5 intentos fallidos / 15 min (`login_intentos`); `admin`/`cliente1` de ejemplo quedan marcados con `must_change_password=1` y son redirigidos a `cambiar_password.php` en su primer login |
| Archivos huérfanos | Movidos a `_legacy/` con explicación (ver `_legacy/LEEME.md`), sin tocar la BD ni `uploads/` reales |
| `stats.php` inalcanzable | Enlazada desde el navbar de `admin.php` |

### Decisión de diseño adicional (no listada explícitamente en el QA, pero necesaria para que la corrección de `salida.php` tenga sentido)
Antes, **`pagar.php` liberaba la celda y marcaba el registro como
`finalizado` en el momento del pago**, mientras el vehículo seguía
físicamente estacionado. Eso contradice el propio texto de la página de
inicio ("Presenta el QR en la barrera de salida y el sistema libera la
celda") y hacía inútil el filtro `estado='activo'` pedido para
`salida.php`. Ahora:
- **Pagar** solo marca `estado_pago = 1` y genera el token/QR (la celda
  sigue "activa").
- **Salida** (control físico) es quien libera la celda y marca
  `estado = 'finalizado'`, solo si ya está pagado.

Esto es más fiel al flujo de negocio real y evita que una celda se muestre
libre mientras el carro sigue ahí.

## 2. Referencias de UI/UX profesional
Se aplicó al **plano de celdas** (punto 8) y a **Mi Parqueadero** (punto 7):
zonas separadas por tipo de vehículo, leyenda de colores, resaltado de la
celda propia, y detalle (placa/hora de ingreso) al pasar el cursor —
patrones típicos de dashboards de ocupación en tiempo real.

## 3. QR y factura
- El QR se genera y se **muestra en pantalla inmediatamente** al confirmar
  el pago (antes solo se enviaba por correo y fallaba en silencio sin SMTP).
- El envío por correo sigue intentándose si hay `correo` y el SMTP está
  configurado, pero nunca bloquea ni oculta el QR en pantalla.
- La factura (`ver_factura.php`) siempre muestra: placa, modelo, tipo de
  vehículo, celda, ingreso, salida, horas, **tarifa aplicada** y **folio de
  verificación** (todos los campos pedidos).
- Instalación documentada en `README_INSTALACION.md`.

## 4. Base de datos funcional
Ver sección 0. Se agregó relación vehículo-propietario (`user_vehiculo` +
`registros_parqueo.user_id`), tarifas configurables (`tarifas`) y auditoría
(`auditoria_admin`).

## 5. Registro de vehículo simplificado
`registro_carro.php` ahora pide únicamente **placa** y **modelo**. El tipo
se detecta automáticamente por el formato de la placa; si no es válido, se
muestra el error y no se registra nada.

## 6. Configuración funcional por rol
Nueva página **`config.php`** (solo `admin`):
- Tarifas por hora (carro/moto).
- Datos SMTP (guardados en BD, nunca en código).
- Gestión de usuarios y roles (con confirmación antes de cambiar un rol).
- Botón "Generar copia de seguridad" → `.sql` descargable vía
  `descargar_backup.php` (solo lectura, con `beginTransaction`/`rollBack`
  en las operaciones sensibles del panel y confirmaciones `onsubmit` en el
  frontend).

Nueva página **`mi_cuenta.php`** (rol `cliente`): datos propios + historial
real de vehículos, sin acceso administrativo.

## 7. "Mi Parqueadero" (cliente)
Nueva página **`mi_parqueadero.php`**: plano visual completo (zonas carro/
moto, colores por estado), resalta la celda del vehículo del usuario
logueado o del buscado por placa (flujo sin sesión), con el mismo estilo
"cyber".

## 8. Mapa del panel admin
`partials/floor_plan.php` reemplaza la cuadrícula alfabética por un plano
por zonas, con leyenda, tooltip de placa/hora, y los mismos botones de
bloquear/habilitar celda (ahora con CSRF y registro en `auditoria_admin`).

## 9. Verificación final
- ✅ Ningún `DROP TABLE` sobre datos reales; toda migración es aditiva
  (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`).
- ✅ Flujo registro → pago → factura/QR → salida revisado para carro y moto
  (validación de placa por tipo, cálculo de tarifa por tipo).
- ✅ Login/registro de cliente y admin separados por rol
  (`require_role()`).
- ✅ Verificación de Composer antes de generar el QR.
- ✅ Diseño visual "cyber", marca "SmartPark Pro" y flujo de negocio
  conservados; solo se agregaron/corrigieron funciones.

## 10. Transversales
- **Eficiencia**: índices en `placa`, `estado`, `fecha_ingreso`, `user_id`,
  `token_factura`; sin `SELECT *` innecesarios nuevos; tarifas cacheadas en
  memoria por request (`obtenerTarifas()`).
- **Seguridad**: credenciales fuera del código (`.env` + tabla
  `configuracion`), CSRF en todos los POST, validación real de placa,
  PDO con prepared statements (sin cambios, ya estaba bien).
- **Ambiente**: se mantiene y refuerza el mensaje "factura 100% digital,
  cero papel" en la página de inicio.

---
**Nota de entorno:** este trabajo se hizo en un entorno sin PHP instalado y
sin acceso a internet, por lo que no fue posible ejecutar `composer install`
ni levantar MySQL para una prueba end-to-end automatizada dentro de esta
conversación. El código se revisó cuidadosamente a mano (incluida una
verificación automática de balance de llaves/paréntesis y de bloques
`if/foreach` en todas las plantillas). Se recomienda correr la checklist de
la sección 9 apenas se despliegue en un entorno real.
