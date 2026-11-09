<?php
// Verdichtung (nur Admin): Lauf von Hand, Cron-Aufruf, Vorlagen mit Stufen
$titel = 'Verdichtung';
$vorlagen = all('SELECT v.*, (SELECT COUNT(*) FROM reihen r WHERE r.vorlage = v.id) AS n FROM vorlagen v ORDER BY name');
$laeufe = all("SELECT * FROM protokoll WHERE art = 'lauf' ORDER BY id DESC LIMIT 5");
$pfad = str_replace('\\', '/', dirname(__DIR__));
$url = (https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . cookie_pfad() . 'cron.php?token=' . urlencode((string)($CFG['cron_token'] ?? ''));
$tokenOk = strlen((string)($CFG['cron_token'] ?? '')) >= 16 && ($CFG['cron_token'] ?? '') !== 'BITTE-AENDERN';
?>
<h1>Verdichtung</h1>
<section class="karte">
  <h2>Lauf</h2>
  <p>Ein Lauf berechnet zuerst die eigenen Reihen (z. B. Monatssummen), dann verdichtet er alle Reihen nach ihren Stufen
    und leert alte Einträge im Papierkorb. Er arbeitet höchstens <?= (int)($CFG['zeitlimit'] ?? 50) ?> Sekunden
    (<code>zeitlimit</code> in der config.php) und macht beim nächsten Mal weiter.</p>
  <div class="zeile">
    <button type="button" class="knopf" data-lauf="probe">Probelauf (nur zählen)</button>
    <button type="button" class="knopf haupt" data-lauf="echt" data-confirm="Jetzt verdichten? Verdichtete Werte lassen sich nicht zurückholen.">Jetzt verdichten</button>
  </div>
  <pre id="laufausgabe" class="ausgabe" hidden></pre>
  <?php if ($laeufe): ?>
  <h3>Letzte Läufe</h3>
  <table class="liste klein"><?php foreach ($laeufe as $l): ?><tr><td><?= utc_zeit($l['am']) ?></td><td><?= h($l['wer']) ?></td><td><?= h($l['text']) ?></td></tr><?php endforeach ?></table>
  <p class="klein"><a href="?s=protokoll">Ganzes Protokoll</a></p>
  <?php endif ?>
  <details class="unter"><summary>Cron einrichten</summary>
    <p>Beim Hoster einen Cronjob <b>stündlich</b> anlegen, entweder als Befehl:</p>
    <pre class="ausgabe">php <?= h($pfad) ?>/cron.php</pre>
    <p>oder als Aufruf der Adresse (falls der Hoster nur Adressen kann):</p>
    <?php if ($tokenOk): ?><pre class="ausgabe"><?= h($url) ?></pre>
    <?php else: ?><p class="meldung fehler">In der config.php fehlt ein eigener <code>cron_token</code> (mind. 16 Zeichen).</p><?php endif ?>
    <p class="leise">Zusätzlich jede Nacht sichern: an Befehl bzw. Adresse <code>--backup</code> bzw. <code>&amp;backup=1</code> anhängen
      (sichert alle Datenbanken mit „nur Messwerte“ und die App-Datenbank in den Backup-Ordner).</p>
  </details>
</section>

<section class="karte">
  <h2>Vorlagen</h2>
  <p class="leise">Eine Vorlage ist eine Liste von Stufen. Reihen mit Vorlage übernehmen Änderungen an der Vorlage automatisch.
    Jede Stufe bearbeitet die Werte ab dem angegebenen Alter; eine spätere Stufe arbeitet auf dem Ergebnis der vorigen.</p>
  <table class="liste">
  <tr><th>Name</th><th>Stufen</th><th class="rechts">Reihen</th><th></th></tr>
  <?php foreach ($vorlagen as $v): $st = json_decode($v['stufen'], true) ?: []; ?>
  <tr>
    <td><?= h($v['name']) ?></td>
    <td><?= h(stufen_text($st)) ?>
      <details class="unter"><summary class="leise klein">bearbeiten</summary>
        <form method="post" class="stufenform"><?= csrf_feld() ?><input type="hidden" name="do" value="vorlage_save"><input type="hidden" name="id" value="<?= $v['id'] ?>">
          <label>Name<input name="name" value="<?= h($v['name']) ?>" required></label>
          <div class="stufen" data-stufen="<?= h($v['stufen']) ?>"></div>
          <input type="hidden" name="stufen">
          <button class="knopf haupt">Speichern</button>
        </form></details></td>
    <td class="rechts"><?= (int)$v['n'] ?></td>
    <td class="rechts"><form method="post" class="inline"><?= csrf_feld() ?><input type="hidden" name="do" value="vorlage_del"><input type="hidden" name="id" value="<?= $v['id'] ?>">
      <button class="knopf klein gefahr" data-confirm="Vorlage „<?= h($v['name']) ?>“ löschen?" <?= $v['n'] ? 'disabled title="wird noch verwendet"' : '' ?>>✕</button></form></td>
  </tr>
  <?php endforeach ?>
  </table>
  <details class="unter"><summary><b>+ Neue Vorlage</b></summary>
    <form method="post" class="stufenform"><?= csrf_feld() ?><input type="hidden" name="do" value="vorlage_save">
      <label>Name<input name="name" required></label>
      <div class="stufen" data-stufen="[]"></div>
      <input type="hidden" name="stufen">
      <button class="knopf haupt">Anlegen</button>
    </form></details>
</section>

<section class="karte">
  <h2>Methoden</h2>
  <table class="liste klein">
    <tr><td><b>Mittelwert je Raster</b></td><td>Aus allen Werten eines Rasters (z. B. 1 Stunde) wird ein Mittelwert in der Mitte des Rasters. Minimum, Maximum und Anzahl bleiben in <code>sm_agg</code>. Für Raumtemperaturen, Feuchte.</td></tr>
    <tr><td><b>Summe je Raster</b></td><td>Summe aller Werte je Raster. Für Einzelwerte wie „gerade verbrauchte Wattstunden“ – die Gesamtsumme bleibt exakt.</td></tr>
    <tr><td><b>Letzter Wert je Raster</b></td><td>Nur der letzte Wert jedes Rasters bleibt (mit seiner echten Zeit). Für Zählerstände.</td></tr>
    <tr><td><b>Kurvenform erhalten (Wendepunkte, Toleranz)</b></td><td>Betrachtet den Verlauf der Kurve: Ein Wert bleibt nur, wenn die gerade Linie zwischen den behaltenen Nachbarn ohne ihn um mehr als die Toleranz (z. B. ±0,5 °C) vom echten Verlauf abweicht. So bleiben echte Wendepunkte (Spitzen, Täler, Knicke), kleines Zittern und gleichmäßige Anstiege fallen weg. Kleinster und größter Wert bleiben immer. Für Vorlauf-/Rücklauftemperaturen.</td></tr>
    <tr><td><b>Nur Änderungen behalten</b></td><td>Gleiche Werte hintereinander fallen weg. Für Schalter (Ein/Aus) und Werte, die sich selten ändern.</td></tr>
    <tr><td><b>Löschen</b></td><td>Werte ab diesem Alter werden gelöscht (nicht in den Papierkorb).</td></tr>
  </table>
  <p class="leise klein">Raster ab 1 Tag richten sich nach Ortszeit (Mitternacht, Montag, Monatserster). Eigene Reihen (z. B. Monatssumme) im Bearbeiten-Fenster einer Reihe anlegen.</p>
</section>
