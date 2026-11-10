<?php
// Eine Reihe: großes Diagramm (Zoom), Kennzahlen, größte Sprünge, Fehlmessungen löschen
$titel = 'Reihe';
$r = reihe($dbk, (int)($_GET['id'] ?? 0));
if (!$r) { echo '<p>Reihe nicht gefunden. <a href="?s=start">Zur Übersicht</a></p>'; return; }
$titel = $r['titel'];
?>
<p class="nodruck"><a href="?s=start&amp;db=<?= h($dbk) ?>">‹ Übersicht</a></p>
<div class="titelzeile">
  <h1><?= h($r['titel']) ?></h1>
  <span class="leise technisch"><?= h($r['name']) ?><?= $r['einheit'] !== '' ? ' · ' . h($r['einheit']) : '' ?></span>
  <span class="platz"></span>
  <?php if ($u['admin']): ?><button type="button" class="knopf klein" data-edit="<?= $r['id'] ?>">✎ Bearbeiten</button><?php endif ?>
</div>
<div id="reihe" data-db="<?= h($dbk) ?>" data-id="<?= $r['id'] ?>" data-typ="<?= $r['typ'] ?>" data-admin="<?= (int)$u['admin'] ?>">
  <div class="zeile zeitwahl">
    <span class="knopfgruppe" id="bereiche">
      <button type="button" class="knopf klein" data-tage="0.0208333">30 min</button><button type="button" class="knopf klein" data-tage="0.125">3 h</button>
      <button type="button" class="knopf klein" data-tage="0.25">6 h</button><button type="button" class="knopf klein" data-tage="1">24 h</button>
      <button type="button" class="knopf klein" data-tage="7">7 Tage</button><button type="button" class="knopf klein" data-tage="30">30 Tage</button>
      <button type="button" class="knopf klein" data-tage="365">1 Jahr</button><button type="button" class="knopf klein" data-tage="3650">Alles</button>
    </span>
    <input type="datetime-local" id="von" step="1" aria-label="von"> – <input type="datetime-local" id="bis" step="1" aria-label="bis">
    <button type="button" class="knopf klein" id="anzeigen">Anzeigen</button>
    <button type="button" class="knopf klein" id="zurueckzoom" title="Vorheriger Zeitraum (oder Doppelklick ins Diagramm)">↶</button>
    <button type="button" class="knopf klein" id="neuladen" title="Neu laden (Zeitraum bis jetzt)">⟳</button>
  </div>
  <div class="karte diagrammkarte"><div id="diagramm" class="diagramm"></div>
    <p class="leise klein" id="diagramminfo"></p></div>
  <p class="leise klein">Mit der Maus einen Bereich aufziehen = hineinzoomen, Doppelklick = zurück.
    <?php if ($u['admin']): ?><b>Umschalt + Ziehen</b> = Bereich zum Löschen markieren.<?php endif ?></p>
  <div class="kacheln kennzahlen" id="kennzahlen"></div>
  <div class="zweispaltig">
    <section class="karte"><h3>Größte Sprünge im Zeitraum</h3>
      <table class="liste klein" id="spruenge"></table>
      <p class="leise klein">Klick auf eine Zeile zoomt auf diese Stelle.</p></section>
    <?php if ($u['admin']): ?>
    <section class="karte" id="loeschbox"><h3>Fehlmessungen löschen</h3>
      <div class="zeile eng"><label class="inline">von <input type="datetime-local" id="lvon" step="1"></label>
        <label class="inline">bis <input type="datetime-local" id="lbis" step="1"></label></div>
      <div class="zeile eng">
        <?php if ($r['typ'] !== 'bool'): ?>
        <label class="inline">nur Werte unter <input type="text" inputmode="decimal" id="lunter" class="kurz"></label>
        <label class="inline">oder über <input type="text" inputmode="decimal" id="lueber" class="kurz"></label>
        <?php endif ?>
      </div>
      <div class="zeile eng"><input id="lgrund" placeholder="Grund (für das Protokoll, optional)" class="breit"></div>
      <div class="zeile eng">
        <button type="button" class="knopf" id="lpruefen">Prüfen</button>
        <button type="button" class="knopf gefahr" id="lloeschen" disabled>Löschen</button>
        <button type="button" class="knopf klein" id="lleeren">Markierung aufheben</button>
      </div>
      <div id="lergebnis" class="leise klein"></div>
      <p class="leise klein">Ohne Grenzwerte wird alles im Zeitraum gelöscht. Gelöschtes bleibt <?= PAPIERKORB_TAGE ?> Tage im Papierkorb.</p>
    </section>
    <?php endif ?>
  </div>
</div>
