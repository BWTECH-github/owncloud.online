# Reverse-Proxy und TLS

Die meisten Produktivinstallationen von owncloud.online stehen hinter einem
Reverse-Proxy, der TLS terminiert. Der Anwendungsserver sieht dann nicht mehr
den Client, sondern den Proxy — Client-Adresse, Protokoll und Hostname stehen
nur noch in HTTP-Kopfzeilen, denen der Server erst ausdrücklich vertrauen muss.
Diese Seite beschreibt, welche Schlüssel dafür gesetzt sein müssen, wie eine
passende nginx- und Apache-Konfiguration aussieht und woran typische
Fehlkonfigurationen erkennbar sind.

## Wie die Client-Adresse ermittelt wird

Maßgeblich ist `OC\AppFramework\Http\Request::getRemoteAddress()` in
`lib/private/AppFramework/Http/Request.php`. Der Ablauf ist kurz:

1. Ausgangswert ist `$_SERVER['REMOTE_ADDR']` — die Adresse der Gegenstelle,
   hinter einem Proxy also die des Proxys.
2. Steht diese Adresse in `trusted_proxies`, werden die in
   `forwarded_for_headers` genannten Server-Variablen gelesen.
3. Diese Variablen werden der Reihe nach durchgegangen; übernommen wird die
   erste syntaktisch gültige IP-Adresse, die dabei auftaucht. Findet sich in
   keiner eine gültige Adresse, bleibt es bei `REMOTE_ADDR`.

Drei Eigenheiten dieser Auswertung entscheiden über Erfolg oder Misserfolg:

- Der Abgleich mit `trusted_proxies` ist ein **exakter Vergleich** der
  Zeichenkette (`in_array`). CIDR-Notation wie `10.0.0.0/24` wird nicht
  aufgelöst und trifft nie zu. Jede Proxy-Adresse einzeln eintragen, IPv6 genau
  in der Schreibweise, in der sie in `REMOTE_ADDR` ankommt.
- `forwarded_for_headers` enthält Namen von `$_SERVER`-Variablen, nicht
  Kopfzeilennamen: aus `X-Forwarded-For` wird `HTTP_X_FORWARDED_FOR`. Ist der
  Schlüssel nicht gesetzt, gilt ausschließlich `HTTP_X_FORWARDED_FOR` als
  Standard.
- Innerhalb der Kopfzeile gewinnt der **linke** Eintrag. Hängt der Proxy seine
  Peer-Adresse nur an einen vom Client mitgeschickten Wert an, bleibt der
  Client-Wert links stehen und wird übernommen. Der Proxy muss die Kopfzeile
  deshalb **setzen** und nicht ergänzen (siehe Beispiele weiter unten).

## Warum trusted_proxies sicherheitsrelevant ist

Die ermittelte Adresse ist kein reiner Protokollwert. Sie ist der Schlüssel,
über den mehrere Schutzmechanismen zählen:

| Verwendung | Fundstelle |
| --- | --- |
| Bremse gegen Passwort-Raten bei der Anmeldung, gezählt pro (IP, Kontoname) | `lib/private/User/Manager.php` → `Throttler::sleepDelay('login', …)` |
| Bremse für Passwörter öffentlicher Links, gezählt pro (IP, Freigabe-Token) | `lib/private/Share20/Manager.php` → `'share_password'` |
| Bremse am MCP-Endpunkt | `apps/oco_mcp/lib/Controller/McpController.php` → `'oco_mcp'` |
| Feld `remoteAddr` in jeder Protokollzeile | `lib/private/Log/Owncloud.php`, `lib/private/Log/Syslog.php` |
| Warnung „Trusted domain error" beim Zugriff über einen unbekannten Hostnamen | `lib/base.php` |

Die Bremse in `lib/private/OCO/Security/Bruteforce/Throttler.php` zählt in drei
Dimensionen und wendet die strengste an: pro (Adresse, Kennung), pro Adresse über
alle Kennungen hinweg und pro Subnetz-Bucket (`/24` bei IPv4, `/64` bei IPv6),
um Passwort-Sprühen über viele Adressen zu erkennen. Eine falsche Adressquelle
wirkt sich damit nicht punktuell, sondern über alle drei Dimensionen aus.

