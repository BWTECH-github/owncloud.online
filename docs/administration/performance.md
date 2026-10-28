# Performance

Gepflegt von der BW-Tech GmbH.

Vergleichen Sie Messwerte vor und nach einer Änderung immer mit identischem
Datenbestand, identischer Hardware und denselben Benchmark-Parametern. Einzelne Messwerte aus
SQLite-Entwicklungsinstanzen sind keine Aussage über den Produktivbetrieb.

## Stufenplan

1. Ausgangswerte mit API-, WebDAV-, Upload- und Download-Messungen erfassen.
2. PHP-FPM, OPcache, MariaDB, Redis-Locking, APCu, System-Cron und begrenzte
   Aufbewahrung (Retention) auf einer isolierten Staging-Instanz prüfen.
3. Einen anonymisierten Produktionsdatenbestand mit mindestens einer Million
   Filecache-Einträgen messen. Erst danach zusätzliche Indizes anhand von
   `EXPLAIN` und Slow-Query-Log bewerten.
4. Den Desktop-Sync mit unverändertem Testbestand profilieren. Server- und
   Client-Änderungen getrennt messen.
5. Änderungen zuerst auf einer Canary-Instanz ausrollen und dabei Fehlerquote,
   Datenbanklatenz, Wartezeiten auf Sperren, Auslastung von PHP-FPM und
   Sync-Dauer überwachen.

Jede Stufe hat einen eigenen Vorher-Nachher-Vergleich und einen dokumentierten
Weg zurück.

## Referenzmessung

Die Stufen 1 bis 3 wurden mit PHP 8.4, nginx, PHP-FPM, MariaDB, Redis und APCu
auf einer isolierten Instanz gemessen. Der Datensatz für Stufe 3 enthielt
1.000 Ordner mit jeweils 1.000 Dateien und damit mehr als eine Million
Filecache-Einträge.

| Messung | SQLite/PHP-Server | MariaDB/Redis/nginx | Mit 1 Mio. Filecache-Einträgen |
| --- | ---: | ---: | ---: |
| 100 kleine Uploads | 41,07 s | 7,53 s | 7,45 s |
| Upload 16 MiB | 26,12 MiB/s | 34,95 MiB/s | 33,99 MiB/s |
| Download 16 MiB | 42,83 MiB/s | 54,84 MiB/s | 44,62 MiB/s |
| PROPFIND p95 | 350,43 ms | 338,89 ms | 322,47 ms |

`files:cleanup` brauchte mit einer Million gültigen und 10.000 verwaisten
Einträgen zunächst 9,33 Sekunden. Seit der Befehl verwaiste Einträge je Speicher
löscht, dauert derselbe Lauf 0,81 Sekunden.

Die Messung rechtfertigt keine zusätzlichen Standardindizes:

- `(storage, path_hash)` bedient die exakte Pfadauflösung.
- `(parent, name)` bedient Ordnerlisten einschließlich der Sortierung nach
  Namen.
- Ein eigener Index nur auf `parent` wäre überflüssig.
- Die Sync-Abfragen des Kerns sortieren nicht global nach `mtime`. Ein
  zusätzlicher `mtime`-Index würde Schreibkosten erhöhen, ohne den gemessenen
  Sync-Pfad zu beschleunigen.
- Suchen mit führendem Platzhalter wie `%begriff%` können keinen normalen
  B-Tree-Index effizient nutzen. Eine Volltextsuche wäre eine eigene Funktion,
  die für alle unterstützten Datenbanken entwickelt werden müsste.

## Empfohlene Produktionsbasis

- PHP 8.4 FPM mit aktiviertem OPcache
- MariaDB oder MySQL statt SQLite
- Redis für transaktionale Dateisperren
- APCu als lokaler Zwischenspeicher
- System-Cron statt AJAX- oder Webcron
- genug RAM für Datenbank und PHP-FPM, ohne dass das System auslagert
- niedrige Latenz zwischen Webserver, Datenbank, Redis und Speicher

Redis und PHP-Erweiterungen prüfen:

```bash
redis-cli ping
php8.4 -m | grep -E 'apcu|redis|Zend OPcache'
```

Konfiguration von owncloud.online prüfen:

```bash
sudo -u www-data php8.4 occ config:system:get dbtype
sudo -u www-data php8.4 occ config:system:get memcache.local
sudo -u www-data php8.4 occ config:system:get memcache.locking
sudo -u www-data php8.4 occ background:queue:status
```

## OPcache-Dimensionierung

