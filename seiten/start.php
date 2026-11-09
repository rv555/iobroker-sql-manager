<?php
// Übersicht: alle Reihen der gewählten Datenbank nach Gruppen, mit letztem Wert, Zahlen, Verdichtung und Mini-Diagramm
$titel = 'Übersicht';
$dbs = datenbanken();
if (!$dbs) { echo '<p>In der <code>config.php</code> ist noch keine Datenbank eingetragen.</p>'; return; }
$liste = reihen_liste($dbk);
$letzt = letzte_werte($dbk);
$stat = statistik($dbk);
$standZeit = (int)einst("statistik_$dbk");
$gruppen = [];
foreach ($liste as $r) $gruppen[$r['gruppe']][] = $r;
uksort($gruppen, fn($a, $b) => ($a === '') <=> ($b === '') ?: strnatcasecmp($a, $b));
foreach ($gruppen as &$g) usort($g, fn($a, $b) => strnatcasecmp($a['titel'], $b['titel']));
unset($g);
$summe = ['n' => 0, 'b' => 0];
foreach ($stat as $x) { $summe['n'] += $x['anzahl']; $summe['b'] += $x['bytes']; }
$jetzt = time() * 1000;
?>
<div class="titelzeile">
  <h1>Übersicht</h1>
  <?php if (count($dbs) > 1): ?>
  <nav class="reiter"><?php foreach ($dbs as $k => $n): ?><a href="?s=start&amp;db=<?= $k ?>"<?= $k === $dbk ? ' class="aktiv"' : '' ?>><?= h($n) ?></a><?php endforeach ?></nav>
  <?php endif ?>
  <span class="platz"></span>
  <input type="search" id="suche" placeholder="Suchen …" class="suche" aria-label="Reihen suchen">
  <label class="inline leise">Verlauf
    <select id="sparkzeit" aria-label="Zeitraum der Mini-Diagramme">
      <option value="1">1 Stunde</option><option value="6" selected>6 Stunden</option><option value="24">24 Stunden</option>
      <option value="72">3 Tage</option><option value="168">7 Tage</option>
    </select></label>
</div>
<div class="leise zeile eng">
  <?= count($liste) ?> Reihen · <?= zahl($summe['n']) ?> Werte · <?= mb($summe['b']) ?> MB
  · Zahlen vom <?= $standZeit ? date('d.m.Y H:i', $standZeit) : '–' ?>
  <?php if ($u['admin']): ?>
  <form method="post" class="inline"><?= csrf_feld() ?><button class="knopf klein" name="do" value="statistik" title="Werte je Reihe neu zählen (kann bei großen Tabellen etwas dauern)">Zahlen aktualisieren</button></form>
  <?php endif ?>
</div>
<?php if (!$liste): ?>
  <p class="karte">In dieser Datenbank gibt es noch keine Reihen (Tabelle <code>datapoints</code> des ioBroker-SQL-Adapters fehlt oder ist leer).</p>
<?php return; endif ?>
<div class="tabrahmen">
<table class="liste reihen" id="reihen" data-db="<?= h($dbk) ?>" data-admin="<?= (int)$u['admin'] ?>">
<thead><tr>
  <th>Reihe</th><th class="rechts">Letzter Wert</th><th class="rechts" title="Anzahl Werte gesamt">Werte</th><th class="rechts" title="Werte der letzten 30 Tage">30 Tage</th>
  <th class="rechts" title="Speicherbedarf (geschätzt)">MB</th><th class="c-verd">Verdichtung</th><th>Verlauf</th><?php if ($u['admin']): ?><th></th><?php endif ?>
</tr></thead>
<?php foreach ($gruppen as $gname => $g): ?>
<tbody>
<tr class="gruppe"><th colspan="<?= $u['admin'] ? 8 : 7 ?>"><?= h($gname !== '' ? $gname : 'Ohne Gruppe') ?> <span class="leise">(<?= count($g) ?>)</span></th></tr>
<?php foreach ($g as $r):
    [$lts, $lv] = $letzt[$r['id']] ?? [null, null];
    $st = $stat[$r['id']] ?? null;
    $alt = $lts && $r['warn_std'] && $jetzt - $lts > $r['warn_std'] * 3600000; ?>
<tr data-id="<?= $r['id'] ?>" data-typ="<?= $r['typ'] ?>" data-farbe="<?= h($r['farbe'] ?? '') ?>" data-letzt="<?= $lv === null ? '' : h($lv) ?>" data-suche="<?= h(mb_strtolower($r['titel'] . ' ' . $r['name'] . ' ' . $r['gruppe'])) ?>">
  <td class="c-name"><a href="?s=reihe&amp;db=<?= h($dbk) ?>&amp;id=<?= $r['id'] ?>"><?= h($r['titel']) ?></a>
    <small class="leise technisch"><?= h($r['quelle'] ? 'eigene Reihe: ' . (FUNKTIONEN[$r['funktion']] ?? '') . ' je ' . (ABL_RASTER[$r['raster']] ?? '') : $r['name']) ?></small></td>
  <td class="rechts c-wert<?= $alt ? ' alt' : '' ?>"><b><?= h(wert_text($lv, $r)) ?></b>
    <small class="<?= $alt ? '' : 'leise' ?>"><?= $alt ? '⚠ ' : '' ?><?= zeit($lts) ?></small></td>
  <td class="rechts"><?= $st ? zahl($st['anzahl']) : '–' ?></td>
  <td class="rechts"><?= $st ? zahl($st['monat']) : '–' ?></td>
  <td class="rechts"><?= $st ? mb((int)$st['bytes']) : '–' ?></td>
  <td class="c-verd"><?= $r['vorlage_name'] !== '' ? '<span class="marke-vorlage">' . h($r['vorlage_name']) . '</span>' : '' ?>
    <small class="leise"><?= h(stufen_text($r['stufen'])) ?></small></td>
  <td class="c-spark"><span class="spark" data-spark="<?= $r['id'] ?>"></span></td>
  <?php if ($u['admin']): ?><td><button type="button" class="knopf klein" data-edit="<?= $r['id'] ?>" title="Bearbeiten: Name, Einheit, Gruppe, Verdichtung">✎</button></td><?php endif ?>
</tr>
<?php endforeach ?>
</tbody>
<?php endforeach ?>
</table>
</div>
