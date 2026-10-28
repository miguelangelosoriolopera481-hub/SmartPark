# ⚠️ Archivos SQL históricos — NO ejecutar sobre la base de datos en producción

Esta carpeta guarda, solo como **referencia histórica**, los distintos
esquemas SQL que existieron en el proyecto antes de unificarse:

- `sistema.sql` — versión más antigua (columnas `nombre_conductor`, `edad`,
  `documento`, sin `modelo`/`correo`/`token_factura`). **Incompatible** con
  el código PHP actual.
- `smartpark.sql` — versión intermedia. El `ENUM` de `role` en `user` **no
  incluye `'cliente'`**, lo que rompe el autorregistro de clientes.
- `smartpark_definitivo.sql` — versión más reciente antes de esta
  actualización, pero sin las columnas `user_id`, `must_change_password`,
  ni las tablas `tarifas`, `auditoria_admin`, `login_intentos` y
  `configuracion` que ahora usa el sistema.
- `migracion_adicional.sql` — parche puntual que ya quedó absorbido dentro
  del script único.

## ¿Cuál debo usar?

**Ninguno de los archivos de esta carpeta.** El único esquema oficial y
soportado es:

```
/sql/instalacion_smartpark.sql
```

Ese script es idempotente y aditivo: se puede correr tanto en una base de
datos nueva como en una que ya tenga los datos de cualquiera de estas
versiones antiguas, sin perder información (no contiene ningún `DROP
TABLE`).

Estos archivos se conservan aquí únicamente para consulta histórica de cómo
evolucionó el esquema, no como una alternativa de instalación.
