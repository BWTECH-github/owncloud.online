# Installation auf einem Linux-Server

Diese Seite führt von einem leeren Linux-Server bis zu einer laufenden
owncloud.online-Instanz: Voraussetzungen prüfen, Release herunterladen und
gegen die Prüfsumme abgleichen, auspacken, Rechte setzen, Datenbank anlegen,
über `occ maintenance:install` installieren und anschließend Webserver,
`trusted_domains`, Cron, Zwischenspeicher und HTTPS einrichten. Am Ende steht
eine Liste der Punkte, die nach der Installation geprüft werden.

Alle Beispiele verwenden den Hostnamen `cloud.example.com`, das
Installationsverzeichnis `/var/www/owncloud.online`, das Datenverzeichnis
`/var/oco-data` und den Webserver-Benutzer `www-data`. Passen Sie diese Werte
an Ihre Umgebung an.

## Voraussetzungen

### Betriebssystem

Eine aktuelle 64-Bit-Linux-Distribution mit Paketverwaltung, systemd und
System-Cron. Die Beispiele nutzen `apt` und die Debian-üblichen Pfade
(`/etc/apache2`, `/run/php`); auf anderen Distributionen ändern sich nur die
Paketnamen und Pfade, nicht der Ablauf.

### PHP 8.4

owncloud.online setzt **PHP 8.4** voraus (`composer.json`: `"php": ">=8.4"`).
Ältere Versionen werden nicht unterstützt. Enthält Ihre Distribution PHP 8.4
nicht in den Standard-Paketquellen, muss die Version aus einer zusätzlichen
Paketquelle stammen.

Empfohlene Betriebsart ist **PHP-FPM**, nicht mod_php.

Diese Erweiterungen prüft der Server bei jedem Start selbst
(`OC_Util::checkServer()` in `lib/private/legacy/util.php`) und verweigert
ohne sie den Dienst:

| Geprüft wird | Erweiterung |
| --- | --- |
| `ZipArchive` | zip |
| `DOMDocument` | dom |
| `XMLWriter`, `XMLReader` | xml |
| `Collator` | intl |
| `xml_parser_create` | libxml |
| `mb_strcut` | mbstring |
| `ctype_digit` | ctype |
| `json_encode` | json |
| `gd_info` | gd |
| `gzencode` | zlib |
| `iconv` | iconv |
| `simplexml_load_string` | simplexml |
| `hash` | hash |
| `curl_init` | curl |
| `PDO::ATTR_DRIVER_NAME` | pdo (plus Treiber, z. B. pdo_mysql) |

Zusätzlich fordert `composer.json` `apcu`, `exif`, `fileinfo`, `imagick`,
`memcached` und `posix`. `posix` ist nicht optional: `console.php` bricht ohne
die Erweiterung ab, `occ` ist dann nicht benutzbar. Der Release-Build lädt
darüber hinaus `openssl` sowie die PDO-Treiber `pdo_mysql` und `pdo_sqlite`
(`.github/workflows/release-owncloud-online.yml`).

Ebenfalls geprüft wird die INI-Einstellung `default_charset` — sie muss
`UTF-8` sein.

Auf Debian und Ubuntu deckt das folgende Auswahl ab:

```bash
sudo apt install -y php8.4-fpm php8.4-cli php8.4-common php8.4-mysql \
  php8.4-curl php8.4-gd php8.4-intl php8.4-mbstring php8.4-xml \
  php8.4-zip php8.4-bcmath php8.4-imagick php8.4-apcu php8.4-redis \
  php8.4-opcache
```

Empfohlene PHP-Werte (in der `php.ini` von FPM **und** CLI):

```ini
memory_limit = 512M
upload_max_filesize = 513M
post_max_size = 513M
max_execution_time = 3600
max_input_time = 3600
default_charset = UTF-8
output_buffering = 0
```

