# Sicherheit und Setup-Warnungen

Gelbe Setup-Warnungen dürfen bei Kundeninstallationen nicht ignoriert werden. Sie zeigen fehlende Produktionskonfiguration an.

## HTTPS

Produktivsysteme müssen über HTTPS laufen.

```bash
certbot --apache -d cloud.example.com
```

Danach setzen:

```bash
sudo -u www-data php8.4 occ config:system:set overwrite.cli.url --value https://cloud.example.com
```

## System-Cron

```bash
crontab -u www-data -e
```

Eintrag:

```cron
*/15 * * * * php8.4 -f /var/www/owncloud.online/cron.php
```

In owncloud.online:

```bash
sudo -u www-data php8.4 occ background:cron
```

## Memory Cache

APCu:

```bash
apt install -y php8.4-apcu
systemctl reload php8.4-fpm
```

`config/config.php`:

```php
'memcache.local' => '\\OC\\Memcache\\APCu',
```

Ohne gesetztes `memcache.local` fällt die Cache-Factory auf `NullCache` zurück
(`lib/private/Memcache/Factory.php`) – die fork-eigenen APCu-Optimierungen
(L10N-Cache, App-Info-Cache) bleiben dann wirkungslos, jede Sprachdatei und jede
`info.xml` wird pro Request neu geparst. Damit auch `occ` und `cron.php` nicht
cachelos laufen, muss APCu in der **CLI**-`php.ini` aktiv sein:

```ini
apc.enable_cli=1
```

## Transactional File Locking

Für Produktionssysteme Redis verwenden:

```bash
apt install -y redis-server php8.4-redis
systemctl enable --now redis-server
systemctl reload php8.4-fpm
```

`config/config.php`:

```php
'memcache.locking' => '\\OC\\Memcache\\Redis',
'redis' => [
    'host' => '127.0.0.1',
    'port' => 6379,
],
```

## Datenbank

SQLite ist nur für lokale Tests geeignet. Kundeninstallationen müssen MariaDB oder MySQL nutzen.

## Integritätsprüfung

Nach Updates:

```bash
sudo -u www-data php8.4 occ integrity:check-core
sudo -u www-data php8.4 occ integrity:check-app market
```

> **Achtung:** `integrity:check-core` ist für den Kanal `bwtech` **deaktiviert**
> (`lib/private/IntegrityCheck/Checker.php`, `$notSignedChannels`) und meldet
> daher **immer** Erfolg – der Core-Build ist nicht signiert. Der Befehl taugt
> auf diesem Kanal **nicht** als Update-Kontrolle. Verlassen Sie sich stattdessen
> auf die SHA256SUMS/SBOM-Artefakte des Releases; `integrity:check-app` für
> einzelne (signierte) Apps bleibt aussagekräftig.

Wenn lokale Branding-Dateien bewusst geändert wurden, muss die Abweichung dokumentiert und im Release-Prozess berücksichtigt werden.

## Versionsinformationen verbergen

`status.php` liefert unauthentifiziert die exakte Version/Edition aus (mit
`Access-Control-Allow-Origin: *`) – zusammen mit dem detaillierten CHANGELOG
ideal zum Fingerprinting ungepatchter Instanzen. Für Kundeninstanzen empfehlen:

```php
'version.hide' => true,
```

Danach lässt `status.php` `version`/`versionstring`/`edition` weg. Trade-off:
Monitoring, das die Version aus `status.php` parst, sieht sie dann nicht mehr.

## Update-Prüfung

Der Update-Checker fragt standardmäßig `updates.owncloud.com` mit dem Kanal
`bwtech` ab — ein Erbe aus der Herkunft des Codes. Der Dienst dort kennt weder
diesen Kanal noch unsere Versionen –
Betreiber erhalten so **nie** Update-Hinweise, senden aber Instanz-Metadaten an
einen Fremdserver. Daher entweder einen eigenen Endpoint hinterlegen
(`'updater.server.url' => '…'`) oder die Prüfung abschalten:

```php
'updatechecker' => false,
```

## HSTS (Strict-Transport-Security)

Die ausgelieferte `.htaccess` setzt HSTS **nur**, wenn Apache selbst TLS
terminiert (`env=HTTPS`, von `mod_ssl` gesetzt):

