<?php
// Verdichtung: Methoden, Stufen je Reihe, abgeleitete Reihen (z. B. Monatssummen), Vorschau, Lauf per Cron
declare(strict_types=1);

// Raster: Schlüssel = Minuten (bzw. 'monat') => Name
const RASTER = ['1' => '1 Minute', '5' => '5 Minuten', '10' => '10 Minuten', '15' => '15 Minuten', '30' => '30 Minuten',
    '60' => '1 Stunde', '180' => '3 Stunden', '360' => '6 Stunden', '1440' => '1 Tag', '10080' => '1 Woche', 'monat' => '1 Monat'];
// Methoden: Name, braucht Raster, braucht Schwelle, auch für Ja/Nein-Reihen
const METHODEN = [
    'mittel'   => ['Mittelwert je Raster', true, false, false],
    'summe'    => ['Summe je Raster', true, false, false],
    'letzter'  => ['Letzter Wert je Raster', true, false, false],
    'wende'    => ['Kurvenform erhalten (Wendepunkte, Toleranz)', false, true, false],
    'wechsel'  => ['Nur Änderungen behalten', false, false, true],
    'loeschen' => ['Löschen', false, false, true],
];
// Abgeleitete Reihen: Funktion und Raster
const FUNKTIONEN = ['summe' => 'Summe', 'mittel' => 'Mittelwert', 'min' => 'Minimum', 'max' => 'Maximum', 'letzter' => 'Letzter Wert',
    'differenz' => 'Verbrauch aus Zählerstand (Differenz)'];
const ABL_RASTER = ['60' => 'Stunde', '1440' => 'Tag', '10080' => 'Woche', 'monat' => 'Monat', 'jahr' => 'Jahr'];

// ---------- Raster ----------

