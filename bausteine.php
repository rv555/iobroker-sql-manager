<?php
// Bausteine für Reports und Berichtsansicht: Reihen-Auswahl, Formulare für Diagramm und Anzeige, Report-Inhalt
declare(strict_types=1);

const DIAGRAMM_ARTEN = ['linie' => 'Linie', 'kurve' => 'Kurve', 'balken' => 'Balken', 'flaeche' => 'Fläche', 'stufen' => 'Treppe'];
const ANZEIGE_STANDARD = ['von' => 0, 'bis' => 30, 'g1' => '', 'g2' => '', 'f1' => '#3b82f6', 'f2' => '#22c55e', 'f3' => '#ef4444'];

// Alle Reihen aller Datenbanken für die Auswahl: "db:id" => Name, Gruppe, Einheit, Farbe
function alle_reihen(): array
{
    $l = [];
    foreach (datenbanken() as $k => $n) {
        try {
            foreach (reihen_liste($k) as $r) $l["$k:{$r['id']}"] = ['db' => $n, 'gruppe' => $r['gruppe'], 'titel' => $r['titel'], 'einheit' => $r['einheit'], 'farbe' => $r['farbe']];
        } catch (Throwable $e) { /* Datenbank nicht erreichbar */ }
    }
    uasort($l, fn($a, $b) => [$a['db'], $a['gruppe'] === '', $a['gruppe'], $a['titel']] <=> [$b['db'], $b['gruppe'] === '', $b['gruppe'], $b['titel']]);
    return $l;
}
function reihe_zusatz(array $x): string
{
    // (kein trim() mit "·": das schneidet bei Mehrbyte-Zeichen wie "°" Bytes ab)
    return implode(' · ', array_filter([$x['einheit'], $x['gruppe'] ?: '–', count(datenbanken()) > 1 ? $x['db'] : ''], 'strlen'));
}

