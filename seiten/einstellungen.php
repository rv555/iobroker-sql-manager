<?php
// Einstellungen (nur Admin): Name der App, Akzentfarbe
$titel = 'Einstellungen';
?>
<form method="post" class="karte schmal">
  <h1>Einstellungen</h1>
  <?= csrf_feld() ?><input type="hidden" name="do" value="einst_save">
  <label>Name der App (oben links und im Browser-Tab)<input name="titel" value="<?= h(einst('titel')) ?>" placeholder="SQL-Manager"></label>
  <label>Akzentfarbe<input type="color" name="farbe" value="<?= h(einst('farbe', '#1f5f99')) ?>"></label>
  <p class="leise">Zugangsdaten der Datenbanken, Zeitlimit des Cron-Laufs und Anzahl der Sicherungen stehen in der <code>config.php</code> auf dem Server.</p>
  <button class="knopf haupt">Speichern</button>
</form>
