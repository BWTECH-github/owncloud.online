# Administration

Die Administration umfasst Systemzustand, Apps, Cron, Cache, Datenbank, Sicherheit, Backups und Updates.

## Tägliche Checks

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:list
sudo -u www-data php8.4 /var/www/owncloud.online/occ background:queue:status
```

## Wichtige Pfade

| Pfad | Zweck |
| --- | --- |
| `/var/www/owncloud.online` | Anwendungscode |
| `/var/owncloud-online-data` | Benutzerdaten |
| `/var/log/apache2` | Apache Logs |
| `/var/log/php8.4-fpm.log` | PHP-FPM Logs |
| `config/config.php` | Systemkonfiguration |

## Admin UI

![Admin Einstellungen](../assets/screenshots/owncloud-online-admin-settings.png)

## Apps UI

![Apps Ansicht](../assets/screenshots/owncloud-online-apps.png)
