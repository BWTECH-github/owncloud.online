# Performance

Maintained by BW-Tech GmbH.

Performance-Änderungen werden immer mit identischem Datenbestand, identischer
Hardware und denselben Benchmark-Parametern verglichen. Einzelne Messwerte aus
SQLite-Entwicklungsinstanzen sind keine Produktionsaussage.

## Stufenplan

1. Baseline mit API-, WebDAV-, Upload- und Download-Messungen erfassen.
2. PHP-FPM, OPcache, MariaDB, Redis-Locking, APCu, System-Cron und begrenzte
   Retention auf einer isolierten Staging-Instanz validieren.
3. Einen anonymisierten Produktionsdatenbestand mit mindestens einer Million
   Filecache-Einträgen messen. Erst danach zusätzliche Indizes anhand von
   `EXPLAIN` und Slow-Query-Log bewerten.
4. Desktop-Sync mit unverändertem Testbestand profilieren. Server- und
   Client-Änderungen getrennt messen.
5. Änderungen per Canary-Instanz ausrollen und Fehlerquote, Datenbanklatenz,
   Lock-Wartezeiten, PHP-FPM-Auslastung und Sync-Dauer überwachen.

Jede Stufe hat einen eigenen Vorher-Nachher-Vergleich und eine dokumentierte
Rollback-Möglichkeit.

## Validierte Referenzmessung

Die Stufen 1 bis 3 wurden mit PHP 8.4, Nginx, PHP-FPM, MariaDB, Redis und APCu
auf einer isolierten Instanz geprüft. Der Stufe-3-Datensatz enthielt 1.000
Ordner mit jeweils 1.000 Dateien und damit mehr als eine Million
Filecache-Einträge.

| Messung | SQLite/PHP-Server | MariaDB/Redis/Nginx | Mit 1 Mio. Filecache-Einträgen |
| --- | ---: | ---: | ---: |
| 100 kleine Uploads | 41,07 s | 7,53 s | 7,45 s |
| Upload 16 MiB | 26,12 MiB/s | 34,95 MiB/s | 33,99 MiB/s |
| Download 16 MiB | 42,83 MiB/s | 54,84 MiB/s | 44,62 MiB/s |
| PROPFIND p95 | 350,43 ms | 338,89 ms | 322,47 ms |

`files:cleanup` benötigte mit einer Million gültigen Einträgen und 10.000
verwaisten Einträgen vorher 9,33 Sekunden. Die Storage-basierte Bereinigung
reduzierte den Lauf auf 0,81 Sekunden.

Die Messung rechtfertigt keine zusätzlichen Standardindizes:

- `(storage, path_hash)` bedient exakte Pfadauflösung.
- `(parent, name)` bedient Ordnerlisten inklusive Namenssortierung.
- Ein alleiniger `parent`-Index wäre redundant.
- Core-Sync-Abfragen sortieren nicht global nach `mtime`. Ein zusätzlicher
  `mtime`-Index würde Schreibkosten erhöhen, ohne den gemessenen Sync-Pfad zu
  beschleunigen.
- Suchen mit führendem Platzhalter wie `%begriff%` können keinen normalen
  B-Tree-Index effizient nutzen. Eine Volltextsuche wäre eine eigene,
  datenbankübergreifend zu entwickelnde Funktion.

## Empfohlene Produktionsbasis

- PHP 8.4 FPM mit aktiviertem OPcache
- MariaDB oder MySQL statt SQLite
- Redis für transaktionales File-Locking
- APCu als lokaler Cache
- System-Cron statt AJAX- oder Web-Cron
- ausreichend RAM für Datenbank und PHP-FPM ohne Swap
- niedrige Latenz zwischen Webserver, Datenbank, Redis und Storage

Redis-Verfügbarkeit prüfen:

```bash
redis-cli ping
php8.4 -m | grep -E 'apcu|redis|Zend OPcache'
```

ownCloud.online-Konfiguration prüfen:

```bash
sudo -u www-data php8.4 occ config:system:get dbtype
sudo -u www-data php8.4 occ config:system:get memcache.local
sudo -u www-data php8.4 occ config:system:get memcache.locking
sudo -u www-data php8.4 occ background:queue:status
```

## Retention

Alte Aktivitäten, Papierkorbobjekte und Versionen erhöhen langfristig
Datenbank- und Storage-Last. Die folgenden Werte sind ein Ausgangspunkt für
Installationen ohne abweichende Aufbewahrungspflichten:

```bash
sudo -u www-data php8.4 occ config:system:set \
  activity_expire_days --type integer --value 64

sudo -u www-data php8.4 occ config:system:set \
  trashbin_retention_obligation --value 'auto, 30'

sudo -u www-data php8.4 occ config:system:set \
  versions_retention_obligation --value 'auto, 30'
```

Vor dem Setzen müssen vertragliche und gesetzliche Aufbewahrungsregeln geprüft
werden. Die Werte löschen nicht sofort während des Befehls. Die Bereinigung
erfolgt durch Hintergrundjobs.

Rollback auf die Standardautomatik:

```bash
sudo -u www-data php8.4 occ config:system:delete activity_expire_days
sudo -u www-data php8.4 occ config:system:set \
  trashbin_retention_obligation --value auto
sudo -u www-data php8.4 occ config:system:set \
  versions_retention_obligation --value auto
```

## Filecache-Wartung

Vor Wartungsbefehlen Datenbank und Datenverzeichnis sichern:

```bash
sudo -u www-data php8.4 occ files:cleanup
```

`files:cleanup` entfernt ausschließlich Filecache-Einträge, deren Storage nicht
mehr existiert. Es ersetzt keine Papierkorb- oder Versionsbereinigung.

Tabellengrößen beobachten:

```sql
SELECT COUNT(*) FROM oc_filecache;
SELECT COUNT(*) FROM oc_activity;
SELECT COUNT(*) FROM oc_file_locks;
```

Zusätzliche Indizes nicht blind anlegen. Vorher und nachher müssen
`EXPLAIN`, Schreiblast, Speicherverbrauch und realer Desktop-Sync gemessen
werden. Der vorhandene Index auf `(parent, name)` kann reine
`parent`-Abfragen bereits bedienen.

## Reproduzierbarer Benchmark

Der mitgelieferte Benchmark liegt unter:

```text
tests/performance/benchmark.py
```

Beispiel:

```bash
export OC_BASE_URL=https://staging.example.com
export OC_USERNAME=performance
export OC_PASSWORD='lokales-testpasswort'

python3 tests/performance/benchmark.py \
  --latency-requests 50 \
  --small-files 10000 \
  --large-size 536870912 \
  --concurrency 8 \
  --output build/performance/staging.json
```

Der Testbenutzer darf keine produktiven Daten enthalten. Für einen
Vorher-Nachher-Vergleich müssen Datenmenge, App-Liste, Netzwerklatenz,
Parallelität und Serverkonfiguration unverändert bleiben.
