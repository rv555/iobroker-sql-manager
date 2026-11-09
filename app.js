// Gesamtes JavaScript: Hell/Dunkel, Helfer, Übersicht (Mini-Diagramme), Diagramme (uPlot), Bearbeiten-Fenster,
// Stufen-Editor, Löschen per Markierung, Reports, Verdichtungslauf

// Thema sofort setzen (Skript steht im <head>), damit nichts aufblitzt
try { const t = localStorage.getItem('sqlman-thema'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const merk = (k, v) => { try { if (v === undefined) return localStorage.getItem('sqlman-' + k); localStorage.setItem('sqlman-' + k, v); } catch (e) { return null; } };
let K = {}; // Konstanten vom Server (Raster, Methoden, Funktionen)

async function api(data) {
  let r;
  try {
    r = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': $('meta[name=csrf]').content },
      body: JSON.stringify(data),
    });
  } catch (e) { verbindung('netz'); throw new Error('Keine Verbindung zum Server.'); }
  const j = await r.json().catch(() => ({ fehler: 'Serverfehler (' + r.status + ')' }));
  verbindung(j.login ? 'login' : 'ok');
  if (!r.ok || j.fehler) throw new Error(j.fehler || 'Fehler');
  return j;
}

// Große Warnung oben, solange der Server nicht erreichbar ist oder die Anmeldung weg ist
let netzTimer = null;
function verbindung(z) {
  let b = $('#netzweg');
  if (z === 'ok') { clearInterval(netzTimer); netzTimer = null; b?.remove(); return; }
  if (!b) {
    b = document.createElement('div');
    b.id = 'netzweg'; b.className = 'netzweg'; b.setAttribute('role', 'alert');
    document.body.append(b);
  }
  b.innerHTML = z === 'login'
    ? '<b>⚠ Abgemeldet</b><span>Bitte die Seite neu laden und anmelden.</span>'
    : '<b>⚠ Keine Verbindung zum Server</b><span>Es wird alle 5 Sekunden erneut versucht.</span>';
  if (z === 'netz' && !netzTimer) netzTimer = setInterval(() => api({ a: 'ping' }).catch(() => {}), 5000);
}
function fehler(e) { alert(e.message || e); }

// ---------- Zahlen und Zeiten ----------

const zahl = (v, st) => v === null || v === undefined || Number.isNaN(+v) ? '–'
  : (+v).toLocaleString('de-DE', { minimumFractionDigits: st ?? 0, maximumFractionDigits: st ?? (Math.abs(v) >= 100 ? 0 : Math.abs(v) >= 10 ? 1 : 2) });
const wert = (v, r) => v === null || v === undefined ? '–'
  : r.typ === 'bool' ? (v === 0 || v === 1 ? (v ? 'Ein' : 'Aus') : zahl(v * 100, 0) + ' % Ein')
  : zahl(v, r.stellen ?? undefined) + (r.einheit ? ' ' + r.einheit : '');
const zw = n => String(n).padStart(2, '0');
const zeit = (ms, sek) => { const d = new Date(ms); return `${zw(d.getDate())}.${zw(d.getMonth() + 1)}.${d.getFullYear()} ${zw(d.getHours())}:${zw(d.getMinutes())}${sek ? ':' + zw(d.getSeconds()) : ''}`; };
// datetime-local <-> ms (Ortszeit)
const zuFeld = ms => { const d = new Date(ms); return `${d.getFullYear()}-${zw(d.getMonth() + 1)}-${zw(d.getDate())}T${zw(d.getHours())}:${zw(d.getMinutes())}:${zw(d.getSeconds())}`; };
const ausFeld = s => s ? new Date(s).getTime() : NaN;
const dauerText = ms => ms >= 86400000 ? zahl(ms / 86400000, ms % 86400000 ? 1 : 0) + ' Tage' : ms >= 3600000 ? zahl(ms / 3600000, ms % 3600000 ? 1 : 0) + ' h' : zahl(ms / 60000) + ' min';

// ---------- Diagramme (uPlot) ----------

const FARBEN = ['#2f6fdb', '#e0702a', '#2e9e5b', '#c0392b', '#8e44ad', '#16a2b8', '#b8860b', '#d63384', '#6c757d', '#20c997', '#795548', '#5c7cfa'];
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();

// Zeitachse auf Deutsch: Uhrzeit, Tag, Monat je nach Abstand der Striche
function zeitachse(u, splits, ai, space, incr) {
  return splits.map(s => {
    const d = new Date(s * 1000);
    if (incr < 86400) return d.getHours() === 0 && d.getMinutes() === 0 ? `${zw(d.getDate())}.${zw(d.getMonth() + 1)}.` : `${zw(d.getHours())}:${zw(d.getMinutes())}`;
    if (incr < 28 * 86400) return `${zw(d.getDate())}.${zw(d.getMonth() + 1)}.`;
    return `${zw(d.getMonth() + 1)}.${d.getFullYear()}`;
  });
}

// Umschalt-Taste beim Ziehen im Diagramm (beim Drücken oder Loslassen der Maus gehalten) = markieren statt zoomen
let umschaltZiehen = false;
document.addEventListener('mousedown', e => { umschaltZiehen = e.shiftKey; }, true);
document.addEventListener('mouseup', e => { if (e.shiftKey) umschaltZiehen = true; }, true);