// Formular für ein Diagramm (neu oder bearbeiten): Reihen mit Achse, Art und Farbe; Zusammenfassen, Stapeln
function diagramm_form(int $rep, ?array $d, array $alle): string
{
    $gew = [];
    foreach (json_decode($d['reihen'] ?? '[]', true) ?: [] as $x) $gew[$x['db'] . ':' . $x['id']] = $x;
    $o = json_decode($d['optionen'] ?? '{}', true) ?: [];
    // gewählte Reihen zuerst (in ihrer Reihenfolge), dann alle anderen
    $alle = array_merge(array_intersect_key(array_replace($gew, $alle), $gew, $alle), array_diff_key($alle, $gew));
    ob_start(); ?>
    <form method="post" class="diagrammform"><?= csrf_feld() ?><input type="hidden" name="do" value="diagramm_save">
      <input type="hidden" name="report" value="<?= $rep ?>"><input type="hidden" name="id" value="<?= (int)($d['id'] ?? 0) ?>">
      <div class="zeile eng"><input name="titel" value="<?= h($d['titel'] ?? '') ?>" placeholder="Überschrift" class="breit">
        <label class="inline leise">Höhe <input type="number" name="hoehe" value="<?= (int)($d['hoehe'] ?? 260) ?>" min="150" max="800" step="10" class="kurz"> px</label></div>
      <div class="zeile eng">
        <label class="inline leise">Werte zusammenfassen
          <?php $zr = (string)($o['raster'] ?? ''); $zmin = $zr !== '' && !isset(ZUSAMMEN_RASTER[$zr]); ?>
          <select name="z_raster"><option value="">nein (Verlauf)</option>
            <?php foreach (ZUSAMMEN_RASTER as $k => $n): ?><option value="<?= $k ?>"<?= $zr === (string)$k ? ' selected' : '' ?>>je <?= h($n) ?></option><?php endforeach ?>
            <option value="min"<?= $zmin ? ' selected' : '' ?>>je … Minuten:</option></select></label>
        <label class="inline leise" title="Beliebige Minuten (1 bis 1439), gilt bei „je … Minuten“. Beispiel: 6 Minuten – Wh × 10 = mittlere Leistung in W">
          <input type="number" name="z_min" min="1" max="1439" value="<?= $zmin ? h($zr) : '' ?>" placeholder="z. B. 6" class="kurz"> min</label>
        <label class="haken"><input type="checkbox" name="stapeln" <?= !empty($o['stapeln']) ? 'checked' : '' ?>> Balken/Flächen stapeln</label>
        <label class="inline leise" title="Punkte auf Linien: automatisch = nur bei wenigen Einzelwerten">Punkte
          <select name="punkte"><?php foreach (['' => 'automatisch', 'an' => 'immer', 'aus' => 'nie'] as $pk => $pn): ?><option value="<?= $pk ?>"<?= ($o['punkte'] ?? '') === $pk ? ' selected' : '' ?>><?= $pn ?></option><?php endforeach ?></select></label>
        <label class="haken" title="Zwei aufeinanderfolgende Diagramme mit halber Breite stehen nebeneinander (auf dem Handy untereinander)"><input type="checkbox" name="halb" <?= !empty($o['halb']) ? 'checked' : '' ?>> halbe Breite</label>
      </div>
      <div class="zeile eng">
        <?php $gz = fn($a, $i) => isset($o[$a][$i]) ? h(str_replace('.', ',', (string)$o[$a][$i])) : ''; ?>
        <span class="leise" title="Leer = automatisch aus den Werten">Achse links</span>
        <input type="text" inputmode="decimal" name="l_min" value="<?= $gz('l', 0) ?>" placeholder="min" class="kurz" aria-label="Achse links Minimum">
        <input type="text" inputmode="decimal" name="l_max" value="<?= $gz('l', 1) ?>" placeholder="max" class="kurz" aria-label="Achse links Maximum">
        <span class="leise">rechts</span>
        <input type="text" inputmode="decimal" name="r_min" value="<?= $gz('r', 0) ?>" placeholder="min" class="kurz" aria-label="Achse rechts Minimum">
        <input type="text" inputmode="decimal" name="r_max" value="<?= $gz('r', 1) ?>" placeholder="max" class="kurz" aria-label="Achse rechts Maximum">
      </div>
      <input type="search" class="auswahlsuche" placeholder="Reihen filtern …">
      <div class="auswahl">
      <?php $platz = array_flip(array_keys($gew));
      foreach ($alle as $k => $x): $g = $gew[$k] ?? null; ?>
        <div class="auswahlzeile" data-suche="<?= h(mb_strtolower($x['titel'] . ' ' . $x['gruppe'] . ' ' . $x['db'])) ?>">
          <label class="haken"><input type="checkbox" name="rd[]" value="<?= h($k) ?>" <?= $g ? 'checked' : '' ?>>
            <?= h($x['titel']) ?> <span class="leise"><?= h(reihe_zusatz($x)) ?></span></label>
          <span class="zeile eng">
            <input type="number" name="platz[<?= h($k) ?>]" value="<?= isset($platz[$k]) ? $platz[$k] + 1 : '' ?>" min="1" max="12" class="platz"
              placeholder="Platz" aria-label="Platz" title="Reihenfolge: 1 = beim Stapeln ganz unten, dann 2, 3 … nach oben (auch Reihenfolge in der Legende). Leer = hinten anhängen.">
            <select name="achse[<?= h($k) ?>]" aria-label="Achse"><option value="l">links</option><option value="r" <?= ($g['achse'] ?? '') === 'r' ? 'selected' : '' ?>>rechts</option></select>
            <select name="art[<?= h($k) ?>]" aria-label="Darstellung"><?php foreach (DIAGRAMM_ARTEN as $ak => $an): ?><option value="<?= $ak ?>"<?= ($g['art'] ?? 'linie') === $ak ? ' selected' : '' ?>><?= $an ?></option><?php endforeach ?></select>
            <select name="fkt[<?= h($k) ?>]" aria-label="Beim Zusammenfassen" title="Beim Zusammenfassen: Summe, Mittelwert, Minimum oder Maximum je Raster">
              <option value="">auto</option><?php foreach (ZUSAMMEN_FKT as $fk => $fn): ?><option value="<?= $fk ?>"<?= ($g['fkt'] ?? '') === $fk ? ' selected' : '' ?>><?= h($fn) ?></option><?php endforeach ?></select>
            <label class="haken" title="Eigene Farbe nur in diesem Diagramm (sonst die Farbe der Reihe)"><input type="checkbox" name="eigenfarbe[<?= h($k) ?>]" <?= !empty($g['farbe']) ? 'checked' : '' ?>>
              <input type="color" name="farbe[<?= h($k) ?>]" value="<?= h($g['farbe'] ?? $x['farbe'] ?? '#2f6fdb') ?>" aria-label="Farbe"></label>
          </span>
        </div>
      <?php endforeach ?>
      </div>
      <p class="leise klein">Höchstens 12 Reihen. Achse: unterschiedliche Einheiten links/rechts. Farbe: Haken = eigene Farbe in diesem Diagramm, sonst die Farbe der Reihe (✎ in der Übersicht).
        „Zusammenfassen“ bildet z. B. Summen je Tag – passend für Balken; je Reihe Summe, Mittelwert, Min oder Max (auto: Balken/Fläche = Summe, sonst Mittelwert; bei Ein/Aus ist der Mittelwert der Anteil „Ein“). „Stapeln“ setzt Balken bzw. Flächen derselben Achse aufeinander. „Halbe Breite“: zwei solche Diagramme hintereinander stehen nebeneinander. Achse min/max: feste Grenzen, leer = automatisch. „Platz“ legt die Reihenfolge fest: 1 liegt beim Stapeln ganz unten, dann 2, 3 … nach oben.</p>
      <button class="knopf haupt">Diagramm speichern</button>
    </form>
    <?php return ob_get_clean();
}

