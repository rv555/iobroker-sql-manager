<?php
// JSON-API (POST, Header X-CSRF): Diagrammdaten, Mini-Diagramme, Bearbeiten-Fenster, Vorschau, Löschen, Lauf
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/messdaten.php';
require __DIR__ . '/verdichtung.php';

header('Content-Type: application/json; charset=utf-8');
header_sicher();
function antwort(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$u = me();
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$u) antwort(['fehler' => 'Nicht angemeldet.', 'login' => 1], 401);
if ($u['pwneu']) antwort(['fehler' => 'Bitte zuerst ein eigenes Passwort festlegen.'], 403);
if (!csrf_ok()) antwort(['fehler' => 'Sitzung abgelaufen, bitte Seite neu laden.', 'login' => 1], 400);

$a = (string)($in['a'] ?? '');
$schreibend = ['loeschen_pruefen', 'loeschen', 'reihe_laden', 'reihe_speichern', 'vorschau', 'abgeleitet_anlegen', 'abgeleitet_loeschen', 'reihe_loeschen', 'lauf'];
if (in_array($a, $schreibend, true) && !$u['admin']) antwort(['fehler' => 'Nur für Admins.'], 403);

function eingabe_db(array $in): string
{
    $k = (string)($in['db'] ?? '');
    if (!isset(datenbanken()[$k])) antwort(['fehler' => 'Unbekannte Datenbank.'], 400);
    return $k;
}
function eingabe_reihe(array $in): array
{
    $dbk = eingabe_db($in);
    $r = reihe($dbk, (int)($in['id'] ?? 0));
    if (!$r) antwort(['fehler' => 'Reihe nicht gefunden.'], 404);
    return [$dbk, $r];
}
// Zeitraum in ms, höchstens 30 Jahre
function zeitraum(array $in): array
{
    $bis = (int)($in['bis'] ?? 0) ?: time() * 1000;
    $von = (int)($in['von'] ?? 0) ?: $bis - 7 * 86400000;
    if ($von >= $bis || $bis - $von > 30 * 366 * 86400000) antwort(['fehler' => 'Ungültiger Zeitraum.'], 400);
    return [$von, $bis];
}
function zahl_oder_null(mixed $v): ?float
{
    if ($v === null || $v === '') return null;
    $v = str_replace(',', '.', (string)$v);
    return is_numeric($v) ? (float)$v : null;
}
function reihe_kurz(string $dbk, array $r): array
{
    return ['db' => $dbk, 'id' => $r['id'], 'titel' => $r['titel'], 'name' => $r['name'], 'einheit' => $r['einheit'], 'typ' => $r['typ'], 'stellen' => $r['stellen'], 'farbe' => $r['farbe']];
}

