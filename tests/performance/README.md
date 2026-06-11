# Performance-Benchmarks

Modified by BW-Tech GmbH.

Dieses Verzeichnis enthaelt reproduzierbare Benchmarks fuer ownCloud.online.
Der Benchmark misst:

- `status.php`-Latenz
- authentifizierte Capabilities-API
- parallele Uploads kleiner Dateien
- Upload und Download einer groesseren Datei
- WebDAV-`PROPFIND` einer gefuellten Ordnerstruktur

Der Test legt einen eindeutigen Ordner im Benutzerkonto an und entfernt ihn
anschliessend. Zugangsdaten werden nur ueber Umgebungsvariablen oder eine
verdeckte Passwortabfrage uebergeben.

## Schnelltest

```bash
export OC_BASE_URL=http://127.0.0.1:8088
export OC_USERNAME=performance
export OC_PASSWORD='lokales-testpasswort'

python3 tests/performance/benchmark.py \
  --small-files 100 \
  --large-size 16777216 \
  --concurrency 4 \
  --output build/performance/baseline.json
```

## Produktionsnaher Test

Nur gegen eine isolierte Staging-Instanz ausfuehren:

```bash
python3 tests/performance/benchmark.py \
  --latency-requests 50 \
  --small-files 10000 \
  --small-size 4096 \
  --large-size 536870912 \
  --concurrency 8 \
  --output build/performance/staging.json
```

Fuer belastbare Vergleiche muessen Server, Datenbestand, Client, Netzwerk und
Parameter identisch sein. Ergebnisse einer SQLite-Entwicklungsinstanz duerfen
nicht mit MariaDB-/Redis-Produktionswerten verglichen werden.

## Sicherheitsregeln

- Kein produktives Benutzerkonto verwenden.
- Passwort niemals in Git oder JSON-Berichte schreiben.
- Vor grossen Tests Datenbank- und Datenverzeichnis-Backup erstellen.
- Zusätzliche Datenbankindizes erst nach `EXPLAIN` und Vorher-Nachher-Test
  ausrollen.
