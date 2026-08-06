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

### Wartungsmodus vor dem Austausch von Theme-Apps

Apps vom Typ `theme` (etwa `theme-owncloudonline`) werden bereits beim Start
jedes Requests geladen — auch beim Start von `occ` selbst. Wird das
App-Verzeichnis durch eine neuere Version ersetzt, bricht **jeder**
`occ`-Aufruf mit `OC\NeedsUpdateException` ab, also auch das `occ upgrade`,
das die Situation auflösen soll:

```
An unhandled exception has been thrown:
OC\NeedsUpdateException in lib/private/legacy/app.php:194
```

Der Ausweg ist der Wartungsmodus: `OC_App::loadApps()` überspringt in diesem
Zustand das Laden der Apps, `occ` startet wieder. Beim automatisierten Ausrollen
gehört der Wartungsmodus deshalb **vor** den Verzeichnistausch:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
# jetzt erst das neue App-Verzeichnis einspielen
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:mode --off
```

Ist der Wartungsmodus nicht mehr über `occ` erreichbar, weil das Verzeichnis
bereits getauscht wurde, hilft der direkte Eintrag in `config/config.php`:

```php
'maintenance' => true,
```

Bricht ein App-Update ab, weil die Datenbank eine **neuere** Version meldet als
der ausgerollte Code, ist ein falsches oder älteres Paket ausgeliefert worden.
Die Meldung nennt beide Versionen; die in der Datenbank vermerkte Version bleibt
dabei unverändert. Richtiges Paket nachliefern und erneut aktualisieren.

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
