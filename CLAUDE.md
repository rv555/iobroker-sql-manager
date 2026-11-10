# SQL-Manager – Messdaten aus ioBroker anzeigen und verdichten (PHP + SQLite + MySQL)

Web-App für die MySQL/MariaDB-Datenbanken, die der ioBroker-SQL-Adapter befüllt: Übersicht aller Reihen mit Mini-Diagramm, große Diagramme mit Zoom, Fehlmessungen per Markierung löschen (Papierkorb), Verdichtung in Stufen je Reihe (per Cron), eigene Reihen (z. B. Monatssummen), Reports aus Diagrammen (Linie, Balken, Fläche, Treppe, gestapelt, zusammengefasst je Tag/Monat) und Anzeigen (Rundinstrumente), eigene Berichtsansicht `berichte.php`, Backup. Mehrere Messdatenbanken möglich. Ziel: Grafana kann entfallen.

Status: **Prototyp**, lokal mit künstlichen Daten getestet, noch nicht gegen die echte Datenbank.

## Arbeitsweise mit dem Entwickler (Rolf)

- Kommunikation auf Deutsch, UI-Texte Deutsch. Wenig Programmiererfahrung: kurz und nachvollziehbar erklären.
- Grundlage: `../BASISANFORDERUNGEN.md` (abgeleitet aus LOP). Abweichungen hier: Messdaten in MySQL (Adapter), uPlot als einzige Fremdbibliothek (lokal in `vendor/`), keine E-Mail, Rollen nur Admin/Leser.
- Keine Frameworks, kein Composer, keine Build-Schritte. Hochladen per FTP.
- Kleine, gezielte Änderungen. Nach jeder Änderung nennen, welche Dateien neu hochzuladen sind. `VERSION` in `lib.php` erhöhen, wenn `style.css`/`app.js` geändert wurden.

## Lokal testen

- `start-lokal.cmd` startet MariaDB aus `../_devtools` (Port 3307) und PHP auf http://127.0.0.1:8082/. Claude-Vorschau: `.claude/launch.json` (`sqlman`).
- Testdaten: `..\_devtools\php\php.exe -d extension=pdo_sqlite tests\seed_demo.php` – legt in `iobroker_test` das Adapter-Schema mit ~5 Mio. Werten über 2 Jahre an (Räume, Feuchte, Heizung mit Brenner-Takten, Wh-Impulse, Zählerstände, PV, Fenster; Ausreißer im Wohnzimmer, Sensorfehler Vorlauf vor 40 Tagen, Keller seit 3 Tagen ausgefallen, Lücke im Bad), in `iobroker_test2` drei kleine Reihen. Löscht vorher `data/sqlman.sqlite`. Zugänge in `tests/zugang-lokal.txt` (admin, leser).
- Cron lokal: `..\_devtools\php\php.exe -d extension=pdo_sqlite cron.php` (`--probe`, `--backup`).
- Icons: `tests/icons.php` (PHP mit GD), blauer Verlauf, „SQL“ weiß.
- Öffentlich auf GitHub (MIT, `README.md`). Screenshots in `docs/` (Edge headless per DevTools-Protokoll, hell, 1280 px). `tests/zugang-lokal.txt` ist nicht im Repo.

## Dateien

