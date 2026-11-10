<?php
// Messdaten in MySQL/MariaDB: Reihen, letzte Werte, Statistik, Diagrammdaten, Löschen mit Papierkorb
declare(strict_types=1);

const EIGENE_AB = 1000000;    // Reihen-IDs ab hier sind eigene (abgeleitete) Reihen in sm_ts
const ROH_GRENZE = 4000;      // bis zu so vielen Werten im Zeitraum liefert das Diagramm Einzelwerte, sonst Mittelwerte je Intervall

function mq(PDO $m, string $sql, array $p = []): PDOStatement
{
    $st = $m->prepare($sql);
    $st->execute($p);
    return $st;
}

// Eigene Tabellen in der Messdatenbank (einmal je Datenbank angelegt). Die Tabellen des Adapters bleiben unverändert.
function m(string $dbk): PDO
{
    static $fertig = [];
    $m = mdb($dbk);
    if (!isset($fertig[$dbk]) && einst("tabellen_$dbk") !== '1') {
        $m->exec('CREATE TABLE IF NOT EXISTS sm_agg (id INT NOT NULL, ts BIGINT NOT NULL, v_min DOUBLE, v_max DOUBLE, cnt INT,
            PRIMARY KEY (id, ts))');
        $m->exec('CREATE TABLE IF NOT EXISTS sm_ts (id INT NOT NULL, ts BIGINT NOT NULL, val DOUBLE, PRIMARY KEY (id, ts))');
        $m->exec('CREATE TABLE IF NOT EXISTS sm_reihen (id INT NOT NULL PRIMARY KEY, name VARCHAR(255) NOT NULL)');
        $m->exec('CREATE TABLE IF NOT EXISTS sm_papierkorb (vorgang INT NOT NULL, id INT NOT NULL, ts BIGINT NOT NULL, val DOUBLE,
            ack TINYINT, _from INT, q INT, PRIMARY KEY (vorgang, id, ts))');
        einst_setzen("tabellen_$dbk", '1');
    }
    $fertig[$dbk] = true;
    return $m;
}

