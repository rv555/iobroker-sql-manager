<?php
// Protokoll: Läufe, Verdichtungen, Löschungen, Sicherungen, Fehler
$titel = 'Protokoll';
const ARTEN = ['lauf' => 'Lauf', 'verdichtung' => 'Verdichtung', 'loeschen' => 'Löschen', 'wiederherstellen' => 'Wiederhergestellt',
    'abgeleitet' => 'Eigene Reihe', 'reihe_loeschen' => 'Reihe gelöscht', 'backup' => 'Sicherung', 'fehler' => 'Fehler'];
$art = isset(ARTEN[$_GET['art'] ?? '']) ? $_GET['art'] : '';
$nr = max(1, (int)($_GET['n'] ?? 1));
$w = $art !== '' ? 'WHERE art = ?' : '';
$p = $art !== '' ? [$art] : [];
$gesamt = (int)val("SELECT COUNT(*) FROM protokoll $w", $p);
$zeilen = all("SELECT * FROM protokoll $w ORDER BY id DESC LIMIT 100 OFFSET " . (($nr - 1) * 100), $p);
// Reihennamen je Datenbank (nur die nötigen Datenbanken abfragen)
$namen = [];
foreach (array_unique(array_filter(array_column($zeilen, 'db'))) as $k) {
    if (!isset(datenbanken()[$k])) continue;
    try { foreach (reihen_liste($k) as $r) $namen[$k][$r['id']] = $r['titel']; } catch (Throwable $e) {}
}
?>
<div class="titelzeile">
  <h1>Protokoll</h1>
  <nav class="reiter"><a href="?s=protokoll"<?= $art === '' ? ' class="aktiv"' : '' ?>>Alle</a>
    <?php foreach (ARTEN as $k => $n): ?><a href="?s=protokoll&amp;art=<?= $k ?>"<?= $art === $k ? ' class="aktiv"' : '' ?>><?= $n ?></a><?php endforeach ?></nav>
</div>
<table class="liste">
<tr><th>Zeit</th><th>Art</th><th>Datenbank · Reihe</th><th>Was</th><th>Wer</th></tr>
<?php foreach ($zeilen as $z): ?>
<tr class="<?= $z['fehler'] ? 'fehlerzeile' : '' ?>">
  <td class="nowrap"><?= utc_zeit($z['am']) ?></td>
  <td><?= h(ARTEN[$z['art']] ?? $z['art']) ?></td>
  <td><?= h(datenbanken()[$z['db']] ?? $z['db']) ?><?php if ($z['reihe']): ?> · <a href="?s=reihe&amp;db=<?= h($z['db']) ?>&amp;id=<?= (int)$z['reihe'] ?>"><?= h($namen[$z['db']][$z['reihe']] ?? '#' . $z['reihe']) ?></a><?php endif ?></td>
  <td><?= h($z['text']) ?></td>
  <td><?= h($z['wer']) ?></td>
</tr>
<?php endforeach ?>
<?php if (!$zeilen): ?><tr><td colspan="5" class="leise">Noch keine Einträge.</td></tr><?php endif ?>
</table>
<?php if ($gesamt > 100): ?>
<p class="zeile">Seite <?= $nr ?> von <?= (int)ceil($gesamt / 100) ?>
  <?php if ($nr > 1): ?><a class="knopf klein" href="?s=protokoll&amp;art=<?= $art ?>&amp;n=<?= $nr - 1 ?>">‹ neuer</a><?php endif ?>
  <?php if ($nr * 100 < $gesamt): ?><a class="knopf klein" href="?s=protokoll&amp;art=<?= $art ?>&amp;n=<?= $nr + 1 ?>">älter ›</a><?php endif ?></p>
<?php endif ?>
<p class="leise klein">Einträge älter als <?= PROTOKOLL_TAGE ?> Tage werden automatisch gelöscht.</p>