// Diagramm zeichnen. reihen = Antwort der API "daten" (je Reihe optional art: linie/balken/flaeche/stufen, farbe, achse).
// opt: hoehe, stapeln, zoom(von, bis), zurueck(), markieren(von, bis), mark()
function diagramm(el, reihen, opt = {}) {
  el._plot?.destroy();
  el.innerHTML = '';
  if (!reihen.length || reihen.every(r => !r.x.length)) { el.innerHTML = '<p class="leer">Keine Werte in diesem Zeitraum.</p>'; return null; }
  const eine = reihen.length === 1;
  let data;
  // Zeit in der Legende: bei zusammengefassten Werten nur so genau wie das Raster
  const zeitText = v => {
    const d = new Date(v * 1000), ra = opt.raster;
    if (ra === 'jahr') return String(d.getFullYear());
    if (ra === 'monat') return `${zw(d.getMonth() + 1)}.${d.getFullYear()}`;
    if (ra === '1440') return `${zw(d.getDate())}.${zw(d.getMonth() + 1)}.${d.getFullYear()}`;
    if (ra === '10080') { const a = new Date(d.getTime() - 3.5 * 86400000); return `Woche ab ${zw(a.getDate())}.${zw(a.getMonth() + 1)}.${a.getFullYear()}`; }
    if (ra) { const a = new Date(d.getTime() - (ra === '15' ? 450000 : 1800000)); return zeit(a.getTime()); }
    return zeit(v * 1000, true);
  };
  const series = [{ label: 'Zeit', value: (u, v) => v == null ? '–' : zeitText(v) }];
  const bands = [];
  const band = eine && !reihen[0].roh && reihen[0].typ !== 'bool' && (reihen[0].art || 'linie') === 'linie';
  if (eine) {
    const r = reihen[0];
    data = [r.x, r.v];
    if (band) data.push(r.min, r.max);
  } else {
    data = uPlot.join(reihen.map(r => [r.x, r.v]));
  }
  // Stapeln: Balken/Flächen derselben Achse aufaddieren; gezeichnet wird von oben nach unten, damit die untere vorn liegt
  const original = data.map(a => a.slice());
  const gestapelt = new Set();
  if (opt.stapeln) {
    for (const achse of ['l', 'r']) {
      const idx = reihen.map((r, i) => i).filter(i => (reihen[i].achse || 'l') === achse && ['balken', 'flaeche'].includes(reihen[i].art));
      if (idx.length < 2) continue;
      const summe = new Array(data[0].length).fill(0);
      idx.forEach(i => {
        data[i + 1] = data[i + 1].map((v, k) => { if (v == null) return summe[k] || null; summe[k] += v; return summe[k]; });
        gestapelt.add(i);
      });
    }
  }
  const rechts = reihen.some(r => r.achse === 'r');
  // kleinster Abstand zweier Zeitpunkte (Balkenbreite, Rand links/rechts)
  let abstand = Infinity;
  for (let k = 1; k < data[0].length; k++) abstand = Math.min(abstand, data[0][k] - data[0][k - 1]);
  const balken = reihen.some(r => r.art === 'balken');
  reihen.forEach((r, i) => {
    const farbe = r.farbe || FARBEN[i % FARBEN.length];
    const art = r.art || (r.typ === 'bool' ? 'stufen' : 'linie');
    const s = {
      label: r.titel + (r.einheit ? ` (${r.einheit})` : ''), stroke: farbe, width: 1.5, scale: r.achse === 'r' ? 'r' : 'y',
      spanGaps: art !== 'balken', value: (u, v, si, k) => wert(k == null ? v : original[i + 1][k], r),
      points: { show: art === 'linie' && (r.punkte ?? (r.roh && !r.zusammen && r.x.length < 600)), size: 4 },
    };
    if (art === 'balken') {
      // mehrere Balken ungestapelt: jeder weitere etwas schmaler, damit alle sichtbar bleiben
      const rang = gestapelt.has(i) ? 0 : reihen.slice(0, i).filter(x => x.art === 'balken').length;
      s.paths = uPlot.paths.bars({ size: [Math.max(0.25, 0.75 - 0.2 * rang), 80] });
      s.fill = farbe + 'cc'; s.width = 1; s.points = { show: false };
    } else if (art === 'flaeche') { s.fill = farbe + (gestapelt.has(i) ? 'aa' : '40'); }
    else if (art === 'stufen') { s.paths = uPlot.paths.stepped({ align: 1 }); s.width = 1.2; if (eine && r.roh && r.typ === 'bool') s.fill = farbe + '22'; }
    series.push(s);
  });
  if (band) {
    series.push({ label: 'Min', stroke: 'transparent', points: { show: false }, value: (u, v) => wert(v, reihen[0]) },
                { label: 'Max', stroke: 'transparent', points: { show: false }, value: (u, v) => wert(v, reihen[0]) });
    bands.push({ series: [3, 2], fill: (reihen[0].farbe || FARBEN[0]) + '30' });
  }
  // gestapelte Reihen rückwärts zeichnen (die Summe zuerst)
  if (gestapelt.size) {
    const g = [...gestapelt];
    const serien = g.map(i => series[i + 1]), daten = g.map(i => data[i + 1]), orig = g.map(i => original[i + 1]);
    g.forEach((i, k) => { series[i + 1] = serien[g.length - 1 - k]; data[i + 1] = daten[g.length - 1 - k]; original[i + 1] = orig[g.length - 1 - k]; });
    // die Wert-Anzeige zeigt jeweils den eigenen (nicht aufsummierten) Wert
    g.forEach((i, k) => { const r = reihen[g[g.length - 1 - k]]; series[i + 1].value = (u, v, si, j) => wert(j == null ? v : original[i + 1][j], r); });
  }
  const achse = { stroke: css('--leise'), grid: { stroke: css('--linie'), width: 1 }, ticks: { stroke: css('--linie'), width: 1 } };
  const einheit = a => [...new Set(reihen.filter(r => (r.achse === 'r') === a).map(r => r.einheit).filter(Boolean))].join(', ');
  const axes = [{ ...achse, values: zeitachse, space: 70 },
    { ...achse, scale: 'y', size: 60, label: einheit(false) || null, labelSize: 18, values: (u, v) => v.map(x => zahl(x)) }];
  if (rechts) axes.push({ ...achse, scale: 'r', side: 1, size: 60, grid: { show: false }, label: einheit(true) || null, labelSize: 18, values: (u, v) => v.map(x => zahl(x)) });
  const boolSkala = r => r.typ === 'bool' && !r.zusammen;
  const scales = { x: { time: true } };
  // Balken: halber Abstand Rand links/rechts; Balken und Flächen beginnen bei 0
  if (balken && Number.isFinite(abstand)) scales.x.range = (u, min, max) => [min - abstand / 2, max + abstand / 2];
  const nullAb = a => reihen.some(r => (r.achse === 'r') === a && ['balken', 'flaeche'].includes(r.art));
  const mitNull = (u, min, max) => [Math.min(0, min), max > 0 ? max * 1.05 : max];
  if (reihen.filter(r => r.achse !== 'r').every(boolSkala)) scales.y = { range: [-0.1, 1.1] };
  else if (nullAb(false)) scales.y = { range: mitNull };
  if (rechts && reihen.filter(r => r.achse === 'r').every(boolSkala)) scales.r = { range: [-0.1, 1.1] };
  else if (rechts && nullAb(true)) scales.r = { range: mitNull };
  const plot = new uPlot({
    width: el.clientWidth || 600, height: opt.hoehe || 320, series, bands, axes, scales,
    legend: { live: true },
    cursor: {
      drag: { x: true, y: false, setScale: false },
      bind: { dblclick: () => e => { e.preventDefault(); opt.zurueck?.(); return null; } },
    },
    hooks: {
      setSelect: [u => {
        if (u.select.width < 4) return;
        const von = u.posToVal(u.select.left, 'x') * 1000, bis = u.posToVal(u.select.left + u.select.width, 'x') * 1000;
        u.setSelect({ left: 0, width: 0, top: 0, height: 0 }, false);
        if (umschaltZiehen && opt.markieren) opt.markieren(Math.round(von), Math.round(bis));
        else opt.zoom?.(Math.round(von), Math.round(bis));
      }],
      draw: [u => { // markierter Löschbereich
        const m = opt.mark?.();
        if (!m) return;
        const x0 = u.valToPos(m[0] / 1000, 'x', true), x1 = u.valToPos(m[1] / 1000, 'x', true);
        const ctx = u.ctx;
        ctx.save();
        ctx.fillStyle = 'rgba(220, 40, 40, .18)';
        ctx.strokeStyle = 'rgba(220, 40, 40, .8)';
        ctx.fillRect(x0, u.bbox.top, x1 - x0, u.bbox.height);
        ctx.strokeRect(x0, u.bbox.top, x1 - x0, u.bbox.height);
        ctx.restore();
      }],
    },
  }, data, el);
  el._plot = plot;
  if (!el._ro) { el._ro = new ResizeObserver(() => el._plot?.setSize({ width: el.clientWidth, height: el._plot.height })); el._ro.observe(el); }
  return plot;
}