| Datei | Inhalt |
|---|---|
| `index.php` | Verteiler: Einrichtung, Login, Formular-Aktionen (Feld `do`, ab Benutzerverwaltung nur Admin), Rahmen mit Menü, `seiten/$s.php`, Konstanten für JS als `<script type="application/json" id="konstanten">`. Bei `s=berichte` schlanker Kopf (`$schlank`): Reports als Menü, Link „SQL-Manager ↗“ |
| `berichte.php` | Kurzer Einstieg: leitet auf `index.php?s=berichte` (`&r=` Report) um |
| `api.php` | JSON-API (POST, Header `X-CSRF`, Feld `a`): `sparks`, `daten` (eine/mehrere Reihen, je Reihe `achse/art/farbe/fkt`, optional `zusammen: {raster}`), `aktuell` (Anzeigen), `kennzahlen`; nur Admin: `loeschen_pruefen`, `loeschen`, `reihe_laden`, `reihe_speichern` (mit `farbe`, `vorlage_aendern`), `vorschau`, `abgeleitet_anlegen`, `abgeleitet_loeschen`, `reihe_loeschen` (ganze Reihe, `bestaetigung` = Anzeigename), `lauf` |
| `bausteine.php` | Reports: `alle_reihen()`, `diagramm_form()`, `anzeige_form()`, `report_inhalt()` (gemeinsam für Seite Reports und Berichtsansicht) |
| `cron.php` | Cron-Einstieg (CLI oder URL mit `cron_token`), Sperrdatei `data/cron.lock` |
| `lib.php` | Konfiguration, SQLite + Schema, `mdb()` (MySQL-Verbindungen aus config), Sitzung, Login, CSRF, `einst()`, `protokoll()`, Ausgabe-Helfer |
| `messdaten.php` | MySQL-Seite: eigene Tabellen anlegen (`m()`), `reihen_liste()`, `letzte_werte()`, `statistik_aktualisieren()`, `daten()`, `daten_zusammen()` (je Raster), `anteil_ein()`, `aktuell()`, `sparks()`, `kennzahlen()`, Löschen/Papierkorb, `reihe_entfernen()` |
| `verdichtung.php` | `RASTER`, `METHODEN`, `FUNKTIONEN`, `verdichte()` (gleich für Vorschau und Lauf), `wendepunkte()`, `reihe_verdichten()`, abgeleitete Reihen, `lauf()`, `vorschau()` |
| `sicherung.php` | Backup als `.sql.gz` ohne mysqldump (ungepufferte Verbindung, gzip per `deflate_add`), Download oder `data/backup`, App-DB per `VACUUM INTO` |
| `farbe.php` | Akzentfarbe als kleines Stylesheet (CSP erlaubt keine `style=""`) |
| `seiten/*.php` | `start` (Übersicht), `reihe`, `reports`, `berichte` (Berichtsansicht), `verdichtung`, `protokoll`, `papierkorb`, `backup`, `benutzer`, `einstellungen`, `hilfe`, `konto`, `login`, `setup`, `notfall` |
| `app.js` | Hell/Dunkel, `api()`, `diagramm()` (uPlot: Linie/Balken/Fläche/Treppe, Stapeln, Farben), `zeitleiste()`, Übersicht/Sparklines, Reihen-Seite (Zoom, Umschalt+Ziehen markiert Löschbereich), `reportSeite()` (neu laden alle 5 min, Anzeigen jede Minute), `anzeigeSvg()` (Rundinstrument), `stufenEditor()`, `bearbeiten()` (Dialog) |
| `vendor/` | uPlot 1.6.32 (MIT), lokal wegen CSP `script-src 'self'` |
| `werkzeuge/influx2sql.py` | Für den Pi: InfluxDB-2-Export (Line Protocol) → SQL für das Adapter-Schema (aus dem Vorgänger „hausdaten“) |
| `config.php` | `initpw`, `cron_token`, `zeitlimit`, `backup_behalten`, `backup_ordner`, `sqlite`, `debug`, `datenbanken` (**nicht im Repo**, Vorlage `config.example.php`) |

## Datenmodell

**MySQL (je Messdatenbank)** – Adapter-Tabellen bleiben unverändert: `datapoints(id, name, type)` (0 Zahl, 1 Text, 2 Ja/Nein), `ts_number`/`ts_bool` (`id, ts` in ms, `val, ack, _from, q`, PK `id, ts`). Eigene Tabellen (von `m()` angelegt): `sm_agg(id, ts, v_min, v_max, cnt)` Min/Max/Anzahl verdichteter Werte, `sm_ts(id, ts, val)` eigene Reihen (IDs ab `EIGENE_AB` = 1000000, Nummern werden nicht neu vergeben: `einst naechste_<db>`), `sm_reihen(id, name)` Namen eigener Reihen für Grafana, `sm_papierkorb`.