// Beginn des Rasters, in dem $ms liegt. Ab 1 Tag nach Ortszeit (Mitternacht, Montag, Monatserster).
function raster_start(int $ms, string $r): int
{
    if (is_numeric($r) && (int)$r < 1440) { $b = (int)$r * 60000; return intdiv($ms, $b) * $b; }
    $d = (new DateTime('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('Europe/Berlin'))->setTime(0, 0);
    if ($r === 'monat') $d->modify('first day of this month');
    elseif ($r === 'jahr') $d->setDate((int)$d->format('Y'), 1, 1);
    elseif ($r === '10080') $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
    return $d->getTimestamp() * 1000;
}
function raster_naechst(int $start, string $r): int
{
    if (is_numeric($r) && (int)$r < 1440) return $start + (int)$r * 60000;
    $d = (new DateTime('@' . intdiv($start, 1000)))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $d->modify(match ($r) { 'monat' => '+1 month', 'jahr' => '+1 year', '10080' => '+7 days', default => '+1 day' });
    return $d->getTimestamp() * 1000;
}

// Stufen prüfen und sortieren (nach "ab Tage")
function stufen_sauber(mixed $l): array
{
    $out = [];
    foreach ((array)$l as $s) {
        if (!is_array($s) || !isset(METHODEN[$s['m'] ?? ''])) continue;
        $m = $s['m'];
        $x = ['ab' => max(1, min(36500, (int)($s['ab'] ?? 90))), 'm' => $m];
        if (METHODEN[$m][1]) $x['r'] = isset(RASTER[(string)($s['r'] ?? '')]) ? (string)$s['r'] : '60';
        if (METHODEN[$m][2]) $x['s'] = max(0, (float)str_replace(',', '.', (string)($s['s'] ?? 0.5)));
        $out[] = $x;
    }
    usort($out, fn($a, $b) => $a['ab'] <=> $b['ab']);
    return $out;
}
function stufe_text(array $s): string
{
    $t = match ($s['m']) {
        'mittel' => 'Mittel ' . RASTER[$s['r']],
        'summe' => 'Summe ' . RASTER[$s['r']],
        'letzter' => 'Letzter ' . RASTER[$s['r']],
        'wende' => 'Kurvenform ±' . zahl($s['s'], $s['s'] == floor($s['s']) ? 0 : ($s['s'] * 10 == floor($s['s'] * 10) ? 1 : 2)),
        'wechsel' => 'nur Änderungen',
        'loeschen' => 'löschen',
    };
    return 'ab ' . tage_text($s['ab']) . ': ' . $t;
}
function tage_text(int $t): string
{
    if ($t >= 365 && $t % 365 === 0) return ($t / 365) . ' J';
    if ($t >= 30 && $t % 30 === 0 && $t < 365) return ($t / 30) . ' Mon';
    return $t . ' T';
}
function stufen_text(array $l): string { return $l ? implode(' · ', array_map('stufe_text', $l)) : 'alles behalten'; }

// ---------- Methoden (gleich für Vorschau und echten Lauf) ----------

// $rows: [[ts, val], ...] nach Zeit sortiert, $agg: ts => [min, max, cnt] aus früheren Stufen.
// Ergebnis: ['behalten' => [ts, ...]] (auswählende Methoden) oder ['neu' => [[ts, val], ...], 'agg' => [[ts, min, max, cnt], ...]]
function verdichte(array $rows, array $s, int $von, int $bis, array $agg = []): array
{
    $n = count($rows);
    if ($s['m'] === 'loeschen') return ['behalten' => []];
    if ($n === 0) return in_array($s['m'], ['mittel', 'summe'], true) ? ['neu' => [], 'agg' => []] : ['behalten' => []];

    if ($s['m'] === 'wechsel') {
        $k = [$rows[0][0]];
        $letzt = $rows[0][1];
        for ($i = 1; $i < $n; $i++) if ($rows[$i][1] != $letzt) { $k[] = $rows[$i][0]; $letzt = $rows[$i][1]; }
        return ['behalten' => $k];
    }

    if ($s['m'] === 'wende') return ['behalten' => wendepunkte($rows, (float)$s['s'])];

    // Rastergrenzen ab $von, dann Werte den Rastern zuordnen
    $grenzen = [];
    for ($g = raster_start($von, $s['r']); $g < $bis; $g = raster_naechst($g, $s['r'])) $grenzen[] = $g;
    $grenzen[] = raster_naechst(end($grenzen), $s['r']);
    $gruppen = [];
    $j = 0;
    foreach ($rows as $z) {
        while ($j + 1 < count($grenzen) && $z[0] >= $grenzen[$j + 1]) $j++;
        $gruppen[$j][] = $z;
    }
    if ($s['m'] === 'letzter') return ['behalten' => array_values(array_map(fn($g) => end($g)[0], $gruppen))];

    $neu = $ag = [];
    foreach ($gruppen as $j => $g) {
        $mitte = intdiv($grenzen[$j] + $grenzen[$j + 1], 2);
        $summe = 0.0; $min = INF; $max = -INF; $cnt = 0;
        foreach ($g as [$ts, $v]) {
            $summe += $v;
            $a = $agg[$ts] ?? null; // schon verdichteter Wert: dessen Min/Max/Anzahl zählen
            $min = min($min, $a ? (float)$a[0] : $v);
            $max = max($max, $a ? (float)$a[1] : $v);
            $cnt += $a ? (int)$a[2] : 1;
        }
        $neu[] = [$mitte, round($s['m'] === 'summe' ? $summe : $summe / count($g), 6)];
        $ag[] = [$mitte, $min, $max, $cnt];
    }
    return ['neu' => $neu, 'agg' => $ag];
}

// Kurvenform erhalten (Douglas-Peucker): Ein Wert bleibt nur, wenn die gerade Linie zwischen den Nachbarn ohne ihn
// um mehr als $toleranz vom echten Verlauf abweicht. So bleiben echte Wendepunkte (Spitzen, Täler, Knicke) erhalten,
// kleines Zittern und gleichmäßige Anstiege fallen weg. Erster, letzter, kleinster und größter Wert bleiben immer.
function wendepunkte(array $rows, float $toleranz): array
{
    $n = count($rows);
    if ($n <= 2) return array_column($rows, 0);
    $tol = max($toleranz, 1e-9);
    $keep = array_fill(0, $n, false);
    $imin = $imax = 0;
    for ($i = 1; $i < $n; $i++) {
        if ($rows[$i][1] < $rows[$imin][1]) $imin = $i;
        if ($rows[$i][1] > $rows[$imax][1]) $imax = $i;
    }
    $fest = array_values(array_unique([0, $imin, $imax, $n - 1]));
    sort($fest);
    foreach ($fest as $i) $keep[$i] = true;
    $stapel = [];
    for ($k = 1; $k < count($fest); $k++) $stapel[] = [$fest[$k - 1], $fest[$k]];
    while ($stapel) {
        [$a, $b] = array_pop($stapel);
        if ($b - $a < 2) continue;
        [$ta, $va] = $rows[$a];
        [$tb, $vb] = $rows[$b];
        $steigung = $tb > $ta ? ($vb - $va) / ($tb - $ta) : 0.0;
        $max = -1.0; $imx = -1;
        for ($i = $a + 1; $i < $b; $i++) { // senkrechter Abstand zur Linie (Werte und Zeit haben verschiedene Einheiten)
            $d = abs($rows[$i][1] - ($va + $steigung * ($rows[$i][0] - $ta)));
            if ($d > $max) { $max = $d; $imx = $i; }
        }
        if ($max > $tol) { $keep[$imx] = true; $stapel[] = [$a, $imx]; $stapel[] = [$imx, $b]; }
    }
    $out = [];
    foreach ($keep as $i => $k) if ($k) $out[] = $rows[$i][0];
    return $out;
}

// ---------- Lauf ----------

// Ende eines Abschnitts: bei Minuten-Rastern 7 Tage (passt immer aufs Raster), sonst ganze Raster für ~1 Monat
function abschnitt_ende(int $von, array $s, int $grenze): int
{
    $r = $s['r'] ?? '';
    if ($r === '' || (is_numeric($r) && (int)$r < 1440)) return min($von + 7 * 86400000, $grenze);
    $b = $von;
    do { $b = raster_naechst($b, $r); } while ($b < $von + 28 * 86400000 && $b < $grenze);
    return min($b, $grenze);
}

// Einen Abschnitt bearbeiten; liefert [vorher, nachher]
function abschnitt(string $dbk, array $r, array $s, int $von, int $bis, bool $probe = false): array
{
    $m = m($dbk);
    $t = tabelle($r);
    $rows = [];
    foreach (mq($m, "SELECT ts, val FROM `$t` WHERE id = ? AND ts >= ? AND ts < ? ORDER BY ts", [$r['id'], $von, $bis])->fetchAll(PDO::FETCH_NUM) as $z)
        if ($z[1] !== null) $rows[] = [(int)$z[0], (float)$z[1]];
    $vorher = count($rows);
    if ($s['m'] === 'loeschen') {
        if (!$probe) {
            $vorher = mq($m, "DELETE FROM `$t` WHERE id = ? AND ts >= ? AND ts < ?", [$r['id'], $von, $bis])->rowCount();
            mq($m, 'DELETE FROM sm_agg WHERE id = ? AND ts >= ? AND ts < ?', [$r['id'], $von, $bis]);
        }
        return [$vorher, 0];
    }
    if ($vorher === 0) return [0, 0];
    $agg = [];
    if (in_array($s['m'], ['mittel', 'summe'], true))
        foreach (mq($m, 'SELECT ts, v_min, v_max, cnt FROM sm_agg WHERE id = ? AND ts >= ? AND ts < ?', [$r['id'], $von, $bis])->fetchAll(PDO::FETCH_NUM) as $a)
            $agg[(int)$a[0]] = [$a[1], $a[2], $a[3]];
    $e = verdichte($rows, $s, $von, $bis, $agg);
    if ($probe) return [$vorher, count($e['behalten'] ?? $e['neu'])];

    $m->beginTransaction();
    try {
        if (isset($e['behalten'])) {
            $weg = array_values(array_diff(array_column($rows, 0), $e['behalten']));
            foreach (array_chunk($weg, 500) as $teil) {
                $ph = implode(',', array_fill(0, count($teil), '?'));
                mq($m, "DELETE FROM `$t` WHERE id = ? AND ts IN ($ph)", [$r['id'], ...$teil]);
                if ($agg || $t !== 'ts_bool') mq($m, "DELETE FROM sm_agg WHERE id = ? AND ts IN ($ph)", [$r['id'], ...$teil]);
            }
            $nachher = count($e['behalten']);
        } else {
            mq($m, "DELETE FROM `$t` WHERE id = ? AND ts >= ? AND ts < ?", [$r['id'], $von, $bis]);
            mq($m, 'DELETE FROM sm_agg WHERE id = ? AND ts >= ? AND ts < ?', [$r['id'], $von, $bis]);
            $extra = array_values(array_intersect(['ack', '_from', 'q'], spalten($dbk, $t)));
            $zusSp = $extra ? ', ' . implode(', ', $extra) : '';
            $zus = $extra ? ', ' . implode(', ', array_map(fn($x) => $x === 'ack' ? '1' : '0', $extra)) : '';
            foreach (array_chunk($e['neu'], 500) as $teil) {
                $ph = implode(',', array_fill(0, count($teil), "(?, ?, ?$zus)"));
                mq($m, "INSERT INTO `$t` (id, ts, val$zusSp) VALUES $ph", array_merge(...array_map(fn($z) => [$r['id'], $z[0], $z[1]], $teil)));
            }
            foreach (array_chunk($e['agg'], 500) as $teil) {
                $ph = implode(',', array_fill(0, count($teil), '(?, ?, ?, ?, ?)'));
                mq($m, "INSERT INTO sm_agg (id, ts, v_min, v_max, cnt) VALUES $ph", array_merge(...array_map(fn($z) => [$r['id'], ...$z], $teil)));
            }
            $nachher = count($e['neu']);
        }
        $m->commit();
    } catch (Throwable $ex) {
        $m->rollBack();
        throw $ex;
    }
    return [$vorher, $nachher];
}

// Alle Stufen einer Reihe abarbeiten, älteste Stufe zuerst. Stufe k bearbeitet nur Werte, die jünger sind als das,
// was die ältere Stufe k+1 schon erledigt hat. Liefert [vorher, nachher, fertig?]
function reihe_verdichten(string $dbk, array $r, float $deadline, bool $probe = false): array
{
    $stufen = stufen_sauber($r['stufen']);
    if (!$stufen) return [0, 0, true];
    $marke = substr(md5(json_encode($stufen)), 0, 12);
    $stand = [];
    foreach (all('SELECT stufe, bis, marke FROM stand WHERE db = ? AND id = ? AND stufe >= 0', [$dbk, $r['id']]) as $x)
        if ($x['marke'] === $marke) $stand[(int)$x['stufe']] = (int)$x['bis'];
    $jetzt = time() * 1000;
    $summe = [0, 0];
    $erster = null;
    for ($k = count($stufen) - 1; $k >= 0; $k--) {
        $s = $stufen[$k];
        $grenze = $jetzt - $s['ab'] * 86400000;
        if (isset($s['r'])) $grenze = raster_start($grenze, $s['r']);
        $von = max($stand[$k] ?? 0, $stand[$k + 1] ?? 0);
        if ($von === 0) {
            $erster ??= (int)mq(m($dbk), 'SELECT MIN(ts) FROM `' . tabelle($r) . '` WHERE id = ?', [$r['id']])->fetchColumn();
            if (!$erster) { $stand[$k] = $grenze; continue; }
            $von = isset($s['r']) ? raster_start($erster, $s['r']) : $erster;
        }
        $teil = [0, 0];
        while ($von < $grenze) {
            if (microtime(true) > $deadline) { stufe_melden($dbk, $r, $k, $s, $teil, $probe); return [$summe[0] + $teil[0], $summe[1] + $teil[1], false]; }
            $bis = abschnitt_ende($von, $s, $grenze);
            [$a, $b] = abschnitt($dbk, $r, $s, $von, $bis, $probe);
            $teil[0] += $a; $teil[1] += $b;
            $von = $bis;
            if (!$probe) q('INSERT OR REPLACE INTO stand (db, id, stufe, bis, marke) VALUES (?, ?, ?, ?, ?)', [$dbk, $r['id'], $k, $von, $marke]);
        }
        $stand[$k] = max($von, $grenze);
        if (!$probe) q('INSERT OR REPLACE INTO stand (db, id, stufe, bis, marke) VALUES (?, ?, ?, ?, ?)', [$dbk, $r['id'], $k, $stand[$k], $marke]);
        stufe_melden($dbk, $r, $k, $s, $teil, $probe);
        $summe[0] += $teil[0]; $summe[1] += $teil[1];
    }
    return [$summe[0], $summe[1], true];
}
function stufe_melden(string $dbk, array $r, int $k, array $s, array $teil, bool $probe): void
{
    if ($probe || $teil[0] === $teil[1]) return;
    protokoll('verdichtung', 'Stufe ' . ($k + 1) . ' (' . stufe_text($s) . '): ' . zahl($teil[0]) . ' → ' . zahl($teil[1]) . ' Werte', $dbk, (int)$r['id'], $teil[0], $teil[1]);
}

// ---------- Abgeleitete Reihen (z. B. Monatssumme einer anderen Reihe) ----------

function abgeleitete_zuruecksetzen(string $dbk, int $quelle, int $ab): void
{
    foreach (all('SELECT id, raster FROM reihen WHERE db = ? AND quelle = ?', [$dbk, $quelle]) as $a)
        q('UPDATE stand SET bis = MIN(bis, ?) WHERE db = ? AND id = ? AND stufe = -1', [raster_start($ab, (string)$a['raster']), $dbk, $a['id']]);
}

// Werte je Raster neu berechnen, vom gemerkten Stand bis jetzt (das laufende Raster wird jedes Mal überschrieben)
function abgeleitet_berechnen(string $dbk, array $a, array $quelle, float $deadline): int
{
    $m = m($dbk);
    $t = tabelle($quelle);
    $rs = (string)$a['raster'];
    $von = (int)val('SELECT bis FROM stand WHERE db = ? AND id = ? AND stufe = -1', [$dbk, $a['id']]);
    if (!$von) {
        $erster = (int)mq($m, "SELECT MIN(ts) FROM `$t` WHERE id = ?", [$quelle['id']])->fetchColumn();
        if (!$erster) return 0;
        $von = raster_start($erster, $rs);
    }
    $jetzt = time() * 1000;
    $n = 0;
    $agg = $m->prepare("SELECT COUNT(*) n, SUM(val) s, AVG(val) a, MIN(val) mi, MAX(val) ma FROM `$t` WHERE id = ? AND ts >= ? AND ts < ?");
    $letzt = $m->prepare("SELECT val FROM `$t` WHERE id = ? AND ts < ? ORDER BY ts DESC LIMIT 1");
    $schreib = $m->prepare('REPLACE INTO sm_ts (id, ts, val) VALUES (?, ?, ?)');
    $weg = $m->prepare('DELETE FROM sm_ts WHERE id = ? AND ts = ?');
    while ($von <= $jetzt) {
        if (microtime(true) > $deadline) break;
        $bis = raster_naechst($von, $rs);
        $agg->execute([$quelle['id'], $von, $bis]);
        $x = $agg->fetch();
        $wert = null;
        if ((int)$x['n'] > 0) {
            $wert = match ($a['funktion']) {
                'summe' => (float)$x['s'], 'mittel' => (float)$x['a'], 'min' => (float)$x['mi'], 'max' => (float)$x['ma'],
                default => null,
            };
            if (in_array($a['funktion'], ['letzter', 'differenz'], true)) {
                $letzt->execute([$quelle['id'], $bis]);
                $ende = (float)$letzt->fetchColumn();
                if ($a['funktion'] === 'letzter') $wert = $ende;
                else {
                    $letzt->execute([$quelle['id'], $von]);
                    $anfang = $letzt->fetchColumn();
                    if ($anfang === false) { // erstes Raster: erster Wert darin
                        $anfang = mq($m, "SELECT val FROM `$t` WHERE id = ? AND ts >= ? ORDER BY ts LIMIT 1", [$quelle['id'], $von])->fetchColumn();
                    }
                    $wert = $ende - (float)$anfang;
                }
            }
        }
        if ($wert === null) $weg->execute([$a['id'], $von]);
        else { $schreib->execute([$a['id'], $von, round($wert, 6)]); $n++; }
        if ($bis > $jetzt) break; // laufendes Raster: Stand bleibt hier, nächster Lauf rechnet es neu
        $von = $bis;
    }
    q('INSERT OR REPLACE INTO stand (db, id, stufe, bis, marke) VALUES (?, ?, -1, ?, ?)', [$dbk, $a['id'], $von, '']);
    return $n;
}

// ---------- Gesamter Lauf (Cron oder Knopf) ----------

function lauf(bool $probe = false, ?int $zeitlimit = null): array
{
    global $CFG;
    $start = microtime(true);
    $deadline = $start + ($zeitlimit ?? max(5, (int)($CFG['zeitlimit'] ?? 50)));
    $log = [];
    $vorher = $nachher = 0;
    $fertig = true;
    foreach (datenbanken() as $dbk => $dbname) {
        try {
            $liste = reihen_liste($dbk);
            if (!$probe) foreach ($liste as $a) {
                if ($a['quelle'] && isset($liste[$a['quelle']])) abgeleitet_berechnen($dbk, $a, $liste[$a['quelle']], $deadline);
            }
            $vorDb = $vorher;
            foreach ($liste as $r) {
                if (!$r['stufen']) continue;
                if (microtime(true) > $deadline) { $fertig = false; break; }
                try {
                    [$a, $b, $ok] = reihe_verdichten($dbk, $r, $deadline, $probe);
                } catch (Throwable $e) {
                    protokoll('fehler', 'Verdichtung: ' . $e->getMessage(), $dbk, $r['id'], null, null, true);
                    $log[] = "$dbname · {$r['titel']}: Fehler – " . $e->getMessage();
                    continue;
                }
                if ($a !== $b) $log[] = "$dbname · {$r['titel']}: " . zahl($a) . ' → ' . zahl($b) . ' Werte';
                $vorher += $a; $nachher += $b;
                if (!$ok) { $fertig = false; break; }
            }
            // Zahlen der Übersicht: nach Änderungen sofort, sonst stündlich
            if (!$probe && ($vorher !== $vorDb || (int)einst("statistik_$dbk") < time() - 3600)) statistik_aktualisieren($dbk);
        } catch (Throwable $e) {
            protokoll('fehler', "Datenbank $dbname: " . $e->getMessage(), $dbk, null, null, null, true);
            $log[] = "$dbname: Fehler – " . $e->getMessage();
        }
    }
    if (!$probe) {
        $weg = papierkorb_leeren();
        if ($weg) $log[] = "Papierkorb: $weg alte Einträge endgültig gelöscht";
        q("DELETE FROM protokoll WHERE am < datetime('now', ?)", ['-' . PROTOKOLL_TAGE . ' days']);
    }
    $dauer = round(microtime(true) - $start, 1);
    $text = ($probe ? 'Vorschau: ' : '') . zahl($vorher) . ' → ' . zahl($nachher) . ' Werte, ' . zahl($dauer, 1) . ' s'
        . ($fertig ? '' : ' (Zeitlimit erreicht, der nächste Lauf macht weiter)');
    if (!$probe) protokoll('lauf', $text, '', null, $vorher, $nachher);
    return ['text' => $text, 'log' => $log, 'fertig' => $fertig];
}

// Vorschau einer Stufe an einem Ausschnitt: Original und Ergebnis, dazu wie viele Werte jetzt betroffen wären
function vorschau(string $dbk, array $r, array $s, int $von, int $bis): array
{
    $m = m($dbk);
    $t = tabelle($r);
    $rows = [];
    foreach (mq($m, "SELECT ts, val FROM `$t` WHERE id = ? AND ts >= ? AND ts < ? ORDER BY ts LIMIT 100000", [$r['id'], $von, $bis])->fetchAll(PDO::FETCH_NUM) as $z)
        if ($z[1] !== null) $rows[] = [(int)$z[0], (float)$z[1]];
    $e = verdichte($rows, $s, $von, $bis);
    if (isset($e['behalten'])) {
        $b = array_flip($e['behalten']);
        $neu = array_values(array_filter($rows, fn($z) => isset($b[$z[0]])));
    } else $neu = $e['neu'];
    $grenze = time() * 1000 - $s['ab'] * 86400000;
    $betroffen = (int)mq($m, "SELECT COUNT(*) FROM `$t` WHERE id = ? AND ts < ?", [$r['id'], $grenze])->fetchColumn();
    return [
        'original' => [array_map(fn($z) => $z[0] / 1000, $rows), array_column($rows, 1)],
        'ergebnis' => [array_map(fn($z) => $z[0] / 1000, $neu), array_column($neu, 1)],
        'vorher' => count($rows), 'nachher' => count($neu), 'betroffen' => $betroffen,
    ];
}
