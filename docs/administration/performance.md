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

owncloud.online-Konfiguration prüfen:

```bash
sudo -u www-data php8.4 occ config:system:get dbtype
sudo -u www-data php8.4 occ config:system:get memcache.local
sudo -u www-data php8.4 occ config:system:get memcache.locking
sudo -u www-data php8.4 occ background:queue:status
```

## OPcache-Dimensionierung

Der Standardwert `opcache.max_accelerated_files=10000` reicht für
owncloud.online nicht: die Installation umfasst inklusive aller Apps und
Vendor-Bibliotheken über 14.000 PHP-Dateien. Wird das Limit überschritten,
verdrängt OPcache Einträge und kompiliert unter wechselnder Last laufend Dateien
neu. Dateizahl prüfen und das Limit darüber setzen:

```bash
find /pfad/zum/webroot -name '*.php' | wc -l
```

Empfohlene `php.ini`- bzw. FPM-Pool-Werte:

```ini
opcache.enable=1
opcache.memory_consumption=256      ; MB, 512 bei sehr vielen Apps
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=24000 ; muss die Dateizahl übersteigen
opcache.validate_timestamps=0       ; nur mit OPcache-Reset im Deploy
opcache.enable_cli=0                 ; CLI/Cron braucht keinen persistenten OPcache
```

`validate_timestamps=0` macht Code-Änderungen erst nach FPM-Reload oder
OPcache-Reset sichtbar. Das Deploy-Werkzeug muss den Reset auslösen, sonst laufen
alte Klassen weiter.

## OPcache-Preloading (PHP 8.4)

Aufbauend auf der Dimensionierung oben kann OPcache den stabilen Server-Code
bereits beim FPM-Start kompilieren und verlinken (Preloading). Worker sparen
sich damit das Klassen-Linking pro Request und die Warmup-Phase nach jedem
Reload. Das mitgelieferte Script `build/preload.php` lädt `lib/private`,
`lib/public`, `core/` und den Composer-Vendor-Baum — App-Verzeichnisse bewusst
nicht, da Apps zur Laufzeit (de)aktiviert und aktualisiert werden.

Aktivierung in der FPM-Pool-Konfiguration bzw. `php.ini`:

```ini
opcache.preload=/var/www/owncloud.online/build/preload.php
opcache.preload_user=www-data
```

Hinweise:

- Preloading setzt die Deploy-Disziplin von `validate_timestamps=0` voraus:
  nach jedem Deploy FPM neu laden, sonst läuft alter Code weiter.
- Meldungen wie `Can't preload unlinked class` beim Start sind harmlos — die
  Klassen werden ungelinkt gecacht und beim ersten Zugriff verlinkt.
- Das Script ist fehlertolerant: einzelne nicht kompilierbare Dateien werden
  übersprungen und verhindern den FPM-Start nicht. Ins Error-Log wird eine
  Zeile `owncloud.online preload: compiled N of M files` geschrieben.
- Erst auf einer Staging-Instanz aktivieren und den FPM-Start prüfen
  (`systemctl restart php8.4-fpm && systemctl status php8.4-fpm`).

## Frontend-Auslieferung

Die Weboberfläche lädt JavaScript und CSS als viele einzelne, unkomprimierte
Dateien. Bis diese gebündelt und minifiziert werden, senkt der TLS-Terminator die
wahrgenommene Ladezeit am wirksamsten:

- HTTP/2 aktivieren; das Multiplexing spart die Round-Trips der vielen Einzeldateien
- Keepalive zum PHP-FPM-Upstream
- gzip oder brotli für `text/*`, `application/javascript` und `application/json`

## Grenzen einzelner Server-Optimierungen

Auf einer Instanz mit warmem OPcache bewegt der kompilierte Route-Cache
(`route.cache`) die Antwortzeit messbar nicht: OPcache hält die Routen-Dateien
bereits vor, und der Router baut ohnehin nur die Routen bereits geladener Apps.
Der Route-Cache hilft vor allem bei kaltem oder deaktiviertem OPcache und frisch
gestarteten FPM-Workern.

Der dominierende Kostenblock eines authentifizierten Requests ist die
Wiederholung von Passwort-Hashing (bcrypt) und Session- sowie Filesystem-Setup.
Ein Client, der seine Session-Cookie über Requests hält, erreicht ein Vielfaches
der Geschwindigkeit gegenüber einem Client, der bei jedem Request erneut per
Basic-Auth authentifiziert. Sync- und API-Clients sollten die Session-Cookie
wiederverwenden.

Für den Desktop- und Mobil-Client ist das bcrypt-Problem bereits gelöst: er
authentifiziert mit einem Geräte-Token (`oc_authtoken`, App-Passwort), nicht mit
dem Kontopasswort. `OC\User\Session::checkTokenCredentials()` prüft bcrypt nur,
wenn die `last_check`-Spalte des Tokens älter als `last_check_timeout` (Standard
5 Minuten) ist — nicht bei jedem Request. Ein per Plaintext-Passwort geschlüsselter
Hash-Cache darf NICHT ergänzt werden: er würde Passwort-Material speichern und
Brute-Force-Drosselung, Rate-Limiting sowie Token-Widerruf aushebeln. Der
verbleibende Per-Request-bcrypt betrifft nur Nicht-Token-Clients, die das rohe
Kontopasswort ohne persistente Session senden (z. B. fremde WebDAV-Integrationen).

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