// Zeitraum-Leiste (Knöpfe, von/bis, zurück) für Reihe und Report
function zeitleiste(box, start, laden) {
  let z = start;
  const verlauf = [];
  const setze = (neu, merken = true) => {
    if (merken) verlauf.push(z);
    z = neu;
    $('#von', box).value = zuFeld(z.von);
    $('#bis', box).value = zuFeld(z.bis);
    $$('#bereiche [data-tage]', box).forEach(b => b.classList.toggle('an', z.tage !== undefined && +b.dataset.tage === z.tage));
    laden(z);
  };
  $$('#bereiche [data-tage]', box).forEach(b => b.addEventListener('click', () => {
    const t = +b.dataset.tage, bis = Date.now();
    setze({ von: bis - t * 86400000, bis, tage: t });
  }));
  $('#anzeigen', box).addEventListener('click', () => {
    const von = ausFeld($('#von', box).value), bis = ausFeld($('#bis', box).value);
    if (!(von < bis)) return alert('Bitte einen gültigen Zeitraum angeben.');
    setze({ von, bis });
  });
  $('#zurueckzoom', box).addEventListener('click', () => { if (verlauf.length) setze(verlauf.pop(), false); });
  setze(z, false);
  return {
    zoom: (von, bis) => setze({ von, bis }),
    zurueck: () => { if (verlauf.length) setze(verlauf.pop(), false); },
    jetzt: () => z,
    neu: () => laden(z),
    ersetze: neu => setze(neu, false),
  };
}

// ---------- Übersicht: Suche und Mini-Diagramme ----------

