<?php
session_start();
// Por seguridad, un cliente no puede liberar su propia celda. Debe ser el vigilante.
header('Location: dashboard_cliente.php?error=seguridad_salida');
exit;