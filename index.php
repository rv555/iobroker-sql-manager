<?php
// Verteiler: Einrichtung, Login, Formular-Aktionen (Feld "do"), Rahmen mit Menü, Seiten aus seiten/
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/messdaten.php';
require __DIR__ . '/verdichtung.php';
require __DIR__ . '/sicherung.php';
require __DIR__ . '/bausteine.php';

$s = preg_replace('/[^a-z_]/', '', $_GET['s'] ?? 'start') ?: 'start';
sitzung();
$u = me();
$fehler = '';
// Gewählte Messdatenbank (Übersicht, Verdichtung): ?db=..., sonst die zuletzt gewählte
if (isset($_GET['db'])) $_SESSION['db'] = dbkey($_GET['db']);
$dbk = dbkey($_SESSION['db'] ?? null);

// ---------- Formular-Aktionen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { http_response_code(400); exit('Sitzung abgelaufen, bitte Seite neu laden.'); }
    $do = $_POST['do'] ?? '';
    $P = fn($k) => trim((string)($_POST[$k] ?? ''));
    $id = (int)($_POST['id'] ?? 0);

    if ($do === 'setup' && !val('SELECT COUNT(*) FROM users')) {
        if (!hash_equals((string)$CFG['initpw'], $P('initpw'))) $fehler = 'Einrichtungspasswort falsch.';
        elseif (mb_strlen($P('pw')) < 8 || $P('pw') !== $P('pw2')) $fehler = 'Passwort: mind. 8 Zeichen, beide gleich.';
        elseif ($P('name') === '') $fehler = 'Benutzername fehlt.';
        else {
            q('INSERT INTO users (name, fullname, pw, pwneu, admin) VALUES (?, ?, ?, 0, 1)',
                [$P('name'), $P('fullname'), password_hash($P('pw'), PASSWORD_DEFAULT)]);
            anmelden((int)db()->lastInsertId());
            weiter();
        }
    } elseif ($do === 'login') {
        if (gesperrt($P('name'))) $fehler = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        else {
            $r = one('SELECT * FROM users WHERE name = ? AND active = 1', [$P('name')]);
            // Auch bei unbekanntem Namen ein Passwort prüfen, damit die Antwortzeit nichts verrät
            $ok = password_verify($P('pw'), $r['pw'] ?? '$2y$12$5gI6Jfki4yUfe8zulkVE9eo75m5kzVAkP9FXVgpP1MOMICE9mQZJe');
            if ($r && $r['pw'] && $ok) {
                q('DELETE FROM fails WHERE name = ?', [$P('name')]);
                anmelden((int)$r['id']);
                if (isset($_POST['merken'])) merken_setzen((int)$r['id']);
                weiter(zurueck());
            }
            q('INSERT INTO fails (name, ip) VALUES (?, ?)', [$P('name'), $_SERVER['REMOTE_ADDR'] ?? '']);
            usleep(500000);
            $fehler = 'Benutzername oder Passwort falsch.';
        }
    } elseif ($do === 'notfall') { // Passwort vergessen: Admin setzt mit dem Einrichtungspasswort ein neues
        $r = one('SELECT * FROM users WHERE name = ? AND active = 1 AND admin = 1', [$P('name')]);
        if (!notfall_moeglich()) $fehler = 'Notfall-Zugang ist nicht eingerichtet.';
        elseif (gesperrt($P('name'))) $fehler = 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        elseif (!$r || !hash_equals(trim((string)$CFG['initpw']), $P('initpw'))) {
            q('INSERT INTO fails (name, ip) VALUES (?, ?)', [$P('name'), $_SERVER['REMOTE_ADDR'] ?? '']);
            usleep(500000);
            $fehler = 'Anmeldename oder Einrichtungspasswort falsch, oder der Benutzer ist kein Admin.';
        } elseif (mb_strlen($P('pw')) < 8 || $P('pw') !== $P('pw2')) $fehler = 'Passwort: mind. 8 Zeichen, beide gleich.';
        elseif ($P('pw') === (string)$CFG['initpw']) $fehler = 'Bitte nicht das Einrichtungspasswort als eigenes Passwort verwenden.';
        else {
            q('UPDATE users SET pw = ?, pwneu = 0 WHERE id = ?', [password_hash($P('pw'), PASSWORD_DEFAULT), $r['id']]);
            q('DELETE FROM fails WHERE name = ?', [$P('name')]);
            q('DELETE FROM logins WHERE user = ?', [$r['id']]);
            anmelden((int)$r['id']); // alle anderen Sitzungen dieses Benutzers sind damit abgemeldet
            flash('Neues Passwort gesetzt.');
            weiter();
        }
    } elseif ($do === 'logout') {
        merken_loeschen();
        $_SESSION = [];
        session_destroy();
        weiter();
    } elseif (!$u) {
        weiter();
    } elseif ($do === 'pw') {
        if (!$u['pwneu'] && !password_verify($P('alt'), (string)$u['pw'])) $fehler = 'Bisheriges Passwort falsch.';
        elseif (mb_strlen($P('pw')) < 8 || $P('pw') !== $P('pw2')) $fehler = 'Passwort: mind. 8 Zeichen, beide gleich.';
        else {
            q('UPDATE users SET pw = ?, pwneu = 0 WHERE id = ?', [password_hash($P('pw'), PASSWORD_DEFAULT), $u['id']]);
            $merk = isset($_COOKIE['sqlman_merk']);
            q('DELETE FROM logins WHERE user = ?', [$u['id']]); // andere Geräte müssen sich neu anmelden
            anmelden((int)$u['id']);
            if ($merk) merken_setzen((int)$u['id']);
            flash('Passwort gespeichert.');
            weiter();
        }
    } elseif ($u['pwneu']) {
        weiter(); // mit Startpasswort nur Passwort setzen oder abmelden
    } elseif (!$u['admin']) {
        http_response_code(403);
        exit('Nur für Admins.');
    } else {
        // ---------- Ab hier nur Admin ----------
        try {
            switch ($do) {
                case 'statistik':
                    statistik_aktualisieren($dbk);
                    flash('Zahlen aktualisiert.');
                    weiter('?s=start');
                case 'einst_save':
                    einst_setzen('farbe', preg_match('/^#[0-9a-f]{6}$/i', $P('farbe')) ? $P('farbe') : '');
                    einst_setzen('titel', mb_substr($P('titel'), 0, 40));
                    flash('Einstellungen gespeichert.');
                    weiter('?s=einstellungen');
                case 'user_add':
                    if ($P('name') === '') weiter('?s=benutzer');
                    if (val('SELECT 1 FROM users WHERE name = ?', [$P('name')])) { flash('Benutzername existiert schon.', 'fehler'); weiter('?s=benutzer'); }
                    $start = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
                    q('INSERT INTO users (name, fullname, pw, pwneu, admin) VALUES (?, ?, ?, 1, ?)',
                        [$P('name'), $P('fullname'), password_hash($start, PASSWORD_DEFAULT), (int)isset($_POST['admin'])]);
                    flash('Benutzer angelegt. Startpasswort: ' . $start);
                    weiter('?s=benutzer');
                case 'user_save':
                    $self = $id === (int)$u['id'];
                    q('UPDATE users SET fullname = ?, admin = ?, active = ? WHERE id = ?',
                        [$P('fullname'), $self ? 1 : (int)isset($_POST['admin']), $self ? 1 : (int)isset($_POST['active']), $id]);
                    if (!$self && !isset($_POST['active'])) q('DELETE FROM logins WHERE user = ?', [$id]);
                    flash('Benutzer gespeichert.');
                    weiter('?s=benutzer');
                case 'user_pw':
                    $start = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
                    q('UPDATE users SET pw = ?, pwneu = 1 WHERE id = ?', [password_hash($start, PASSWORD_DEFAULT), $id]);
                    q('DELETE FROM logins WHERE user = ?', [$id]);
                    flash('Neues Startpasswort: ' . $start);
                    weiter('?s=benutzer');
                case 'user_del':
                    if ($id === (int)$u['id']) { flash('Das eigene Konto lässt sich nicht löschen.', 'fehler'); weiter('?s=benutzer'); }
                    q('DELETE FROM users WHERE id = ?', [$id]);
                    flash('Benutzer gelöscht.');
                    weiter('?s=benutzer');
                case 'vorlage_save': // Stufen kommen als JSON aus dem Stufen-Editor (app.js)
                    $stufen = json_encode(stufen_sauber(json_decode($P('stufen'), true)));
                    if ($P('name') === '') { flash('Name fehlt.', 'fehler'); weiter('?s=verdichtung'); }
                    if ($id) q('UPDATE vorlagen SET name = ?, stufen = ?, updated = CURRENT_TIMESTAMP WHERE id = ?', [$P('name'), $stufen, $id]);
                    else q('INSERT INTO vorlagen (name, stufen) VALUES (?, ?)', [$P('name'), $stufen]);
                    flash('Vorlage gespeichert.' . ($id ? ' Alle Reihen mit dieser Vorlage verdichten ab dem nächsten Lauf danach.' : ''));
                    weiter('?s=verdichtung');
                case 'vorlage_del':
                    $n = (int)val('SELECT COUNT(*) FROM reihen WHERE vorlage = ?', [$id]);
                    if ($n) { flash("Die Vorlage wird noch von $n Reihen genutzt.", 'fehler'); weiter('?s=verdichtung'); }
                    q('DELETE FROM vorlagen WHERE id = ?', [$id]);
                    flash('Vorlage gelöscht.');
                    weiter('?s=verdichtung');
                case 'report_save':
                    $tage = max(1, min(3650, (int)$P('tage') ?: 7));
                    if ($P('name') === '') weiter('?s=reports');
                    if ($id) q('UPDATE reports SET name = ?, tage = ?, pos = COALESCE(?, pos), updated = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?',
                        [$P('name'), $tage, $P('pos') === '' ? null : (int)$P('pos'), uid(), $id]);
                    else {
                        q('INSERT INTO reports (name, tage, pos, created_by) VALUES (?, ?, (SELECT COALESCE(MAX(pos), 0) + 1 FROM reports), ?)', [$P('name'), $tage, uid()]);
                        $id = (int)db()->lastInsertId();
                    }
                    weiter('?s=reports&r=' . $id);
                case 'report_del':
                    q('DELETE FROM reports WHERE id = ?', [$id]);
                    flash('Report gelöscht.');
                    weiter('?s=reports');
                case 'diagramm_save': // Reihen: rd[] = "db:id", je Reihe achse/art/farbe (farbe nur mit Haken eigenfarbe)
                    $rep = (int)$P('report');
                    if (!val('SELECT 1 FROM reports WHERE id = ?', [$rep])) weiter('?s=reports');
                    $reihen = [];
                    foreach (array_slice((array)($_POST['rd'] ?? []), 0, 12) as $x) {
                        if (!preg_match('/^([a-z0-9_]+):(\d+)$/', (string)$x, $mm) || !isset(datenbanken()[$mm[1]])) continue;
                        $e = ['db' => $mm[1], 'id' => (int)$mm[2], 'achse' => ($_POST['achse'][$x] ?? 'l') === 'r' ? 'r' : 'l',
                            'art' => isset(DIAGRAMM_ARTEN[$_POST['art'][$x] ?? '']) ? $_POST['art'][$x] : 'linie'];
                        if (isset($_POST['eigenfarbe'][$x]) && ($f = farbe($_POST['farbe'][$x] ?? null))) $e['farbe'] = $f;
                        if (isset(ZUSAMMEN_FKT[$_POST['fkt'][$x] ?? ''])) $e['fkt'] = $_POST['fkt'][$x];
                        $p = trim((string)($_POST['platz'][$x] ?? ''));
                        $reihen[] = [$p === '' ? 999 : (int)$p, count($reihen), $e];
                    }
                    // Reihenfolge nach "Platz" (1 = beim Stapeln unten), Leere hinten, sonst wie gewählt
                    usort($reihen, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
                    $reihen = array_column($reihen, 2);
                    $opt = [];
                    $zr = zusammen_raster($P('z_raster') === 'min' ? trim($P('z_min')) : $P('z_raster'));
                    if ($zr !== '') $opt = ['raster' => $zr];
                    if (isset($_POST['stapeln'])) $opt['stapeln'] = 1;
                    if (isset($_POST['halb'])) $opt['halb'] = 1;
                    $hoehe = max(150, min(800, (int)$P('hoehe') ?: 260));
                    $werte = [$P('titel'), $hoehe, json_encode($reihen), json_encode((object)$opt)];
                    if ($id) q("UPDATE diagramme SET titel = ?, hoehe = ?, reihen = ?, optionen = ?, art = 'diagramm' WHERE id = ? AND report = ?", [...$werte, $id, $rep]);
                    else q("INSERT INTO diagramme (report, pos, titel, hoehe, reihen, optionen, art) VALUES (?, (SELECT COALESCE(MAX(pos), 0) + 1 FROM diagramme WHERE report = ?), ?, ?, ?, ?, 'diagramm')",
                        [$rep, $rep, ...$werte]);
                    q('UPDATE reports SET updated = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?', [uid(), $rep]);
                    weiter('?s=reports&r=' . $rep);
                case 'anzeige_save': // Anzeige (Gauge): eine Reihe, Skala von/bis, Grenzen g1/g2, Farben f1-f3
                    $rep = (int)$P('report');
                    if (!val('SELECT 1 FROM reports WHERE id = ?', [$rep])) weiter('?s=reports');
                    $zahl = fn($k) => is_numeric($v = str_replace(',', '.', $P($k))) ? (float)$v : null;
                    if (!preg_match('/^([a-z0-9_]+):(\d+)$/', $P('reihe'), $mm) || !isset(datenbanken()[$mm[1]]) || $zahl('von') === null || $zahl('bis') === null || $zahl('von') >= $zahl('bis')) {
                        flash('Bitte eine Reihe wählen und eine gültige Skala (von kleiner als bis) angeben.', 'fehler');
                        weiter('?s=reports&r=' . $rep);
                    }
                    $opt = ['von' => $zahl('von'), 'bis' => $zahl('bis'), 'g1' => $zahl('g1'), 'g2' => $zahl('g2')];
                    foreach (['f1', 'f2', 'f3'] as $k) $opt[$k] = farbe($P($k)) ?? ANZEIGE_STANDARD[$k];
                    $werte = [$P('titel'), json_encode([['db' => $mm[1], 'id' => (int)$mm[2]]]), json_encode($opt)];
                    if ($id) q("UPDATE diagramme SET titel = ?, reihen = ?, optionen = ?, art = 'anzeige' WHERE id = ? AND report = ?", [...$werte, $id, $rep]);
                    else q("INSERT INTO diagramme (report, pos, titel, reihen, optionen, art) VALUES (?, (SELECT COALESCE(MAX(pos), 0) + 1 FROM diagramme WHERE report = ?), ?, ?, ?, 'anzeige')",
                        [$rep, $rep, ...$werte]);
                    weiter('?s=reports&r=' . $rep);
                case 'diagramm_del':
                    $rep = (int)val('SELECT report FROM diagramme WHERE id = ?', [$id]);
                    q('DELETE FROM diagramme WHERE id = ?', [$id]);
                    weiter('?s=reports&r=' . $rep);
                case 'diagramm_kopie': // Diagramm oder Anzeige kopieren, ans Ende desselben oder eines anderen Reports
                    $d = one('SELECT * FROM diagramme WHERE id = ?', [$id]);
                    $ziel = (int)$P('ziel');
                    if (!$d || !val('SELECT 1 FROM reports WHERE id = ?', [$ziel])) weiter('?s=reports');
                    $titel = $ziel === (int)$d['report'] && $d['titel'] !== '' ? $d['titel'] . ' (Kopie)' : $d['titel'];
                    q('INSERT INTO diagramme (report, pos, art, titel, hoehe, reihen, optionen) VALUES (?, (SELECT COALESCE(MAX(pos), 0) + 1 FROM diagramme WHERE report = ?), ?, ?, ?, ?, ?)',
                        [$ziel, $ziel, $d['art'], $titel, $d['hoehe'], $d['reihen'], $d['optionen']]);
                    q('UPDATE reports SET updated = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?', [uid(), $ziel]);
                    flash('Kopie angelegt (am Ende des Reports).');
                    weiter('?s=reports&r=' . $ziel);
                case 'diagramm_pos': // ein Diagramm nach oben/unten
                    $d = one('SELECT * FROM diagramme WHERE id = ?', [$id]);
                    if ($d) {
                        $nb = one('SELECT * FROM diagramme WHERE report = ? AND pos ' . ($P('r') === 'hoch' ? '< ? ORDER BY pos DESC' : '> ? ORDER BY pos') . ' LIMIT 1', [$d['report'], $d['pos']]);
                        if ($nb) { q('UPDATE diagramme SET pos = ? WHERE id = ?', [$nb['pos'], $d['id']]); q('UPDATE diagramme SET pos = ? WHERE id = ?', [$d['pos'], $nb['id']]); }
                    }
                    weiter('?s=reports&r=' . ($d['report'] ?? ''));
                case 'pk_zurueck':
                    flash(zahl(wiederherstellen($id)) . ' Werte wiederhergestellt.');
                    weiter('?s=papierkorb');
                case 'pk_weg':
                    papierkorb_leeren($id);
                    flash('Endgültig gelöscht.');
                    weiter('?s=papierkorb');
                case 'backup':
                    $b = dbkey($P('db'));
                    $nurDaten = $P('umfang') !== 'alle';
                    if ($P('ziel') === 'download') backup_download($b, $nurDaten);
                    $f = backup_datei($b, $nurDaten);
                    flash('Sicherung erstellt: ' . basename($f) . ' (' . mb(filesize($f)) . ' MB)');
                    weiter('?s=backup');
                case 'backup_app':
                    $f = backup_app();
                    flash('App-Datenbank gesichert: ' . basename($f));
                    weiter('?s=backup');
                case 'backup_del':
                    $f = backup_pfad($P('datei'));
                    if ($f) { unlink($f); protokoll('backup', 'Sicherung gelöscht: ' . basename($f)); flash('Sicherung gelöscht.'); }
                    weiter('?s=backup');
            }
        } catch (Throwable $e) {
            flash('Fehler: ' . $e->getMessage(), 'fehler');
            weiter(zurueck() ?: '?s=' . $s);
        }
        weiter();
    }
}

