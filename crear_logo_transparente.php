<?php
header('Content-Type: text/plain; charset=utf-8');

if (!extension_loaded('gd')) {
    die("ERROR: GD no esta habilitado. Abre C:\\xampp\\php\\php.ini y descomenta 'extension=gd', luego reinicia Apache.\n");
}

$source = __DIR__ . '/IMG/SMARTPARK.png';
$dest = __DIR__ . '/IMG/SMARTPARK_TRANSPARENTE.png';

if (!file_exists($source)) {
    die("ERROR: No existe $source\n");
}

$img = @imagecreatefrompng($source);
if (!$img) {
    $img = @imagecreatefromjpeg($source);
    if (!$img) { die("ERROR: No se pudo cargar como PNG ni JPEG\n"); }
    echo "INFO: El archivo original es JPEG disfrazado de PNG.\n\n";
}

$w = imagesx($img);
$h = imagesy($img);
echo "Dimensiones: {$w}x{$h}px\n";

// Detectar color de fondo de las 4 esquinas
$off = 5;
$esquinas = [
    imagecolorat($img, $off, $off),
    imagecolorat($img, $w - 1 - $off, $off),
    imagecolorat($img, $off, $h - 1 - $off),
    imagecolorat($img, $w - 1 - $off, $h - 1 - $off),
];

$rBg = 0; $gBg = 0; $bBg = 0;
foreach ($esquinas as $c) {
    $rBg += ($c >> 16) & 0xFF;
    $gBg += ($c >> 8) & 0xFF;
    $bBg += $c & 0xFF;
}
$rBg = intval($rBg / 4);
$gBg = intval($gBg / 4);
$bBg = intval($bBg / 4);
echo "Color de fondo detectado: rgb({$rBg}, {$gBg}, {$bBg})\n";

$new = imagecreatetruecolor($w, $h);
imagealphablending($new, false);
imagesavealpha($new, true);

$tolerancia = 50;
$transparentes = 0;

for ($x = 0; $x < $w; $x++) {
    for ($y = 0; $y < $h; $y++) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        
        $dist = sqrt(pow($r - $rBg, 2) + pow($g - $gBg, 2) + pow($b - $bBg, 2));
        
        if ($dist < $tolerancia) {
            $color = imagecolorallocatealpha($new, 0, 0, 0, 127);
            $transparentes++;
        } elseif ($dist < $tolerancia * 1.8) {
            $alpha = 127 - intval((($dist - $tolerancia) / ($tolerancia * 0.8)) * 127);
            $alpha = max(0, min(127, $alpha));
            $color = imagecolorallocatealpha($new, $r, $g, $b, $alpha);
        } else {
            $color = imagecolorallocatealpha($new, $r, $g, $b, 0);
        }
        
        imagesetpixel($new, $x, $y, $color);
    }
}

imagepng($new, $dest, 9);
imagedestroy($img);
imagedestroy($new);

echo "Pixeles hechos transparentes: {$transparentes}\n";
echo "Archivo creado: " . basename($dest) . "\n";
echo "Tamano: " . round(filesize($dest)/1024, 1) . " KB\n";