| Konstellation | Folge |
| --- | --- |
| `trusted_proxies` korrekt gesetzt | Gezählt wird die echte Client-Adresse — der Schutz wirkt, und im Protokoll steht eine verwertbare Adresse |
| `trusted_proxies` nicht gesetzt | Alle Anfragen zählen auf die Proxy-Adresse. Nutzer bremsen sich gegenseitig aus, und jede Protokollzeile nennt dieselbe Adresse |
| Der Proxy hängt `X-Forwarded-For` nur an, statt es zu setzen | Der vom Client mitgeschickte Wert steht links und wird übernommen — ein Angreifer bekommt pro Anfrage eine neue „Adresse", die Bremse greift nicht |
| In `trusted_proxies` steht eine Adresse, die nicht ausschließlich der Proxy belegt | Wer von dort aus Anfragen stellt, bestimmt seine Adresse über die Kopfzeile selbst — mit derselben Folge |
| Anwendungsserver direkt aus dem Netz erreichbar | Anfragen umgehen den Proxy. Die Client-Adresse bleibt zwar echt, weil `REMOTE_ADDR` dann nicht in `trusted_proxies` steht, aber `X-Forwarded-Proto` und `X-Forwarded-Host` werden ohne diesen Abgleich ausgewertet — Protokoll und Hostname erzeugter Links sind von außen setzbar |

Daraus folgen zwei Pflichten für die Inbetriebnahme:

1. `trusted_proxies` auf die **tatsächlichen** Adressen der Proxys setzen — keine
   Bereiche „auf Verdacht", kein `0.0.0.0/0` (das ohnehin nicht ausgewertet
   würde). Jede dort eingetragene Adresse darf die Client-Adresse frei
   behaupten.
2. Den Anwendungsserver (PHP-FPM oder das Backend-HTTP) niemals direkt
   exponieren: Bindung an `127.0.0.1` oder eine Firewallregel, die nur den Proxy
   durchlässt.

Ist die App `brute_force_protection` installiert und aktiv, überlässt ihr die
eingebaute Bremse die Richtlinie für Anmeldung und Link-Passwörter; die übrigen
Wege bleiben von der eingebauten Bremse geschützt. An der Bedeutung der
Client-Adresse ändert das nichts.

## Werte setzen

```bash
sudo -u www-data php8.4 occ config:system:set trusted_proxies 0 --value 10.0.0.5
sudo -u www-data php8.4 occ config:system:set overwriteprotocol --value https
sudo -u www-data php8.4 occ config:system:set overwrite.cli.url \
  --value https://cloud.example.com
```

Der zweite Positionsparameter (`0`) ist der Index im Array — `config:system:set`
nimmt mehrere Namensteile entgegen und baut daraus verschachtelte Werte. Für
einen zweiten Proxy folgt entsprechend Index `1`.

Gleichwertig direkt in `config/config.php`:

```php
'trusted_proxies' => ['10.0.0.5'],
'forwarded_for_headers' => ['HTTP_X_FORWARDED_FOR'],
'overwriteprotocol' => 'https',
'overwrite.cli.url' => 'https://cloud.example.com',
```

## Die overwrite-Schlüssel

| Schlüssel | Wirkung | Beispiel |
| --- | --- | --- |
| `overwriteprotocol` | Ersetzt das erkannte Protokoll bei der URL-Erzeugung. Gültig sind nur `http` und `https` | `'https'` |
| `overwritehost` | Ersetzt den Hostnamen, bei Bedarf mit Port. Gilt im Code ausdrücklich als vertrauenswürdig und wird nicht gegen `trusted_domains` geprüft | `'cloud.example.com:8443'` |
| `overwritewebroot` | Unterpfad, unter dem die Instanz von außen erreichbar ist | `'/cloud'` |
| `overwritecondaddr` | Regulärer Ausdruck auf `REMOTE_ADDR`; begrenzt im ausgelieferten Code nur `overwriteprotocol` | `'^10\.0\.0\.5$'` |
| `overwrite.cli.url` | Basis-URL für Aufrufe ohne Request, also Cron und `occ` | `'https://cloud.example.com'` |

