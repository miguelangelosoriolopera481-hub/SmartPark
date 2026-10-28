# SmartPark Pro — Guía de instalación

## 1. Requisitos
- PHP 8.0 o superior, con extensión `pdo_mysql` habilitada.
- MySQL 8.0.29+ o MariaDB 10.x (el script de instalación usa `ADD COLUMN IF
  NOT EXISTS`, soportado desde esas versiones).
- Composer (o usa el `composer.phar` incluido: `php composer.phar install`).

## 2. Instalar dependencias de Composer
```bash
composer install
```
Esto crea la carpeta `vendor/` con `endroid/qr-code` (generación de QR) y
`phpmailer/phpmailer` (envío de correo). **El pago y la factura NO
funcionan sin este paso** — antes era justamente el bug #1 detectado en QA.

## 3. Importar la base de datos
Hay un único script oficial, ubicado en `sql/instalacion_smartpark.sql`.
Es **idempotente y aditivo**: se puede correr tanto en una base nueva como
en una que ya tenga datos de una versión anterior del proyecto, sin perder
información (no contiene ningún `DROP TABLE`).

```bash
mysql -u root -p < sql/instalacion_smartpark.sql
```

> ⚠️ Los archivos en `sql_legacy/` (esquemas antiguos) **no deben
> ejecutarse**. Se conservan solo como referencia histórica — ver
> `sql_legacy/LEEME_NO_EJECUTAR.md`.

## 4. Configurar variables de entorno
Copia la plantilla y complétala con tus datos reales:
```bash
cp .env.example .env
```
Edita `.env` con los datos de tu base de datos (`DB_HOST`, `DB_NAME`,
`DB_USER`, `DB_PASS`). Los datos de `MAIL_*` en `.env` solo se usan como
valor inicial: una vez instalado, el SMTP real se administra desde
**Panel Admin → Configuración**, guardado en la base de datos (nunca en el
código fuente).

`.env` **nunca** debe subirse a git (ya está en `.gitignore`).

## 5. Permisos de la carpeta `uploads/` y `backups/`
```bash
chmod 775 uploads/ backups/
```

## 6. Primer inicio de sesión
El script de instalación crea dos cuentas de ejemplo:

| Usuario    | Contraseña | Rol      |
|------------|------------|----------|
| `admin`    | `admin123` | admin    |
| `cliente1` | `admin123` | cliente  |

(Ambas cuentas comparten el mismo hash de ejemplo heredado de
`smartpark_definitivo.sql` — otra razón más para cambiarla de inmediato.)

Ambas cuentas están marcadas para **exigir cambio de contraseña en el
primer login** (redirigen automáticamente a `cambiar_password.php`). Cambia
esa contraseña antes de usar el sistema en producción.

## 7. Verificación rápida del flujo completo
1. Inicia sesión como `cliente1` → cambia la contraseña → ve a
   **Registrar Vehículo** → ingresa una placa válida (`ABC123` para carro,
   `ABC12D` para moto) y un modelo.
2. Ve a **Mi Parqueadero** y confirma que tu celda aparece resaltada.
3. Ve a `pagar.php?placa=ABC123`, confirma el pago: el QR debe aparecer
   **en pantalla inmediatamente** (con o sin correo configurado).
4. Ve a `salida.php`, ingresa la placa o el token del QR: la celda se
   libera solo en este paso (no al pagar), tal como indica la página de
   inicio ("Presenta el QR en la barrera de salida...").
5. Inicia sesión como `admin`, entra a **Panel** y confirma que el plano de
   celdas, las estadísticas y **Configuración** (tarifas, SMTP, usuarios,
   backup) funcionan.

## 8. Copia de seguridad
Desde **Panel Admin → Configuración → Copia de seguridad**, el botón genera
un `.sql` descargable (solo lectura sobre la base de datos, nunca destructivo)
guardado en `backups/`.
