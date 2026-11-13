<?php $titel = 'Hilfe' ?>
<h1>Hilfe</h1>
<div class="karte">
<h2>Übersicht</h2>
<p>Alle Zahlen- und Ein/Aus-Reihen, die der ioBroker-SQL-Adapter schreibt, nach Gruppen. Je Reihe: letzter Wert mit Zeit
  (rot mit ⚠, wenn länger als eingestellt keine Werte kamen), Anzahl Werte gesamt und der letzten 30 Tage, geschätzter Speicher, Verdichtung und ein Mini-Diagramm.
  Den Zeitraum der Mini-Diagramme oben rechts wählen. Die Zählung („Zahlen vom …“) aktualisiert der Cron stündlich oder der Knopf „Zahlen aktualisieren“.</p>
<p>✎ öffnet das Bearbeiten-Fenster: Anzeigename, Einheit, Gruppe, Nachkommastellen, Warnung bei Ausfall und die Verdichtung (Vorlage oder eigene Stufen, mit Vorschau).
  Dort lassen sich auch eigene Reihen berechnen, z. B. „Summe je Monat“ aus Wh-Einzelwerten, „Verbrauch je Tag“ aus einem Zählerstand oder „Anzahl Starts je Tag“ aus einem Schaltzustand.</p>
<p><b>Ein/Aus oder Zahl?</b> Das entscheidet nicht der Wert, sondern der Typ des Datenpunkts in ioBroker: Typ „boolean“ speichert der Adapter in <code>ts_bool</code>
  (Anzeige „Ein“/„Aus“), Typ „number“ in <code>ts_number</code> – auch wenn nur 0 und 1 vorkommen (Anzeige „1“/„0“, z. B. bei Modbus-Registern).
  Nur echte Ein/Aus-Reihen werden im Diagramm bis an die Ränder gezeichnet und beim Zusammenfassen als „Anteil Ein“ über die Zeit gewichtet;
  bei einer 0/1-Zahlenreihe ist der Mittelwert nur der Durchschnitt der gespeicherten Werte (bei „nur Änderungen speichern“ verfälscht). „Anzahl Starts“ funktioniert bei beiden.
  Tipp: Schaltzustände in ioBroker als „boolean“ anlegen. Wird der Typ nachträglich umgestellt, landen nur neue Werte in <code>ts_bool</code>; die alten bleiben als Zahlen in <code>ts_number</code>
  und erscheinen hier nicht mehr, sobald der Adapter den Typ in <code>datapoints</code> auf „boolean“ setzt (sie belegen aber weiter Platz).</p>
<h2>Diagramm einer Reihe</h2>
<ul>
  <li>Maus ziehen = hineinzoomen. Zurück zum vorherigen Zeitraum, Schritt für Schritt: Doppelklick ins Diagramm, ↶, <b>Strg+Z</b> oder der Zurück-Knopf des Browsers (bzw. die Zurück-Taste der Maus). Ist der Zeitverlauf am Anfang, geht der Zurück-Knopf wie gewohnt zur vorherigen Seite.
    ⟳ lädt neu – ein fester Zeitraum wie „3 h“ rückt dabei bis jetzt nach. Bei vielen Werten zeigt das Diagramm Mittelwerte mit Min/Max-Fläche, beim Hineinzoomen die Einzelwerte.</li>
  <li><b>Fehlmessungen löschen (Admin):</b> Umschalt-Taste halten und den Bereich im Diagramm aufziehen – er wird rot markiert und von/bis werden ausgefüllt.
    Optional nur Werte unter/über einem Grenzwert. „Prüfen“ zeigt, wie viele Werte betroffen sind, dann „Löschen“.
    Gelöschtes liegt <?= PAPIERKORB_TAGE ?> Tage im Papierkorb.</li>
  <li>„Größte Sprünge“ listet die stärksten Wertänderungen im Zeitraum – Klick zoomt dorthin.</li>