Zum Protokoll: Ist `overwriteprotocol` leer, wertet `getServerProtocol()`
`X-Forwarded-Proto` aus — und zwar **ohne** Abgleich mit `trusted_proxies`.
Dasselbe gilt für `X-Forwarded-Host` in `getInsecureServerHost()`. Beide
Kopfzeilen muss der Proxy deshalb selbst setzen und einen vom Client
mitgeschickten Wert dabei überschreiben.

Zum Host: Ohne `overwritehost` wird der Name aus `X-Forwarded-Host` bzw. `Host`
gegen `trusted_domains` geprüft. Passt er nicht, verwendet der Server den ersten
Eintrag aus `trusted_domains` — Links zeigen dann auf einen anderen Hostnamen,
als der Benutzer aufgerufen hat.

Zu `overwritecondaddr` drei Punkte, die in der Praxis Zeit kosten:

- Geprüft wird `REMOTE_ADDR`, also die Adresse des **Proxys**, nicht die des
  Clients. Dort gehört die Proxy-Adresse hinein.
- Der Ausdruck wird ungeschützt zwischen zwei Schrägstriche gesetzt. Punkte
  maskieren, Anker verwenden, keine Schrägstriche im Muster.
- `config/config.sample.php` schreibt, die Bedingung gelte für
  `overwritewebroot`, `overwriteprotocol` und `overwritehost`. Im Code trifft
  das nur auf `overwriteprotocol` zu: `isOverwriteCondition()` wird für Host,
  Webroot und Request-URI ohne Typangabe aufgerufen, und die letzte Bedingung
  `$type !== 'protocol'` macht das Ergebnis dann unabhängig vom Ausdruck immer
  wahr. Wer `overwritehost` per Adresse begrenzen will, erreicht das mit diesem
  Schlüssel nicht.

## nginx als TLS-Terminator