try {
    switch ($a) {
        case 'ping':
            antwort(['ok' => 1]);

        case 'sparks':
            $dbk = eingabe_db($in);
            $std = max(1, min(24 * 31, (int)($in['stunden'] ?? 6)));
            antwort(['daten' => sparks($dbk, reihen_liste($dbk), $std)]);

        case 'daten': // eine oder mehrere Reihen (Reports): [{db, id, achse, art, farbe}, ...], optional zusammen: {raster, funktion}
            [$von, $bis] = zeitraum($in);
            $zr = (string)($in['zusammen']['raster'] ?? '');
            $zr = zusammen_raster($zr);
            $out = [];
            foreach (array_slice((array)($in['reihen'] ?? []), 0, 12) as $x) {
                $dbk = (string)($x['db'] ?? '');
                if (!isset(datenbanken()[$dbk])) continue;
                $r = reihe($dbk, (int)($x['id'] ?? 0));
                if (!$r) continue;
                $k = reihe_kurz($dbk, $r);
                if ($f = farbe($x['farbe'] ?? null)) $k['farbe'] = $f; // eigene Farbe im Diagramm
                $k['achse'] = ($x['achse'] ?? 'l') === 'r' ? 'r' : 'l';
                $k['art'] = in_array($x['art'] ?? '', ['linie', 'kurve', 'balken', 'flaeche', 'stufen'], true) ? $x['art'] : 'linie';
                $fkt = isset(ZUSAMMEN_FKT[$x['fkt'] ?? '']) ? $x['fkt'] : (in_array($k['art'], ['balken', 'flaeche'], true) ? 'summe' : 'mittel');
                $out[] = $k + ($zr !== '' ? daten_zusammen($dbk, $r, $von, $bis, $zr, $fkt) : daten($dbk, $r, $von, $bis, (int)($in['punkte'] ?? 1500) ?: 1500));
            }
            antwort(['reihen' => $out, 'von' => $von, 'bis' => $bis]);

        case 'aktuell': // Anzeigen: letzter Wert je Reihe
            $out = [];
            foreach (array_slice((array)($in['reihen'] ?? []), 0, 40) as $x) {
                $dbk = (string)($x['db'] ?? '');
                if (!isset(datenbanken()[$dbk])) continue;
                $r = reihe($dbk, (int)($x['id'] ?? 0));
                if ($r) $out[] = reihe_kurz($dbk, $r) + aktuell($dbk, $r);
            }
            antwort(['reihen' => $out]);

        case 'kennzahlen':
            [$dbk, $r] = eingabe_reihe($in);
            [$von, $bis] = zeitraum($in);
            antwort(kennzahlen($dbk, $r, $von, $bis));

        case 'loeschen_pruefen':
        case 'loeschen':
            [$dbk, $r] = eingabe_reihe($in);
            [$von, $bis] = zeitraum($in);
            $unter = zahl_oder_null($in['unter'] ?? null);
            $ueber = zahl_oder_null($in['ueber'] ?? null);
            if ($a === 'loeschen_pruefen') antwort(loeschen_zaehlen($dbk, $r, $von, $bis, $unter, $ueber));
            $n = loeschen($dbk, $r, $von, $bis, $unter, $ueber, mb_substr(trim((string)($in['grund'] ?? '')), 0, 200));
            antwort(['geloescht' => $n]);

        case 'reihe_laden': // für das Bearbeiten-Fenster
            [$dbk, $r] = eingabe_reihe($in);
            $liste = reihen_liste($dbk);
            antwort([
                'reihe' => $r,
                'vorlagen' => array_map(fn($v) => ['id' => (int)$v['id'], 'name' => $v['name'], 'stufen' => stufen_sauber(json_decode($v['stufen'], true)), 'n' => (int)$v['n']],
                    all('SELECT v.*, (SELECT COUNT(*) FROM reihen r WHERE r.vorlage = v.id) AS n FROM vorlagen v ORDER BY name')),
                'gruppen' => array_values(array_unique(array_filter(array_column($liste, 'gruppe')))),
                'abgeleitete' => array_values(array_map(fn($x) => ['id' => $x['id'], 'titel' => $x['titel'], 'funktion' => FUNKTIONEN[$x['funktion']] ?? '', 'raster' => ABL_RASTER[$x['raster']] ?? ''],
                    array_filter($liste, fn($x) => $x['quelle'] === $r['id']))),
                'quelle' => $r['quelle'] && isset($liste[$r['quelle']]) ? $liste[$r['quelle']]['titel'] : null,
                'anzahl' => (int)(statistik($dbk)[$r['id']]['anzahl'] ?? 0),
                'raster' => RASTER, 'methoden' => METHODEN, 'funktionen' => FUNKTIONEN, 'abl_raster' => ABL_RASTER, 'funktionen_wechsel' => FUNKTIONEN_WECHSEL,
            ]);

        case 'reihe_speichern':
            [$dbk, $r] = eingabe_reihe($in);
            $vorlage = (int)($in['vorlage'] ?? 0);
            if ($vorlage && !val('SELECT 1 FROM vorlagen WHERE id = ?', [$vorlage])) $vorlage = 0;
            $stufen = stufen_sauber($in['stufen'] ?? []);
            if ($r['typ'] === 'bool') $stufen = array_values(array_filter($stufen, fn($s) => METHODEN[$s['m']][3]));
            $stellen = ($in['stellen'] ?? '') === '' ? null : max(0, min(6, (int)$in['stellen']));
            $warn = ($in['warn_std'] ?? '') === '' ? null : max(0, min(8760, (int)$in['warn_std']));
            $t = fn($k, $n) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
            q('INSERT INTO reihen (db, id, name, typ) VALUES (?, ?, ?, ?) ON CONFLICT (db, id) DO NOTHING', [$dbk, $r['id'], $r['name'], $r['typ']]);
            q('UPDATE reihen SET name = ?, typ = ?, anzeige = ?, einheit = ?, gruppe = ?, stellen = ?, warn_std = ?, vorlage = ?, stufen = ?, farbe = ?,
                updated = CURRENT_TIMESTAMP, updated_by = ? WHERE db = ? AND id = ?',
                [$r['name'], $r['typ'], $t('anzeige', 100), $t('einheit', 20), $t('gruppe', 60), $stellen, $warn, $vorlage ?: null,
                 json_encode($vorlage ? [] : $stufen), farbe($in['farbe'] ?? null), uid(), $dbk, $r['id']]);
            if ($r['id'] >= EIGENE_AB && $t('anzeige', 100) !== '') mq(m($dbk), 'REPLACE INTO sm_reihen (id, name) VALUES (?, ?)', [$r['id'], $t('anzeige', 100)]);
            // Stufen einer Vorlage im Fenster geändert: Vorlage ändern (gilt für alle Reihen mit dieser Vorlage)
            $vg = 0;
            if ($vorlage && !empty($in['vorlage_aendern'])) {
                $alt = stufen_sauber(json_decode((string)val('SELECT stufen FROM vorlagen WHERE id = ?', [$vorlage]), true));
                if ($alt !== $stufen) {
                    q('UPDATE vorlagen SET stufen = ?, updated = CURRENT_TIMESTAMP WHERE id = ?', [json_encode($stufen), $vorlage]);
                    $vg = (int)val('SELECT COUNT(*) FROM reihen WHERE vorlage = ?', [$vorlage]);
                    protokoll('verdichtung', 'Vorlage „' . val('SELECT name FROM vorlagen WHERE id = ?', [$vorlage]) . '“ geändert: ' . stufen_text($stufen) . " (gilt für $vg Reihen)", $dbk, $r['id']);
                }
            }
            antwort(['ok' => 1, 'vorlage_geaendert' => $vg]);

        case 'vorschau':
            [$dbk, $r] = eingabe_reihe($in);
            $s = stufen_sauber([$in['stufe'] ?? []])[0] ?? null;
            if (!$s) antwort(['fehler' => 'Ungültige Stufe.'], 400);
            // Ausschnitt: angegebener Zeitraum, sonst die letzten 2 Tage
            $bis = (int)($in['bis'] ?? 0) ?: time() * 1000;
            $von = (int)($in['von'] ?? 0) ?: $bis - 2 * 86400000;
            if ($von >= $bis || $bis - $von > 62 * 86400000) antwort(['fehler' => 'Vorschau höchstens für 2 Monate.'], 400);
            antwort(reihe_kurz($dbk, $r) + vorschau($dbk, $r, $s, $von, $bis));

        case 'abgeleitet_anlegen': // neue Reihe, z. B. Monatssumme
            [$dbk, $q] = eingabe_reihe(['db' => $in['db'] ?? '', 'id' => $in['quelle'] ?? 0]);
            $f = (string)($in['funktion'] ?? '');
            $rs = (string)($in['raster'] ?? '');
            $wechsel = in_array($f, FUNKTIONEN_WECHSEL, true);
            if (!isset(FUNKTIONEN[$f]) || !isset(ABL_RASTER[$rs]) || ($q['typ'] === 'bool' && !$wechsel) || $q['id'] >= EIGENE_AB) antwort(['fehler' => 'Ungültige Angaben.'], 400);
            // Nummern gelöschter eigener Reihen nicht neu vergeben (Grafana-Abfragen zeigen sonst eine andere Reihe)
            $neu = max(EIGENE_AB, (int)val('SELECT MAX(id) FROM reihen WHERE db = ?', [$dbk]) + 1, (int)einst("naechste_$dbk"));
            einst_setzen("naechste_$dbk", (string)($neu + 1));
            $anz = mb_substr(trim((string)($in['anzeige'] ?? '')), 0, 100) ?: $q['titel'] . ' – ' . FUNKTIONEN[$f] . ' je ' . ABL_RASTER[$rs];
            $einheit = $wechsel ? '' : $q['einheit'];
            q("INSERT INTO reihen (db, id, name, typ, anzeige, einheit, gruppe, quelle, funktion, raster, updated_by) VALUES (?, ?, ?, 'zahl', ?, ?, ?, ?, ?, ?, ?)",
                [$dbk, $neu, 'sm.' . $neu, $anz, $einheit, $q['gruppe'], $q['id'], $f, $rs, uid()]);
            mq(m($dbk), 'REPLACE INTO sm_reihen (id, name) VALUES (?, ?)', [$neu, $anz]);
            $n = abgeleitet_berechnen($dbk, reihe($dbk, $neu), $q, microtime(true) + 20);
            protokoll('abgeleitet', "Neue Reihe „{$anz}“ angelegt, $n Werte berechnet", $dbk, $neu, null, $n);
            antwort(['id' => $neu, 'werte' => $n]);

        case 'abgeleitet_loeschen':
        case 'reihe_loeschen': // ganze Reihe mit allen Werten, ohne Papierkorb
            [$dbk, $r] = eingabe_reihe($in);
            if ($a === 'abgeleitet_loeschen' && $r['id'] < EIGENE_AB) antwort(['fehler' => 'Nur eigene Reihen lassen sich so löschen.'], 400);
            if ($a === 'reihe_loeschen' && trim((string)($in['bestaetigung'] ?? '')) !== $r['titel']) antwort(['fehler' => 'Name zur Bestätigung stimmt nicht.'], 400);
            set_time_limit(600);
            try { $n = reihe_entfernen($dbk, $r); } catch (RuntimeException $e) { antwort(['fehler' => $e->getMessage()], 400); }
            antwort(['ok' => 1, 'werte' => $n]);

        case 'lauf': // Verdichtung von Hand (probe = nur zählen)
            set_time_limit((int)($CFG['zeitlimit'] ?? 50) + 60);
            $lock = fopen(dirname(db_datei()) . '/cron.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) antwort(['fehler' => 'Ein Lauf ist gerade aktiv (Cron). Bitte gleich noch einmal.'], 409);
            $r = lauf(!empty($in['probe']));
            flock($lock, LOCK_UN);
            antwort($r);
    }
    antwort(['fehler' => 'Unbekannte Aktion.'], 400);
} catch (Throwable $e) {
    antwort(['fehler' => $e instanceof PDOException ? 'Datenbankfehler: ' . $e->getMessage() : $e->getMessage()], 500);
}
