<?php
// Testdaten (nur lokal): Schema des ioBroker-SQL-Adapters, ~40 künstliche Reihen über 2 Jahre, Benutzer admin/leser.
// Aufruf: ..\_devtools\php\php.exe -d extension=pdo_sqlite tests\seed_demo.php
// Löscht vorher alle Tabellen der Testdatenbanken und data/sqlman.sqlite.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('Nur auf der Kommandozeile.');
@unlink(__DIR__ . '/../data/sqlman.sqlite');
require __DIR__ . '/../lib.php';
require __DIR__ . '/../messdaten.php';
mt_srand(42);
ini_set('memory_limit', '1G');

$TAGE = 730;
$jetzt = intdiv(time(), 60) * 60;
$start = $jetzt - $TAGE * 86400;

function adapter_schema(PDO $m): void
{
    foreach ($m->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $m->exec("DROP TABLE `$t`");
    $m->exec('CREATE TABLE datapoints (id INTEGER NOT NULL PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255), type INTEGER)');
    $m->exec('CREATE TABLE sources (id INTEGER NOT NULL PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255))');
    $m->exec('CREATE TABLE ts_number (id INTEGER, ts BIGINT, val REAL, ack BOOLEAN, _from INTEGER, q INTEGER, PRIMARY KEY (id, ts))');
    $m->exec('CREATE TABLE ts_bool (id INTEGER, ts BIGINT, val BOOLEAN, ack BOOLEAN, _from INTEGER, q INTEGER, PRIMARY KEY (id, ts))');
    $m->exec('CREATE TABLE ts_string (id INTEGER, ts BIGINT, val TEXT, ack BOOLEAN, _from INTEGER, q INTEGER, PRIMARY KEY (id, ts))');
    $m->exec('CREATE TABLE ts_counter (id INTEGER, ts BIGINT, val REAL)');
    $m->exec("INSERT INTO sources (name) VALUES ('system.adapter.hm-rpc.0')");
}

// Werte schreiben: $werte = Liste [ts in Sekunden, Wert]
function schreib(PDO $m, string $tab, int $id, iterable $werte): int
{
    $n = 0;
    $puffer = [];
    $flush = function () use ($m, $tab, &$puffer) {
        if (!$puffer) return;
        $m->exec("INSERT IGNORE INTO $tab (id, ts, val, ack, _from, q) VALUES " . implode(',', $puffer));
        $puffer = [];
    };
    $m->beginTransaction();
    foreach ($werte as [$ts, $v]) {
        $puffer[] = '(' . $id . ',' . ($ts * 1000 + mt_rand(0, 999)) . ',' . (is_bool($v) ? (int)$v : round($v, 3)) . ',1,1,0)';
        $n++;
        if (count($puffer) >= 5000) $flush();
    }
    $flush();
    $m->commit();
    return $n;
}
function dp(PDO $m, string $name, int $typ = 0): int
{
    mq($m, 'INSERT INTO datapoints (name, type) VALUES (?, ?)', [$name, $typ]);
    return (int)$m->lastInsertId();
}
function rauschen(float $a): float { return (mt_rand() / mt_getrandmax() * 2 - 1) * $a; }
// Außentemperatur zum Zeitpunkt (Jahresgang + Tagesgang + langsame Wetterlage)
function aussen(int $ts): float
{
    $tag = $ts / 86400;
    $jahr = -cos(2 * M_PI * (($tag - 15) / 365.25));
    $stunde = (int)date('G', $ts) + (int)date('i', $ts) / 60;
    return 9.5 + 10 * $jahr + 4.5 * sin(2 * M_PI * ($stunde - 9) / 24) + 3 * sin($tag / 3.1) + 1.5 * sin($tag / 1.3);
}
// Zeitpunkte im Abstand $schritt Sekunden mit etwas Streuung
function takte(int $von, int $bis, int $schritt): Generator
{
    for ($t = $von; $t < $bis; $t += $schritt + mt_rand(-intdiv($schritt, 10), intdiv($schritt, 10))) yield $t;
}

adapter_schema(mdb('haus'));
$m = m('haus');   // legt die eigenen Tabellen (sm_*) an
$meta = [];
$t0 = microtime(true);

// Räume: alle 10 Minuten, Zieltemperatur mit Nachtabsenkung
$raeume = ['Wohnzimmer' => 21.5, 'Küche' => 21, 'Bad' => 23, 'Schlafzimmer' => 18.5, 'Kind 1' => 20.5, 'Kind 2' => 20.5,
    'Büro' => 21, 'Flur' => 19, 'Keller' => 15, 'Gästezimmer' => 18];
$i = 0;
foreach ($raeume as $raum => $soll) {
    $i++;
    $id = dp($m, sprintf('hm-rpc.0.MEQ00%04d.1.ACTUAL_TEMPERATURE', 1200 + $i));
    $meta[$id] = [$raum, '°C', 'Räume', 'Raumtemperatur'];
    $gen = (function () use ($start, $jetzt, $soll, $raum) {
        $v = $soll;
        foreach (takte($start, $jetzt, 600) as $t) {
            if ($raum === 'Keller' && $t > $jetzt - 3 * 86400) return;          // Sensor ausgefallen
            if ($raum === 'Bad' && $t > $jetzt - 200 * 86400 && $t < $jetzt - 195 * 86400) continue; // Lücke
            $h = (int)date('G', $t);
            $ziel = $soll - ($h < 6 || $h >= 22 ? 1.5 : 0) + max(0, aussen($t) - 20) * 0.6;
            $v += ($ziel - $v) * 0.08 + rauschen(0.08);
            $w = $v;
            if ($raum === 'Wohnzimmer' && mt_rand(0, 9000) === 0) $w = mt_rand(0, 1) ? 85.0 : -40.0; // Fehlmessung
            yield [$t, $w];
        }
    })();
    schreib($m, 'ts_number', $id, $gen);
}
// Luftfeuchte: alle 15 Minuten
foreach (['Wohnzimmer' => 45, 'Bad' => 60, 'Schlafzimmer' => 50, 'Keller' => 65] as $raum => $basis) {
    $i++;
    $id = dp($m, sprintf('hm-rpc.0.MEQ00%04d.1.HUMIDITY', 1200 + $i));
    $meta[$id] = ["Feuchte $raum", '%', 'Feuchte', 'Raumtemperatur'];
    schreib($m, 'ts_number', $id, (function () use ($start, $jetzt, $basis) {
        foreach (takte($start, $jetzt, 900) as $t) yield [$t, max(20, min(95, $basis + 8 * sin($t / 86400 / 58) + rauschen(4)))];
    })());
}
// Außentemperatur: alle 5 Minuten
$id = dp($m, 'hm-rpc.0.MEQ0001300.1.ACTUAL_TEMPERATURE');
$meta[$id] = ['Außentemperatur', '°C', 'Wetter', 'Raumtemperatur'];
schreib($m, 'ts_number', $id, (function () use ($start, $jetzt) { foreach (takte($start, $jetzt, 300) as $t) yield [$t, aussen($t) + rauschen(0.2)]; })());

// Heizung: alle 2 Minuten, Brenner taktet je nach Außentemperatur
$ids = [];
foreach (['vl' => ['Vorlauf HK1', 'modbus.0.holdingRegisters.40001_Vorlauf_HK1'], 'rl' => ['Rücklauf HK1', 'modbus.0.holdingRegisters.40002_Ruecklauf_HK1'],
    'vl2' => ['Vorlauf HK2 (Fußboden)', 'modbus.0.holdingRegisters.40003_Vorlauf_HK2'], 'ww' => ['Warmwasser', 'modbus.0.holdingRegisters.40010_Warmwasser']] as $k => [$anz, $name]) {
    $ids[$k] = dp($m, $name);
    $meta[$ids[$k]] = [$anz, '°C', 'Heizung', 'Heizkreis (Vor-/Rücklauf)'];
}
$idBrenner = dp($m, 'modbus.0.coils.10001_Brenner', 2);
$meta[$idBrenner] = ['Brenner', '', 'Heizung', 'Schalter (Ein/Aus)'];
$idPumpe = dp($m, 'modbus.0.coils.10002_Umwaelzpumpe', 2);
$meta[$idPumpe] = ['Umwälzpumpe', '', 'Heizung', 'Schalter (Ein/Aus)'];
$idGas = dp($m, 'mqtt.0.gaszaehler.impuls');
$meta[$idGas] = ['Gas (Impulse)', 'm³', 'Verbrauch', 'Verbrauch (Einzelwerte, z. B. Wh)'];
$h = ['vl' => [], 'rl' => [], 'vl2' => [], 'ww' => [], 'br' => [], 'pu' => [], 'gas' => []];
$vl = 30; $ww = 50; $an = false; $pumpe = false;
foreach (takte($start, $jetzt, 120) as $t) {
    $ta = aussen($t);
    $bedarf = $ta < 15;
    $soll = 30 + (15 - $ta) * 1.6;
    $wwBedarf = $ww < 46;
    if ($an && ($vl > ($bedarf ? $soll + 6 : 62) || (!$bedarf && !$wwBedarf && $vl > 55))) $an = false;
    elseif (!$an && (($bedarf && $vl < $soll - 4) || $wwBedarf)) $an = true;
    $vl += $an ? 1.6 + rauschen(0.3) : -($vl - 24) * 0.035 + rauschen(0.1);
    if ($an && $wwBedarf) $ww += 0.6;
    $ww -= 0.012 + ((int)date('G', $t) === 7 || (int)date('G', $t) === 19 ? 0.25 : 0);
    $p = $bedarf || $an;
    $v = $vl;
    if ($t > $jetzt - 40 * 86400 && $t < $jetzt - 40 * 86400 + 3600) $v = 0; // Sensorfehler: eine Stunde 0
    $h['vl'][] = [$t, $v];
    $h['rl'][] = [$t, $vl - 4 - max(0, (15 - $ta) * 0.3) + rauschen(0.2)];
    $h['vl2'][] = [$t, $bedarf ? 26 + (15 - $ta) * 0.5 + rauschen(0.3) : 22 + rauschen(0.3)];
    $h['ww'][] = [$t, $ww];
    if ($an !== (end($h['br'])[1] ?? null)) $h['br'][] = [$t, $an];
    if ($p !== (end($h['pu'])[1] ?? null)) $h['pu'][] = [$t, $p];
    if ($an && mt_rand(0, 2) === 0) $h['gas'][] = [$t, 0.01];
    if (count($h['vl']) >= 50000) {
        foreach (['vl', 'rl', 'vl2', 'ww'] as $k) { schreib($m, 'ts_number', $ids[$k], $h[$k]); $h[$k] = []; }
    }
}
foreach (['vl', 'rl', 'vl2', 'ww'] as $k) schreib($m, 'ts_number', $ids[$k], $h[$k]);
schreib($m, 'ts_bool', $idBrenner, $h['br']);
schreib($m, 'ts_bool', $idPumpe, $h['pu']);
schreib($m, 'ts_number', $idGas, $h['gas']);
unset($h);

// Strom: Einzelimpulse (Wh) und Zählerstand (kWh, alle 15 Minuten)
$idWh = dp($m, 'mqtt.0.stromzaehler.wh_impuls');
$meta[$idWh] = ['Strom (Wh-Impulse)', 'Wh', 'Verbrauch', 'Verbrauch (Einzelwerte, z. B. Wh)'];
$idZ = dp($m, 'mqtt.0.stromzaehler.zaehlerstand');
$meta[$idZ] = ['Stromzähler', 'kWh', 'Verbrauch', 'Zählerstand'];
$idW = dp($m, 'mqtt.0.wasserzaehler.zaehlerstand');
$meta[$idW] = ['Wasserzähler', 'm³', 'Verbrauch', 'Zählerstand'];
$idPv = dp($m, 'modbus.1.inputRegisters.30775_PV_Leistung');
$meta[$idPv] = ['PV-Leistung', 'W', 'Strom', null];
$wh = []; $zs = []; $wz = []; $pv = [];
$stand = 41250.0; $wasser = 812.0; $naechst = $start;
foreach (takte($start, $jetzt, 60) as $t) {
    $std = (int)date('G', $t);
    $last = 250 + ($std >= 7 && $std <= 22 ? 350 : 0) + (mt_rand(0, 30) === 0 ? 2000 : 0);   // Watt
    $e = $last / 60;                                                                         // Wh in dieser Minute
    $stand += $e / 1000;
    if ($e >= 1) $wh[] = [$t, round($e)];
    if ($t >= $naechst) {
        $zs[] = [$t, $stand];
        $wasser += $std >= 6 && $std <= 22 ? 0.006 + rauschen(0.004) : 0;
        $wz[] = [$t, $wasser];
        $tag = $t / 86400;
        $sonne = max(0, sin(M_PI * ($std + (int)date('i', $t) / 60 - 6) / 14)) * (0.55 - 0.45 * cos(2 * M_PI * ($tag - 15) / 365.25));
        $pv[] = [$t, max(0, 8000 * $sonne * (0.6 + 0.4 * sin($tag * 1.7)) + rauschen(50))];
        $naechst = $t + 900;
    }
    if (count($wh) >= 50000) { schreib($m, 'ts_number', $idWh, $wh); $wh = []; }
}
schreib($m, 'ts_number', $idWh, $wh);
schreib($m, 'ts_number', $idZ, $zs);
schreib($m, 'ts_number', $idW, $wz);
schreib($m, 'ts_number', $idPv, $pv);

// Wallbox: an etwa jedem dritten Abend 2-3 Stunden Laden mit 11 kW (ein Wert je Minute in Wh)
$idWb = dp($m, 'mqtt.0.wallbox.wh_impuls');
$meta[$idWb] = ['Wallbox (Wh-Impulse)', 'Wh', 'Verbrauch', 'Verbrauch (Einzelwerte, z. B. Wh)'];
$wb = [];
for ($tag = $start - $start % 86400; $tag < $jetzt; $tag += 86400) {
    if (mt_rand(0, 2)) continue;
    $beginn = $tag + 17 * 3600 + mt_rand(0, 3 * 3600);
    for ($t = $beginn; $t < $beginn + mt_rand(120, 180) * 60 && $t < $jetzt; $t += 60) $wb[] = [$t, 183];
}
schreib($m, 'ts_number', $idWb, $wb);
unset($wb);

// Fenster (Ja/Nein): ein paar Mal am Tag auf und zu
foreach (['Wohnzimmer', 'Küche', 'Bad', 'Schlafzimmer'] as $raum) {
    $i++;
    $id = dp($m, sprintf('hm-rpc.0.MEQ00%04d.1.STATE', 1200 + $i), 2);
    $meta[$id] = ["Fenster $raum", '', 'Fenster', 'Schalter (Ein/Aus)'];
    $w = [];
    for ($t = $start; $t < $jetzt; $t += mt_rand(3600, 12 * 3600)) { $w[] = [$t, true]; $w[] = [$t + mt_rand(120, 1800), false]; }
    schreib($m, 'ts_bool', $id, $w);
}
// Ein paar Reihen ohne Zusatzangaben (wie frisch vom Adapter)
foreach (['zigbee.0.00158d0001a2b3c4.temperature' => 300, 'zigbee.0.00158d0001a2b3c4.humidity' => 300, 'system.adapter.admin.0.memRss' => 900] as $name => $schritt) {
    $id = dp($m, $name);
    schreib($m, 'ts_number', $id, (function () use ($start, $jetzt, $schritt) { $v = 20; foreach (takte($jetzt - 120 * 86400, $jetzt, $schritt) as $t) { $v += rauschen(0.3); yield [$t, $v]; } })());
}
echo 'Haus: ', (int)$m->query('SELECT COUNT(*) FROM ts_number')->fetchColumn() + (int)$m->query('SELECT COUNT(*) FROM ts_bool')->fetchColumn(),
    ' Werte in ', round(microtime(true) - $t0), " s\n";

// Zweite Datenbank: klein, 60 Tage
adapter_schema(mdb('garten'));
$g = m('garten');
$a = dp($g, 'hm-rpc.1.GEWAECHSHAUS.1.ACTUAL_TEMPERATURE');
schreib($g, 'ts_number', $a, (function () use ($jetzt) { foreach (takte($jetzt - 60 * 86400, $jetzt, 600) as $t) yield [$t, aussen($t) + 6 + rauschen(0.5)]; })());
$b = dp($g, 'hm-rpc.1.BODEN.1.HUMIDITY');
schreib($g, 'ts_number', $b, (function () use ($jetzt) { foreach (takte($jetzt - 60 * 86400, $jetzt, 1800) as $t) yield [$t, 40 + 15 * sin($t / 86400) + rauschen(2)]; })());
$c = dp($g, 'hm-rpc.1.PUMPE.1.STATE', 2);
$w = [];
for ($t = $jetzt - 60 * 86400; $t < $jetzt; $t += 86400) { $w[] = [$t + 6 * 3600, true]; $w[] = [$t + 6 * 3600 + 900, false]; }
schreib($g, 'ts_bool', $c, $w);
q('INSERT INTO reihen (db, id, name, anzeige, einheit, gruppe) VALUES (?, ?, ?, ?, ?, ?)', ['garten', $a, '', 'Gewächshaus', '°C', 'Garten']);

// App: Benutzer, Zusatzangaben, ein Report
q("INSERT INTO users (name, fullname, pw, pwneu, admin) VALUES ('admin', 'Admin (Test)', ?, 0, 1)", [password_hash('testtest1', PASSWORD_DEFAULT)]);
q("INSERT INTO users (name, fullname, pw, pwneu, admin) VALUES ('leser', 'Leser (Test)', ?, 0, 0)", [password_hash('lesen1234', PASSWORD_DEFAULT)]);
$vorlagen = array_column(all('SELECT id, name FROM vorlagen'), 'id', 'name');
$namen = array_column($m->query('SELECT id, name FROM datapoints')->fetchAll(), 'name', 'id');
foreach ($meta as $id => [$anz, $einheit, $gruppe, $vorlage]) {
    q('INSERT INTO reihen (db, id, name, anzeige, einheit, gruppe, vorlage) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ['haus', $id, $namen[$id], $anz, $einheit, $gruppe, $vorlage ? $vorlagen[$vorlage] : null]);
}
q("UPDATE reihen SET warn_std = 6 WHERE db = 'haus' AND gruppe = 'Räume'");
// Farben je Reihe
$farben = ['Vorlauf HK1' => '#e03131', 'Rücklauf HK1' => '#1c7ed6', 'Vorlauf HK2 (Fußboden)' => '#f08c00', 'Warmwasser' => '#c2255c',
    'Außentemperatur' => '#2f9e44', 'Brenner' => '#fd7e14', 'Strom (Wh-Impulse)' => '#1971c2', 'Wallbox (Wh-Impulse)' => '#7048e8', 'PV-Leistung' => '#fab005'];
foreach ($farben as $n => $f) q("UPDATE reihen SET farbe = ? WHERE db = 'haus' AND anzeige = ?", [$f, $n]);
$idAussen = array_search('Außentemperatur', array_map(fn($x) => $x[0], $meta));
$idWohn = array_search('Wohnzimmer', array_map(fn($x) => $x[0], $meta));
$r = fn($id, $achse = 'l', $art = 'linie') => ['db' => 'haus', 'id' => $id, 'achse' => $achse, 'art' => $art];
$teil = function (int $rep, int $pos, string $titel, array $reihen, array $opt = [], string $art = 'diagramm', int $hoehe = 260) {
    q('INSERT INTO diagramme (report, pos, titel, reihen, optionen, art, hoehe) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$rep, $pos, $titel, json_encode($reihen), json_encode((object)$opt), $art, $hoehe]);
};
$skala = fn($von, $bis, $g1, $g2, $f1 = '#3b82f6', $f2 = '#22c55e', $f3 = '#ef4444') => compact('von', 'bis', 'g1', 'g2', 'f1', 'f2', 'f3');
q("INSERT INTO reports (name, tage, pos) VALUES ('Heizung', 7, 1)");
$rep = (int)db()->lastInsertId();
$teil($rep, 1, 'Wohnzimmer', [$r($idWohn)], $skala(14, 28, 19, 23), 'anzeige');
$teil($rep, 2, 'Außen', [$r($idAussen)], $skala(-15, 35, 0, 25, '#60a5fa', '#22c55e', '#f97316'), 'anzeige');
$teil($rep, 3, 'Vorlauf HK1', [$r($ids['vl'])], $skala(20, 80, 30, 65), 'anzeige');
$teil($rep, 4, 'Warmwasser', [$r($ids['ww'])], $skala(30, 70, 42, 60, '#ef4444', '#22c55e', '#f97316'), 'anzeige');
$teil($rep, 5, 'Vorlauf und Rücklauf', [$r($ids['vl']), $r($ids['rl']), $r($ids['vl2']), $r($idAussen, 'r')]);
$teil($rep, 6, 'Brenner (Anteil Ein je Stunde) und Warmwasser', [$r($ids['ww']), $r($idBrenner, 'r', 'flaeche') + ['fkt' => 'mittel']], ['raster' => '60']);
$teil($rep, 7, 'Gas je Tag', [$r($idGas, 'l', 'balken'), $r($idAussen, 'r')], ['raster' => '1440']);
q("INSERT INTO reports (name, tage, pos) VALUES ('Strom', 30, 2)");
$rep = (int)db()->lastInsertId();
$teil($rep, 1, 'Verbrauch je Tag (gestapelt)', [$r($idWh, 'l', 'balken'), $r($idWb, 'l', 'balken')], ['raster' => '1440', 'stapeln' => 1], 'diagramm', 300);
$teil($rep, 2, 'PV-Leistung', [$r($idPv, 'l', 'flaeche')]);
$teil($rep, 3, 'Stromzähler', [$r($idZ)]);
statistik_aktualisieren('haus');
statistik_aktualisieren('garten');
echo "Fertig in ", round(microtime(true) - $t0), " s. Logins: admin / testtest1, leser / lesen1234\n";
