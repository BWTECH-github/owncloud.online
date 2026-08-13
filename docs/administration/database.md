# Datenbank

owncloud.online läuft mit MariaDB/MySQL, PostgreSQL oder SQLite. Für den
produktiven Betrieb ist **MariaDB** die getestete Standardvariante; SQLite ist
nur für lokale Tests geeignet und bricht bei mehreren gleichzeitigen Zugriffen
ein.

## Empfohlene Einstellungen (MariaDB/MySQL)

Die Datenbank muss `utf8mb4` verwenden, sonst scheitern Dateinamen mit Emojis
oder seltenen Zeichen:

```sql
CREATE DATABASE owncloud_online
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

In `config.php` gehört dazu:

```php
'dbtype' => 'mysql',
'mysql.utf8mb4' => true,
```

Prüfen, ob eine bestehende Instanz schon umgestellt ist:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:convert-mysql-charset
```

## Von SQLite auf MariaDB umstellen

Eine mit SQLite aufgesetzte Instanz lässt sich ohne Datenverlust umziehen — die
Benutzerdateien bleiben unberührt, nur die Metadaten wandern.

```bash
# 1. Backup von Daten UND Datenbank (siehe Backups und Updates)

# 2. Wartungsmodus
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on

# 3. Zieldatenbank anlegen (utf8mb4, siehe oben), dann konvertieren
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:convert-type \
  --all-apps mysql owncloud_user 127.0.0.1 owncloud_online

# 4. Wartungsmodus aus
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

`db:convert-type` schreibt die neuen Zugangsdaten selbst in `config.php`. Die
alte SQLite-Datei bleibt liegen — erst löschen, wenn die Instanz nachweislich
läuft.

Nach dem Umzug prüfen:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
sudo -u www-data php8.4 /var/www/owncloud.online/occ files:scan --all
```

## Wartung

```bash
# fehlende Indizes und Constraints nachziehen (nach Updates)
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:add-missing-indices
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:add-missing-columns

# Migrationsstand ansehen
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:status core

# einzelne Migration nachziehen
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:execute core <Version>
```

!!! warning "Migrationen nach jedem Update"
    Nach einem Code-Update muss `occ upgrade` laufen. Fehlen die Migrationen,
    erwartet der Code Spalten, die es in der Datenbank noch nicht gibt — die
    Instanz antwortet dann an einzelnen Stellen mit Fehler 500. Siehe
    [Serverprotokoll und Fehlermeldungen](logging.md).

## Backup und Wiederherstellung

```bash
# Sicherung
mysqldump --single-transaction -u root -p owncloud_online > /root/oco-db-$(date +%F).sql

# Wiederherstellung (Instanz vorher in den Wartungsmodus)
mysql -u root -p owncloud_online < /root/oco-db-2026-08-06.sql
```

Datenbank- und Datensicherung gehören immer zum selben Zeitpunkt — sonst
verweisen Metadaten auf Dateien, die es nicht (mehr) gibt. Vollständiger Ablauf
unter [Backups und Updates](backups-updates.md).

## Häufige Probleme

| Symptom | Ursache und Abhilfe |
| --- | --- |
| „Fehler 500" nach einem Update | Migrationen fehlen → `occ upgrade` |
| Dateinamen mit Emojis schlagen fehl | Datenbank nicht auf `utf8mb4` → `db:convert-mysql-charset` |
| Sehr langsame Dateilisten | Fehlende Indizes → `db:add-missing-indices` |
| „Database is locked" | SQLite im Mehrbenutzerbetrieb → auf MariaDB umstellen |
| Verbindungsabbrüche unter Last | `max_connections` der Datenbank und PHP-FPM-Poolgröße aufeinander abstimmen, siehe [Performance](performance.md) |
