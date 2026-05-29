# Backups und Updates

Backups müssen vor jedem Core- oder Plugin-Update erstellt werden.

## Backup

```bash
systemctl stop apache2
systemctl stop php8.4-fpm

tar -czf /root/owncloud-online-code-$(date +%F).tar.gz /var/www/owncloud.online
tar -czf /root/owncloud-online-data-$(date +%F).tar.gz /var/owncloud-online-data
mysqldump -u root -p owncloud_online > /root/owncloud-online-db-$(date +%F).sql

systemctl start php8.4-fpm
systemctl start apache2
```

## Update Ablauf

```bash
cd /root/owncloud-online-server-bundle
sudo bash bootstrap-empty-linux-server.sh --domain cloud.example.com --ref 11.0.1
```

Danach:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ upgrade
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:repair
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:list
```

## Rollback

1. Wartungsmodus aktivieren.
2. Code-Backup zurückspielen.
3. Datenbankdump zurückspielen.
4. Data-Verzeichnis zurückspielen.
5. `occ maintenance:repair` ausführen.
6. Wartungsmodus deaktivieren.

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
sudo -u www-data php8.4 occ maintenance:mode --off
```
