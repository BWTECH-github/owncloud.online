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

Wenn lokale Branding-Dateien bewusst geändert wurden, muss die Abweichung dokumentiert und im Release-Prozess berücksichtigt werden.
