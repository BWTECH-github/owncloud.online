# owncloud.online Installation

Ziel: owncloud.online sauber installieren, bauen, starten und optionale Apps
(`workflow`, `wnd`, `files_primary_s3`) aktivieren.

Wichtig: `files_primary_s3` ist Primary Storage. `objectstore` nur auf neuer
Instanz oder vor erster echter Nutzung setzen. Bestehende lokale Daten werden
nicht automatisch nach S3 migriert.

## 0. Webhosting ohne Root-Rechte

Wenn der Zielserver nur normales Webhosting ist und du dort Composer, npm/yarn
oder Systempakete nicht installieren kannst, nutze nicht die Git-Installation.
Baue vorher auf deinem Windows-PC ein fertiges Webhosting-Paket:

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Build-PluginPackages.ps1
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Create-WebhostingBundle.ps1
```

Danach hochladen:

```text
C:\git\_webhosting_bundle\owncloud-online-webhosting-bundle-YYYYMMDD-HHMMSS.zip
```

Die ZIP-Datei im Hosting-Dateimanager entpacken und den Inhalt aus `owncloud/`
in den Webroot kopieren. Danach die Domain im Browser oeffnen und im
Setup-Formular Admin-User, Datenverzeichnis und MySQL/MariaDB-Zugangsdaten
eintragen.

Voraussetzungen:

- PHP 8.4
- MySQL/MariaDB
- `.htaccess` erlaubt
- ZIP im Dateimanager entpackbar
- Webcron oder Cronjob

Details:

```text
C:\git\owncloud-online-release-tools\WEBHOSTING_INSTALLATION_DE.md
```

## 1. Geraet/Plattform

Empfohlen fuer Produktion:

- VPS, Bare Metal, Proxmox VM/LXC: Ubuntu 24.04 LTS oder Debian 12.
- Raspberry Pi/ARM: Ubuntu Server 24.04 64-bit, SSD statt SD-Karte.
- Synology/QNAP: besser VM oder Container mit Ubuntu, nicht direkt im NAS-Webserver.
- Windows/macOS: fuer Entwicklung WSL2, Docker oder VM nutzen.
- Handy/Tablet/Client-PC: keine Serverinstallation. Nur Browser/App auf die URL.

## 2. Ubuntu/Debian Basis

```bash
apt update
apt upgrade -y
apt install -y software-properties-common curl wget git unzip make npm
npm install -g yarn
```

PHP 8.4 installieren:

```bash
add-apt-repository ppa:ondrej/php -y
apt update
apt install -y \
  php8.4-common php8.4-cli php8.4-fpm php8.4-opcache \
  php8.4-mysql php8.4-sqlite3 php8.4-xml php8.4-curl \
  php8.4-zip php8.4-mbstring php8.4-gd php8.4-intl \
  php8.4-bcmath php8.4-apcu php8.4-imagick php8.4-memcached
```

Composer:

```bash
cd /usr/local/bin
curl -sS https://getcomposer.org/installer | php8.4
mv composer.phar composer
chmod +x composer
composer --version
```

## 3. Datenbank

MariaDB:

```bash
apt install -y mariadb-server
mysql_secure_installation
mysql -u root -p
```

SQL:

```sql
CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'owncloud'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON owncloud_online.* TO 'owncloud'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 4. Code holen und bauen

```bash
cd /var/www
git clone https://github.com/BWTECH-github/owncloud.online.git owncloud.online
cd /var/www/owncloud.online
composer install
make
```

Wenn `composer.lock` wegen PHP 8.4 nicht passt:

```bash
composer update
make
```

Rechte:

```bash
mkdir -p /var/owncloud-online-data
chown -R www-data:www-data /var/www/owncloud.online /var/owncloud-online-data
```

## 5. Apache Produktion

```bash
apt install -y apache2 libapache2-mod-fcgid
a2enmod rewrite headers env dir mime proxy_fcgi setenvif http2 deflate brotli
a2enconf php8.4-fpm
```

`http2` aktiviert HTTP/2-Multiplexing, `deflate`/`brotli` die Antwort-Kompression
(die passenden Direktiven liegen bereits in der `.htaccess`). Beides zusammen ist
laut `docs/administration/performance.md` der wirksamste Frontend-Hebel.

VirtualHost `/etc/apache2/sites-available/owncloud.online.conf`:

```apache
<VirtualHost *:80>
    ServerName cloud.example.com
    DocumentRoot /var/www/owncloud.online

    <Directory /var/www/owncloud.online>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Aktivieren:

```bash
a2ensite owncloud.online.conf
a2dissite 000-default.conf
apachectl configtest
systemctl reload apache2
```

HTTPS:

```bash
apt install -y certbot python3-certbot-apache
certbot --apache -d cloud.example.com
```

Danach HTTP/2 im SSL-VirtualHost aktivieren: In der von certbot angelegten
`/etc/apache2/sites-available/owncloud.online-le-ssl.conf` direkt unter
`<VirtualHost *:443>` ergaenzen:

```apache
Protocols h2 http/1.1
```

```bash
apachectl configtest
systemctl reload apache2
```

## 6. Erstinstallation

CLI:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:install \
  --database mysql \
  --database-name owncloud_online \
  --database-user owncloud \
  --database-pass 'CHANGE_ME_STRONG_PASSWORD' \
  --admin-user admin \
  --admin-pass 'CHANGE_ME_ADMIN_PASSWORD' \
  --data-dir /var/owncloud-online-data
```

Basis-Config:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:set trusted_domains 1 --value cloud.example.com
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:set overwrite.cli.url --value https://cloud.example.com
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:set memcache.local --value '\OC\Memcache\APCu'
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:set version.hide --value true --type boolean
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:repair
```

Wichtig: Ohne `memcache.local` laeuft die Instanz mit `NullCache` — dann werden
Sprachdateien und App-Metadaten bei jedem Request neu geparst. APCu ist ueber
`php8.4-apcu` bereits installiert. Damit auch `occ` und `cron.php` den Cache
nutzen, APCu fuer die CLI aktivieren:

```bash
echo 'apc.enable_cli=1' > /etc/php/8.4/cli/conf.d/99-owncloud-apcu-cli.ini
```

Fuer produktive Instanzen zusaetzlich Redis als Locking-Backend einrichten
(`memcache.locking`) — Anleitung in `docs/administration/security-hardening.md`
(Abschnitte "Memory Cache" und "Transactional File Locking"), weitere
Performance-Optionen in `docs/administration/performance.md`.

## 7. Apps installieren

Apps in `/var/www/owncloud.online/apps/<app_id>` kopieren, dann je App:

```bash
cd /var/www/owncloud.online/apps/files_primary_s3
composer install --no-dev --optimize-autoloader
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:enable files_primary_s3
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:check-code files_primary_s3
```

Workflow/WND:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:enable workflow
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:enable wnd
```

## 8. S3 Primary Object Storage

Nur auf neuer Instanz aktivieren. Bucket vorher erstellen oder mit App-Befehl
erstellen. Beispiel fuer MinIO/Ceph/Scality/S3-kompatibel:

```php
'objectstore' => [
    'class' => 'OCA\\Files_Primary_S3\\S3Storage',
    'arguments' => [
        'bucket' => 'owncloud-primary',
        'part_size' => 5242880,
        'concurrency' => 2,
        'options' => [
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.example.com',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => 'ACCESS_KEY',
                'secret' => 'SECRET_KEY',
            ],
        ],
    ],
],
```

Pruefen:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ s3:create-bucket owncloud-primary --accept-warning
sudo -u www-data php8.4 /var/www/owncloud.online/occ s3:list
```

## 9. Lokale Entwicklung/WSL

Schnellstart ohne Apache:

```bash
cd /opt/owncloud.online-live
php8.4 -S 127.0.0.1:8088 -t /opt/owncloud.online-live  # nur lokal, NICHT fuer Produktion (Dev-Server)
```

Dann Browser:

```text
http://localhost:8088
```

## 10. Checks nach Installation

```bash
php8.4 occ status
php8.4 occ app:list
php8.4 occ maintenance:repair
curl -I https://cloud.example.com/status.php
```

Hinweis: `occ integrity:check-core` ist auf dem Kanal `bwtech` deaktiviert und
meldet immer Erfolg — zur echten Update-Kontrolle stattdessen die
SHA256SUMS/SBOM-Artefakte des Releases pruefen (Details in
`docs/administration/security-hardening.md`, Abschnitt "Integritätsprüfung").

Cron:

```bash
crontab -u www-data -e
```

Eintrag:

```cron
*/15 * * * * php8.4 -f /var/www/owncloud.online/cron.php
```

## 11. Backup

Immer zusammen sichern:

- Datenbank Dump.
- `/var/www/owncloud.online/config/config.php`.
- Lokales Data-Verzeichnis oder S3-Bucket.
- Apps-Verzeichnis mit Custom Apps.

MariaDB Dump:

```bash
mysqldump -u owncloud -p owncloud_online > owncloud_online.sql
```

## 12. Fehlerbilder

- Keine Loginmaske: `make` nicht gelaufen, Composer/Yarn fehlt, Apache zeigt falschen DocumentRoot.
- 500 nach App Enable: `composer install` im App-Ordner fehlt oder PHP Extension fehlt.
- S3 Klasse nicht gefunden: App nicht enabled oder `objectstore.class` falsch escaped.
- S3 Daten fehlen: `objectstore` auf bestehender lokaler Instanz gesetzt. Restore Config/Backup, Migration separat planen.
- WebDAV 507: Storage meldet keinen freien Speicher oder S3 Bucket nicht erreichbar.
