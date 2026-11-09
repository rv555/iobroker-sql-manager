<?php
// Berichtsansicht: nur die Reports, Menü oben (siehe index.php, $schlank). Link: berichte.php oder index.php?s=berichte
$reports = all('SELECT * FROM reports ORDER BY pos, name');
$rid = (int)($_GET['r'] ?? ($reports[0]['id'] ?? 0));
$rep = $rid ? one('SELECT * FROM reports WHERE id = ?', [$rid]) : null;
$titel = $rep['name'] ?? 'Berichte';
if (!$rep): ?>
  <p class="karte"><?= $reports ? 'Bericht nicht gefunden.' : 'Noch keine Berichte. Im SQL-Manager unter „Reports“ anlegen.' ?></p>
<?php return; endif ?>
<h1 class="berichtstitel"><?= h($rep['name']) ?></h1>
<?= report_inhalt($rep, false) ?>
