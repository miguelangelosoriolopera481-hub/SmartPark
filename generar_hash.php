<?php
$password = 'Vigilante2026!';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

echo "====================================\n";
echo "PASSWORD: $password\n";
echo "====================================\n";
echo "HASH:\n";
echo $hash . "\n";
echo "====================================\n";
echo "Copia el HASH completo y pégalo en el INSERT.\n";