Der Standardwert `opcache.max_accelerated_files=10000` reicht für
owncloud.online nicht. Das Release-Paket 11.0.25 bringt allein 5.878
PHP-Dateien mit, jede App aus dem Marktplatz weitere, einzelne Apps samt ihren
Bibliotheken über 1.000. Mit allen Apps sind es über 14.000. Wird das Limit
überschritten, verdrängt OPcache Einträge und kompiliert unter wechselnder Last
laufend Dateien neu. Zählen Sie die Dateien und setzen Sie das Limit darüber:

```bash
find /var/www/owncloud.online -name '*.php' | wc -l
```

Empfohlene Werte für `php.ini` bzw. den FPM-Pool:

```ini
opcache.enable=1
opcache.memory_consumption=256      ; MB, 512 bei sehr vielen Apps
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=24000 ; muss die Dateizahl übersteigen
opcache.validate_timestamps=0       ; nur mit OPcache-Reset beim Update
opcache.enable_cli=0                ; CLI/Cron braucht keinen persistenten OPcache
```

Mit `validate_timestamps=0` werden Code-Änderungen erst nach einem Neuladen von
PHP-FPM oder einem OPcache-Reset sichtbar. Laden Sie PHP-FPM deshalb nach jedem
Update neu (`systemctl reload php8.4-fpm`), sonst laufen alte Klassen weiter.

## OPcache-Preloading (PHP 8.4)

Aufbauend auf der Dimensionierung oben kann OPcache den stabilen Server-Code
schon beim Start von PHP-FPM kompilieren und verlinken (Preloading). Die
Worker sparen sich damit das Verlinken der Klassen bei jeder Anfrage und die
Aufwärmphase nach jedem Neuladen. Das Skript `build/preload.php` lädt
`lib/private`, `lib/public`, `core/` und die Composer-Bibliotheken unter
`lib/composer`. App-Verzeichnisse lädt es bewusst nicht, weil Apps im laufenden
Betrieb aktiviert, deaktiviert und aktualisiert werden.

Das Skript liegt im Quellcode-Repository, nicht im Release-Paket. Für eine
Installation aus dem Release-Paket legen Sie es passend zur installierten
Version unter `build/` im Installationsverzeichnis ab. Es bestimmt das
Installationsverzeichnis über seinen eigenen Ablageort und muss deshalb genau
dort liegen:

```bash
sudo -u www-data mkdir -p /var/www/owncloud.online/build
sudo -u www-data curl -fLo /var/www/owncloud.online/build/preload.php \
  https://raw.githubusercontent.com/BWTECH-github/owncloud.online/v11.0.25/build/preload.php
```

Von außen ist die Datei nicht abrufbar: Die `.htaccess` und die
nginx-Konfiguration aus der [Installationsanleitung](../installation/linux-server.md)
sperren `build/`.

Aktivierung in der FPM-Pool-Konfiguration bzw. `php.ini`:

```ini
opcache.preload=/var/www/owncloud.online/build/preload.php
opcache.preload_user=www-data
```

Hinweise:

- Preloading setzt dieselbe Disziplin wie `validate_timestamps=0` voraus: nach
  jedem Update PHP-FPM neu laden, sonst läuft alter Code weiter.
- Ein Update ersetzt das Programmverzeichnis, `build/preload.php` fehlt danach.
  Legen Sie die Datei in der Version des neuen Release wieder ab, bevor PHP-FPM
  neu startet. Fehlt sie, bricht PHP schon beim Start mit „Failed opening
  required …“ ab.
- Meldungen wie `Can't preload unlinked class` beim Start sind harmlos. Diese
  Klassen liegen unverlinkt im Zwischenspeicher und werden beim ersten Zugriff
  verlinkt.
- Das Skript ist fehlertolerant: Einzelne Dateien, die sich nicht kompilieren
  lassen, überspringt es, sie verhindern den Start von PHP-FPM nicht. Ins
  Fehlerprotokoll von PHP schreibt es eine Zeile
  `owncloud.online preload: compiled N of M files`.
- Aktivieren Sie Preloading zuerst auf einer Staging-Instanz und prüfen Sie den
  Start (`systemctl restart php8.4-fpm && systemctl status php8.4-fpm`).

## Frontend-Auslieferung

Die Weboberfläche lädt JavaScript und CSS als viele einzelne Dateien. Seit
11.0.12 sind die Dateien des Kerns und der mitgelieferten Apps minifiziert, und
unter Apache komprimiert die mitgelieferte `.htaccess` sie mit `mod_brotli` oder
`mod_deflate`. Gebündelt werden die Dateien nicht. Am TLS-Terminator senken
deshalb diese Punkte die Ladezeit am stärksten:

- HTTP/2 einschalten: Per Multiplexing laufen die vielen Einzeldateien
  gleichzeitig über eine Verbindung, statt nacheinander auf Antworten zu warten
- Keepalive zum PHP-FPM-Upstream
- gzip oder brotli für `text/*`, `application/javascript` und
  `application/json`, wenn die Dateien nicht von Apache mit der mitgelieferten
  `.htaccess` ausgeliefert werden