function uebersicht(tab) {
  const db = tab.dataset.db;
  const suche = $('#suche');
  suche?.addEventListener('input', () => {
    const q = suche.value.trim().toLowerCase();
    $$('tbody', tab).forEach(tb => {
      let sichtbar = 0;
      $$('tr[data-id]', tb).forEach(tr => { const an = !q || tr.dataset.suche.includes(q); tr.hidden = !an; sichtbar += an; });
      tb.hidden = !sichtbar;
    });
  });
  const wahl = $('#sparkzeit');
  if (merk('spark')) wahl.value = merk('spark');
  const laden = async () => {
    merk('spark', wahl.value);
    $$('.spark', tab).forEach(s => s.classList.add('laedt'));
    try {
      const j = await api({ a: 'sparks', db, stunden: +wahl.value });
      $$('.spark', tab).forEach(s => {
        s.classList.remove('laedt');
        const tr = s.closest('tr');
        const von = Date.now() / 1000 - wahl.value * 3600;
        let d = j.daten[s.dataset.spark];
        // Schalter ohne Wechsel im Zeitraum: Linie auf dem letzten Zustand
        if (!d && tr.dataset.typ === 'bool' && tr.dataset.letzt !== '') d = [[von, Date.now() / 1000], [+tr.dataset.letzt, +tr.dataset.letzt]];
        s.innerHTML = sparkSvg(d, tr.dataset.typ === 'bool', von);
        if (tr.dataset.farbe) s.style.color = tr.dataset.farbe;
      });
    } catch (e) { $$('.spark', tab).forEach(s => { s.classList.remove('laedt'); s.textContent = '–'; }); }
  };
  wahl.addEventListener('change', laden);
  laden();
}
function sparkSvg(d, bool, von) {
  const B = 130, H = 28;
  if (!d || !d[0].length) return '<span class="leise klein">keine Werte</span>';
  const [x, y] = d;
  let min = Math.min(...y), max = Math.max(...y);
  if (bool) { min = 0; max = 1; }
  if (max - min < 1e-9) { max += 0.5; min -= 0.5; }
  const bis = Date.now() / 1000;
  const px = t => ((t - von) / (bis - von) * B).toFixed(1), py = v => (H - 2 - (v - min) / (max - min) * (H - 4)).toFixed(1);
  let p = '';
  x.forEach((t, i) => {
    if (bool && i) p += ` ${px(t)},${py(y[i - 1])}`;
    p += ` ${px(t)},${py(y[i])}`;
  });
  const titel = bool ? '' : `min ${zahl(Math.min(...y))} · max ${zahl(Math.max(...y))}`;
  return `<svg viewBox="0 0 ${B} ${H}" preserveAspectRatio="none" aria-hidden="true"><title>${titel}</title><polyline points="${p.trim()}" fill="none" stroke="currentColor" stroke-width="1.3" vector-effect="non-scaling-stroke"/></svg>`;
}

// ---------- Seite einer Reihe ----------

function reiheSeite(box) {
  const db = box.dataset.db, id = +box.dataset.id, admin = box.dataset.admin === '1';
  const el = $('#diagramm');
  let mark = null;
  const info = $('#diagramminfo');
  const lvon = $('#lvon'), lbis = $('#lbis');
  const markSetzen = (von, bis) => {
    mark = von && bis ? [von, bis] : null;
    if (lvon) { lvon.value = mark ? zuFeld(von) : ''; lbis.value = mark ? zuFeld(bis) : ''; }
    $('#lloeschen') && ($('#lloeschen').disabled = true);
    $('#lergebnis') && ($('#lergebnis').textContent = '');
    el._plot?.redraw(false);
  };
  const laden = async z => {
    el.classList.add('laedt');
    try {
      const [d, k] = await Promise.all([
        api({ a: 'daten', reihen: [{ db, id }], von: z.von, bis: z.bis }),
        api({ a: 'kennzahlen', db, id, von: z.von, bis: z.bis }),
      ]);
      const r = d.reihen[0];
      diagramm(el, d.reihen, { hoehe: 360, zoom: zl.zoom, zurueck: zl.zurueck, markieren: admin ? markSetzen : null, mark: () => mark });
      info.textContent = !r ? '' : r.roh ? `${zahl(r.n)} Einzelwerte`
        : r.typ === 'bool' ? `${zahl(r.n)} Schaltwerte, angezeigt als Anteil „Ein“ je ${dauerText(r.intervall)}`
        : `${zahl(r.n)} Werte, angezeigt als Mittelwert je ${dauerText(r.intervall)} (Fläche: Minimum bis Maximum)`;
      $('#kennzahlen').innerHTML = [['Werte', zahl(k.n)], ['Mittel', wert(k.avg, r || {})], ['Minimum', wert(k.min, r || {})], ['Maximum', wert(k.max, r || {})]]
        .map(([n, v]) => `<div class="kachel"><b>${esc(v)}</b><span>${n}</span></div>`).join('');
      const sp = $('#spruenge');
      sp.innerHTML = k.spruenge.length ? '<tr><th>Zeit</th><th class="rechts">Wert</th><th class="rechts">Sprung</th></tr>' + k.spruenge.map(s =>
        `<tr class="klickbar" data-ts="${s.ts}"><td>${zeit(+s.ts, true)}</td><td class="rechts">${esc(wert(+s.val, r || {}))}</td><td class="rechts">Δ ${zahl(+s.d)}</td></tr>`).join('')
        : '<tr><td class="leise">keine</td></tr>';
    } catch (e) { fehler(e); }
    el.classList.remove('laedt');
  };
  const tage = +(merk('tage-reihe') || 7);
  const zl = zeitleiste(box, { von: Date.now() - tage * 86400000, bis: Date.now(), tage }, laden);
  $$('#bereiche [data-tage]', box).forEach(b => b.addEventListener('click', () => merk('tage-reihe', b.dataset.tage)));
  $('#spruenge').addEventListener('click', e => {
    const tr = e.target.closest('tr[data-ts]');
    if (!tr) return;
    const z = zl.jetzt(), ts = +tr.dataset.ts, halb = Math.max(1800000, (z.bis - z.von) / 40);
    zl.zoom(ts - halb, ts + halb);
  });
  if (!admin || !lvon) return;
  // Löschen: Bereich markieren (Umschalt + Ziehen) oder von/bis eintippen, prüfen, löschen
  [lvon, lbis].forEach(f => f.addEventListener('change', () => {
    const a = ausFeld(lvon.value), b = ausFeld(lbis.value);
    mark = a < b ? [a, b] : null;
    $('#lloeschen').disabled = true;
    el._plot?.redraw(false);
  }));
  const daten = () => ({ db, id, von: ausFeld(lvon.value), bis: ausFeld(lbis.value), unter: $('#lunter')?.value ?? '', ueber: $('#lueber')?.value ?? '', grund: $('#lgrund').value });
  $('#lpruefen').addEventListener('click', async () => {
    const d = daten();
    if (!(d.von < d.bis)) return alert('Bitte zuerst einen Bereich markieren (Umschalt + Ziehen im Diagramm) oder von/bis eintragen.');
    try {
      const j = await api({ a: 'loeschen_pruefen', ...d });
      $('#lergebnis').innerHTML = `<b>${zahl(j.n)} Werte</b> würden gelöscht` + (j.beispiele.length ? ': ' + j.beispiele.map(b => `${zeit(+b.ts, true)} = ${zahl(+b.val)}`).join(', ') + (j.n > j.beispiele.length ? ' …' : '') : '.');
      $('#lloeschen').disabled = !j.n;
      $('#lloeschen').dataset.n = j.n;
    } catch (e) { fehler(e); }
  });
  $('#lloeschen').addEventListener('click', async () => {
    const d = daten();
    if (!confirm(`${zahl(+$('#lloeschen').dataset.n)} Werte löschen? Sie bleiben 30 Tage im Papierkorb.`)) return;
    try {
      const j = await api({ a: 'loeschen', ...d });
      markSetzen(null, null);
      $('#lergebnis').textContent = `${zahl(j.geloescht)} Werte gelöscht (im Papierkorb).`;
      zl.neu();
    } catch (e) { fehler(e); }
  });
  $('#lleeren').addEventListener('click', () => markSetzen(null, null));
}

