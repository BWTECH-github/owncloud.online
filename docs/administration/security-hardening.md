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

In ownCloud.online:

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
`bwtech` ab. Der Upstream kennt weder diesen Kanal noch die Fork-Versionen –
Betreiber erhalten so **nie** Update-Hinweise, senden aber Instanz-Metadaten an
einen Fremdserver. Daher entweder einen eigenen Endpoint hinterlegen
(`'updater.server.url' => '…'`) oder die Prüfung abschalten:

```php
'updatechecker' => false,
```
