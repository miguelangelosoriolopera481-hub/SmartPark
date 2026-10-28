<?php
/**
 * registro_usuario.php
 * ----------------------
 * Este archivo existía como una copia casi idéntica del formulario y la
 * lógica de registro que ya vive en login.php / auth_handler.php (código
 * duplicado y huérfano: no estaba enlazado desde ningún menú). En vez de
 * borrarlo -por si algún enlace externo o marcador ya apunta aquí- se deja
 * como un simple redirect de compatibilidad hacia el flujo unificado.
 */
header("Location: login.php?modo=registro");
exit;