// ---------- Report ----------

function reportSeite(box) {
  const els = $$('.diagramm[data-diagramm]', box);
  const json = (t, v) => { try { return JSON.parse(t || '') ?? v; } catch (e) { return v; } };
  const laden = async z => {
    await Promise.all(els.map(async el => {
      const reihen = json(el.dataset.diagramm, []);
      const o = json(el.dataset.optionen, {});
      if (!reihen.length) { el.innerHTML = '<p class="leer">Keine Reihen gewählt.</p>'; return; }
      el.classList.add('laedt');
      try {
        const d = await api({ a: 'daten', reihen, von: z.von, bis: z.bis, punkte: 1000, zusammen: o.raster ? { raster: o.raster } : null });
        diagramm(el, d.reihen, { hoehe: +el.dataset.hoehe || 260, stapeln: !!o.stapeln, raster: o.raster || '', zoom: zl.zoom, zurueck: zl.zurueck });
      } catch (e) { el.innerHTML = `<p class="leer">${esc(e.message)}</p>`; }
      el.classList.remove('laedt');
    }));
  };
  const tage = +box.dataset.tage || 7;
  const zl = zeitleiste(box, { von: Date.now() - tage * 86400000, bis: Date.now(), tage }, laden);
  // Anzeigen (aktueller Wert) laden und jede Minute erneuern
  const anz = $$('.anzeige[data-anzeige]', box);
  const anzeigenLaden = async () => {
    if (!anz.length) return;
    try {
      const j = await api({ a: 'aktuell', reihen: anz.map(el => json(el.dataset.anzeige, [])[0]).filter(Boolean) });
      anz.forEach(el => {
        const r0 = json(el.dataset.anzeige, [])[0];
        const r = r0 && j.reihen.find(x => x.db === r0.db && x.id === r0.id);
        el.innerHTML = r ? anzeigeSvg(r, json(el.dataset.optionen, {}), el.dataset.titel) : '<p class="leer">Reihe nicht gefunden.</p>';
        const w = $('.anzeigewert', el);
        if (w) w.style.color = w.dataset.farbe;
      });
    } catch (e) { /* nächster Versuch in einer Minute */ }
  };
  anzeigenLaden();
  setInterval(anzeigenLaden, 60000);
  // Diagramme alle 5 Minuten neu laden, wenn ein fester Zeitraum bis "jetzt" gewählt ist
  setInterval(() => {
    const z = zl.jetzt();
    if (z.tage !== undefined && !document.hidden) { const bis = Date.now(); zl.ersetze({ von: bis - z.tage * 86400000, bis, tage: z.tage }); }
  }, 300000);
}

// Rundinstrument: Halbkreis von "von" bis "bis", Farbbereiche (f1 bis g1, f2 bis g2, f3 darüber), Zeiger, Wert
function anzeigeSvg(r, o, titel) {
  const von = +o.von, bis = +o.bis;
  const leer = x => x === null || x === '' || x === undefined;
  const g1 = leer(o.g1) ? null : +o.g1, g2 = leer(o.g2) ? null : +o.g2;
  const W = 220, cx = 110, cy = 112, R = 86;
  const anteil = v => Math.max(0, Math.min(1, (v - von) / (bis - von)));
  const punkt = (a, rad) => [cx - rad * Math.cos(Math.PI * a), cy - rad * Math.sin(Math.PI * a)];
  const bogen = (a0, a1, farbe) => {
    if (a1 <= a0) return '';
    const [x0, y0] = punkt(a0, R), [x1, y1] = punkt(a1, R);
    return `<path d="M${x0.toFixed(1)} ${y0.toFixed(1)} A${R} ${R} 0 0 1 ${x1.toFixed(1)} ${y1.toFixed(1)}" stroke="${esc(farbe)}" stroke-width="16" fill="none"/>`;
  };
  const b1 = g1 === null ? 0 : anteil(g1), b2 = Math.max(b1, g2 === null ? 1 : anteil(g2));
  const zonen = bogen(0, b1, o.f1) + bogen(b1, b2, o.f2) + bogen(b2, 1, o.f3);
  const v = r.wert;
  const farbe = v === null ? css('--leise') : (g1 !== null && v < g1) ? o.f1 : (g2 !== null && v > g2) ? o.f3 : o.f2;
  let zeiger = '';
  if (v !== null) {
    const [zx, zy] = punkt(anteil(v), R - 4);
    zeiger = `<line x1="${cx}" y1="${cy}" x2="${zx.toFixed(1)}" y2="${zy.toFixed(1)}" stroke="currentColor" stroke-width="3" stroke-linecap="round"/><circle cx="${cx}" cy="${cy}" r="6" fill="currentColor"/>`;
  }
  const alt = r.ts && Date.now() - r.ts > 6 * 3600000;
  const st = r.stellen ?? undefined;
  return `<div class="anzeigetitel">${esc(titel || r.titel)}</div>
    <svg viewBox="0 0 ${W} 132" class="gauge" role="img" aria-label="${esc((titel || r.titel) + ': ' + wert(v, r))}">
      ${zonen}${zeiger}
      <text x="${punkt(0, R)[0]}" y="129" text-anchor="middle" class="gskala">${esc(zahl(von))}</text>
      <text x="${punkt(1, R)[0]}" y="129" text-anchor="middle" class="gskala">${esc(zahl(bis))}</text>
    </svg>
    <div class="anzeigewert" data-farbe="${esc(farbe)}">${esc(wert(v, r))}</div>
    <div class="leise klein${alt ? ' alt' : ''}">${r.ts ? (alt ? '⚠ ' : '') + zeit(r.ts) : 'keine Werte'}${r.min24 !== null ? ` · 24 h: ${esc(zahl(r.min24, st))} – ${esc(zahl(r.max24, st))}` : ''}</div>`;
}