**SQLite (`data/sqlman.sqlite`)**: `users` (admin 1/0 = Leser), `fails`, `logins`, `einst` (`titel`, `farbe`, `statistik_<db>`, `tabellen_<db>`), `vorlagen(name, stufen JSON)`, `reihen(db, id, anzeige, einheit, gruppe, stellen, warn_std, farbe, vorlage, stufen, quelle/funktion/raster für eigene Reihen)`, `stand(db, id, stufe, bis, marke)` (Fortschritt je Stufe, `stufe = -1` = eigene Reihe), `statistik` (Zählung für die Übersicht), `protokoll`, `papierkorb`, `reports(name, tage, pos)`, `diagramme(report, pos, art, titel, hoehe, reihen, optionen)`: `art = diagramm` → `reihen` [{db, id, achse l/r, art linie/balken/flaeche/stufen, farbe?, fkt?}], `optionen` {raster?, stapeln?, halb?}; `art = anzeige` → eine Reihe, `optionen` {von, bis, g1, g2, f1, f2, f3}. Schema-Version über `PRAGMA user_version` (`SCHEMA` in `lib.php`, jetzt 2).

## Verdichtung

- Stufe = `{ab: Tage, m: Methode, r: Raster, s: Schwelle}`; `ab` = 0 heißt „sofort“ (nur fertige Raster; `loeschen` frühestens ab 1 Tag). Methoden: `mittel`, `summe` (Wert in der Rastermitte, Min/Max/Anzahl nach `sm_agg`, bei erneuter Verdichtung werden vorhandene `sm_agg`-Werte übernommen), `letzter` (letzter Wert behält seine Zeit), `wende` = „Kurvenform erhalten“ (Douglas-Peucker: ein Wert bleibt nur, wenn die Linie zwischen den Nachbarn ohne ihn um mehr als die Toleranz `s` abweicht; senkrechter Abstand, Min/Max/erster/letzter immer; kein Raster), `wechsel` (nur Änderungen, auch für Ja/Nein), `loeschen`. Raster ab 1 Tag nach Ortszeit.
- `reihe_verdichten()`: älteste Stufe zuerst; Stufe k bearbeitet nur `[max(stand_k, stand_k+1), jetzt − ab)`. Abschnitte von 7 Tagen (Minuten-Raster) bzw. ganzen Rastern ~1 Monat, je Abschnitt eine Transaktion. Ändern sich die Stufen (`marke` = md5), beginnt die Reihe von vorn (unschädlich, Methoden sind wiederholbar).
- Vorlage gewählt → Stufen der Vorlage gelten (Änderung der Vorlage wirkt auf alle Reihen), sonst `reihen.stufen`. Im ✎-Fenster lassen sich die Stufen einer Vorlage direkt ändern (Rückfrage, `vorlage_aendern`, Protokolleintrag); „– eigene Stufen –“ übernimmt sie nur für die Reihe.
- `lauf()`: erst eigene Reihen fortschreiben, dann verdichten bis `zeitlimit` (config, Standard 50 s), Zählung aktualisieren (nach Änderungen sofort, sonst stündlich), Papierkorb > 30 Tage leeren, Protokoll > 365 Tage löschen. Verdichtete Werte kommen nicht in den Papierkorb.
- Reihe ganz löschen (✎-Fenster, Name eintippen): `reihe_entfernen()` löscht Werte in Paketen à 50.000 (wiederholbar), `sm_agg`, `sm_papierkorb`, zuletzt `datapoints` bzw. `sm_reihen`, dazu SQLite `reihen/stand/statistik`, Papierkorb-Einträge, Verwendung in Diagrammen (leere Diagramme und Anzeigen fallen weg). Ohne Papierkorb. Gesperrt, solange eigene Reihen daraus berechnet werden. Adapter-Aufzeichnung vorher abschalten.
- Eigene Reihen: `summe/mittel/min/max/letzter/differenz` (Zählerstand: letzter Wert des Rasters − letzter Wert davor) je Stunde/Tag/Woche/Monat/Jahr, Zeitstempel = Rasterbeginn, laufendes Raster wird jedes Mal neu gerechnet. Löschen/Wiederherstellen in der Quelle setzt den Stand zurück (`abgeleitete_zuruecksetzen()`).

## Anzeige

