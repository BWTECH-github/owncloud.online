# Leerer Linux-Server

Diese Installation ist für einen neuen Ubuntu- oder Debian-Server gedacht. Das Bootstrap-Script installiert Systempakete, PHP 8.4, MariaDB, Apache, Composer, ownCloud.online und Plugin-Pakete.

## 1. Bundle auf Windows bauen

Auf dem Windows-PC:

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Build-PluginPackages.ps1
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Create-ServerBundle.ps1
```

Ergebnis:

```text
C:\git\_server_bundle\owncloud-online-server-bundle-YYYYMMDD-HHMMSS.tar.gz
```

## 2. Bundle auf Server kopieren

```powershell
scp C:\git\_server_bundle\owncloud-online-server-bundle-*.tar.gz root@SERVER_IP:/root/
```

## 3. Installation ausführen

Auf dem Linux-Server:

```bash
cd /root
tar -xzf owncloud-online-server-bundle-*.tar.gz
cd owncloud-online-server-bundle
sudo bash bootstrap-empty-linux-server.sh --domain cloud.example.com --ref main
```

Für eine Installation ohne Domain kann vorübergehend die Server-IP verwendet werden:

```bash
sudo bash bootstrap-empty-linux-server.sh --domain SERVER_IP --ref main
```

## 4. Ergebnis prüfen

Zugangsdaten:

```text
/root/owncloud-online-credentials.txt
```

Status:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:list
curl -I http://cloud.example.com/status.php
```

## 5. HTTPS aktivieren

```bash
apt install -y certbot python3-certbot-apache
certbot --apache -d cloud.example.com
```

Danach in `config/config.php` prüfen:

```php
'overwrite.cli.url' => 'https://cloud.example.com',
'trusted_domains' => [
    0 => 'cloud.example.com',
],
```

## 6. Updates

Für ein späteres Update auf einen Tag oder Branch:

```bash
cd /root/owncloud-online-server-bundle
sudo bash bootstrap-empty-linux-server.sh --domain cloud.example.com --ref 11.0.1
```

Das Script erkennt eine vorhandene `config/config.php` und überschreibt keine bestehenden Datenbank-Zugangsdaten.
