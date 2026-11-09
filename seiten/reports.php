<?php
// Reports: Diagramme und Anzeigen zusammenstellen; dieselben Reports zeigt die Berichtsansicht (?s=berichte) ohne großes Menü
$titel = 'Reports';
$reports = all('SELECT * FROM reports ORDER BY pos, name');
$rid = (int)($_GET['r'] ?? ($reports[0]['id'] ?? 0));
$rep = $rid ? one('SELECT * FROM reports WHERE id = ?', [$rid]) : null;
?>
<div class="titelzeile">
  <h1>Reports</h1>
  <nav class="reiter"><?php foreach ($reports as $x): ?><a href="?s=reports&amp;r=<?= $x['id'] ?>"<?= $x['id'] === $rid ? ' class="aktiv"' : '' ?>><?= h($x['name']) ?></a><?php endforeach ?></nav>
  <span class="platz"></span>
  <a class="knopf klein" href="berichte.php<?= $rep ? '?r=' . $rid : '' ?>" title="Nur die Reports, mit kleinem Menü – gut als Lesezeichen oder auf dem Handy-Startbildschirm">Berichtsansicht ↗</a>
  <?php if ($u['admin']): ?>
  <details class="aufklapp"><summary class="knopf klein">+ Neuer Report</summary>
    <form method="post" class="karte"><?= csrf_feld() ?><input type="hidden" name="do" value="report_save">
      <label>Name<input name="name" required></label>
      <label>Zeitraum beim Öffnen (Tage)<input type="number" name="tage" value="7" min="1" max="3650"></label>
      <button class="knopf haupt">Anlegen</button></form></details>
  <?php endif ?>
</div>
<?php if (!$rep): ?>
  <p class="karte"><?= $reports ? 'Report nicht gefunden.' : 'Noch keine Reports.' . ($u['admin'] ? ' Oben rechts „+ Neuer Report“ anlegen und Diagramme zusammenstellen.' : '') ?></p>
<?php return; endif;
$alle = $u['admin'] ? alle_reihen() : [];
echo report_inhalt($rep, (bool)$u['admin'], $alle);
if ($u['admin']): ?>
<details class="karte nodruck"><summary><b>+ Diagramm hinzufügen</b></summary><?= diagramm_form($rid, null, $alle) ?></details>
<details class="karte nodruck"><summary><b>+ Anzeige hinzufügen</b> <span class="leise">(aktueller Wert als Rundinstrument mit Farbskala)</span></summary><?= anzeige_form($rid, null, $alle) ?></details>
<details class="karte nodruck"><summary>Report umbenennen / löschen</summary>
  <form method="post" class="zeile"><?= csrf_feld() ?><input type="hidden" name="do" value="report_save"><input type="hidden" name="id" value="<?= $rid ?>">
    <input name="name" value="<?= h($rep['name']) ?>" required>
    <label class="inline leise">Zeitraum beim Öffnen <input type="number" name="tage" value="<?= (int)$rep['tage'] ?>" min="1" max="3650" class="kurz"> Tage</label>
    <label class="inline leise">Reihenfolge <input type="number" name="pos" value="<?= (int)$rep['pos'] ?>" class="kurz"></label>
    <button class="knopf">Speichern</button></form>
  <form method="post" class="zeile"><?= csrf_feld() ?><input type="hidden" name="do" value="report_del"><input type="hidden" name="id" value="<?= $rid ?>">
    <button class="knopf gefahr" data-confirm="Report „<?= h($rep['name']) ?>“ mit allen Diagrammen löschen?">Report löschen</button></form>
</details>
<?php endif ?>