// Formular für eine Anzeige (Gauge): eine Reihe, Skala von–bis, zwei Grenzen mit drei Farben
function anzeige_form(int $rep, ?array $d, array $alle): string
{
    $o = (json_decode($d['optionen'] ?? '{}', true) ?: []) + ANZEIGE_STANDARD;
    $r = (json_decode($d['reihen'] ?? '[]', true) ?: [])[0] ?? null;
    $sel = $r ? $r['db'] . ':' . $r['id'] : '';
    ob_start(); ?>
    <form method="post" class="anzeigeform"><?= csrf_feld() ?><input type="hidden" name="do" value="anzeige_save">
      <input type="hidden" name="report" value="<?= $rep ?>"><input type="hidden" name="id" value="<?= (int)($d['id'] ?? 0) ?>">
      <div class="zeile eng">
        <input name="titel" value="<?= h($d['titel'] ?? '') ?>" placeholder="Überschrift (leer = Name der Reihe)" class="breit">
        <select name="reihe" required><option value="">– Reihe wählen –</option>
          <?php foreach ($alle as $k => $x): ?><option value="<?= h($k) ?>"<?= $k === $sel ? ' selected' : '' ?>><?= h($x['titel']) ?> (<?= h(reihe_zusatz($x)) ?>)</option><?php endforeach ?></select>
      </div>
      <div class="zeile eng">
        <label class="inline">Skala von <input type="text" inputmode="decimal" name="von" value="<?= h($o['von']) ?>" class="kurz" required></label>
        <label class="inline">bis <input type="text" inputmode="decimal" name="bis" value="<?= h($o['bis']) ?>" class="kurz" required></label>
      </div>
      <div class="zeile eng farbskala">
        <input type="color" name="f1" value="<?= h($o['f1']) ?>" aria-label="Farbe unten">
        <label class="inline">bis <input type="text" inputmode="decimal" name="g1" value="<?= h($o['g1']) ?>" class="kurz"></label>
        <input type="color" name="f2" value="<?= h($o['f2']) ?>" aria-label="Farbe Mitte">
        <label class="inline">bis <input type="text" inputmode="decimal" name="g2" value="<?= h($o['g2']) ?>" class="kurz"></label>
        <input type="color" name="f3" value="<?= h($o['f3']) ?>" aria-label="Farbe oben">
      </div>
      <p class="leise klein">Farbskala: unterhalb der ersten Grenze die erste Farbe, zwischen den Grenzen die zweite, darüber die dritte. Grenzen leer lassen = ganze Skala in der mittleren Farbe.</p>
      <button class="knopf haupt">Anzeige speichern</button>
    </form>
    <?php return ob_get_clean();
}

