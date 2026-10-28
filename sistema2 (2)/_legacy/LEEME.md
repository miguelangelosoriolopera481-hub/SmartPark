# Archivos huérfanos detectados por QA

Estos archivos **no están referenciados por ningún archivo PHP activo** del
proyecto (se verificó con `grep` en todo el código). Se movieron aquí en
vez de borrarse directamente, para que quede evidencia de qué se limpió y
por qué, y para que puedas recuperarlos fácilmente si alguno resulta
necesario. Si no los necesitas, esta carpeta completa se puede eliminar sin
ningún riesgo para la aplicación ni para la base de datos.

- **`script.js`** — Lógica de un formulario antiguo (`edad`, `documento`,
  animación de barrera) que correspondía a la estructura vieja de
  `sistema.sql` (con columnas `nombre_conductor`/`edad`/`documento`). El
  formulario actual (`registro_carro.php`) ya no usa esos campos.
- **`style.css`** (raíz) — Hoja de estilos de 446 líneas con variables
  distintas (`--cyan-primary`, `--green-accent`) a las oficiales
  (`--primary-cyan`, `--primary-green` en `CSS/style.css`). Ninguna página
  la carga.
- **`CSS/style2.css`** y **`CSS/style3.css`** — Hojas de estilo de otra
  identidad visual (paleta clara/dorada y un formulario centrado en rosa)
  que no corresponden al tema "cyber" del proyecto y no se referencian en
  ningún archivo.
- **`IMG/*`** (excepto `SMARTPARK.png`, que sí se usa) — Imágenes sueltas de
  una plantilla genérica, sin relación con el negocio de parqueadero.
- **`uploads_no_relacionado/hotel_*.png`** — 3 copias idénticas (2.4&nbsp;MB
  cada una) de una imagen de un hotel, ajena a un sistema de parqueadero;
  claro indicio de plantilla reciclada. **Se dejaron intactos**, en cambio,
  los otros dos archivos en `uploads/` (`1781130303_472b09e7.jpg` y
  `1784314317_a404c556.png`), por no tener ninguna señal de ser plantilla y
  no poder descartarse con certeza como contenido subido real.

`registro_usuario.php` también era código duplicado/huérfano, pero se
conservó en su ubicación original como un simple *redirect* de
compatibilidad hacia `login.php?modo=registro` (ver ese archivo).