```apache
Header always set Strict-Transport-Security "max-age=15552000; includeSubDomains" env=HTTPS
```

**nginx / Reverse-Proxy:** Die `.htaccess` wird von nginx ignoriert und hinter
einem TLS-terminierenden Proxy sieht das Backend nur HTTP – dort muss der
**Proxy/nginx** den Header setzen (einmalig, sonst Doppel-Header):

```nginx
add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;
```

Vor dem Setzen sicherstellen, dass **alle** Subdomains dauerhaft HTTPS können
(HSTS ist für `max-age` bindend). Optional `preload` erst nach Test ergänzen.

## Reverse-Proxy: trusted_proxies

Hinter einem Reverse-Proxy sieht der Server als Absender jeder Anfrage den
Proxy. Die echte Client-Adresse steht dann in `X-Forwarded-For` — und dieser
Header wird **nur** für Adressen ausgewertet, die als `trusted_proxies`
eingetragen sind:

```php
'trusted_proxies' => ['10.0.0.5'],
'overwriteprotocol' => 'https',
```

Das ist sicherheitsrelevant, weil mehrere Schutzmechanismen die Client-IP als
Schlüssel benutzen — allen voran die Bremse gegen Passwort-Raten bei Anmeldung,
Freigabe-Passwörtern und am MCP-Endpunkt. Sie zählt Fehlversuche pro
(IP, Konto).

| Konstellation | Folge |
| --- | --- |
| `trusted_proxies` korrekt gesetzt | Zählung trifft die echte Client-IP — Schutz wirkt |
| `trusted_proxies` **nicht** gesetzt | Alle Anfragen zählen auf die Proxy-IP — legitime Nutzer bremsen sich gegenseitig aus |
| App-Server **direkt** aus dem Netz erreichbar | Ein Angreifer setzt `X-Forwarded-For` selbst und bekommt pro Anfrage eine neue „IP" — die Bremse greift nie |

Deshalb gehören beide Punkte in die Inbetriebnahme:

1. `trusted_proxies` auf die **tatsächlichen** Proxy-Adressen setzen (keine
   Netzbereiche „auf Verdacht", keine `0.0.0.0/0`).
2. Den PHP-FPM-/App-Server **niemals** direkt exponieren — er darf nur über den
   Reverse-Proxy erreichbar sein (Firewall oder Bind an `127.0.0.1`).

Prüfen, welche Adresse tatsächlich ankommt: Nach einer fehlgeschlagenen
Anmeldung steht sie im Protokoll (`remoteAddr`), siehe
[Serverprotokoll und Fehlermeldungen](logging.md). Erscheint dort die Proxy-IP
statt der Client-IP, ist `trusted_proxies` falsch.

## PHP-Upload-Fortschritt

PHP kann den Fortschritt eines Formular-Uploads in der Sitzung mitschreiben
(`session.upload_progress.enabled`, ab Werk an). owncloud.online nutzt das
nicht – die Fortschrittsanzeige im Browser rechnet selbst. Solange es an ist,
schreibt PHP bei jedem Upload mit dem Formularfeld `PHP_SESSION_UPLOAD_PROGRESS`
Daten des Absenders in den Sitzungsspeicher, bevor owncloud.online die Anfrage
überhaupt sieht und ohne Anmeldung. Das ist ein bekannter Baustein für Angriffe
über eingebundene Dateien und füllt nebenbei den Sitzungsspeicher.

Die mitgelieferte `.user.ini` (PHP-FPM, CGI) und `.htaccess` (Apache mit
`mod_php`) schalten es ab. Beide gelten nur für den owncloud.online-Ordner, und
PHP-FPM liest die `.user.ini` erst nach bis zu fünf Minuten neu
(`user_ini.cache_ttl`). Serverweit und sofort wirkt die Einstellung in der
`php.ini` oder im FPM-Pool:

```ini
; php.ini
session.upload_progress.enabled = Off

; oder im Pool, z. B. /etc/php/8.4/fpm/pool.d/www.conf
php_admin_flag[session.upload_progress.enabled] = off
```

Wirksam ist der Wert, den `phpinfo()` im Abschnitt „session“ als „Local Value“
zeigt; die CLI (`php -i`, `occ`) hat ihre eigene `php.ini` und sagt darüber
nichts.
