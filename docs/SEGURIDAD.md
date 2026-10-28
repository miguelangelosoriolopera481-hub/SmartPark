# Guia de seguridad para SmartPark Pro

## Antes de subir a GitHub

1. Ejecuta: php preparar_para_compartir.php
2. Verifica: git status --ignored | findstr ".env"
3. Exporta la BD y verifica: findstr "IA_API_KEY" dump.sql (debe estar vacio)
4. git add . && git commit -m "Initial commit" && git push

## Setup para quien recibe el proyecto

1. git clone
2. composer install
3. cp .env.example .env
4. Generar APP_CRYPTO_KEY: php -r "echo base64_encode(random_bytes(32));"
5. Importar el dump SQL
6. Login como developer en /dev_config.php
7. Pegar SU PROPIA API key de Gemini
8. Probar con: php test_ia.php

## Reglas de oro

- NUNCA subir .env
- NUNCA compartir la API key
- Regenerar la key si se filtra
- Cada persona tiene su propia key