// Vorhandene Tabellen der Messdatenbank (gecacht je Aufruf)
function hat_tabelle(string $dbk, string $t): bool
{
    static $c = [];
    $c[$dbk] ??= array_flip(m($dbk)->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    return isset($c[$dbk][$t]);
}
// Spalten einer Tabelle (für INSERT nur mit vorhandenen Spalten, z. B. ack/_from/q)
function spalten(string $dbk, string $t): array
{
    static $c = [];
    return $c["$dbk.$t"] ??= array_column(m($dbk)->query("SHOW COLUMNS FROM `$t`")->fetchAll(), 'Field');
}

// Tabelle einer Reihe: eigene Reihen in sm_ts, sonst je nach Typ
function tabelle(array $r): string
{
    if ($r['id'] >= EIGENE_AB) return 'sm_ts';
    return $r['typ'] === 'bool' ? 'ts_bool' : 'ts_number';
}

// Alle Reihen einer Datenbank: Zahlen und Ja/Nein aus datapoints, dazu eigene Reihen; mit Zusatzangaben aus SQLite
function reihen_liste(string $dbk): array
{
    $meta = [];
    foreach (all('SELECT * FROM reihen WHERE db = ?', [$dbk]) as $r) $meta[(int)$r['id']] = $r;
    $vorlagen = array_column(all('SELECT id, name, stufen FROM vorlagen'), null, 'id');
    $l = [];
    if (hat_tabelle($dbk, 'datapoints')) {
        foreach (m($dbk)->query('SELECT id, name, type FROM datapoints WHERE type IN (0, 2) ORDER BY name') as $d)
            $l[(int)$d['id']] = ['id' => (int)$d['id'], 'name' => (string)$d['name'], 'typ' => (int)$d['type'] === 2 ? 'bool' : 'zahl'];
    }
    foreach ($meta as $id => $r) if ($id >= EIGENE_AB) $l[$id] = ['id' => $id, 'name' => $r['name'], 'typ' => 'zahl'];
    foreach ($l as $id => &$r) {
        $x = $meta[$id] ?? [];
        $vid = isset($x['vorlage']) ? (int)$x['vorlage'] : null;
        $r += [
            'farbe' => farbe($x['farbe'] ?? null),
            'anzeige' => (string)($x['anzeige'] ?? ''), 'einheit' => (string)($x['einheit'] ?? ''), 'gruppe' => (string)($x['gruppe'] ?? ''),
            'stellen' => isset($x['stellen']) ? (int)$x['stellen'] : null, 'warn_std' => isset($x['warn_std']) ? (int)$x['warn_std'] : null,
            'vorlage' => $vid && isset($vorlagen[$vid]) ? $vid : null,
            'vorlage_name' => $vid && isset($vorlagen[$vid]) ? $vorlagen[$vid]['name'] : '',
            'stufen_eigen' => json_decode((string)($x['stufen'] ?? '[]'), true) ?: [],
            'quelle' => isset($x['quelle']) ? (int)$x['quelle'] : null, 'funktion' => $x['funktion'] ?? null, 'raster' => $x['raster'] ?? null,
        ];
        $r['stufen'] = $r['vorlage'] ? (json_decode($vorlagen[$r['vorlage']]['stufen'], true) ?: []) : $r['stufen_eigen'];
        $r['titel'] = $r['anzeige'] !== '' ? $r['anzeige'] : kurzname($r['name']);
    }
    unset($r);
    return $l;
}
function reihe(string $dbk, int $id): ?array { return reihen_liste($dbk)[$id] ?? null; }

// Kurzer Name aus der ioBroker-ID: die letzten zwei Teile ("hm-rpc.0.ABC123.1.TEMPERATURE" -> "1.TEMPERATURE")
function kurzname(string $n): string
{
    $t = explode('.', $n);
    return count($t) > 2 ? implode('.', array_slice($t, -2)) : $n;
}

// Letzter Wert je Reihe: id => [ts, val]
function letzte_werte(string $dbk): array
{
    $l = [];
    foreach (['ts_number', 'ts_bool', 'sm_ts'] as $t) {
        if (!hat_tabelle($dbk, $t)) continue;
        foreach (m($dbk)->query("SELECT t.id, t.ts, t.val FROM `$t` t JOIN (SELECT id, MAX(ts) m FROM `$t` GROUP BY id) x ON x.id = t.id AND x.m = t.ts") as $r)
            $l[(int)$r['id']] = [(int)$r['ts'], $r['val'] === null ? null : (float)$r['val']];
    }
    return $l;
}

// Anzahl gesamt / letzter Monat, erster Wert und geschätzter Speicher je Reihe in SQLite ablegen
function statistik_aktualisieren(string $dbk): void
{
    $m = m($dbk);
    $monat = (time() - 30 * 86400) * 1000;
    $neu = [];
    foreach (['ts_number', 'ts_bool', 'sm_ts'] as $t) {
        if (!hat_tabelle($dbk, $t)) continue;
        // Speicher je Zeile aus der Tabellengröße (Daten + Index) geschätzt
        // (table_rows ist bei InnoDB nur geschätzt, daher die echte Zeilenzahl aus der Zählung)
        $g = mq($m, 'SELECT data_length + index_length AS b FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t])->fetch();
        $zeilen = mq($m, "SELECT id, COUNT(*) n, SUM(ts >= ?) mon, MIN(ts) erster FROM `$t` GROUP BY id", [$monat])->fetchAll();
        $summe = array_sum(array_map(fn($r) => (int)$r['n'], $zeilen));
        $proZeile = $g && $summe > 0 ? (int)$g['b'] / $summe : 40;
        foreach ($zeilen as $r) $neu[(int)$r['id']] = $r + ['bytes' => (int)round((int)$r['n'] * $proZeile)];
    }
    db()->beginTransaction();
    q('DELETE FROM statistik WHERE db = ?', [$dbk]);
    foreach ($neu as $id => $r) q('INSERT INTO statistik (db, id, anzahl, monat, erster, bytes) VALUES (?, ?, ?, ?, ?, ?)',
        [$dbk, $id, (int)$r['n'], (int)$r['mon'], (int)$r['erster'], $r['bytes']]);
    db()->commit();
    einst_setzen("statistik_$dbk", (string)time());
}
function statistik(string $dbk): array
{
    if (einst("statistik_$dbk") === '') statistik_aktualisieren($dbk);
    return array_column(all('SELECT * FROM statistik WHERE db = ?', [$dbk]), null, 'id');
}

// ---------- Diagrammdaten ----------

// Werte einer Reihe im Zeitraum (ms). Wenige Werte: Einzelwerte; viele: Mittel/Min/Max je Intervall
// (bei Ein/Aus ist der Mittelwert der Anteil "Ein" der Schaltwerte).
// Zeiten in Sekunden (für uPlot).
function daten(string $dbk, array $r, int $von, int $bis, int $punkte = 1500): array
{
    $m = m($dbk);
    $t = tabelle($r);
    $n = (int)mq($m, "SELECT COUNT(*) FROM `$t` WHERE id = ? AND ts BETWEEN ? AND ?", [$r['id'], $von, $bis])->fetchColumn();
    $x = $v = $mi = $ma = [];
    if ($n <= ROH_GRENZE) {
        $st = mq($m, "SELECT ts, val FROM `$t` WHERE id = ? AND ts BETWEEN ? AND ? ORDER BY ts LIMIT 200000", [$r['id'], $von, $bis]);
        foreach ($st as $z) { $x[] = $z['ts'] / 1000; $v[] = $z['val'] === null ? null : (float)$z['val']; }
        return ['roh' => true, 'n' => $n, 'intervall' => 0, 'x' => $x, 'v' => $v];
    }
    $b = max(60000, (int)ceil(($bis - $von) / $punkte / 60000) * 60000);
    $st = mq($m, "SELECT FLOOR(ts / $b) * $b + $b / 2 AS t, AVG(val) a, MIN(val) mi, MAX(val) ma FROM `$t`
        WHERE id = ? AND ts BETWEEN ? AND ? GROUP BY FLOOR(ts / $b) ORDER BY t", [$r['id'], $von, $bis]);
    foreach ($st as $z) { $x[] = $z['t'] / 1000; $v[] = (float)$z['a']; $mi[] = (float)$z['mi']; $ma[] = (float)$z['ma']; }
    return ['roh' => false, 'n' => $n, 'intervall' => $b, 'x' => $x, 'v' => $v, 'min' => $mi, 'max' => $ma];
}

// Werte je Raster zusammengefasst (für Balken, z. B. Strom je Tag): Summe, Mittel, Min oder Max.
// Ab 1 Stunde: erst stundenweise in SQL, dann nach Ortszeit zu Tagen/Wochen/Monaten/Jahren (Mittel gewichtet nach Anzahl).
const ZUSAMMEN_RASTER = ['15' => '15 Minuten', '30' => '30 Minuten', '60' => 'Stunde', '180' => '3 Stunden', '1440' => 'Tag', '10080' => 'Woche', 'monat' => 'Monat', 'jahr' => 'Jahr'];
const ZUSAMMEN_FKT = ['summe' => 'Summe', 'mittel' => 'Mittelwert', 'min' => 'Minimum', 'max' => 'Maximum'];
// Raster zum Zusammenfassen: feste Auswahl oder beliebige Minuten (1 bis 1439, z. B. "6")
function zusammen_raster(string $r): string
{
    if (isset(ZUSAMMEN_RASTER[$r])) return $r;
    return ctype_digit($r) && (int)$r >= 1 && (int)$r < 1440 ? (string)(int)$r : '';
}
function gcd_min(int $a, int $b = 60): int { while ($b) [$a, $b] = [$b, $a % $b]; return $a; }
function zusammen_raster_text(string $r): string { return ZUSAMMEN_RASTER[$r] ?? "$r Minuten"; }
function daten_zusammen(string $dbk, array $r, int $von, int $bis, string $raster, string $fkt): array
{
    $m = m($dbk);
    $t = tabelle($r);
    $von = raster_start($von, $raster);
    $grenzen = [];
    for ($g = $von; $g < $bis; $g = raster_naechst($g, $raster)) {
        $grenzen[] = $g;
        if (count($grenzen) > 3000) throw new RuntimeException('Zu viele Balken – bitte einen kürzeren Zeitraum oder ein gröberes Raster wählen.');
    }
    $grenzen[] = raster_naechst(end($grenzen), $raster);
    if ($r['typ'] === 'bool' && $fkt !== 'summe') return anteil_ein($dbk, $r, $grenzen, min($bis, time() * 1000));
    $b = is_numeric($raster) && (int)$raster < 1440 ? gcd_min((int)$raster) * 60000 : 3600000;
    $st = mq($m, "SELECT FLOOR(ts / $b) * $b AS k, SUM(val) s, COUNT(*) n, MIN(val) mi, MAX(val) ma FROM `$t`
        WHERE id = ? AND ts >= ? AND ts < ? GROUP BY FLOOR(ts / $b) ORDER BY k", [$r['id'], $von, end($grenzen)]);
    $sum = [];
    $j = 0;
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $su, $n, $mi, $ma]) {
        while ($j + 1 < count($grenzen) && $k >= $grenzen[$j + 1]) $j++;
        $x = &$sum[$j];
        $x ??= [0.0, 0, INF, -INF];
        $x[0] += (float)$su; $x[1] += (int)$n; $x[2] = min($x[2], (float)$mi); $x[3] = max($x[3], (float)$ma);
        unset($x);
    }
    $xs = $vs = [];
    foreach ($sum as $j => [$su, $n, $mi, $ma]) {
        $xs[] = ($grenzen[$j] + $grenzen[$j + 1]) / 2000;
        $vs[] = round(match ($fkt) { 'mittel' => $su / $n, 'min' => $mi, 'max' => $ma, default => $su }, 6);
    }
    return ['roh' => true, 'zusammen' => true, 'n' => count($xs), 'intervall' => 0, 'x' => $xs, 'v' => $vs];
}

// Ein/Aus-Reihen speichern nur Wechsel: Anteil "Ein" je Raster nach der Zeit gewichtet (0 bis 1)
function anteil_ein(string $dbk, array $r, array $grenzen, int $ende): array
{
    $m = m($dbk);
    $t = tabelle($r);
    $von = $grenzen[0];
    $bis = min(end($grenzen), $ende);
    $vorher = mq($m, "SELECT val FROM `$t` WHERE id = ? AND ts < ? ORDER BY ts DESC LIMIT 1", [$r['id'], $von])->fetchColumn();
    $zustand = $vorher === false ? null : (int)$vorher;
    $wechsel = mq($m, "SELECT ts, val FROM `$t` WHERE id = ? AND ts >= ? AND ts < ? ORDER BY ts", [$r['id'], $von, $bis])->fetchAll(PDO::FETCH_NUM);
    $wechsel[] = [$bis, null];
    $an = $bekannt = array_fill(0, count($grenzen) - 1, 0);
    $seit = $von;
    $j = 0;
    foreach ($wechsel as [$ts, $v]) {
        $ts = (int)$ts;
        // Zeit von $seit bis $ts mit dem bisherigen Zustand auf die Raster verteilen
        while ($seit < $ts && $j < count($grenzen) - 1) {
            $stueck = min($ts, $grenzen[$j + 1]) - $seit;
            if ($zustand !== null) { $bekannt[$j] += $stueck; if ($zustand) $an[$j] += $stueck; }
            $seit += $stueck;
            if ($seit >= $grenzen[$j + 1]) $j++;
        }
        if ($v !== null) $zustand = (int)$v;
    }
    $xs = $vs = [];
    foreach ($bekannt as $j => $b) {
        if ($b <= 0) continue;
        $xs[] = ($grenzen[$j] + $grenzen[$j + 1]) / 2000;
        $vs[] = round($an[$j] / $b, 4);
    }
    return ['roh' => true, 'zusammen' => true, 'n' => count($xs), 'intervall' => 0, 'x' => $xs, 'v' => $vs];
}

// Aktueller Wert (für Anzeigen): letzter Wert mit Zeit, dazu Min/Max der letzten 24 Stunden
function aktuell(string $dbk, array $r): array
{
    $m = m($dbk);
    $t = tabelle($r);
    $l = mq($m, "SELECT ts, val FROM `$t` WHERE id = ? ORDER BY ts DESC LIMIT 1", [$r['id']])->fetch();
    $g = mq($m, "SELECT MIN(val) mi, MAX(val) ma FROM `$t` WHERE id = ? AND ts >= ?", [$r['id'], (time() - 86400) * 1000])->fetch();
    return ['ts' => $l ? (int)$l['ts'] : null, 'wert' => $l && $l['val'] !== null ? (float)$l['val'] : null,
        'min24' => $g['mi'] === null ? null : (float)$g['mi'], 'max24' => $g['ma'] === null ? null : (float)$g['ma']];
}

// Mini-Diagramme: je Reihe höchstens ~60 Mittelwerte der letzten Stunden
function sparks(string $dbk, array $reihen, int $stunden): array
{
    $m = m($dbk);
    $bis = time() * 1000;
    $von = $bis - $stunden * 3600000;
    $b = max(60000, intdiv($stunden * 3600000, 60));
    $nachTab = [];
    foreach ($reihen as $r) $nachTab[tabelle($r)][] = (int)$r['id'];
    $l = [];
    foreach ($nachTab as $t => $ids) {
        if (!hat_tabelle($dbk, $t)) continue;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = mq($m, "SELECT id, FLOOR(ts / $b) k, AVG(val) a FROM `$t` WHERE id IN ($ph) AND ts >= ? GROUP BY id, k ORDER BY id, k", [...$ids, $von]);
        foreach ($st as $z) { $l[(int)$z['id']][0][] = (int)$z['k'] * $b / 1000; $l[(int)$z['id']][1][] = round((float)$z['a'], 3); }
    }
    return $l;
}

// Kennzahlen im Zeitraum, dazu größte Sprünge (Kandidaten für Fehlmessungen)
function kennzahlen(string $dbk, array $r, int $von, int $bis): array
{
    $m = m($dbk);
    $t = tabelle($r);
    $s = mq($m, "SELECT COUNT(*) n, AVG(val) avg, MIN(val) min, MAX(val) max FROM `$t` WHERE id = ? AND ts BETWEEN ? AND ?", [$r['id'], $von, $bis])->fetch();
    $spr = [];
    try {
        $spr = mq($m, "SELECT ts, val, d FROM (SELECT ts, val, ABS(val - LAG(val) OVER (ORDER BY ts)) d FROM `$t` WHERE id = ? AND ts BETWEEN ? AND ?) x
            WHERE d IS NOT NULL ORDER BY d DESC LIMIT 5", [$r['id'], $von, $bis])->fetchAll();
    } catch (Throwable $e) { /* ältere Datenbank ohne Fensterfunktionen */ }
    return ['n' => (int)$s['n'], 'avg' => $s['avg'] === null ? null : (float)$s['avg'], 'min' => $s['min'] === null ? null : (float)$s['min'],
        'max' => $s['max'] === null ? null : (float)$s['max'], 'spruenge' => $spr];
}

// ---------- Löschen mit Papierkorb ----------

// Bedingung für einen Löschbereich: Zeitraum, optional nur Werte unter $unter oder über $ueber
function loesch_bedingung(array $r, int $von, int $bis, ?float $unter, ?float $ueber): array
{
    $w = 'id = ? AND ts BETWEEN ? AND ?';
    $p = [$r['id'], $von, $bis];
    $g = [];
    if ($unter !== null) { $g[] = 'val < ?'; $p[] = $unter; }
    if ($ueber !== null) { $g[] = 'val > ?'; $p[] = $ueber; }
    if ($g) $w .= ' AND (' . implode(' OR ', $g) . ')';
    return [$w, $p];
}
function loeschen_zaehlen(string $dbk, array $r, int $von, int $bis, ?float $unter = null, ?float $ueber = null): array
{
    [$w, $p] = loesch_bedingung($r, $von, $bis, $unter, $ueber);
    $t = tabelle($r);
    $n = (int)mq(m($dbk), "SELECT COUNT(*) FROM `$t` WHERE $w", $p)->fetchColumn();
    $bsp = mq(m($dbk), "SELECT ts, val FROM `$t` WHERE $w ORDER BY ts LIMIT 10", $p)->fetchAll();
    return ['n' => $n, 'beispiele' => $bsp];
}
// Werte in den Papierkorb verschieben; liefert die Anzahl
function loeschen(string $dbk, array $r, int $von, int $bis, ?float $unter, ?float $ueber, string $grund): int
{
    [$w, $p] = loesch_bedingung($r, $von, $bis, $unter, $ueber);
    $m = m($dbk);
    $t = tabelle($r);
    q('INSERT INTO papierkorb (db, reihe, von, bis, grund, wer) VALUES (?, ?, ?, ?, ?, ?)', [$dbk, $r['id'], $von, $bis, $grund, wer()]);
    $pk = (int)db()->lastInsertId();
    $extra = array_values(array_intersect(['ack', '_from', 'q'], spalten($dbk, $t)));
    $sp = implode(', ', ['id', 'ts', 'val', ...$extra]);
    $m->beginTransaction();
    try {
        mq($m, "INSERT INTO sm_papierkorb (vorgang, $sp) SELECT ?, $sp FROM `$t` WHERE $w", [$pk, ...$p]);
        $n = mq($m, "DELETE FROM `$t` WHERE $w", $p)->rowCount();
        if ($r['typ'] !== 'bool') mq($m, 'DELETE FROM sm_agg WHERE id = ? AND ts BETWEEN ? AND ? AND ts NOT IN (SELECT ts FROM `' . $t . '` WHERE id = ? AND ts BETWEEN ? AND ?)',
            [$r['id'], $von, $bis, $r['id'], $von, $bis]);
        $m->commit();
    } catch (Throwable $e) {
        $m->rollBack();
        q('DELETE FROM papierkorb WHERE id = ?', [$pk]);
        throw $e;
    }
    q('UPDATE papierkorb SET anzahl = ? WHERE id = ?', [$n, $pk]);
    abgeleitete_zuruecksetzen($dbk, (int)$r['id'], $von);
    protokoll('loeschen', "$n Werte gelöscht (" . zeit($von) . ' bis ' . zeit($bis) . ')' . ($grund !== '' ? ": $grund" : ''), $dbk, (int)$r['id'], $n, 0);
    return $n;
}
function wiederherstellen(int $pk): int
{
    $v = one("SELECT * FROM papierkorb WHERE id = ? AND status = 'da'", [$pk]);
    if (!$v) throw new RuntimeException('Eintrag nicht mehr im Papierkorb.');
    $dbk = $v['db'];
    $r = reihe($dbk, (int)$v['reihe']);
    if (!$r) throw new RuntimeException('Reihe gibt es nicht mehr.');
    $m = m($dbk);
    $t = tabelle($r);
    $extra = array_values(array_intersect(['ack', '_from', 'q'], spalten($dbk, $t)));
    $sp = implode(', ', ['id', 'ts', 'val', ...$extra]);
    $m->beginTransaction();
    $n = mq($m, "INSERT IGNORE INTO `$t` ($sp) SELECT $sp FROM sm_papierkorb WHERE vorgang = ?", [$pk])->rowCount();
    mq($m, 'DELETE FROM sm_papierkorb WHERE vorgang = ?', [$pk]);
    $m->commit();
    q("UPDATE papierkorb SET status = 'zurueck' WHERE id = ?", [$pk]);
    abgeleitete_zuruecksetzen($dbk, (int)$r['id'], (int)$v['von']);
    protokoll('wiederherstellen', "$n Werte aus dem Papierkorb zurückgeholt (" . zeit((int)$v['von']) . ' bis ' . zeit((int)$v['bis']) . ')', $dbk, (int)$r['id'], 0, $n);
    return $n;
}
// Endgültig löschen: einen Eintrag oder alle älter als PAPIERKORB_TAGE
function papierkorb_leeren(?int $pk = null): int
{
    $l = $pk ? all("SELECT * FROM papierkorb WHERE id = ? AND status = 'da'", [$pk])
        : all("SELECT * FROM papierkorb WHERE status = 'da' AND am < datetime('now', ?)", ['-' . PAPIERKORB_TAGE . ' days']);
    foreach ($l as $v) {
        try { mq(m($v['db']), 'DELETE FROM sm_papierkorb WHERE vorgang = ?', [$v['id']]); } catch (Throwable $e) { continue; }
        q("UPDATE papierkorb SET status = 'weg' WHERE id = ?", [$v['id']]);
    }
    return count($l);
}

// Eine Reihe ganz aus der Datenbank löschen: alle Werte, verdichtete Werte, Papierkorb, Eintrag in datapoints,
// Einstellungen und Verwendung in Reports. Ohne Papierkorb (nur Backup hilft). Liefert die Anzahl gelöschter Werte.
// Reihenfolge: erst Werte in Paketen (bricht der Aufruf ab, lässt er sich wiederholen), datapoints und Einstellungen zuletzt.
function reihe_entfernen(string $dbk, array $r): int
{
    $id = (int)$r['id'];
    $abh = array_filter(reihen_liste($dbk), fn($x) => $x['quelle'] === $id);
    if ($abh) throw new RuntimeException('Aus dieser Reihe werden eigene Reihen berechnet: „' . implode('“, „', array_column($abh, 'titel'))
        . '“. Bitte diese zuerst löschen.');
    $m = m($dbk);
    $n = 0;
    $tabellen = $id >= EIGENE_AB ? ['sm_ts'] : ['ts_number', 'ts_bool', 'ts_string'];
    foreach ([...$tabellen, 'sm_agg', 'sm_papierkorb'] as $t) {
        if (!hat_tabelle($dbk, $t)) continue;
        do {
            $k = mq($m, "DELETE FROM `$t` WHERE id = ? LIMIT 50000", [$id])->rowCount();
            if ($t !== 'sm_agg' && $t !== 'sm_papierkorb') $n += $k;
        } while ($k === 50000);
    }
    if ($id >= EIGENE_AB) mq($m, 'DELETE FROM sm_reihen WHERE id = ?', [$id]);
    elseif (hat_tabelle($dbk, 'datapoints')) mq($m, 'DELETE FROM datapoints WHERE id = ?', [$id]);

    db()->beginTransaction();
    q("UPDATE papierkorb SET status = 'weg' WHERE db = ? AND reihe = ? AND status = 'da'", [$dbk, $id]);
    foreach (['reihen', 'stand', 'statistik'] as $t) q("DELETE FROM $t WHERE db = ? AND id = ?", [$dbk, $id]);
    // aus Diagrammen entfernen; Anzeigen dieser Reihe und leere Diagramme fallen weg
    foreach (all('SELECT id, reihen FROM diagramme') as $d) {
        $alt = json_decode($d['reihen'], true) ?: [];
        $neu = array_values(array_filter($alt, fn($x) => !(($x['db'] ?? '') === $dbk && (int)($x['id'] ?? 0) === $id)));
        if (count($neu) === count($alt)) continue;
        if ($neu) q('UPDATE diagramme SET reihen = ? WHERE id = ?', [json_encode($neu), $d['id']]);
        else q('DELETE FROM diagramme WHERE id = ?', [$d['id']]);
    }
    db()->commit();
    protokoll('reihe_loeschen', "Reihe „{$r['titel']}“ ({$r['name']}) ganz gelöscht", $dbk, $id, $n, 0);
    return $n;
}

// ---------- Anzeige ----------

function wert_text(?float $v, array $r): string
{
    if ($v === null) return '–';
    if ($r['typ'] === 'bool') return $v ? 'Ein' : 'Aus';
    $st = $r['stellen'] ?? (abs($v) >= 100 ? 0 : (abs($v) >= 10 ? 1 : 2));
    return zahl($v, $st) . ($r['einheit'] !== '' ? ' ' . $r['einheit'] : '');
}
function mb(int $bytes): string { return $bytes < 1048576 ? zahl($bytes / 1048576, 2) : zahl($bytes / 1048576, 1); }
