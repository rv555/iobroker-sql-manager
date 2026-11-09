<?php
// Sicherung (nur Admin): Messdatenbank als .sql.gz herunterladen oder im Backup-Ordner ablegen, App-Datenbank sichern
$titel = 'Backup';
$liste = backup_liste();
$groesse = [];
foreach (datenbanken() as $k => $n) {
    try {
        $groesse[$k] = mq(m($k), 'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    } catch (Throwable $e) { $groesse[$k] = null; }
}
?>
<h1>Backup</h1>
<section class="karte">
  <h2>Sicherung erstellen</h2>
  <form method="post"><?= csrf_feld() ?><input type="hidden" name="do" value="backup">
    <div class="zeile">
      <label class="inline">Datenbank
        <select name="db"><?php foreach (datenbanken() as $k => $n): ?><option value="<?= $k ?>"<?= $k === $dbk ? ' selected' : '' ?>><?= h($n) ?><?= $groesse[$k] !== null ? ' (' . mb((int)$groesse[$k]) . ' MB)' : ' (nicht erreichbar)' ?></option><?php endforeach ?></select></label>
      <label class="inline">Umfang
        <select name="umfang"><option value="daten">nur Messwerte (Adapter- und eigene Tabellen)</option><option value="alle">alle Tabellen der Datenbank</option></select></label>
    </div>
    <div class="zeile">
      <button class="knopf haupt" name="ziel" value="download">Herunterladen</button>
      <button class="knopf" name="ziel" value="ordner">Im Backup-Ordner speichern</button>
    </div>
  </form>
  <p class="leise klein">Die Datei ist eine gepackte SQL-Datei (<code>.sql.gz</code>), wie sie z. B. phpMyAdmin wieder einlesen kann. Große Datenbanken brauchen etwas Zeit.
    Im Ordner bleiben je Datenbank die letzten <?= max(1, (int)($CFG['backup_behalten'] ?? 5)) ?> Sicherungen (<code>backup_behalten</code> in der config.php).</p>
  <form method="post" class="zeile"><?= csrf_feld() ?><button class="knopf" name="do" value="backup_app">App-Datenbank sichern</button>
    <span class="leise klein">Benutzer, Einstellungen, Vorlagen, Reports und Protokoll (SQLite).</span></form>
  <p class="leise klein">Automatisch: Cron mit <code>--backup</code> bzw. <code>&amp;backup=1</code> (siehe Verdichtung → Cron einrichten).</p>
</section>
<section class="karte">
  <h2>Gespeicherte Sicherungen</h2>
  <table class="liste">
  <tr><th>Datei</th><th>Erstellt</th><th class="rechts">Größe</th><th></th></tr>
  <?php foreach ($liste as $b): ?>
  <tr><td><?= h($b['datei']) ?></td><td><?= date('d.m.Y H:i', $b['zeit']) ?></td><td class="rechts"><?= mb($b['groesse']) ?> MB</td>
    <td class="rechts nowrap"><a class="knopf klein" href="?s=backup_dl&amp;f=<?= urlencode($b['datei']) ?>">Herunterladen</a>
      <form method="post" class="inline"><?= csrf_feld() ?><input type="hidden" name="datei" value="<?= h($b['datei']) ?>">
        <button class="knopf klein gefahr" name="do" value="backup_del" data-confirm="Sicherung <?= h($b['datei']) ?> löschen?">✕</button></form></td></tr>
  <?php endforeach ?>
  <?php if (!$liste): ?><tr><td colspan="4" class="leise">Noch keine Sicherungen im Ordner.</td></tr><?php endif ?>
  </table>
</section>