## Grenzen einzelner Server-Optimierungen

Auf einer Instanz mit warmem OPcache bewegt der kompilierte Routen-Cache
(`route.cache`) die Antwortzeit nicht messbar: OPcache hält die Routen-Dateien
bereits vor, und der Router baut ohnehin nur die Routen bereits geladener Apps.
Der Routen-Cache hilft vor allem bei kaltem oder abgeschaltetem OPcache und
frisch gestarteten FPM-Workern.

Den größten Teil der Kosten einer angemeldeten Anfrage verursachen das
wiederholte Passwort-Hashing (bcrypt) und der Aufbau von Sitzung und
Dateisystem. Ein Client, der sein Sitzungs-Cookie über mehrere Anfragen hält,
ist um ein Vielfaches schneller als einer, der sich bei jeder Anfrage erneut
per HTTP-Basic anmeldet. Sync- und API-Clients sollten das Sitzungs-Cookie
deshalb wiederverwenden.

Für Desktop- und Mobil-Client ist das bcrypt-Problem bereits gelöst: Sie melden
sich mit einem Geräte-Token an (`oc_authtoken`, App-Passwort), nicht mit dem
Passwort des Kontos. `OC\User\Session::checkTokenCredentials()` prüft bcrypt
nur, wenn die Spalte `last_check` des Tokens älter als `last_check_timeout`
(Vorgabe 5 Minuten) ist, nicht bei jeder Anfrage. Einen Zwischenspeicher für
Passwortprüfungen, der auf dem Klartext-Passwort beruht, legt owncloud.online
bewusst nicht an: Er müsste Passwortdaten speichern und würde die Bremse gegen
Passwort-Raten, die Begrenzung der Anfragerate und den Widerruf von Tokens
aushebeln. Das verbleibende bcrypt pro Anfrage betrifft nur Clients ohne Token,
die das Passwort des Kontos ohne dauerhafte Sitzung senden (etwa fremde
WebDAV-Integrationen).

## Aufbewahrung (Retention)

Alte Aktivitäten, Objekte im Papierkorb und Versionen erhöhen auf Dauer die
Last für Datenbank und Speicher. Die folgenden Werte sind ein Ausgangspunkt für
Installationen ohne abweichende Aufbewahrungspflichten:

```bash
sudo -u www-data php8.4 occ config:system:set \
  activity_expire_days --type integer --value 64

sudo -u www-data php8.4 occ config:system:set \
  trashbin_retention_obligation --value 'auto, 30'

sudo -u www-data php8.4 occ config:system:set \
  versions_retention_obligation --value 'auto, 30'
```

Prüfen Sie vorher vertragliche und gesetzliche Aufbewahrungsregeln. Die Befehle
löschen nichts sofort. Die Bereinigung übernehmen die Hintergrund-Jobs.

Zurück zur Standardautomatik:

```bash
sudo -u www-data php8.4 occ config:system:delete activity_expire_days
sudo -u www-data php8.4 occ config:system:set \
  trashbin_retention_obligation --value auto
sudo -u www-data php8.4 occ config:system:set \
  versions_retention_obligation --value auto
```

## Filecache-Wartung

Sichern Sie vor Wartungsbefehlen Datenbank und Datenverzeichnis, siehe
[Backups und Updates](backups-updates.md).

```bash
sudo -u www-data php8.4 occ files:cleanup
```

`files:cleanup` entfernt ausschließlich Filecache-Einträge, deren Speicher
nicht mehr existiert. Es ersetzt keine Bereinigung von Papierkorb oder
Versionen.

Tabellengrößen beobachten:

```sql
SELECT COUNT(*) FROM oc_filecache;
SELECT COUNT(*) FROM oc_activity;
SELECT COUNT(*) FROM oc_file_locks;
```

Legen Sie zusätzliche Indizes nicht blind an. Messen Sie vorher und nachher
`EXPLAIN`, Schreiblast, Speicherverbrauch und einen echten Desktop-Sync. Der
vorhandene Index auf `(parent, name)` bedient reine `parent`-Abfragen bereits.

## Reproduzierbarer Benchmark

Der Benchmark liegt im Quellcode-Repository, nicht im Release-Paket:

```text
tests/performance/benchmark.py
```

Er braucht nur Python 3 ohne weitere Pakete. Er legt im Konto einen eigenen
Ordner an und löscht ihn nach der Messung wieder (außer mit `--keep-data`).

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

Das Testkonto darf keine produktiven Daten enthalten. Für einen
Vorher-Nachher-Vergleich müssen Datenmenge, App-Liste, Netzwerklatenz,
Parallelität und Serverkonfiguration unverändert bleiben.
