# Installation

ownCloud.online kann auf drei Wegen installiert werden:

| Variante | Zielgruppe | Paket |
| --- | --- | --- |
| Leerer Linux-Server | VPS, VM, Root-Server | Server-Bundle `.tar.gz` |
| Webhosting | Benutzer ohne Root, SSH oder Composer | Webhosting-ZIP |
| Lokale Entwicklung | Windows mit WSL2 | Git-Checkout oder lokaler Release-Build |

## Empfohlene Produktionsvariante

Für Kundeninstallationen ist ein leerer Linux-Server mit Ubuntu 24.04 LTS oder Debian 12 die beste Basis. Dort können PHP 8.4, MariaDB, Apache/Nginx, Cron, Redis/APCu und HTTPS sauber eingerichtet werden.

## Mindestanforderungen

| Komponente | Empfehlung |
| --- | --- |
| PHP | 8.4 |
| Datenbank | MariaDB oder MySQL |
| Webserver | Apache mit PHP-FPM oder Nginx mit PHP-FPM |
| Speicher | SSD, Data-Verzeichnis außerhalb des Webroots |
| Cron | System-Cron |
| HTTPS | Pflicht für produktive Nutzung |
| Cache | APCu lokal, Redis für File Locking |

## Nach jeder Installation prüfen

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:list
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:repair
```

Im Browser muss `/status.php` ownCloud.online ausgeben:

```json
{
  "installed": true,
  "maintenance": false,
  "version": "11.0.0.0",
  "versionstring": "11.0.0",
  "productname": "ownCloud.online",
  "product": "ownCloud.online"
}
```
