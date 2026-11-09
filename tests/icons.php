<?php
// Erzeugt die SQL-Manager-Icons: blauer Verlauf, "SQL" in Weiß, Schrift Bahnschrift (ähnlich Saira)
// Lokales Werkzeug, wird nicht hochgeladen. Aufruf aus dem Projektordner:
// ..\_devtools\php\php.exe -d extension=gd tests\icons.php .
$ziel = rtrim($argv[1] ?? '.', '/\\');
$font = 'C:/Windows/Fonts/bahnschrift.ttf';
$orange = [0xFF, 0xFF, 0xFF];   // Schriftfarbe
$oben = [0x2F, 0x7B, 0xD0];   // Blau oben
$unten = [0x1B, 0x4F, 0x8A];  // Blau unten

function icon(int $px, string $datei, float $anteil, float $fett = 0): void
{
    global $font, $orange, $oben, $unten;
    $s = 4;                      // vierfach zeichnen, dann verkleinern (glatte Kanten)
    $g = $px * $s;
    $im = imagecreatetruecolor($g, $g);
    for ($y = 0; $y < $g; $y++) { // senkrechter Verlauf
        $t = $y / ($g - 1);
        $c = imagecolorallocate($im, ...array_map(fn($a, $b) => (int)round($a + ($b - $a) * $t), $oben, $unten));
        imageline($im, 0, $y, $g, $y, $c);
    }
    $farbe = imagecolorallocate($im, ...$orange);
    // Schriftgröße so wählen, dass "LOP" $anteil der Breite füllt
    $pt = 100;
    $bb = imagettfbbox($pt, 0, $font, 'SQL');
    $pt *= ($g * $anteil) / ($bb[2] - $bb[0]);
    $bb = imagettfbbox($pt, 0, $font, 'SQL');
    $b = $bb[2] - $bb[0];
    $h = $bb[1] - $bb[7];
    $x = (int)round(($g - $b) / 2 - $bb[0]);
    $y = (int)round(($g + $h) / 2 - $bb[1]);
    // kräftiger: Schrift mehrfach leicht versetzt zeichnen ($fett = Anteil der Bildbreite)
    $r = (int)round($g * $fett);
    for ($dx = -$r; $dx <= $r; $dx++) for ($dy = -$r; $dy <= $r; $dy++)
        if ($dx * $dx + $dy * $dy <= $r * $r) imagettftext($im, $pt, 0, $x + $dx, $y + $dy, $farbe, $font, 'SQL');
    $aus = imagecreatetruecolor($px, $px);
    imagecopyresampled($aus, $im, 0, 0, 0, 0, $px, $px, $g, $g);
    imagepng($aus, $datei, 9);
}

// klein: Schrift füllt mehr (Taskleiste, Browser-Tab), groß: mehr Rand wie beim fab-Icon
foreach ([['favicon.png', 64, 0.84, 0.022], ['icon-48.png', 48, 0.86, 0.024], ['icon-96.png', 96, 0.82, 0.018],
          ['icon-180.png', 180, 0.66, 0.006], ['icon-192.png', 192, 0.66, 0.006], ['icon-512.png', 512, 0.66, 0.006]] as [$n, $px, $a, $f]) {
    icon($px, "$ziel/$n", $a, $f);
    echo "$n\n";
}
