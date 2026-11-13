<?php
// Grundfunktionen: Konfiguration, App-Datenbank (SQLite), Sitzung, Login, Rechte, Hilfsfunktionen
declare(strict_types=1);

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set('Europe/Berlin');
// Fehlermeldungen nie im Browser zeigen (verraten Pfade und Code), außer lokal mit 'debug' => true
ini_set('display_errors', empty($CFG['debug']) ? '0' : '1');
const SCHEMA = 2;
const VERSION = 14;            // an style.css/app.js, damit Browser nach dem Hochladen neu laden
const MERKEN_TAGE = 60;       // "Angemeldet bleiben": so lange ohne Nutzung, jede Nutzung verlängert
const MAX_FEHLVERSUCHE = 5;   // danach ist der Login 15 Minuten gesperrt (je Benutzername und je IP)
const PAPIERKORB_TAGE = 30;   // manuell gelöschte Messwerte bleiben so lange wiederherstellbar
const PROTOKOLL_TAGE = 365;   // ältere Protokolleinträge werden gelöscht

// ---------- App-Datenbank (SQLite) ----------

function db_datei(): string
{
    global $CFG;
    return $CFG['sqlite'] ?? __DIR__ . '/data/sqlman.sqlite';
}
function db(): PDO
{
    static $db = null;
    if ($db) return $db;
    $file = db_datei();
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_file("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "Require all denied\n");
    $db = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000');
    $v = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($v < SCHEMA) {
        schema($db, $v);
        $db->exec('PRAGMA user_version = ' . SCHEMA);
    }
    return $db;
}

function schema(PDO $db, int $v): void
{
    $db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE, fullname TEXT NOT NULL DEFAULT '',
        pw TEXT, pwneu INTEGER NOT NULL DEFAULT 1, admin INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
        last_login TEXT, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS fails (name TEXT NOT NULL COLLATE NOCASE, ip TEXT NOT NULL, at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS logins (sel TEXT PRIMARY KEY, hash TEXT NOT NULL,
        user INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, marke TEXT NOT NULL, expires TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS einst (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '');
    -- Vorlagen für die Verdichtung (Stufen als JSON)
    CREATE TABLE IF NOT EXISTS vorlagen (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, stufen TEXT NOT NULL DEFAULT '[]',
        created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated TEXT);
    -- Zusatzangaben je Datenreihe (id = datapoints.id des Adapters, ab 1000000 = eigene Reihe in sm_ts)
    CREATE TABLE IF NOT EXISTS reihen (
        db TEXT NOT NULL, id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', typ TEXT NOT NULL DEFAULT 'zahl',
        anzeige TEXT NOT NULL DEFAULT '', einheit TEXT NOT NULL DEFAULT '', gruppe TEXT NOT NULL DEFAULT '',
        stellen INTEGER, warn_std INTEGER,
        vorlage INTEGER REFERENCES vorlagen(id) ON DELETE SET NULL, stufen TEXT NOT NULL DEFAULT '[]',
        quelle INTEGER, funktion TEXT, raster TEXT, aktiv INTEGER NOT NULL DEFAULT 1,
        created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated TEXT, updated_by INTEGER,
        PRIMARY KEY (db, id));
    -- Fortschritt je Reihe und Stufe (bis = ms, bis dahin bearbeitet; marke = Fingerabdruck der Stufen)
    CREATE TABLE IF NOT EXISTS stand (db TEXT NOT NULL, id INTEGER NOT NULL, stufe INTEGER NOT NULL,
        bis INTEGER NOT NULL DEFAULT 0, marke TEXT NOT NULL DEFAULT '', PRIMARY KEY (db, id, stufe));
    -- Zahlen für die Übersicht (vom Cron bzw. Knopf aktualisiert, die Zählung ist bei großen Tabellen langsam)
    CREATE TABLE IF NOT EXISTS statistik (db TEXT NOT NULL, id INTEGER NOT NULL, anzahl INTEGER NOT NULL DEFAULT 0,
        monat INTEGER NOT NULL DEFAULT 0, erster INTEGER, bytes INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (db, id));
    CREATE TABLE IF NOT EXISTS protokoll (id INTEGER PRIMARY KEY, am TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        art TEXT NOT NULL, db TEXT NOT NULL DEFAULT '', reihe INTEGER, text TEXT NOT NULL DEFAULT '',
        vorher INTEGER, nachher INTEGER, wer TEXT NOT NULL DEFAULT '', fehler INTEGER NOT NULL DEFAULT 0);
    CREATE INDEX IF NOT EXISTS protokoll_am ON protokoll(am);
    -- Manuell gelöschte Bereiche; die Werte selbst liegen in sm_papierkorb der Messdatenbank
    CREATE TABLE IF NOT EXISTS papierkorb (id INTEGER PRIMARY KEY, am TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        db TEXT NOT NULL, reihe INTEGER NOT NULL, von INTEGER NOT NULL, bis INTEGER NOT NULL, anzahl INTEGER NOT NULL DEFAULT 0,
        grund TEXT NOT NULL DEFAULT '', wer TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'da');
    CREATE TABLE IF NOT EXISTS reports (id INTEGER PRIMARY KEY, name TEXT NOT NULL, pos INTEGER NOT NULL DEFAULT 0,
        tage INTEGER NOT NULL DEFAULT 7, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER, updated TEXT, updated_by INTEGER);
    CREATE TABLE IF NOT EXISTS diagramme (id INTEGER PRIMARY KEY, report INTEGER NOT NULL REFERENCES reports(id) ON DELETE CASCADE,
        pos INTEGER NOT NULL DEFAULT 0, titel TEXT NOT NULL DEFAULT '', hoehe INTEGER NOT NULL DEFAULT 260, reihen TEXT NOT NULL DEFAULT '[]');
    ");
    if ($v < 1) { // Standard-Vorlagen
        $st = $db->prepare('INSERT OR IGNORE INTO vorlagen (name, stufen) VALUES (?, ?)');
        foreach ([
            'Raumtemperatur' => [['ab' => 90, 'm' => 'mittel', 'r' => '60']],
            'Heizkreis (Vor-/Rücklauf)' => [['ab' => 90, 'm' => 'wende', 's' => 0.5], ['ab' => 730, 'm' => 'mittel', 'r' => '1440']],
            'Verbrauch (Einzelwerte, z. B. Wh)' => [['ab' => 90, 'm' => 'summe', 'r' => '60'], ['ab' => 730, 'm' => 'summe', 'r' => '1440']],
            'Zählerstand' => [['ab' => 90, 'm' => 'letzter', 'r' => '60'], ['ab' => 730, 'm' => 'letzter', 'r' => '1440']],
            'Schalter (Ein/Aus)' => [['ab' => 30, 'm' => 'wechsel']],
            'Alles behalten' => [],
        ] as $n => $s) $st->execute([$n, json_encode($s)]);
    }
    if ($v < 2) { // Farbe je Reihe; Diagramm-Art (diagramm/anzeige) und Optionen (Zusammenfassen, Stapeln, Skala der Anzeige)
        $db->exec("ALTER TABLE reihen ADD COLUMN farbe TEXT");
        $db->exec("ALTER TABLE diagramme ADD COLUMN art TEXT NOT NULL DEFAULT 'diagramm'");
        $db->exec("ALTER TABLE diagramme ADD COLUMN optionen TEXT NOT NULL DEFAULT '{}'");
    }
}
// Farbe '#rrggbb' oder null
function farbe(mixed $f): ?string { return is_string($f) && preg_match('/^#[0-9a-f]{6}$/i', $f) ? strtolower($f) : null; }

function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function one(string $sql, array $p = []): ?array { return q($sql, $p)->fetch() ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []): mixed { return q($sql, $p)->fetchColumn(); }

// ---------- Messdaten-Datenbanken (MySQL/MariaDB aus config.php) ----------

// Schlüssel => Anzeigename aller eingetragenen Datenbanken
function datenbanken(): array
{
    global $CFG;
    $l = [];
    foreach ((array)($CFG['datenbanken'] ?? []) as $k => $d) if (preg_match('/^[a-z0-9_]+$/', (string)$k)) $l[$k] = (string)($d['name'] ?? $k);
    return $l;
}
// Gültiger Datenbank-Schlüssel (sonst die erste)
function dbkey(mixed $k): string
{
    $l = datenbanken();
    return is_string($k) && isset($l[$k]) ? $k : (string)array_key_first($l);
}
function mdb(string $k): PDO
{
    static $c = [];
    if (isset($c[$k])) return $c[$k];
    global $CFG;
    $d = $CFG['datenbanken'][$k] ?? null;
    if (!$d || !isset(datenbanken()[$k])) throw new RuntimeException('Datenbank nicht eingetragen.');
    $c[$k] = new PDO((string)$d['dsn'], (string)($d['user'] ?? ''), (string)($d['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    return $c[$k];
}

// ---------- Sitzung, Login, CSRF ----------

function https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function sitzung(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('sqlman');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => https(), 'path' => cookie_pfad()]);
    session_start();
}
// Cookies nur für den Pfad dieser App (mehrere Programme unter einer Subdomain stören sich nicht)
function cookie_pfad(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
}
function header_sicher(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'self'; form-action 'self'; base-uri 'none'; object-src 'none'");
    if (https()) header('Strict-Transport-Security: max-age=31536000');
}

function me(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    sitzung();
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = one('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']]);
        // Nach Passwortwechsel oder -reset sind alle älteren Sitzungen ungültig
        if (!$u || !hash_equals(pw_marke($u), (string)($_SESSION['pwm'] ?? ''))) { unset($_SESSION['uid']); $u = null; }
    }
    if (!$u && ($id = merken_pruefen())) { // Sitzung abgelaufen, aber "angemeldet bleiben" gesetzt
        anmelden($id);
        $u = one('SELECT * FROM users WHERE id = ?', [$id]);
    }
    return $u;
}
function ist_admin(): bool { $u = me(); return $u && $u['admin'] && !$u['pwneu']; }
function wer(): string { $u = me(); return $u ? ($u['fullname'] ?: $u['name']) : 'Cron'; }

// "Angemeldet bleiben": Cookie "Kennung:Geheimnis", in der Datenbank nur der Hash des Geheimnisses
function merk_cookie(string $wert, int $ablauf): void
{
    setcookie('sqlman_merk', $wert, ['expires' => $ablauf, 'path' => cookie_pfad(), 'secure' => https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function merken_setzen(int $uid): void
{
    $sel = bin2hex(random_bytes(9));
    $geheim = bin2hex(random_bytes(24));
    q("INSERT INTO logins (sel, hash, user, marke, expires) VALUES (?, ?, ?, ?, datetime('now', ?))",
        [$sel, hash('sha256', $geheim), $uid, pw_marke(one('SELECT pw FROM users WHERE id = ?', [$uid])), '+' . MERKEN_TAGE . ' days']);
    merk_cookie("$sel:$geheim", time() + MERKEN_TAGE * 86400);
}
function merken_loeschen(): void
{
    [$sel] = explode(':', (string)($_COOKIE['sqlman_merk'] ?? '')) + [''];
    if ($sel !== '') q('DELETE FROM logins WHERE sel = ?', [$sel]);
    merk_cookie('', 1);
}
function merken_pruefen(): ?int
{
    if (empty($_COOKIE['sqlman_merk']) || !str_contains($_COOKIE['sqlman_merk'], ':')) return null;
    [$sel, $geheim] = explode(':', (string)$_COOKIE['sqlman_merk'], 2);
    q("DELETE FROM logins WHERE expires < datetime('now')");
    $r = one('SELECT l.*, u.pw, u.active FROM logins l JOIN users u ON u.id = l.user WHERE l.sel = ?', [$sel]);
    if (!$r || !$r['active'] || !hash_equals($r['hash'], hash('sha256', $geheim)) || !hash_equals($r['marke'], pw_marke($r))) {
        merken_loeschen();
        return null;
    }
    q("UPDATE logins SET expires = datetime('now', ?) WHERE sel = ?", ['+' . MERKEN_TAGE . ' days', $sel]);
    merk_cookie($_COOKIE['sqlman_merk'], time() + MERKEN_TAGE * 86400);
    return (int)$r['user'];
}
// Kurzer Fingerabdruck des Passwort-Hashs für die Sitzung
function pw_marke(array $u): string { return substr(hash('sha256', (string)$u['pw']), 0, 16); }
function anmelden(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    $_SESSION['pwm'] = pw_marke(one('SELECT pw FROM users WHERE id = ?', [$id]));
    q('UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?', [$id]);
}
function uid(): ?int { return !empty($_SESSION['uid']) ? (int)$_SESSION['uid'] : null; }

// Login-Sperre: zu viele Fehlversuche je Name oder IP in den letzten 15 Minuten?
function gesperrt(string $name): bool
{
    q("DELETE FROM fails WHERE at < datetime('now', '-15 minutes')");
    return (int)val('SELECT COUNT(*) FROM fails WHERE name = ?', [$name]) >= MAX_FEHLVERSUCHE
        || (int)val('SELECT COUNT(*) FROM fails WHERE ip = ?', [$_SERVER['REMOTE_ADDR'] ?? '']) >= MAX_FEHLVERSUCHE * 4;
}
// Notfall-Zugang nur, wenn ein eigenes Einrichtungspasswort gesetzt ist
function notfall_moeglich(): bool
{
    global $CFG;
    $p = (string)($CFG['initpw'] ?? '');
    return mb_strlen($p) >= 8 && $p !== 'BITTE-AENDERN';
}
function csrf(): string
{
    sitzung();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_ok(): bool
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    return is_string($t) && hash_equals(csrf(), $t);
}
function csrf_feld(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }

// ---------- Einstellungen ----------

function einst(string $k, string $vorgabe = ''): string
{
    $v = val('SELECT v FROM einst WHERE k = ?', [$k]);
    return is_string($v) && $v !== '' ? $v : $vorgabe;
}
function einst_setzen(string $k, string $v): void { q('INSERT OR REPLACE INTO einst (k, v) VALUES (?, ?)', [$k, $v]); }

// ---------- Protokoll ----------

function protokoll(string $art, string $text, string $db = '', ?int $reihe = null, ?int $vorher = null, ?int $nachher = null, bool $fehler = false): void
{
    q('INSERT INTO protokoll (art, db, reihe, text, vorher, nachher, wer, fehler) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$art, $db, $reihe, mb_substr($text, 0, 2000), $vorher, $nachher, wer(), (int)$fehler]);
}

// ---------- Ausgabe ----------

function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function zahl(float|int|null $z, int $stellen = 0): string { return $z === null ? '–' : number_format((float)$z, $stellen, ',', '.'); }
// Zeitpunkt in Millisekunden als Datum/Uhrzeit (Ortszeit)
function zeit(?int $ms, bool $sek = false): string { return $ms ? date($sek ? 'd.m.Y H:i:s' : 'd.m.Y H:i', intdiv($ms, 1000)) : '–'; }
function utc_zeit(?string $t): string { return $t ? date('d.m.Y H:i', strtotime($t . ' UTC')) : ''; }

function flash(string $msg, string $art = 'ok'): void { sitzung(); $_SESSION['flash'][] = [$art, $msg]; }
function weiter(string $q = ''): never
{
    header('Location: index.php' . $q);
    exit;
}
// Rücksprung-Adresse aus einem Formularfeld (nur Zeichen einer Abfrage, keine fremde Adresse)
function zurueck(string $feld = 'zurueck'): string
{
    $z = preg_replace('/[^\w=&%.\-\[\]]/', '', (string)($_POST[$feld] ?? ''));
    return $z !== '' ? '?' . $z : '';
}
