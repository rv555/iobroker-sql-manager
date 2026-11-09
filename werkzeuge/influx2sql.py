#!/usr/bin/env python3
"""
influx2sql.py – wandelt einen InfluxDB-2-Export (Line Protocol, erzeugt mit
`influxd inspect export-lp`) in SQL für das Schema des ioBroker-SQL-Adapters.

Nur Python-Standardbibliothek, läuft direkt auf dem Raspi.

  Messwerte ansehen:   python3 influx2sql.py --list export.lp.gz
  SQL erzeugen:        python3 influx2sql.py export.lp.gz --before 2026-11-08 > import.sql
  Mit Umbenennung:     python3 influx2sql.py export.lp.gz --map map.csv > import.sql

map.csv (optional, Semikolon-getrennt): influx_name;iobroker_id
Es wird nur das Feld "value" übernommen (so schreibt der ioBroker-Influx-Adapter).
"""
import argparse, gzip, sys, datetime
from collections import Counter

BATCH = 1000          # Zeilen pro INSERT
COMMIT_EVERY = 50     # INSERTs pro Transaktion
TABLES = {0: "ts_number", 1: "ts_string", 2: "ts_bool"}


def open_any(path):
    if path == "-":
        return sys.stdin
    if path.endswith(".gz"):
        return gzip.open(path, "rt", encoding="utf-8", errors="replace")
    return open(path, encoding="utf-8", errors="replace")


def split_unescaped(s, sep):
    """Teilt an sep, beachtet Backslash-Escapes und Strings in Anführungszeichen."""
    parts, cur, inq, i = [], [], False, 0
    while i < len(s):
        ch = s[i]
        if ch == "\\" and i + 1 < len(s):
            cur.append(s[i:i + 2]); i += 2; continue
        if ch == '"':
            inq = not inq
        if ch == sep and not inq:
            parts.append("".join(cur)); cur = []
        else:
            cur.append(ch)
        i += 1
    parts.append("".join(cur))
    return parts


def unescape(s):
    return s.replace("\\,", ",").replace("\\ ", " ").replace("\\=", "=").replace('\\"', '"').replace("\\\\", "\\")


def parse_value(v):
    """Gibt (typ, wert) zurück: 0=Zahl, 1=String, 2=Bool."""
    if v.startswith('"') and v.endswith('"'):
        return 1, unescape(v[1:-1])
    if v in ("t", "T", "true", "True", "TRUE"):
        return 2, 1
    if v in ("f", "F", "false", "False", "FALSE"):
        return 2, 0
    if v[-1:] in ("i", "u"):
        return 0, int(v[:-1])
    return 0, float(v)


def parse_line(line):
    line = line.strip()
    if not line or line.startswith("#"):
        return None
    parts = split_unescaped(line, " ")
    parts = [p for p in parts if p != ""]
    if len(parts) < 3:
        return None
    key, fields, ts = parts[0], parts[1], parts[2]
    measurement = unescape(split_unescaped(key, ",")[0])
    for f in split_unescaped(fields, ","):
        kv = split_unescaped(f, "=")
        if len(kv) < 2:
            continue
        name = unescape(kv[0])
        if name != "value":
            continue
        typ, val = parse_value("=".join(kv[1:]))
        return measurement, typ, val, int(ts) // 1_000_000  # ns -> ms
    return None


def sql_str(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("file", help="Export-Datei (.lp oder .lp.gz, '-' für stdin)")
    ap.add_argument("--list", action="store_true", help="nur Messwerte mit Anzahl auflisten")
    ap.add_argument("--before", help="nur Daten vor diesem Datum (YYYY-MM-DD), um Überlappung mit SQL zu vermeiden")
    ap.add_argument("--map", help="CSV influx_name;iobroker_id zum Umbenennen")
    ap.add_argument("--only", help="nur Messwerte, deren Name diesen Text enthält")
    a = ap.parse_args()

    rename = {}
    if a.map:
        with open(a.map, encoding="utf-8") as fh:
            for row in fh:
                if ";" in row:
                    k, v = row.rstrip("\n").split(";", 1)
                    rename[k.strip()] = v.strip()

    before_ms = None
    if a.before:
        before_ms = int(datetime.datetime.fromisoformat(a.before).timestamp() * 1000)

    if a.list:
        cnt, first, last, types = Counter(), {}, {}, {}
        for line in open_any(a.file):
            p = parse_line(line)
            if not p:
                continue
            m, t, _, ts = p
            cnt[m] += 1; types[m] = t
            first[m] = min(first.get(m, ts), ts); last[m] = max(last.get(m, ts), ts)
        fmt = lambda ms: datetime.datetime.fromtimestamp(ms / 1000).strftime("%Y-%m-%d")
        for m, n in sorted(cnt.items()):
            print(f"{n:>10}  {TABLES[types[m]]:<9}  {fmt(first[m])} … {fmt(last[m])}  {m}")
        print(f"{sum(cnt.values()):>10}  gesamt, {len(cnt)} Messwerte", file=sys.stderr)
        return

    out = sys.stdout
    out.write("-- erzeugt von influx2sql.py\nSET NAMES utf8mb4;\nSET autocommit=0;\n")
    cur_key, buf, n_ins, total = None, [], 0, 0

    def flush():
        nonlocal buf, n_ins
        if not buf:
            return
        table = TABLES[cur_key[1]]
        out.write(f"INSERT IGNORE INTO {table} (id, ts, val, ack, _from, q) VALUES\n")
        out.write(",\n".join(buf) + ";\n")
        buf = []; n_ins += 1
        if n_ins % COMMIT_EVERY == 0:
            out.write("COMMIT;\n")

    for line in open_any(a.file):
        p = parse_line(line)
        if not p:
            continue
        m, typ, val, ts = p
        if a.only and a.only not in m:
            continue
        if before_ms is not None and ts >= before_ms:
            continue
        name = rename.get(m, m)
        key = (name, typ)
        if key != cur_key:
            flush()
            cur_key = key
            out.write(f"INSERT INTO datapoints (name, type) SELECT {sql_str(name)}, {typ} FROM DUAL "
                      f"WHERE NOT EXISTS (SELECT 1 FROM datapoints WHERE name={sql_str(name)});\n")
            out.write(f"SET @id := (SELECT id FROM datapoints WHERE name={sql_str(name)} LIMIT 1);\n")
        v = sql_str(val) if typ == 1 else repr(val)
        buf.append(f"(@id,{ts},{v},1,0,0)")
        total += 1
        if len(buf) >= BATCH:
            flush()
    flush()
    out.write("COMMIT;\n")
    print(f"{total} Werte geschrieben", file=sys.stderr)


if __name__ == "__main__":
    main()
