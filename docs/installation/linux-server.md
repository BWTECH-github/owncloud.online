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
sudo bash bootstrap-empty-linux-server.sh --domain cloud.example.com --ref 11.0.12
```

Das Script erkennt eine vorhandene `config/config.php` und überschreibt keine bestehenden Datenbank-Zugangsdaten.

## 7. E-Mail (SMTP)

Ohne konfigurierten Mailversand schlagen Passwort-Reset- und Freigabe-Mails
**still** fehl: Standard ist `mail_smtpmode => 'sendmail'`, das einen lokalen
MTA unter `/usr/sbin/sendmail` voraussetzt (den die Anleitung nicht installiert).
Für Produktion SMTP in `config/config.php` setzen:

```php
'mail_smtpmode' => 'smtp',
'mail_smtphost' => 'smtp.example.com',
'mail_smtpport' => 587,
'mail_smtpsecure' => 'tls',
'mail_smtpauth' => true,
'mail_smtpname' => 'noreply@example.com',
'mail_smtppassword' => 'CHANGE_ME',
'mail_from_address' => 'noreply',
'mail_domain' => 'example.com',
```

Test: `sudo -u www-data php8.4 occ config:system:get mail_smtphost` und eine
Test-Mail über **Einstellungen → Allgemein → E-Mail-Server**.

## 8. Log-Rotation

`owncloud.log` wächst standardmäßig unbegrenzt (`log_rotate_size => false`).
Entweder in `config/config.php` die eingebaute Rotation aktivieren …

```php
'log_rotate_size' => 104857600,
```

… (rotiert bei 100 MB nach `owncloud.log.1`) oder ein OS-`logrotate`-Snippet für
`data/owncloud.log` hinterlegen.