Das Installationsverzeichnis enthält eine `.user.ini`, die `upload_max_filesize`,
`post_max_size`, `memory_limit`, `default_charset` und `output_buffering` auf
dieselben Werte setzt; die beiden Zeitgrenzen stehen dort nicht.
PHP-FPM liest diese Datei aus, die CLI dagegen nicht — `occ` und `cron.php`
richten sich ausschließlich nach der CLI-`php.ini`. Die `php_value`-Zeilen der
mitgelieferten `.htaccess` stehen in `<IfModule mod_php5.c>` bzw.
`<IfModule mod_php7.c>` und greifen unter PHP-FPM nie.

Zur OPcache-Dimensionierung siehe [Performance](../administration/performance.md);
der Standardwert `opcache.max_accelerated_files=10000` reicht für
owncloud.online nicht aus.

### Webserver

Apache 2.4 oder nginx, jeweils mit PHP-FPM. Beispielkonfigurationen stehen
weiter unten unter [Webserver einrichten](#webserver-einrichten).

### Datenbank

MariaDB oder MySQL ist die für den produktiven Betrieb getestete Variante.
Unterstützt sind laut `OC\Setup::getSupportedDatabases()` außerdem PostgreSQL
(`pgsql`) und SQLite (`sqlite`); SQLite eignet sich nur für lokale Tests und
bricht bei mehreren gleichzeitigen Zugriffen ein. Näheres unter
[Datenbank](../administration/database.md).

### Weitere Dienste

| Dienst | Zweck | Pflicht |
| --- | --- | --- |
| System-Cron | Hintergrund-Jobs | ja, siehe [Hintergrund-Jobs](../administration/background-jobs.md) |
| APCu | lokaler Zwischenspeicher | dringend empfohlen |
| Redis | transaktionales File-Locking | für Mehrbenutzerbetrieb empfohlen |
| TLS-Zertifikat | HTTPS | für produktive Nutzung Pflicht |
| SMTP-Zugang | Mailversand | siehe [E-Mail-Versand](../administration/email.md) |

## 1. Release herunterladen und Prüfsumme vergleichen

Die Releases liegen unter
<https://github.com/BWTECH-github/owncloud.online/releases>. Der Tag eines
Release lautet `v<Version>`, aktuell **11.0.13**.

Ein Release enthält:

| Datei | Inhalt |
| --- | --- |
| `owncloud-online-<version>.tar.gz` | vollständige Instanz |
| `owncloud-online-<version>.zip` | derselbe Inhalt als ZIP |
| `SHA256SUMS.txt` | SHA-256-Prüfsummen der beiden Archive, des Manifests und der Stückliste |
| `sbom-owncloud-online-<version>.cdx.json` | Stückliste im CycloneDX-Format |
| `release-manifest.json` | Version, Commit, PHP-Version des Builds |
| `removed-release-files.txt` | im Build entfernte Entwicklungsdateien |

Archiv und Prüfsummendatei herunterladen:

```bash
cd /tmp
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.13/owncloud-online-11.0.13.tar.gz
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.13/SHA256SUMS.txt
```

Prüfsumme vergleichen — der Befehl muss `OK` ausgeben:

```bash
sha256sum --ignore-missing -c SHA256SUMS.txt
```

`--ignore-missing` überspringt die Einträge der nicht heruntergeladenen
Dateien. Ohne diese Option meldet `sha256sum` für jede fehlende Datei einen
Fehler. Schlägt der Vergleich fehl, ist der Download unvollständig oder
verändert — dann nicht weiterarbeiten, sondern erneut laden.

## 2. Auspacken und Rechte setzen

Das Archiv enthält ein einziges Verzeichnis `owncloud/`. Beim Auspacken
entsteht es unterhalb des Zielpfades und wird danach umbenannt:

```bash
sudo mkdir -p /var/www
sudo tar -xzf /tmp/owncloud-online-11.0.13.tar.gz -C /var/www
sudo mv /var/www/owncloud /var/www/owncloud.online
```

Das Datenverzeichnis gehört **außerhalb** des Webroots. Andernfalls hängt seine
Absicherung allein an der `.htaccess`, die nginx gar nicht liest:

```bash
sudo mkdir -p /var/oco-data
```

Eigentümer und Rechte:

```bash
sudo chown -R www-data:www-data /var/www/owncloud.online /var/oco-data
sudo find /var/www/owncloud.online -type d -exec chmod 750 {} \;
sudo find /var/www/owncloud.online -type f -exec chmod 640 {} \;
sudo chmod 750 /var/oco-data
```

Drei Verzeichnisse müssen für `www-data` beschreibbar bleiben:

| Verzeichnis | Grund |
| --- | --- |
| `config/` | die Installation schreibt `config/config.php` |
| `apps-external/` | Ablage für Apps aus dem Markt (`OC\Setup::install()` legt es an und prüft es auf Schreibrecht) |
| `/var/oco-data` | Benutzerdateien, Protokoll, Vorschaubilder |

`occ` muss immer als der Benutzer laufen, dem `config/config.php` gehört —
`console.php` bricht sonst mit „Console has to be executed with the user that
owns the file config/config.php" ab. Deshalb in dieser Anleitung durchgehend
`sudo -u www-data php8.4 occ …`.

Alle folgenden `occ`-Aufrufe erwarten das Installationsverzeichnis als
Arbeitsverzeichnis:

```bash
cd /var/www/owncloud.online
```

## 3. Datenbank anlegen

Die Datenbank muss `utf8mb4` verwenden, sonst scheitern Dateinamen mit Emojis
oder seltenen Zeichen:

```sql
CREATE DATABASE owncloud_online
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'owncloud'@'localhost' IDENTIFIED BY 'HIER_EIN_STARKES_PASSWORT';
GRANT ALL PRIVILEGES ON owncloud_online.* TO 'owncloud'@'localhost';
FLUSH PRIVILEGES;
```

Die Installation erkennt selbst, ob der Server 4-Byte-Zeichen unterstützt, und
setzt in diesem Fall `mysql.utf8mb4` auf `true` (`lib/private/Setup/MySQL.php`).
Legt sie die Datenbank selbst an — das geschieht mit `CREATE DATABASE IF NOT
EXISTS` —, verwendet sie die Kollation `utf8mb4_bin`. Eine wie oben von Hand
angelegte Datenbank behält ihre eigene Kollation.

## 4. Installation über occ maintenance:install

```bash
sudo -u www-data php8.4 occ maintenance:install \
  --database mysql \
  --database-name owncloud_online \
  --database-host localhost \
  --database-user owncloud \
  --database-pass 'HIER_EIN_STARKES_PASSWORT' \
  --admin-user admin \
  --admin-pass 'HIER_EIN_STARKES_ADMINPASSWORT' \
  --data-dir /var/oco-data
```

Bei Erfolg gibt der Befehl `owncloud.online was successfully installed` aus.
Alle Optionen stammen aus `core/Command/Maintenance/Install.php`:

| Option | Vorgabe | Anmerkung |
| --- | --- | --- |
| `--database` | `sqlite` | `mysql`, `pgsql`, `sqlite`; `oci` nur mit geladener `oci8`-Erweiterung |
| `--database-name` | — | bei allem außer `sqlite` erforderlich |
| `--database-host` | `localhost` | |
| `--database-user` | — | bei allem außer `sqlite` erforderlich |
| `--database-pass` | — | fehlt die Option, wird verdeckt abgefragt |
| `--database-table-prefix` | `oc_` | |
| `--admin-user` | `admin` | |
| `--admin-pass` | — | fehlt die Option, wird verdeckt abgefragt |
| `--data-dir` | `<Installationspfad>/data` | unbedingt setzen |

Werden `--database-pass` und `--admin-pass` weggelassen, fragt der Befehl beide
verdeckt ab. Das ist der sauberere Weg, weil die Passwörter dann nicht in der
Shell-Historie und nicht in der Prozessliste stehen.

Der Befehl prüft vor der Installation die Umgebung und bricht mit einer
Fehlerliste ab, wenn eine Erweiterung fehlt oder ein Verzeichnis nicht
beschreibbar ist.

Was die Installation erledigt (`OC\Setup::install()`):

- schreibt `config/config.php` samt Datenbankzugang und `datadirectory`
- legt die Tabellen an und installiert die mitgelieferten Apps; Apps mit
  `<default_enable/>` in der `info.xml` werden dabei aktiviert — dazu gehört
  die im Release enthaltene Markt-App
- legt `apps_paths` an: `apps/` als nicht beschreibbar, `apps-external/` als
  beschreibbar
- setzt `logtimezone` auf die Zeitzone des Systems
- legt im Datenverzeichnis die Markierungsdatei `.ocdata` an und schreibt eine
  `.htaccess`, die den Zugriff über Apache verbietet

Ein Punkt, der bei der Installation über die Kommandozeile leicht übersehen
wird: `Setup::updateHtaccess()` bricht im CLI-Betrieb sofort ab, solange
`overwrite.cli.url` leer ist. Die `.htaccess` bleibt dann ohne die
`ErrorDocument`-Zeilen und ohne den Front-Controller-Block unterhalb der Zeile
`#### DO NOT CHANGE ANYTHING ABOVE THIS LINE ####`. Der nächste Abschnitt holt
das nach.

## 5. Grundkonfiguration

### trusted_domains

`trusted_domains` legt fest, unter welchen Hostnamen die Instanz aufgerufen
werden darf. Die Installation trägt dort den beim Aufruf erkannten Namen ein —
auf der Kommandozeile ist das nicht Ihr späterer Hostname. Deshalb den ersten
Eintrag setzen:

```bash
sudo -u www-data php8.4 occ config:system:set trusted_domains 0 \
  --value cloud.example.com
```

Die Zahl nach dem Schlüsselnamen ist der Index im Array; `config:system:set`
nimmt mehrere Namensteile entgegen und baut daraus verschachtelte Werte. Ein
zweiter Name bekommt entsprechend den Index `1`. Passt der aufgerufene Name zu
keinem Eintrag, erscheint statt der Oberfläche die Meldung „Sie greifen auf den
Server über eine nicht vertrauenswürdige Domain zu.".

### Basis-URL für Cron und occ

Ohne HTTP-Anfrage kennt der Server seinen eigenen Namen nicht. Mails und Links
aus Hintergrund-Jobs verwenden deshalb `overwrite.cli.url`:

```bash
sudo -u www-data php8.4 occ config:system:set overwrite.cli.url \
  --value https://cloud.example.com
```

### Kurze URLs ohne index.php

Ist `htaccess.RewriteBase` gesetzt, schreibt `occ maintenance:update:htaccess`
den Rewrite-Block in die `.htaccess` und setzt darin
`SetEnv front_controller_active true`. Erst diese Umgebungsvariable lässt
`OC\URLGenerator` und `OC\Route\Router` URLs ohne `index.php` erzeugen.

```bash
sudo -u www-data php8.4 occ config:system:set htaccess.RewriteBase --value /
sudo -u www-data php8.4 occ maintenance:update:htaccess
```

Der Befehl gibt `.htaccess has been updated` aus. Er wirkt nur bei Apache mit
`AllowOverride All`. Unter nginx entsteht dieselbe Wirkung über
`fastcgi_param front_controller_active true;` (siehe Beispiel unten);
`htaccess.RewriteBase` bleibt dort ohne Bedeutung.

## 6. Webserver einrichten

### Apache

Benötigte Module:

```bash
sudo a2enmod rewrite headers env dir mime setenvif ssl proxy_fcgi
sudo systemctl restart apache2
```

VirtualHost:

```apache
<VirtualHost *:80>
    ServerName cloud.example.com
    Redirect permanent / https://cloud.example.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName cloud.example.com
    DocumentRoot /var/www/owncloud.online

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/cloud.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cloud.example.com/privkey.pem

    <Directory /var/www/owncloud.online>
        Require all granted
        AllowOverride All
        Options FollowSymLinks
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Das abschliessende MOVE eines Chunk-Uploads laeuft lange ohne Datenverkehr
    TimeOut 3600
    LimitRequestBody 0

    ErrorLog  ${APACHE_LOG_DIR}/owncloud-online-error.log
    CustomLog ${APACHE_LOG_DIR}/owncloud-online-access.log combined
</VirtualHost>
```

`AllowOverride All` ist Pflicht. Ohne diese Zeile wirkt die mitgelieferte
`.htaccess` nicht: es fehlen die Sicherheits-Kopfzeilen, die 404-Sperren für
`config/`, `lib/` und `templates/`, die `.well-known`-Umleitungen und die
kurzen URLs.

Setzt Apache selbst das TLS um, setzt `mod_ssl` die Umgebungsvariable `HTTPS`,
und die `.htaccess` sendet den HSTS-Header eigenständig
(`Header always set Strict-Transport-Security … env=HTTPS`). Er darf dann nicht
zusätzlich im VirtualHost gesetzt werden.

### nginx

nginx liest keine `.htaccess`. Alles, was dort steht — Sicherheits-Kopfzeilen,
Sperren, Umleitungen, kurze URLs —, muss hier ausdrücklich stehen.

```nginx
upstream php-handler {
    server unix:/run/php/php8.4-fpm.sock;
}

server {
    listen 80;
    listen [::]:80;
    server_name cloud.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name cloud.example.com;

    ssl_certificate     /etc/letsencrypt/live/cloud.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cloud.example.com/privkey.pem;

    root  /var/www/owncloud.online;
    index index.php index.html;

    # Ersatz fuer die Kopfzeilen der .htaccess
    add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;
    add_header X-Content-Type-Options             "nosniff"  always;
    add_header X-Frame-Options                    "SAMEORIGIN" always;
    add_header X-Robots-Tag                       "none"     always;
    add_header X-Download-Options                 "noopen"   always;
    add_header X-Permitted-Cross-Domain-Policies  "none"     always;

    # Grosse Uploads: keine Groessengrenze, grosszuegige Zeitgrenzen
    client_max_body_size 0;
    client_body_timeout  300s;

    # Ersatz fuer die .well-known-RewriteRules der .htaccess
    location = /.well-known/host-meta      { rewrite ^ /public.php?service=host-meta      last; }
    location = /.well-known/host-meta.json { rewrite ^ /public.php?service=host-meta-json last; }
    location ^~ /.well-known/carddav { return 301 /remote.php/dav/; }
    location ^~ /.well-known/caldav  { return 301 /remote.php/dav/; }
    location ^~ /.well-known/acme-challenge/ { }
    location ^~ /.well-known/pki-validation/ { }

    # Ersatz fuer die 404-Sperren der .htaccess
    location ~ ^/(?:build|tests|config|lib|3rdparty|templates|changelog)/ { return 404; }
    location ~ ^/(?:\.|autotest|occ|issue|indie|db_|console)  { return 404; }
    location = /core/signature.json { return 404; }
    location ^~ /core/skeleton/     { return 404; }
    location ^~ /data/             { return 404; }

    # Apps liefern ihre Abhaengigkeiten und Tests mit; keines davon gehoert
    # ins Web. Die Sperren oben greifen nur auf oberster Ebene, nicht in
    # apps/<name>/. Die Apps bringen dafuer eigene .htaccess-Dateien mit -
    # die liest nginx nicht, deshalb steht dieselbe Aussage hier.
    location ~ ^/apps/[^/]+/(?:vendor|tests)/ { return 404; }
    location ~ ^/apps/[^/]+/composer[.](json|lock)$ { return 404; }

    location / {
        rewrite ^/remote/(.*) /remote.php last;
        try_files $uri $uri/ /index.php$request_uri;
    }

    location ~ \.php(?:$|/) {
        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
        try_files $fastcgi_script_name =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO       $fastcgi_path_info;
        fastcgi_param HTTPS on;
        # Ohne diese Zeile scheitert jede Basic-Auth-Anmeldung von WebDAV-Clients
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        # Entspricht SetEnv front_controller_active true aus der .htaccess
        fastcgi_param front_controller_active true;
        fastcgi_param modHeadersAvailable    true;
        fastcgi_pass php-handler;
        fastcgi_read_timeout 3600s;
        fastcgi_request_buffering off;
    }

    location ~ \.(?:css|js|woff2?|svg|gif|png|jpg|jpeg|ico|map)$ {
        try_files $uri /index.php$request_uri;
        access_log off;
        expires 7d;
    }
}
```

Zwei Punkte zu diesem Beispiel:

- `HTTP_AUTHORIZATION` muss durchgereicht werden. `lib/base.php` baut
  `PHP_AUTH_USER` und `PHP_AUTH_PW` aus dieser Variablen nach; fehlt sie,
  erhalten WebDAV- und Desktop-Clients trotz richtiger Zugangsdaten 401.
- Die Sperre `location ~ ^/(?:\.|…)` würde auch `/.well-known/…` treffen. Die
  vier `location ^~`-Blöcke davor haben Vorrang vor regulären Ausdrücken und
  halten die Umleitungen und die Zertifikatsausstellung frei.

Steht owncloud.online hinter einem eigenständigen Reverse-Proxy, gelten
zusätzlich `trusted_proxies`, `overwriteprotocol` und die dortigen
Kopfzeilenregeln — siehe
[Reverse-Proxy und TLS](../administration/reverse-proxy.md).

## 7. Cron einrichten

Ohne Cron laufen Papierkorb- und Versionsbereinigung, Freigabe-Abläufe,
Benachrichtigungen und Aktivitäts-Mails nie. Die Instanz wirkt zunächst normal
und wächst unbegrenzt.

```bash
sudo crontab -u www-data -e
```

Eintrag:

```
*/15  *  *  *  * /usr/bin/php8.4 -f /var/www/owncloud.online/cron.php
```

Danach den Modus umstellen:

```bash
sudo -u www-data php8.4 occ background:cron
```

Der Cron-Auftrag muss als `www-data` laufen. Läuft er als `root`, gehören neu
erzeugte Dateien danach `root`, und der Webserver kann sie nicht mehr
schreiben. Einzelheiten unter
[Hintergrund-Jobs (Cron)](../administration/background-jobs.md).

## 8. Zwischenspeicher und File-Locking

Ist `memcache.local` nicht gesetzt, fällt die Cache-Factory auf `NullCache`
zurück (`lib/private/Memcache/Factory.php`): jede Sprachdatei und jede
`info.xml` wird dann bei jeder Anfrage neu geparst.

```bash
sudo -u www-data php8.4 occ config:system:set memcache.local \
  --value '\OC\Memcache\APCu'
```

Damit auch `occ` und `cron.php` nicht ohne Zwischenspeicher laufen, muss APCu
in der CLI-`php.ini` aktiv sein:

```ini
apc.enable_cli=1
```

Für das transaktionale File-Locking ist Redis die empfohlene Ablage — andere
Backends können Werte ohne Vorwarnung verwerfen, was hier Datenverlust bedeuten
kann:

```bash
sudo apt install -y redis-server php8.4-redis
sudo systemctl enable --now redis-server

sudo -u www-data php8.4 occ config:system:set memcache.locking \
  --value '\OC\Memcache\Redis'
sudo -u www-data php8.4 occ config:system:set redis host --value 127.0.0.1
sudo -u www-data php8.4 occ config:system:set redis port --value 6379 \
  --type integer
sudo systemctl reload php8.4-fpm
```

`filelocking.enabled` steht ohne Zutun auf `true`; es muss nicht gesetzt
werden.

## 9. HTTPS

Produktive Instanzen laufen über HTTPS. Mit Let's Encrypt und Apache:

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d cloud.example.com
```

Mit nginx entsprechend `python3-certbot-nginx` und `certbot --nginx`.

Danach die Basis-URL auf `https://` setzen, falls noch nicht geschehen:

```bash
sudo -u www-data php8.4 occ config:system:set overwrite.cli.url \
  --value https://cloud.example.com
```

Die Zertifikatsausstellung über HTTP benötigt den Pfad
`/.well-known/acme-challenge/`. Die mitgelieferte `.htaccess` sperrt Pfade, die
mit einem Punkt beginnen, per 404 — ausgenommen sind ausdrücklich
`/.well-known/acme-challenge/` und `/.well-known/pki-validation/`. Im
nginx-Beispiel oben stehen dafür die beiden `location ^~`-Blöcke.

## 10. Apps aus dem Markt

Die Markt-App ist im Release enthalten (`apps-external/market`) und wird bei der
Installation aktiviert. Sie erscheint für Administratoren im App-Menü oben links
als **Markt**.

![Markt mit installierten Apps](../assets/screenshots/owncloud-online-apps.png)

Über die Kommandozeile stehen dieselben Funktionen zur Verfügung:

```bash
sudo -u www-data php8.4 occ market:list
sudo -u www-data php8.4 occ market:install <app_id>
sudo -u www-data php8.4 occ market:upgrade <app_id>
sudo -u www-data php8.4 occ market:uninstall <app_id>
```

Eine App lässt sich auch von Hand einspielen. Das Paket wird dazu nach
`apps-external/` ausgepackt — das ist der als beschreibbar eingetragene
App-Pfad — und anschließend aktiviert:

```bash
sudo -u www-data tar -xzf /tmp/<app_id>-<version>.tar.gz \
  -C /var/www/owncloud.online/apps-external
sudo -u www-data php8.4 occ app:enable <app_id>
sudo -u www-data php8.4 occ app:list
```

Die Adresse des Katalogs steht in `appstoreurl`. Näheres unter
[Apps und Marketplace](../administration/apps-market.md).

## 11. Nach der Installation prüfen

Erster Aufruf im Browser unter `https://cloud.example.com` — es erscheint die
Anmeldung mit dem bei der Installation angelegten Administratorkonto.

![Anmeldung](../assets/screenshots/owncloud-online-login.png)

Diese Prüfungen gehören zu jeder Inbetriebnahme:

```bash
cd /var/www/owncloud.online

# 1. Umgebung: gibt bei fehlenden Erweiterungen eine Liste aus, sonst nichts
sudo -u www-data php8.4 occ check

# 2. Zustand der Instanz (installed, version, versionstring, edition)
sudo -u www-data php8.4 occ status

# 3. aktive und inaktive Apps
sudo -u www-data php8.4 occ app:list

# 4. Cron: Modus muss cron sein, lastcron darf nicht alt sein
sudo -u www-data php8.4 occ config:app:get core backgroundjobs_mode
sudo -u www-data php8.4 occ config:app:get core lastcron
sudo -u www-data php8.4 occ background:queue:status

# 5. Grundwerte
sudo -u www-data php8.4 occ config:system:get trusted_domains
sudo -u www-data php8.4 occ config:system:get overwrite.cli.url
sudo -u www-data php8.4 occ config:system:get datadirectory
sudo -u www-data php8.4 occ config:system:get memcache.local
sudo -u www-data php8.4 occ config:system:get memcache.locking
```

Von außen:

```bash
# Statusendpunkt: installed=true, maintenance=false, productname owncloud.online
curl -s https://cloud.example.com/status.php

# .well-known-Umleitung: erwartet 301 auf /remote.php/dav/
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' \
  https://cloud.example.com/.well-known/caldav/

# Datenverzeichnis darf nicht erreichbar sein: erwartet 403 oder 404
curl -s -o /dev/null -w '%{http_code}\n' https://cloud.example.com/data/.ocdata
```

Zusätzlich prüfen:

- **Setup-Warnungen** unter *Einstellungen → Administration → Allgemein*. Sie
  zeigen fehlende Produktionskonfiguration an und dürfen nicht stehen bleiben,
  siehe [Sicherheit und Setup-Warnungen](../administration/security-hardening.md).
- **Mailversand** einrichten und testen. Ohne SMTP fallen Passwort-Reset,
  Freigabe-Einladungen und Kontoanlage still aus, siehe
  [E-Mail-Versand](../administration/email.md).
- **Protokollrotation**: `log_rotate_size` steht ohne Zutun auf `false`,
  `owncloud.log` wächst dann unbegrenzt. Entweder die eingebaute Rotation
  aktivieren …

```bash
sudo -u www-data php8.4 occ config:system:set log_rotate_size \
  --value 104857600 --type integer
```

  … womit bei 100 MB nach `owncloud.log.1` rotiert wird, oder die Datei über
  das `logrotate` des Systems behandeln. Siehe
  [Serverprotokoll und Fehlermeldungen](../administration/logging.md).

- **Sicherung** einrichten, bevor die Instanz produktiv genutzt wird.
  Datenverzeichnis und Datenbank gehören zum selben Zeitpunkt gesichert, siehe
  [Backups und Updates](../administration/backups-updates.md).

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| `maintenance:install` bricht mit „PHP module … not installed" ab | Eine der von `OC_Util::checkServer()` geprüften Erweiterungen fehlt | Paket nachinstallieren, `systemctl reload php8.4-fpm`, dann `occ check` |
| „Console has to be executed with the user that owns the file config/config.php" | `occ` läuft als falscher Benutzer | Aufruf mit `sudo -u www-data php8.4 occ …` wiederholen |
| „The posix extensions are required" | Erweiterung `posix` fehlt | `posix` nachinstallieren; ohne sie ist `occ` nicht benutzbar |
| „Can't create or write into the data directory …" | Datenverzeichnis gehört nicht `www-data` oder ist nicht beschreibbar | `chown -R www-data:www-data` auf das Datenverzeichnis |
| „Can't create or write into the apps-external directory …" | `apps-external/` fehlt oder ist nicht beschreibbar | Verzeichnis anlegen und `www-data` als Eigentümer setzen |
| „Sie greifen auf den Server über eine nicht vertrauenswürdige Domain zu." | Aufgerufener Hostname steht nicht in `trusted_domains` | `occ config:system:set trusted_domains 0 --value cloud.example.com` |
| Oberfläche lädt, aber alle URLs enthalten `index.php` | `htaccess.RewriteBase` nicht gesetzt bzw. `front_controller_active` fehlt | Bei Apache Schlüssel setzen und `occ maintenance:update:htaccess`; bei nginx `fastcgi_param front_controller_active true;` |
| `occ maintenance:update:htaccess` ändert nichts | Auf der Kommandozeile bricht `updateHtaccess()` ab, solange `overwrite.cli.url` leer ist | Erst `overwrite.cli.url` setzen, dann den Befehl erneut ausführen |
| Sicherheits-Kopfzeilen und Sperren fehlen unter Apache | `AllowOverride All` fehlt, die `.htaccess` wird nicht gelesen | Im `<Directory>`-Block ergänzen und Apache neu laden |
| Sicherheits-Kopfzeilen und `.well-known`-Umleitungen fehlen unter nginx | nginx liest keine `.htaccess` | Die `add_header`- und `location`-Blöcke aus dem Beispiel übernehmen |
| WebDAV- oder Desktop-Client erhält 401 trotz richtiger Zugangsdaten | Die `Authorization`-Kopfzeile erreicht PHP nicht | Unter nginx `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` setzen |
| Datenverzeichnis ist über den Browser erreichbar | Es liegt im Webroot, und die schützende `.htaccess` greift nicht | Datenverzeichnis außerhalb des Webroots legen und `datadirectory` anpassen |
| „Letzte Cron-Job-Ausführung: … Möglicherweise liegt ein Fehler vor." | Cron-Eintrag fehlt oder läuft unter dem falschen Benutzer | Eintrag als `www-data` anlegen, danach `occ background:cron` |
| Dateinamen mit Emojis schlagen fehl | Datenbank nicht auf `utf8mb4` | Zeichensatz prüfen, siehe [Datenbank](../administration/database.md) |
| Großer Upload bricht kurz vor dem Ende ab | Das abschließende `MOVE` setzt die Teile zusammen und überschreitet eine Zeitgrenze | `TimeOut` bzw. `fastcgi_read_timeout` und `request_terminate_timeout` erhöhen |
| Upload bricht bei fester Größe ab | Grenze in PHP; die `php_value`-Zeilen der `.htaccess` gelten nur unter mod_php, die `.user.ini` nicht für die CLI | `upload_max_filesize` und `post_max_size` in der `php.ini` von FPM und CLI setzen |
| Keine Passwort-Reset- und Freigabe-Mails | Kein Mailversand eingerichtet | SMTP setzen, siehe [E-Mail-Versand](../administration/email.md) |

Bleibt die Ursache unklar, steht sie meist im Protokoll unter
`/var/oco-data/owncloud.log`, siehe
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).