</ul>
<h2>Verdichtung</h2>
<p>Jede Reihe hat Stufen, z. B. „ab 90 Tagen: Kurvenform ±0,5 · ab 2 Jahren: Mittel 1 Tag“. Werte jünger als die erste Stufe bleiben unverändert.
  Der Cron-Lauf arbeitet die Stufen ab; was einmal verdichtet ist, lässt sich nicht zurückholen. Vorher mit „Vorschau“ im Bearbeiten-Fenster prüfen.
  Methoden und Cron-Einrichtung: Menü <b>Verdichtung</b>.</p>
<p><b>Vorlagen ändern:</b> im ✎-Fenster einer Reihe mit Vorlage die Stufen ändern und speichern – nach Rückfrage gilt das für alle Reihen mit dieser Vorlage.
  Alternativ im Menü Verdichtung → Vorlagen → bearbeiten. Nur eine Reihe abweichend: im ✎-Fenster „– eigene Stufen –“ wählen.</p>
<h2>Reports und Berichtsansicht</h2>
<p>Ein Report besteht aus Diagrammen und Anzeigen. Der Zeitraum gilt für alle Diagramme; Zoomen in einem Diagramm zoomt alle. Bei einem Zeitraum bis „jetzt“ laden die Diagramme alle 5 Minuten neu, Anzeigen jede Minute.</p>
<ul>
  <li><b>Diagramm:</b> bis zu 12 Reihen, je Reihe Achse (links/rechts), Darstellung (Linie, Kurve = geglättete Linie, Balken, Fläche, Treppe) und auf Wunsch eine eigene Farbe nur in diesem Diagramm.
    „Werte zusammenfassen“ bildet z. B. die Summe je Tag oder Monat (passend für Balken), mit „je … Minuten“ auch beliebige Minuten (z. B. 6 Minuten: Wh × 10 = mittlere Leistung in W). „Stapeln“ setzt Balken bzw. Flächen derselben Achse aufeinander.
    „Achse links/rechts“ min/max: feste Grenzen, leer = automatisch. Ja/Nein-Reihen werden bis an die Ränder gezeichnet (der Adapter speichert nur Wechsel). „Punkte“: automatisch (nur bei wenigen Einzelwerten), immer oder nie – auch auf der Seite einer Reihe wählbar. „Halbe Breite“: zwei solche Diagramme hintereinander stehen nebeneinander (auf dem Handy untereinander).</li>
  <li><b>Kopieren:</b> ⧉ neben ↑ ↓ ✕ legt eine Kopie im selben Report an; in einen anderen Report: unter „bearbeiten“ eines Diagramms oder einer Anzeige „Kopieren nach“ – in denselben Report (mit „(Kopie)“ im Titel) oder einen anderen, jeweils ans Ende.</li>
  <li><b>Anzeige:</b> aktueller Wert einer Reihe als Rundinstrument mit Skala von–bis und drei Farbbereichen, dazu Min/Max der letzten 24 Stunden.</li>
  <li><b>Farbe einer Reihe:</b> im ✎-Fenster „eigene Farbe“ – gilt in allen Diagrammen und im Mini-Diagramm der Übersicht.</li>
  <li><b>Berichtsansicht:</b> <code>berichte.php</code> zeigt nur die Reports mit kleinem Menü oben und einem Link zurück zum SQL-Manager – gut als Lesezeichen oder auf dem Handy-Startbildschirm. Reihenfolge im Menü: „Reihenfolge“ beim Report.</li>
</ul>
<h2>Grafana</h2>
<p>Verdichtete Werte stehen weiter in <code>ts_number</code>, Grafana-Abfragen bleiben also gleich. Min/Max verdichteter Intervalle: <code>sm_agg</code>.
  Eigene Reihen: <code>sm_ts</code> (Namen in <code>sm_reihen</code>).</p>
<h2>Tastatur</h2>
<ul><li><kbd>Esc</kbd> schließt Menüs und das Bearbeiten-Fenster.</li><li><kbd>Tab</kbd> erreicht alle Knöpfe und Felder.</li></ul>
</div>
