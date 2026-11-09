<?php
// Cron-Einstieg: Verdichtung, abgeleitete Reihen, Papierkorb aufräumen, Zahlen aktualisieren.
//   Kommandozeile:  php /pfad/cron.php            (--probe = nur zählen, --backup = zusätzlich Sicherung aller Datenbanken)
//   per URL:        https://.../cron.php?token=...  (&probe=1, &backup=1)
// Empfehlung: stündlich. Ein Lauf arbeitet höchstens 'zeitlimit' Sekunden (config.php), der nächste macht weiter.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/messdaten.php';
require __DIR__ . '/verdichtung.php';
require __DIR__ . '/sicherung.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $token = (string)($CFG['cron_token'] ?? '');
    if (strlen($token) < 16 || $token === 'BITTE-AENDERN' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit("Zugriff verweigert\n");
    }
    $probe = !empty($_GET['probe']);
    $backup = !empty($_GET['backup']);
} else {
    $probe = in_array('--probe', $argv, true);
    $backup = in_array('--backup', $argv, true);
}

// Nicht zweimal gleichzeitig laufen
$lock = fopen(dirname(db_datei()) . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Läuft bereits.\n");
set_time_limit((int)($CFG['zeitlimit'] ?? 50) + 60);
ignore_user_abort(true);

$r = lauf($probe);
echo date('d.m.Y H:i:s'), "\n", $r['text'], "\n", implode("\n", $r['log']), "\n";
if ($backup && !$probe) {
    foreach (datenbanken() as $k => $n) {
        try { echo 'Sicherung: ', basename(backup_datei($k, true)), "\n"; } catch (Throwable $e) { echo "Sicherung $n fehlgeschlagen: ", $e->getMessage(), "\n"; }
    }
    echo 'Sicherung: ', basename(backup_app()), "\n";
}
flock($lock, LOCK_UN);
