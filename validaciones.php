<?php
/**
 * validaciones.php - Reglas de validación compartidas del negocio
 * ------------------------------------------------------------------
 * Antes, el sistema detectaba "carro" o "moto" mirando solo el último
 * carácter de la placa (is_numeric($ultimo_caracter)), lo que aceptaba
 * placas inválidas como "123456" o "A1B2C3". Ahora se valida el formato
 * COMPLETO según el estándar colombiano vigente:
 *   - Carro: 3 letras + 3 números   -> AAA999   (ej. ABC123)
 *   - Moto : 3 letras + 2 números + 1 letra -> AAA99A (ej. ABC12D)
 */

if (!function_exists('normalizar_placa')) {
    function normalizar_placa(string $placa): string
    {
        $placa = str_replace(['-', ' ', '.'], '', trim($placa));
        return strtoupper($placa);
    }
}

if (!function_exists('detectar_tipo_placa')) {
    /**
     * Devuelve 'carro', 'moto' o null si el formato no corresponde a
     * NINGUNO de los dos patrones válidos. Nunca se asume un tipo por
     * defecto: si es null, el llamador debe rechazar el registro.
     */
    function detectar_tipo_placa(string $placa): ?string
    {
        $placa = normalizar_placa($placa);

        if (preg_match('/^[A-Z]{3}[0-9]{3}$/', $placa)) {
            return 'carro';
        }

        if (preg_match('/^[A-Z]{3}[0-9]{2}[A-Z]$/', $placa)) {
            return 'moto';
        }

        return null;
    }
}

if (!function_exists('mensaje_formato_placa_invalido')) {
    function mensaje_formato_placa_invalido(): string
    {
        return "Formato de placa no válido. Debe ser AAA999 para carro (ej. ABC123) "
             . "o AAA99A para moto (ej. ABC12D).";
    }
}
