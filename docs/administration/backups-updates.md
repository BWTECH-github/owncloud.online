# Backups und Updates

Legen Sie vor jedem Update des Kerns oder einer App eine Sicherung an. Zurück
auf die alte Version geht es nur über diese Sicherung: `occ upgrade` ändert die
Datenbank, und eine ältere Version lehnt der Server danach ab („Downgrading is
not supported …“).

Die Beispiele verwenden dieselben Werte wie die
[Installationsanleitung](../installation/linux-server.md): Installationsverzeichnis
`/var/www/owncloud.online`, Datenverzeichnis `/var/oco-data`, Datenbank
`owncloud_online`, Apache mit PHP-FPM. Die Befehle laufen als `root`. Das
tatsächliche Datenverzeichnis Ihrer Instanz zeigt:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:get datadirectory
```

## Sicherung

Drei Teile gehören zusammen und müssen vom selben Zeitpunkt stammen: das
Programmverzeichnis mit `config/config.php`, das Datenverzeichnis und die
Datenbank. Der Wartungsmodus hält währenddessen Webzugriffe und Hintergrund-Jobs
an (`occ system:cron` überspringt seine Läufe im Wartungsmodus).

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
systemctl stop apache2
systemctl stop php8.4-fpm

mkdir -p /var/backups/owncloud.online
cd /var/backups/owncloud.online
tar -czf code-$(date +%F).tar.gz -C /var/www owncloud.online
tar -czf data-$(date +%F).tar.gz -C /var oco-data
mysqldump --single-transaction -u root -p owncloud_online > db-$(date +%F).sql

systemctl start php8.4-fpm
systemctl start apache2
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

Bei nginx heißt der Dienst `nginx` statt `apache2`. Folgt direkt ein Update,
lassen Sie die Dienste angehalten und den Wartungsmodus an.

Im Datenverzeichnis liegen auch versteckte Dateien, die zur Funktion gehören,
etwa `.ocdata` und bei aktiver Verschlüsselung die Schlüssel. `tar` auf das
ganze Verzeichnis erfasst sie. Bewahren Sie eine Kopie der Sicherung außerhalb
des Servers auf.

## Update

Ein Update tauscht das Programmverzeichnis gegen das neue Release-Paket aus und
passt danach mit `occ upgrade` die Datenbank an. Aus dem alten Verzeichnis
übernehmen Sie nur `config/config.php` und die Apps aus dem Marktplatz unter
`apps-external/`. Das Paket selbst enthält nur die Kern-Apps und die Market-App.

Ohne SSH-Zugang beschreibt [Webhosting](../installation/webhosting.md) den Weg
über FTP und die Update-Seite im Browser.

**1. Release herunterladen und prüfen.** Der Abgleich muss `OK` ausgeben:

```bash
cd /tmp
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/owncloud-online-11.0.25.tar.gz
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/SHA256SUMS.txt
sha256sum --ignore-missing -c SHA256SUMS.txt
```

**2. Sichern** wie oben, aber ohne die letzten drei Befehle: Die Dienste
bleiben angehalten, der Wartungsmodus bleibt an.

**3. Programmverzeichnis tauschen.** Das alte Verzeichnis bleibt als
`owncloud.online.alt` liegen, bis das Update geprüft ist:

```bash
mv /var/www/owncloud.online /var/www/owncloud.online.alt
tar -xzf /tmp/owncloud-online-11.0.25.tar.gz -C /var/www
mv /var/www/owncloud /var/www/owncloud.online
```

**4. Konfiguration und Apps übernehmen:**

```bash
cp -a /var/www/owncloud.online.alt/config/*config.php /var/www/owncloud.online/config/
rsync -a --exclude=/market /var/www/owncloud.online.alt/apps-external/ \
  /var/www/owncloud.online/apps-external/
chown -R www-data:www-data /var/www/owncloud.online
```

`*config.php` erfasst `config.php` und weitere Dateien der Form
`<name>.config.php`. `rsync` (gleichnamiges Paket) kopiert alle Apps aus dem
Marktplatz, nur die Market-App selbst kommt aus dem neuen Paket. Liegt das
Datenverzeichnis ausnahmsweise im Programmverzeichnis (`data/`), verschieben
Sie es vor dem Start ebenfalls in das neue Verzeichnis.

**5. Datenbank aktualisieren und Wartungsmodus beenden:**

```bash
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:repair
systemctl start php8.4-fpm
systemctl start apache2
sudo -u www-data php8.4 occ maintenance:mode --off
```

`occ upgrade` lässt den Wartungsmodus an, wenn er schon vorher an war. Deshalb
schaltet ihn der letzte Befehl ab. `occ maintenance:repair` läuft nur im
Wartungsmodus. Ist `htaccess.RewriteBase` gesetzt, schreibt `occ upgrade` den
Rewrite-Block wieder in die neue `.htaccess`, sofern `overwrite.cli.url`
gesetzt ist.

**6. Prüfen:**

```bash
sudo -u www-data php8.4 occ status
sudo -u www-data php8.4 occ app:list
```

`occ status` muss die neue Version zeigen. Fehlt eine App in `app:list`, fehlt
meist ihr Verzeichnis unter `apps-external/`. Prüfen Sie danach das
[Serverprotokoll](logging.md) auf neue Fehler. Läuft alles, löschen Sie
`/var/www/owncloud.online.alt`.

Haben Sie OPcache-Preloading eingerichtet, legen Sie `build/preload.php` vor dem
Start von PHP-FPM wieder ab, siehe [Performance](performance.md).

Updates einzelner Apps aus dem Marktplatz beschreibt
[Apps verwalten](apps-market.md).

### Wartungsmodus vor dem Austausch von Theme-Apps

Bis owncloud.online 11.0.11 lud der Kern Apps vom Typ `theme` (etwa
`theme-owncloudonline`), `session` und `license` schon beim Start jeder Anfrage,
auch beim Start von `occ` selbst. Wurde ein solches App-Verzeichnis durch eine
Version mit geänderter Haupt- oder Nebenversion ersetzt (etwa 1.2.x auf 3.0.0),
brach **jeder** `occ`-Aufruf mit `OC\NeedsUpdateException` ab, also auch das
`occ upgrade`, das die Lage auflösen soll:

```text
An unhandled exception has been thrown:
OC\NeedsUpdateException in lib/private/legacy/app.php:194
```

Seit 11.0.12 lädt der Kern diese Apps erst, wenn kein Update mehr aussteht, und
`occ upgrade` läuft durch. Patch-Versionen waren nie betroffen.

Unabhängig von der Version gilt beim automatisierten Ausrollen: Wartungsmodus
**vor** dem Austausch eines App-Verzeichnisses einschalten. Im Wartungsmodus
überspringt `OC_App::loadApps()` das Laden der Apps, `occ` startet also auch auf
älteren Versionen:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
# erst jetzt das neue App-Verzeichnis einspielen
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:mode --off
```

Ist `occ` schon blockiert, weil das Verzeichnis bereits getauscht wurde, hilft
der direkte Eintrag in `config/config.php`:

```php
'maintenance' => true,
```

Bricht ein App-Update ab, weil die Datenbank eine **neuere** Version meldet als
der ausgerollte Code, wurde ein falsches oder älteres Paket ausgeliefert. Die
Meldung nennt beide Versionen; die in der Datenbank vermerkte Version bleibt
dabei unverändert. Liefern Sie das richtige Paket nach und aktualisieren Sie
erneut.

## Zurück auf die alte Version

1. Wartungsmodus aktivieren und Dienste anhalten.
2. Programmverzeichnis aus der Sicherung zurückspielen.
3. Datenbank-Dump in eine leere Datenbank einspielen.
4. Datenverzeichnis aus der Sicherung zurückspielen.
5. `occ maintenance:repair` ausführen.
6. Dienste starten, Wartungsmodus deaktivieren.

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
systemctl stop apache2
systemctl stop php8.4-fpm

cd /var/backups/owncloud.online
mv /var/www/owncloud.online /var/www/owncloud.online.defekt
tar -xzf code-JJJJ-MM-TT.tar.gz -C /var/www

mysql -u root -p -e "DROP DATABASE owncloud_online;
  CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root -p owncloud_online < db-JJJJ-MM-TT.sql

mv /var/oco-data /var/oco-data.defekt
tar -xzf data-JJJJ-MM-TT.tar.gz -C /var

sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:repair
systemctl start php8.4-fpm
systemctl start apache2
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

Die Datenbank muss vor dem Einspielen leer sein: Tabellen, die erst das Update
angelegt hat, stünden sonst neben den Daten aus dem Dump. `DROP DATABASE` nimmt
dem Datenbankbenutzer seine Rechte auf `owncloud_online.*` nicht. Die
Verzeichnisse mit der Endung `.defekt` löschen Sie, sobald die Instanz wieder
läuft.