// Inhalt eines Reports: Zeitleiste, Anzeigen (nebeneinander) und Diagramme; $bearbeiten = Knöpfe und Formulare für Admins
function report_inhalt(array $rep, bool $bearbeiten, array $alle = []): string
{
    $teile = all('SELECT * FROM diagramme WHERE report = ? ORDER BY pos', [$rep['id']]);
    $knoepfe = function (array $d) {
        ob_start(); ?>
        <form method="post" class="inline nodruck"><?= csrf_feld() ?><input type="hidden" name="do" value="diagramm_pos"><input type="hidden" name="id" value="<?= $d['id'] ?>">
          <button class="knopf klein" name="r" value="hoch" title="nach vorn">↑</button><button class="knopf klein" name="r" value="runter" title="nach hinten">↓</button></form>
        <form method="post" class="inline nodruck"><?= csrf_feld() ?><input type="hidden" name="do" value="diagramm_kopie"><input type="hidden" name="id" value="<?= $d['id'] ?>"><input type="hidden" name="ziel" value="<?= $d['report'] ?>">
          <button class="knopf klein" title="Kopie in diesem Report anlegen (in einen anderen Report: unter „bearbeiten“ → Kopieren nach)">⧉</button></form>
        <form method="post" class="inline nodruck"><?= csrf_feld() ?><input type="hidden" name="do" value="diagramm_del"><input type="hidden" name="id" value="<?= $d['id'] ?>">
          <button class="knopf klein gefahr" data-confirm="„<?= h($d['titel'] ?: 'Diagramm') ?>“ entfernen?">✕</button></form>
        <?php return ob_get_clean();
    };
    // Kopieren in diesen oder einen anderen Report
    $berichte = $bearbeiten ? all('SELECT id, name FROM reports ORDER BY pos, name') : [];
    $kopie = function (array $d) use ($berichte, $rep) {
        ob_start(); ?>
        <form method="post" class="zeile eng kopieform"><?= csrf_feld() ?><input type="hidden" name="do" value="diagramm_kopie"><input type="hidden" name="id" value="<?= $d['id'] ?>">
          <label class="inline leise">Kopieren nach <select name="ziel">
            <?php foreach ($berichte as $b): ?><option value="<?= $b['id'] ?>"<?= $b['id'] == $rep['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach ?></select></label>
          <button class="knopf klein">Kopieren</button></form>
        <?php return ob_get_clean();
    };
    ob_start(); ?>
<div id="report" data-tage="<?= (int)$rep['tage'] ?>">
  <div class="zeile zeitwahl">
    <span class="knopfgruppe" id="bereiche">
      <button type="button" class="knopf klein" data-tage="0.0208333">30 min</button><button type="button" class="knopf klein" data-tage="0.125">3 h</button>
      <button type="button" class="knopf klein" data-tage="1">24 h</button><button type="button" class="knopf klein" data-tage="7">7 Tage</button>
      <button type="button" class="knopf klein" data-tage="30">30 Tage</button><button type="button" class="knopf klein" data-tage="90">3 Monate</button>
      <button type="button" class="knopf klein" data-tage="365">1 Jahr</button>
    </span>
    <input type="datetime-local" id="von" aria-label="von"> – <input type="datetime-local" id="bis" aria-label="bis">
    <button type="button" class="knopf klein" id="anzeigen">Anzeigen</button>
    <button type="button" class="knopf klein" id="zurueckzoom" title="Vorheriger Zeitraum">↶</button>
    <button type="button" class="knopf klein" id="neuladen" title="Neu laden (Zeitraum bis jetzt)">⟳</button>
    <span class="platz"></span>
  </div>
  <?php $offen = ''; // offener Block: '' / 'anzeigen' / 'diagramme'
  $block = function (string $art) use (&$offen) {
      if ($offen === $art) return;
      if ($offen) echo '</div>';
      if ($art) echo '<div class="' . $art . '">';
      $offen = $art;
  };
  foreach ($teile as $d):
    if ($d['art'] === 'anzeige'):
      $block('anzeigen'); ?>
    <section class="karte anzeigekarte">
      <div class="anzeige" data-anzeige="<?= h($d['reihen']) ?>" data-optionen="<?= h($d['optionen']) ?>" data-titel="<?= h($d['titel']) ?>"></div>
      <?php if ($bearbeiten): ?>
      <div class="zeile eng nodruck"><?= $knoepfe($d) ?></div>
      <details class="unter nodruck"><summary class="leise klein">bearbeiten</summary><?= anzeige_form((int)$rep['id'], $d, $alle) ?><?= $kopie($d) ?></details>
      <?php endif ?>
    </section>
  <?php else:
      $block('diagramme'); $halb = !empty((json_decode($d['optionen'] ?: '{}', true) ?: [])['halb']); ?>
  <section class="karte diagrammkarte<?= $halb ? ' halb' : '' ?>">
    <div class="zeile eng"><h3><?= h($d['titel'] ?: 'Diagramm') ?></h3><span class="platz"></span><?= $bearbeiten ? $knoepfe($d) : '' ?></div>
    <div class="diagramm" data-diagramm="<?= h($d['reihen']) ?>" data-optionen="<?= h($d['optionen']) ?>" data-hoehe="<?= (int)$d['hoehe'] ?>"></div>
    <?php if ($bearbeiten): ?>
    <details class="unter nodruck"><summary class="leise klein">Diagramm bearbeiten</summary><?= diagramm_form((int)$rep['id'], $d, $alle) ?><?= $kopie($d) ?></details>
    <?php endif ?>
  </section>
  <?php endif; endforeach;
  $block(''); ?>
  <?php if (!$teile): ?><p class="karte leise">Dieser Report hat noch keine Diagramme.</p><?php endif ?>
</div>
    <?php return ob_get_clean();
}