// ---------- Seiten ----------
header_sicher();
$seite = match (true) {
    !val('SELECT COUNT(*) FROM users') => 'setup',
    !$u && $s === 'notfall' => 'notfall',
    !$u => 'login',
    (bool)$u['pwneu'] => 'konto',
    default => $s,
};
if ($seite === 'backup_dl' && $u['admin']) { // gespeicherte Sicherung herunterladen
    $f = backup_pfad((string)($_GET['f'] ?? ''));
    if (!$f) { http_response_code(404); exit('Datei nicht gefunden.'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}
$nurAdmin = ['verdichtung', 'papierkorb', 'backup', 'benutzer', 'einstellungen'];
if (!is_file(__DIR__ . "/seiten/$seite.php") || (in_array($seite, $nurAdmin, true) && !$u['admin'])) $seite = 'start';

// Seite zuerst puffern: sie setzt $titel und darf noch umleiten
ob_start();
$titel = 'Übersicht';
try {
    require __DIR__ . "/seiten/$seite.php";
} catch (PDOException $e) {
    echo '<div class="meldung fehler">Datenbank nicht erreichbar oder Fehler: ' . h($e->getMessage()) . '</div>';
}
$inhalt = ob_get_clean();
$farbe = einst('farbe');
$appname = einst('titel', 'SQL-Manager');
// Berichtsansicht: kleines Menü mit den Reports, Link zum SQL-Manager
$schlank = $seite === 'berichte';
$menue = ['start' => 'Übersicht', 'reports' => 'Reports', 'protokoll' => 'Protokoll'];
if ($u && $u['admin']) $menue += ['verdichtung' => 'Verdichtung', 'papierkorb' => 'Papierkorb', 'backup' => 'Backup'];
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrf() ?>">
<title><?= h($titel) ?> · <?= h($appname) ?></title>
<link rel="icon" type="image/png" sizes="64x64" href="favicon.png">
<link rel="icon" type="image/png" sizes="48x48" href="icon-48.png">
<link rel="icon" type="image/png" sizes="96x96" href="icon-96.png">
<link rel="apple-touch-icon" href="icon-180.png">
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#1b4f8a">
<link rel="stylesheet" href="vendor/uPlot.min.css">
<link rel="stylesheet" href="style.css?v=<?= VERSION ?>">
<?php if ($farbe): ?><link rel="stylesheet" href="farbe.php?f=<?= urlencode(substr($farbe, 1)) ?>"><?php endif ?>
<script src="vendor/uPlot.iife.min.js"></script>
<script src="app.js?v=<?= VERSION ?>"></script>
</head>
<body<?= $schlank ? ' class="schlank"' : '' ?>>
<header class="kopf">
  <?php if ($schlank): $rid = (int)($_GET['r'] ?? 0); ?>
  <a class="marke" href="berichte.php">Berichte</a>
  <nav class="hauptmenue">
    <?php foreach (all('SELECT id, name FROM reports ORDER BY pos, name') as $i => $x): ?><a href="?s=berichte&amp;r=<?= $x['id'] ?>"<?= $x['id'] === $rid || (!$rid && !$i) ? ' class="aktiv"' : '' ?>><?= h($x['name']) ?></a><?php endforeach ?>
  </nav>
  <span class="platz"></span>
  <a class="kopflink" href="index.php" title="Zur Verwaltung"><?= h($appname) ?> ↗</a>
  <button type="button" class="knopf klein" id="thema" title="Hell / Dunkel">◐</button>
  <?php else: ?>
  <a class="marke" href="index.php"><?= h($appname) ?></a>
  <?php if ($u && !$u['pwneu']): ?>
  <nav class="hauptmenue">
    <?php foreach ($menue as $k => $n): ?><a href="?s=<?= $k ?>"<?= $seite === $k || ($k === 'start' && $seite === 'reihe') ? ' class="aktiv"' : '' ?>><?= $n ?></a><?php endforeach ?>
  </nav>
  <?php endif ?>
  <span class="platz"></span>
  <a class="kopflink" href="berichte.php" title="Nur die Reports, mit kleinem Menü">Berichte ↗</a>
  <button type="button" class="knopf klein" id="thema" title="Hell / Dunkel">◐</button>
  <?php endif ?>
  <?php if ($u): ?>
  <details class="menue">
    <summary><?= h($u['fullname'] ?: $u['name']) ?> ▾</summary>
    <div class="menueliste">
      <?php if (!$u['pwneu']): ?>
      <a href="?s=hilfe">Hilfe</a>
      <a href="?s=konto">Passwort ändern</a>
      <?php if ($u['admin']): ?>
      <a href="?s=benutzer">Benutzer</a>
      <a href="?s=einstellungen">Einstellungen</a>
      <?php endif ?>
      <?php endif ?>
      <form method="post"><?= csrf_feld() ?><button name="do" value="logout">Abmelden</button></form>
    </div>
  </details>
  <?php endif ?>
</header>
<main>
<?php foreach ($_SESSION['flash'] ?? [] as [$art, $msg]): ?><div class="meldung <?= $art ?>"><?= h($msg) ?></div><?php endforeach;
unset($_SESSION['flash']);
if ($fehler): ?><div class="meldung fehler"><?= h($fehler) ?></div><?php endif ?>
<?= $inhalt ?>
</main>
<script type="application/json" id="konstanten"><?= json_encode(['raster' => RASTER, 'methoden' => METHODEN, 'funktionen' => FUNKTIONEN, 'abl_raster' => ABL_RASTER],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</body>
</html>