- `daten()`: bis `ROH_GRENZE` (4000) Werte im Zeitraum Einzelwerte, sonst Mittel/Min/Max je Intervall. Reports bis 12 Reihen, zwei Achsen (`achse` l/r), Ausrichtung per `uPlot.join`.
- Zusammenfassen (`daten_zusammen()`, `ZUSAMMEN_RASTER` 15 min … Jahr oder beliebige Minuten 1–1439 über `zusammen_raster()`, im Formular „je … Minuten“ + `z_min`): SQL je Stunde (bei Minuten-Rastern je ggT(Minuten, 60)), dann PHP nach Ortszeit-Rastern; Funktion je Reihe (`fkt`, auto: Balken/Fläche Summe, sonst Mittel); Zeitpunkt = Rastermitte; höchstens 3000 Balken. Ein/Aus mit Mittel: `anteil_ein()` zeitgewichtet (Adapter speichert nur Wechsel).
- Stapeln (`optionen.stapeln`): Balken/Flächen je Achse aufsummiert, rückwärts gezeichnet, Legende zeigt den eigenen Wert (oberste zuerst). Reihenfolge = Reihenfolge in `reihen`, im Formular über „Platz“ (1 = unten, leer = hinten; `diagramm_save` sortiert danach). Mehrere Balken ungestapelt: jeder weitere schmaler.
- Halbe Breite (`optionen.halb`): Diagramme liegen in `.diagramme` (Grid, 2 Spalten), `.halb` belegt eine Spalte; unter 860 px untereinander.
- Zeitleiste: 30 min, 3 h, (6 h,) 24 h … und ⟳ (`#neuladen`, fester Zeitraum rückt bis jetzt nach, im Report auch die Anzeigen).
- Zeit-Zoom zurück: jeder Zeitwechsel mit `merken` macht `history.pushState({tiefe})`; ↶, Doppelklick und Strg+Z rufen `history.back()`, `popstate` stellt den Zeitraum her (also auch der Zurück-Knopf des Browsers).
- Kopieren (`diagramm_kopie`, im Bearbeiten-Bereich): Diagramm/Anzeige ans Ende desselben oder eines anderen Reports, im selben Report mit „(Kopie)“.
- Farbe: Diagramm-Eintrag `farbe` > `reihen.farbe` > Palette `FARBEN`. Mini-Diagramme in der Farbe der Reihe.
- Übersicht: letzter Wert live (`MAX(ts) GROUP BY id`), Anzahl/30 Tage/MB aus `statistik` (MB geschätzt = Tabellengröße / Zeilen; InnoDB gibt Platz nach Löschen erst mit `OPTIMIZE TABLE` frei). Warnung, wenn `warn_std` überschritten.

## Sicherheit

- Wie LOP: Platzhalter in SQL (Tabellennamen nur aus `tabelle()`), CSRF bei jedem POST, `h()`/`esc()`, CSP `script-src 'self'` ohne Inline-Skripte und `style=""`, Login-Sperre (5 je Name / 20 je IP, 15 min), „Angemeldet bleiben“ (Cookie `sqlman_merk`), Notfall-Zugang mit `initpw`, Startpasswort muss geändert werden.
- Schreibende Aktionen nur Admin (index.php ab `user_add`, api.php Liste `$schreibend`). Leser sehen Übersicht, Reihen, Reports, Protokoll.
- Cron per URL nur mit `cron_token` ≥ 16 Zeichen. Backup-Dateinamen streng geprüft (`backup_pfad()`), Ordner `data/backup` mit „Require all denied“.
- `.htaccess` sperrt interne PHP-Dateien (auch `bausteine.php`), `*.md/.sqlite/.py/.txt/.cmd/.gz/.lock`, versteckte Dateien und die Ordner `tests`, `data`, `seiten`, `werkzeuge`, `.claude`, `.git`.

## Grafana

Verdichtete Werte bleiben in `ts_number`, Abfragen ändern sich nicht (`ts BETWEEN $__unixEpochFrom()*1000 AND $__unixEpochTo()*1000`). Eigene Reihen aus `sm_ts`. Im SQL-Adapter die Vorhaltezeit auf „nie löschen“ stellen.

## Ideen / offen

- Gegen die echte Datenbank testen (Spalten des Adapters, Laufzeit beim Hoster, `zeitlimit` ausprobieren).
- Influx-Historie importieren (`werkzeuge/influx2sql.py`), danach verdichten.
- Knopf „Speicher freigeben“ (`OPTIMIZE TABLE`) nach großen Verdichtungen.
- Bei Diagrammen über verdichtete Bereiche sind Mittelwerte nicht zeitgewichtet (behaltene Kurvenpunkte zählen gleich viel wie Rasterwerte).
- Mehrere Balken nebeneinander (gruppiert) statt schmaler übereinander.