Die Kopfzeilen werden hier mit `proxy_set_header` gesetzt und überschreiben
damit alles, was der Client mitgeschickt hat. `X-Forwarded-For` bewusst auf
`$remote_addr` statt auf `$proxy_add_x_forwarded_for`: Letzteres hängt an einen
vom Client gesetzten Wert an, und ausgewertet wird der linke Eintrag.

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name cloud.example.com;

    ssl_certificate     /etc/letsencrypt/live/cloud.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cloud.example.com/privkey.pem;

    # Die .htaccess wird von nginx nicht gelesen; HSTS gehoert hierher
    add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;

    # WebDAV-Umleitungen, siehe Abschnitt ".well-known"
    location ^~ /.well-known/carddav { return 301 /remote.php/dav/; }
    location ^~ /.well-known/caldav  { return 301 /remote.php/dav/; }

    # Grenzen fuer grosse Uploads
    client_max_body_size 0;
    client_body_timeout  300s;

    location / {
        proxy_pass http://127.0.0.1:8080;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host  $host;

        proxy_http_version 1.1;
        proxy_request_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

Liefert nginx die Instanz selbst über PHP-FPM aus, gelten dieselben Punkte für
den FastCGI-Block. Wichtig ist dort zusätzlich, dass die `Authorization`-Kopfzeile
bei PHP ankommt: `lib/base.php` baut `PHP_AUTH_USER`/`PHP_AUTH_PW` aus
`HTTP_AUTHORIZATION` bzw. `REDIRECT_HTTP_AUTHORIZATION` nach. Ohne diese
Kopfzeile scheitert jede Basic-Auth-Anmeldung von WebDAV-Clients.

```nginx
location ~ \.php(?:$|/) {
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    include fastcgi_params;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_read_timeout 3600s;
}
```

## Apache als TLS-Terminator

`RequestHeader unset X-Forwarded-For` entfernt zuerst einen vom Client
mitgeschickten Wert; mod_proxy trägt danach die tatsächliche Peer-Adresse als
einzigen Eintrag ein.

```apache
<VirtualHost *:443>
    ServerName cloud.example.com

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/cloud.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cloud.example.com/privkey.pem

    Header always set Strict-Transport-Security "max-age=15552000; includeSubDomains"

    RequestHeader unset X-Forwarded-For
    RequestHeader set   X-Forwarded-Proto "https"
    RequestHeader set   X-Forwarded-Host  "cloud.example.com"
    ProxyPreserveHost On

    RedirectMatch 301 ^/\.well-known/carddav /remote.php/dav/
    RedirectMatch 301 ^/\.well-known/caldav  /remote.php/dav/

    TimeOut      3600
    ProxyTimeout 3600
    LimitRequestBody 0

    ProxyPass        / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/
</VirtualHost>
```

Terminiert Apache das TLS und führt owncloud.online in derselben Instanz aus,
ist kein Reverse-Proxy im Spiel: `mod_ssl` setzt dann die Umgebungsvariable
`HTTPS`, und die mitgelieferte `.htaccess` setzt den HSTS-Header selbst
(`env=HTTPS`). In diesem Fall darf HSTS nicht zusätzlich im VirtualHost gesetzt
werden, sonst geht der Header doppelt hinaus. Voraussetzung bleibt
`AllowOverride All` für das Verzeichnis, sonst wirken weder die Umleitungen noch
die Sicherheits-Kopfzeilen aus der `.htaccess`.

## Zeitgrenzen für große Uploads

Große Dateien laden die Clients in Teilstücken nach
`/remote.php/dav/uploads/…` hoch und schließen den Vorgang mit einem `MOVE` ab.
Erst dieses `MOVE` setzt die Teile serverseitig zusammen
(`apps/dav/lib/Upload/AssemblyStream.php`) und schreibt die Datei an ihren
Zielort. Der Abschluss ist damit eine einzelne, lange laufende Anfrage ohne
Datenverkehr — genau das Muster, das Proxys nach der Standard-Zeitgrenze
abbrechen. Der Client meldet dann einen Fehler, obwohl alle Teile bereits
übertragen sind.

| Ebene | Stellschraube |
| --- | --- |
| nginx (Proxy) | `client_max_body_size`, `client_body_timeout`, `proxy_read_timeout`, `proxy_send_timeout`, `proxy_request_buffering off` |
| nginx (FastCGI) | `fastcgi_read_timeout` |
| Apache | `TimeOut`, `ProxyTimeout`, `LimitRequestBody` |
| PHP-FPM | `request_terminate_timeout` im Pool |
| PHP | `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`, `max_input_time` |

Ein Stolperstein: Die mitgelieferte `.htaccess` enthält zwar
`php_value upload_max_filesize 513M` und `php_value post_max_size 513M`, aber
nur innerhalb von `<IfModule mod_php5.c>` und `<IfModule mod_php7.c>`. Unter
PHP-FPM — der für owncloud.online empfohlenen Betriebsart — wird dieser Block
nie ausgeführt. Die Werte gehören dort in `php.ini` oder die Pool-Konfiguration.

## .well-known-Umleitungen

Kalender- und Kontakte-Clients suchen den DAV-Endpunkt über
`/.well-known/caldav` und `/.well-known/carddav`. Die mitgelieferte `.htaccess`
leitet beide dauerhaft auf den DAV-Endpunkt um:

```apache
RewriteRule ^\.well-known/carddav /remote.php/dav/ [R=301,L]
RewriteRule ^\.well-known/caldav  /remote.php/dav/ [R=301,L]
```

`/remote.php/dav` ist in `remote.php` fest verdrahtet und ebenso `webdav`,
`caldav`, `carddav` und `files` — die Umleitungen zeigen also auf einen
Endpunkt, den keine App-Registrierung erst herstellen muss.

Zwei Punkte dazu:

- nginx liest keine `.htaccess`. Ohne die beiden `location`-Blöcke aus dem
  nginx-Beispiel fehlen die Umleitungen ersatzlos.
- Die `.htaccess` sperrt Pfade, die mit einem Punkt beginnen, per 404 —
  ausgenommen sind ausdrücklich `/.well-known/acme-challenge/` und
  `/.well-known/pki-validation/`. Wer die Zertifikatsausstellung über einen
  eigenen Pfad abwickelt, muss ihn selbst freigeben.

Die Weboberfläche prüft beide Umleitungen nach: **Einstellungen →
Administration → Allgemein** sendet ein `PROPFIND` auf
`/.well-known/caldav/` und `/.well-known/carddav/` und erwartet den Status 207.
Andernfalls erscheint der Hinweis „Ihr Webserver ist nicht richtig konfiguriert,
um … aufzulösen". Wird die Umleitung bewusst nicht bereitgestellt, lässt sich
die Prüfung abschalten:

```bash
sudo -u www-data php8.4 occ config:system:set \
  check_for_working_wellknown_setup --type boolean --value false
```

## Prüfen

```bash
sudo -u www-data php8.4 occ config:system:get trusted_proxies
sudo -u www-data php8.4 occ config:system:get overwriteprotocol
sudo -u www-data php8.4 occ config:list system
sudo -u www-data php8.4 occ status
```

Ob die Umleitungen greifen, zeigt ein einzelner Aufruf:

```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' \
  https://cloud.example.com/.well-known/caldav/
```

Erwartet wird `301` mit dem Ziel `/remote.php/dav/`.

Welche Adresse tatsächlich ankommt, verrät das Protokoll: Nach einer
fehlgeschlagenen Anmeldung steht sie im Feld `remoteAddr`, siehe
[Serverprotokoll und Fehlermeldungen](logging.md). Erscheint dort die
Proxy-Adresse statt der Client-Adresse, stimmt `trusted_proxies` nicht.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Alle Protokollzeilen zeigen dieselbe Adresse in `remoteAddr` | `trusted_proxies` fehlt oder enthält nicht die Adresse, mit der der Proxy den Server anspricht | Adresse aus `REMOTE_ADDR` ermitteln und exakt eintragen |
| `trusted_proxies` ist gesetzt, wirkt aber nicht | Eintrag in CIDR-Notation; der Vergleich ist ein exakter Zeichenkettenvergleich | Jede Proxy-Adresse einzeln eintragen |
| Legitime Benutzer werden gegenseitig ausgebremst | Die Bremse zählt auf die Proxy-Adresse statt auf den Client | `trusted_proxies` korrigieren |
| Erzeugte Links und Weiterleitungen beginnen mit `http://`, der Browser blockiert Inhalte | `overwriteprotocol` fehlt, der Proxy setzt kein `X-Forwarded-Proto` | `overwriteprotocol` auf `https` setzen |
| „Sie greifen auf den Server über eine nicht vertrauenswürdige Domain zu." | Der Proxy reicht `Host` bzw. `X-Forwarded-Host` nicht durch, oder der Name fehlt in `trusted_domains` | Kopfzeile setzen und den Namen in `trusted_domains` aufnehmen |
| Links zeigen auf einen anderen Hostnamen als den aufgerufenen | Host nicht in `trusted_domains`; der Server fällt auf den ersten Eintrag zurück | Hostnamen ergänzen oder `overwritehost` setzen |
| Bei Installation in einem Unterpfad fehlen Teile der Oberfläche | `overwritewebroot` nicht gesetzt | `overwritewebroot` auf den externen Pfad setzen |
| `overwritecondaddr` begrenzt `overwritehost` nicht | Der Code wertet die Bedingung nur für `overwriteprotocol` aus | Auf die Begrenzung verzichten oder die Unterscheidung im Proxy treffen |
| Setup-Warnung zu `/.well-known/caldav/` | nginx liest die `.htaccess` nicht, die Umleitungen fehlen | `location`-Blöcke ergänzen |
| WebDAV- oder Desktop-Client erhält 401 trotz richtiger Zugangsdaten | Die `Authorization`-Kopfzeile erreicht PHP nicht | Kopfzeile durchreichen, bei FastCGI `HTTP_AUTHORIZATION` setzen |
| Großer Upload bricht kurz vor dem Ende mit 504 ab | Das abschließende `MOVE` setzt die Teile zusammen und überschreitet die Zeitgrenze des Proxys | `proxy_read_timeout` bzw. `ProxyTimeout` und `request_terminate_timeout` erhöhen |
| Upload bricht bei fester Größe ab | Grenze im Proxy oder in PHP; die `php_value`-Zeilen der `.htaccess` gelten nur unter mod_php | `client_max_body_size`/`LimitRequestBody` und `php.ini` prüfen |
| Cron erzeugt Links mit falschem Host | `overwrite.cli.url` fehlt — ohne Request gibt es keine Kopfzeilen | `overwrite.cli.url` auf die externe Basis-URL setzen |

Weiterführend: [Sicherheit und Setup-Warnungen](security-hardening.md),
[Konfiguration (config.php)](config-reference.md) und
[Serverprotokoll und Fehlermeldungen](logging.md).
