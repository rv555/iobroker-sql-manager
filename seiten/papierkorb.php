<?php
// Papierkorb (nur Admin): manuell gelöschte Bereiche wiederherstellen oder endgültig löschen
$titel = 'Papierkorb';
$l = all("SELECT * FROM papierkorb WHERE status = 'da' ORDER BY id DESC");
$namen = [];
foreach (array_unique(array_column($l, 'db')) as $k) {
    if (!isset(datenbanken()[$k])) continue;
    try { foreach (reihen_liste($k) as $r) $namen[$k][$r['id']] = $r['titel']; } catch (Throwable $e) {}
}
?>
<h1>Papierkorb</h1>
<p class="leise">Manuell gelöschte Messwerte bleiben <?= PAPIERKORB_TAGE ?> Tage hier und werden danach beim Cron-Lauf endgültig gelöscht. Werte aus der Verdichtung kommen nicht in den Papierkorb.</p>
<table class="liste">
<tr><th>Gelöscht am</th><th>Reihe</th><th>Zeitraum</th><th class="rechts">Werte</th><th>Grund</th><th>Wer</th><th></th></tr>
<?php foreach ($l as $v): ?>
<tr>
  <td class="nowrap"><?= utc_zeit($v['am']) ?></td>
  <td><a href="?s=reihe&amp;db=<?= h($v['db']) ?>&amp;id=<?= (int)$v['reihe'] ?>"><?= h($namen[$v['db']][$v['reihe']] ?? '#' . $v['reihe']) ?></a>
    <?= count(datenbanken()) > 1 ? '<small class="leise">' . h(datenbanken()[$v['db']] ?? $v['db']) . '</small>' : '' ?></td>
  <td class="nowrap"><?= zeit((int)$v['von'], true) ?> –<br><?= zeit((int)$v['bis'], true) ?></td>
  <td class="rechts"><?= zahl($v['anzahl']) ?></td>
  <td><?= h($v['grund']) ?></td>
  <td><?= h($v['wer']) ?></td>
  <td class="rechts nowrap"><form method="post" class="inline"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= $v['id'] ?>">
    <button class="knopf klein" name="do" value="pk_zurueck">Wiederherstellen</button>
    <button class="knopf klein gefahr" name="do" value="pk_weg" data-confirm="Diese <?= (int)$v['anzahl'] ?> Werte endgültig löschen? Das lässt sich nicht rückgängig machen.">Endgültig löschen</button></form></td>
</tr>
<?php endforeach ?>
<?php if (!$l): ?><tr><td colspan="7" class="leise">Der Papierkorb ist leer.</td></tr><?php endif ?>
</table>
