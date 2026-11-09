<?php
// Akzentfarbe aus den Einstellungen als kleines Stylesheet (keine style-Attribute wegen der CSP)
declare(strict_types=1);
$f = preg_match('/^[0-9a-f]{6}$/i', (string)($_GET['f'] ?? '')) ? '#' . $_GET['f'] : '#1f5f99';
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=31536000');
echo ":root, :root[data-theme] { --akzent: $f; }\n";