// ---------- Stufen-Editor (Bearbeiten-Fenster und Vorlagen) ----------

// Liefert {el, wert(), sperren(bool), setze(stufen)}; opt.bool = nur Methoden für Ja/Nein, opt.vorschau(stufe)
function stufenEditor(stufen, opt = {}) {
  const el = document.createElement('div');
  el.className = 'stufeneditor';
  let gesperrt = false;
  const methoden = Object.entries(K.methoden).filter(([, m]) => !opt.bool || m[3]);
  const zeile = s => {
    const z = document.createElement('div');
    z.className = 'stufe';
    z.innerHTML = `<label class="inline">ab <input type="number" class="ab kurz" min="1" max="36500" value="${s.ab ?? 90}"> Tagen</label>
      <select class="m">${methoden.map(([k, m]) => `<option value="${k}"${k === s.m ? ' selected' : ''}>${esc(m[0])}</option>`).join('')}</select>
      <select class="r">${Object.entries(K.raster).map(([k, n]) => `<option value="${k}"${k === String(s.r ?? '60') ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>
      <label class="inline s-feld"><span>Schwelle</span> <input type="text" inputmode="decimal" class="s kurz" value="${s.s ?? 0.5}"></label>
      ${opt.vorschau ? '<button type="button" class="knopf klein vs">Vorschau</button>' : ''}
      <button type="button" class="knopf klein gefahr weg" title="Stufe entfernen">✕</button>`;
    const sicht = () => {
      const m = K.methoden[$('.m', z).value];
      $('.r', z).hidden = !m[1];
      $('.s-feld', z).hidden = !m[2];
      $('.s-feld span', z).textContent = $('.m', z).value === 'wende' ? 'Toleranz ±' : 'Schwelle';
    };
    $('.m', z).addEventListener('change', sicht);
    $('.weg', z).addEventListener('click', () => { z.remove(); leer(); });
    $('.vs', z)?.addEventListener('click', () => opt.vorschau(lies(z)));
    sicht();
    return z;
  };
  const lies = z => ({ ab: +$('.ab', z).value, m: $('.m', z).value, r: $('.r', z).value, s: String($('.s', z).value).replace(',', '.') });
  const liste = document.createElement('div');
  const hinweis = document.createElement('p');
  hinweis.className = 'leise klein';
  const leer = () => { hinweis.textContent = liste.children.length ? '' : 'Keine Stufen: alle Werte bleiben unverändert erhalten.'; };
  const plus = document.createElement('button');
  plus.type = 'button'; plus.className = 'knopf klein'; plus.textContent = '+ Stufe';
  plus.addEventListener('click', () => {
    const alle = [...liste.children].map(lies);
    const letzte = alle[alle.length - 1];
    liste.append(zeile({ ab: letzte ? letzte.ab * 4 : 90, m: opt.bool ? 'wechsel' : 'mittel', r: '60' }));
    leer();
  });
  el.append(liste, hinweis, plus);
  const setze = st => { liste.innerHTML = ''; (st || []).forEach(s => liste.append(zeile(s))); leer(); sperren(gesperrt); };
  const sperren = an => {
    gesperrt = an;
    $$('input, select, .weg', liste).forEach(x => { x.disabled = an; });
    plus.hidden = an;
  };
  setze(stufen);
  return { el, setze, sperren, wert: () => [...liste.children].map(lies).sort((a, b) => a.ab - b.ab) };
}

// Stufen in die Form bringen, die auch der Server speichert (zum Vergleichen)
const stufenNorm = l => JSON.stringify((l || []).map(s => {
  const m = K.methoden[s.m] || [];
  const x = { ab: +s.ab, m: s.m };
  if (m[1]) x.r = String(s.r ?? '60');
  if (m[2]) x.s = +String(s.s ?? 0).replace(',', '.');
  return x;
}).sort((a, b) => a.ab - b.ab));

// ---------- Bearbeiten-Fenster einer Reihe ----------

async function bearbeiten(db, id) {
  let j;
  try { j = await api({ a: 'reihe_laden', db, id }); } catch (e) { return fehler(e); }
  const r = j.reihe;
  const bool = r.typ === 'bool';
  const eigene = r.id >= 1000000;
  let d = $('#bdialog');
  if (!d) { d = document.createElement('dialog'); d.id = 'bdialog'; d.className = 'dialog'; document.body.append(d); }
  d.innerHTML = `<form method="dialog" class="bform">
    <div class="zeile eng"><h2>${esc(r.titel)}</h2><span class="platz"></span><button class="knopf klein" value="abbrechen" title="Schließen (Esc)">✕</button></div>
    <p class="leise technisch">${esc(r.name)}${j.quelle ? ' · berechnet aus „' + esc(j.quelle) + '“: ' + esc(K.funktionen[r.funktion] || '') + ' je ' + esc(K.abl_raster[r.raster] || '') : ''}</p>
    <div class="felder">
      <label>Anzeigename<input name="anzeige" value="${esc(r.anzeige)}" placeholder="${esc(r.titel)}"></label>
      <label>Einheit<input name="einheit" value="${esc(r.einheit)}" placeholder="z. B. °C" class="kurz"></label>
      <label>Gruppe<input name="gruppe" value="${esc(r.gruppe)}" list="gruppenliste" placeholder="z. B. Heizung"></label>
      ${bool ? '' : `<label>Nachkommastellen<input type="number" name="stellen" min="0" max="6" value="${r.stellen ?? ''}" placeholder="auto" class="kurz"></label>`}
      <label>Warnung, wenn keine neuen Werte seit<span class="zeile eng"><input type="number" name="warn_std" min="0" max="8760" value="${r.warn_std ?? ''}" placeholder="aus" class="kurz"> Stunden</span></label>
      <label>Farbe in Diagrammen<span class="zeile eng"><label class="haken"><input type="checkbox" class="farbe-an"${r.farbe ? ' checked' : ''}> eigene Farbe</label>
        <input type="color" class="farbe-wahl" value="${esc(r.farbe || '#2f6fdb')}" aria-label="Farbe"></span></label>
    </div>
    <datalist id="gruppenliste">${j.gruppen.map(g => `<option value="${esc(g)}">`).join('')}</datalist>
    <h3>Verdichtung</h3>
    <label class="inline">Vorlage <select name="vorlage"><option value="0">– eigene Stufen –</option>${j.vorlagen.map(v => `<option value="${v.id}"${v.id === r.vorlage ? ' selected' : ''}>${esc(v.name)}</option>`).join('')}</select></label>
    <p class="vorlagehinweis klein" hidden></p>
    <div class="stufenplatz"></div>
    <div class="vorschau" hidden>
      <div class="zeile eng"><b class="vtitel">Vorschau</b><span class="platz"></span>
        <label class="inline klein">Ausschnitt ab <input type="date" class="vtag"></label>
        <select class="vlang klein"><option value="0.25">6 Stunden</option><option value="1">1 Tag</option><option value="2" selected>2 Tage</option><option value="7">7 Tage</option></select></div>
      <div class="vdiagramm diagramm"></div>
      <p class="vtext klein"></p>
    </div>
    ${!bool && !eigene ? `<details class="unter"><summary>Eigene Reihe daraus berechnen (z. B. Monatssumme)</summary>
      ${j.abgeleitete.length ? '<ul class="klein">' + j.abgeleitete.map(a => `<li>${esc(a.titel)} <span class="leise">(${esc(a.funktion)} je ${esc(a.raster)})</span></li>`).join('') + '</ul>' : ''}
      <div class="zeile eng">
        <select class="afunk">${Object.entries(K.funktionen).map(([k, n]) => `<option value="${k}">${esc(n)}</option>`).join('')}</select>
        je <select class="araster">${Object.entries(K.abl_raster).map(([k, n]) => `<option value="${k}"${k === 'monat' ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select>
        <input class="aname" placeholder="Name (optional)">
        <button type="button" class="knopf klein aneu">Anlegen</button></div>
      <p class="leise klein">Die neue Reihe steht in der Tabelle <code>sm_ts</code> (Grafana: Namen in <code>sm_reihen</code>) und wird bei jedem Lauf fortgeschrieben.</p>
    </details>` : ''}
    ${eigene ? '<p><button type="button" class="knopf klein gefahr aweg">Diese eigene Reihe löschen</button></p>' : ''}
    <div class="zeile"><button class="knopf haupt" value="speichern">Speichern</button><button class="knopf" value="abbrechen">Abbrechen</button></div>
  </form>`;
  const f = $('form', d);
  const vorlagen = Object.fromEntries(j.vorlagen.map(v => [v.id, v.stufen]));
  const vorlageInfo = Object.fromEntries(j.vorlagen.map(v => [v.id, v]));
  const vorschauBox = $('.vorschau', d);
  let letzteStufe = null;
  const vorschau = async s => {
    letzteStufe = s;
    vorschauBox.hidden = false;
    const tag = $('.vtag', d).value, lang = +$('.vlang', d).value * 86400000;
    const von = tag ? new Date(tag + 'T00:00').getTime() : Date.now() - lang;
    $('.vtitel', d).textContent = 'Vorschau: ' + (K.methoden[s.m]?.[0] || '');
    $('.vtext', d).textContent = 'Lädt …';
    try {
      const v = await api({ a: 'vorschau', db, id, stufe: s, von, bis: von + lang });
      const reihen = [
        { titel: 'Original', einheit: r.einheit, typ: r.typ, stellen: r.stellen, roh: true, x: v.original[0], v: v.original[1], farbe: css('--leise'), punkte: false },
        { titel: 'Verdichtet', einheit: r.einheit, typ: r.typ, stellen: r.stellen, roh: true, x: v.ergebnis[0], v: v.ergebnis[1], farbe: FARBEN[1], punkte: v.nachher < 800 },
      ];
      diagramm($('.vdiagramm', d), reihen, { hoehe: 240 });
      $('.vtext', d).innerHTML = `Im Ausschnitt: <b>${zahl(v.vorher)} → ${zahl(v.nachher)} Werte</b>` + (v.vorher ? ` (${zahl(v.nachher / v.vorher * 100, 1)} %)` : '')
        + `. Älter als ${s.ab} Tage sind jetzt ${zahl(v.betroffen)} Werte (bereits verdichtete zählen mit).`
        + (v.vorher ? '' : ' <span class="leise">Im Ausschnitt gibt es keine Werte – oben einen anderen Tag wählen.</span>');
    } catch (e) { $('.vtext', d).textContent = e.message; }
  };
  $('.vtag', d).addEventListener('change', () => letzteStufe && vorschau(letzteStufe));
  $('.vlang', d).addEventListener('change', () => letzteStufe && vorschau(letzteStufe));
  const ed = stufenEditor(r.vorlage ? vorlagen[r.vorlage] : r.stufen_eigen, { bool, vorschau });
  $('.stufenplatz', d).append(ed.el);
  const vw = $('[name=vorlage]', d);
  // Mit Vorlage: die Stufen der Vorlage lassen sich hier direkt ändern – das gilt dann für alle Reihen mit dieser Vorlage.
  // "– eigene Stufen –" übernimmt die angezeigten Stufen nur für diese Reihe.
  const vorlageSicht = neu => {
    const v = vorlageInfo[vw.value];
    if (v && neu) ed.setze(vorlagen[vw.value]);
    const h = $('.vorlagehinweis', d);
    h.hidden = !v;
    if (v) h.textContent = `Änderungen an diesen Stufen ändern die Vorlage „${v.name}“ – sie gelten dann für alle ${v.n + (r.vorlage === v.id ? 0 : 1)} Reihen mit dieser Vorlage. Nur für diese Reihe: oben „– eigene Stufen –“ wählen.`;
  };
  vw.addEventListener('change', () => vorlageSicht(true));
  vorlageSicht(false);
  $('.aneu', d)?.addEventListener('click', async () => {
    try {
      const a = await api({ a: 'abgeleitet_anlegen', db, quelle: id, funktion: $('.afunk', d).value, raster: $('.araster', d).value, anzeige: $('.aname', d).value });
      alert(`Neue Reihe angelegt, ${zahl(a.werte)} Werte berechnet.`);
      location.reload();
    } catch (e) { fehler(e); }
  });
  $('.aweg', d)?.addEventListener('click', async () => {
    if (!confirm('Diese eigene Reihe mit allen Werten löschen?')) return;
    try { await api({ a: 'abgeleitet_loeschen', db, id }); location.href = '?s=start'; } catch (e) { fehler(e); }
  });
  f.addEventListener('submit', async e => {
    if (e.submitter?.value !== 'speichern') return;
    e.preventDefault();
    const w = Object.fromEntries(new FormData(f));
    const v = vorlageInfo[w.vorlage];
    const stufen = ed.wert();
    const aendern = !!v && stufenNorm(stufen) !== stufenNorm(vorlagen[w.vorlage]);
    if (aendern && !confirm(`Die Vorlage „${v.name}“ wird geändert. Das gilt für alle Reihen mit dieser Vorlage (${v.n + (r.vorlage === v.id ? 0 : 1)}). Fortfahren?`)) return;
    try {
      await api({ a: 'reihe_speichern', db, id, ...w, vorlage: +w.vorlage, stufen, vorlage_aendern: aendern,
        farbe: $('.farbe-an', d).checked ? $('.farbe-wahl', d).value : '' });
      location.reload();
    } catch (err) { fehler(err); }
  });
  $('.farbe-wahl', d).addEventListener('input', () => { $('.farbe-an', d).checked = true; });
  d.showModal();
}

// ---------- Start ----------

document.addEventListener('DOMContentLoaded', () => {
  try { K = JSON.parse($('#konstanten')?.textContent || '{}'); } catch (e) {}
  $('#thema')?.addEventListener('click', () => {
    const jetzt = document.documentElement.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    const neu = jetzt === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = neu;
    merk('thema', neu);
    $$('.diagramm').forEach(el => el._plot?.redraw()); // Farben der Achsen gelten erst nach Neuladen der Daten
  });
  document.addEventListener('click', e => {
    const c = e.target.closest('[data-confirm]');
    if (c && !c.dataset.lauf && !confirm(c.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); return; }
    if (e.target.closest('[data-print]')) window.print();
    const ed = e.target.closest('[data-edit]');
    if (ed) bearbeiten(($('#reihen') || $('#reihe')).dataset.db, +ed.dataset.edit);
    // Menüs schließen, wenn daneben geklickt wird
    $$('details.menue[open], details.aufklapp[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; });
  }, true);
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') $$('details.menue[open], details.aufklapp[open]').forEach(d => { d.open = false; });
  });
  const tab = $('#reihen');
  if (tab) uebersicht(tab);
  const rb = $('#reihe');
  if (rb) reiheSeite(rb);
  const rp = $('#report');
  if (rp) reportSeite(rp);
  // Stufen-Editor in den Vorlagen-Formularen (Seite Verdichtung)
  $$('form.stufenform').forEach(f => {
    const box = $('.stufen', f);
    const ed = stufenEditor(JSON.parse(box.dataset.stufen || '[]'));
    box.append(ed.el);
    f.addEventListener('submit', () => { $('[name=stufen]', f).value = JSON.stringify(ed.wert()); });
  });
  // Reihen-Auswahl im Diagramm-Formular filtern
  $$('.auswahlsuche').forEach(s => s.addEventListener('input', () => {
    const q = s.value.trim().toLowerCase();
    $$('.auswahlzeile', s.form).forEach(z => { z.hidden = !!q && !z.dataset.suche.includes(q) && !$('input', z).checked; });
  }));
  // Verdichtung von Hand
  $$('[data-lauf]').forEach(b => b.addEventListener('click', async () => {
    if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
    const aus = $('#laufausgabe');
    aus.hidden = false;
    aus.textContent = 'Läuft … (höchstens so lange wie das Zeitlimit)';
    $$('[data-lauf]').forEach(x => { x.disabled = true; });
    try {
      const j = await api({ a: 'lauf', probe: b.dataset.lauf === 'probe' });
      aus.textContent = j.text + (j.log.length ? '\n\n' + j.log.join('\n') : '\n\nNichts zu tun.');
    } catch (e) { aus.textContent = 'Fehler: ' + e.message; }
    $$('[data-lauf]').forEach(x => { x.disabled = false; });
  }));
});
