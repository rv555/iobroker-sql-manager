<?php
// Sicherung: Messdatenbank als SQL-Datei (gzip), App-Datenbank als SQLite-Kopie. Ohne mysqldump, reines PHP.
declare(strict_types=1);

// Tabellen für "nur Messwerte": Adapter-Tabellen und eigene Tabellen
const BACKUP_DATEN = ['datapoints', 'sources', 'ts_number', 'ts_bool', 'ts_string', 'ts_counter', 'sm_agg', 'sm_ts', 'sm_reihen', 'sm_papierkorb'];

function backup_ordner(): string
{
    global $CFG;
    $d = $CFG['backup_ordner'] ?? __DIR__ . '/data/backup';
    if (!is_dir($d)) mkdir($d, 0755, true);
    if (!is_file("$d/.htaccess")) file_put_contents("$d/.htaccess", "Require all denied\n");
    return $d;
}
// Nur Dateinamen, die diese App selbst erzeugt, und nur im Backup-Ordner
function backup_pfad(string $name): ?string
{
    if (!preg_match('/^[a-z0-9_]+_\d{4}-\d\d-\d\d_\d{6}(_daten|_alle)?\.(sql\.gz|sqlite)$/', $name)) return null;
    $f = backup_ordner() . '/' . $name;
    return is_file($f) ? $f : null;
}
function backup_liste(): array
{
    $l = [];
    foreach (glob(backup_ordner() . '/*') ?: [] as $f) {
        if (backup_pfad(basename($f))) $l[] = ['datei' => basename($f), 'groesse' => filesize($f), 'zeit' => filemtime($f)];
    }
    usort($l, fn($a, $b) => $b['zeit'] <=> $a['zeit']);
    return $l;
}

// SQL-Text der Datenbank stückweise an $aus übergeben
function backup_schreiben(string $dbk, bool $nurDaten, callable $aus): void
{
    global $CFG;
    $d = $CFG['datenbanken'][$dbk];
    // eigene Verbindung, die Zeilen ungepuffert liest (große Tabellen passen sonst nicht in den Speicher)
    $ungepuffert = defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    $m = new PDO((string)$d['dsn'], (string)($d['user'] ?? ''), (string)($d['pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $tabellen = $m->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $tabellen = array_values(array_map(fn($t) => $t[0], array_filter($tabellen, fn($t) => $t[1] === 'BASE TABLE')));
    if ($nurDaten) $tabellen = array_values(array_intersect($tabellen, BACKUP_DATEN));
    $aus("-- SQL-Manager Sicherung: " . datenbanken()[$dbk] . ', ' . date('d.m.Y H:i:s') . "\n-- Tabellen: " . implode(', ', $tabellen)
        . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    foreach ($tabellen as $t) {
        $create = $m->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $aus("DROP TABLE IF EXISTS `$t`;\n$create;\n");
        $m->setAttribute($ungepuffert, false);
        $st = $m->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $puffer = [];
        $kopf = null;
        foreach ($st as $z) {
            if ($kopf === null) {
                $sp = [];
                for ($i = 0; $i < $st->columnCount(); $i++) $sp[] = '`' . $st->getColumnMeta($i)['name'] . '`';
                $kopf = "INSERT INTO `$t` (" . implode(', ', $sp) . ") VALUES\n";
            }
            $puffer[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : $m->quote((string)$v)), $z)) . ')';
            if (count($puffer) >= 1000) { $aus($kopf . implode(",\n", $puffer) . ";\n"); $puffer = []; }
        }
        if ($puffer) $aus($kopf . implode(",\n", $puffer) . ";\n");
        $st->closeCursor();
        $m->setAttribute($ungepuffert, true);
        $aus("\n");
    }
    $aus("SET FOREIGN_KEY_CHECKS = 1;\n");
}

function backup_name(string $dbk, bool $nurDaten): string { return $dbk . '_' . date('Y-m-d_His') . ($nurDaten ? '_daten' : '_alle') . '.sql.gz'; }

// Sicherung in den Backup-Ordner; ältere über backup_behalten hinaus werden gelöscht
function backup_datei(string $dbk, bool $nurDaten): string
{
    global $CFG;
    set_time_limit(0);
    $f = backup_ordner() . '/' . backup_name($dbk, $nurDaten);
    $fp = fopen("$f.tmp", 'wb');
    $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    try {
        backup_schreiben($dbk, $nurDaten, fn($s) => fwrite($fp, deflate_add($z, $s, ZLIB_NO_FLUSH)));
        fwrite($fp, deflate_add($z, '', ZLIB_FINISH));
        fclose($fp);
        rename("$f.tmp", $f);
    } catch (Throwable $e) {
        fclose($fp);
        @unlink("$f.tmp");
        protokoll('fehler', 'Sicherung fehlgeschlagen: ' . $e->getMessage(), $dbk, null, null, null, true);
        throw $e;
    }
    backup_aufraeumen($dbk . '_', '.sql.gz');
    protokoll('backup', 'Sicherung ' . ($nurDaten ? '(nur Messwerte)' : '(alle Tabellen)') . ' gespeichert: ' . basename($f) . ', ' . mb(filesize($f)) . ' MB', $dbk);
    return $f;
}
function backup_download(string $dbk, bool $nurDaten): never
{
    set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . backup_name($dbk, $nurDaten) . '"');
    protokoll('backup', 'Sicherung ' . ($nurDaten ? '(nur Messwerte)' : '(alle Tabellen)') . ' heruntergeladen', $dbk);
    $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    backup_schreiben($dbk, $nurDaten, function ($s) use ($z) { echo deflate_add($z, $s, ZLIB_NO_FLUSH); flush(); });
    echo deflate_add($z, '', ZLIB_FINISH);
    exit;
}
// Kopie der App-Datenbank (Benutzer, Einstellungen, Reports, Protokoll)
function backup_app(): string
{
    $f = backup_ordner() . '/app_' . date('Y-m-d_His') . '.sqlite';
    q('VACUUM INTO ?', [$f]);
    backup_aufraeumen('app_', '.sqlite');
    protokoll('backup', 'App-Datenbank gesichert: ' . basename($f));
    return $f;
}
function backup_aufraeumen(string $anfang, string $ende): void
{
    global $CFG;
    $behalten = max(1, (int)($CFG['backup_behalten'] ?? 5));
    $l = array_values(array_filter(backup_liste(), fn($b) => str_starts_with($b['datei'], $anfang) && str_ends_with($b['datei'], $ende)));
    foreach (array_slice($l, $behalten) as $b) @unlink(backup_ordner() . '/' . $b['datei']);
}
