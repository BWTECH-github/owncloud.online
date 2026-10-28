# Datenbank

owncloud.online läuft mit MariaDB/MySQL, PostgreSQL oder SQLite. Für den
produktiven Betrieb ist **MariaDB** die getestete Standardvariante. SQLite
eignet sich nur für lokale Tests und bricht bei mehreren gleichzeitigen
Zugriffen ein.

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

Ob eine bestehende Instanz schon umgestellt ist, zeigt dieser Befehl; die
erwartete Ausgabe ist `true`:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:get mysql.utf8mb4
```

Umgestellt wird mit `db:convert-mysql-charset`. Der Befehl prüft zuerst, ob der
Datenbankserver `utf8mb4` unterstützt, setzt dann `mysql.utf8mb4` in
`config.php` und stellt alle Tabellen um. Legen Sie vorher eine Sicherung an
und schalten Sie den Wartungsmodus ein:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:convert-mysql-charset
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

## Von SQLite auf MariaDB umstellen

Eine Umwandlung an Ort und Stelle gibt es in dieser Version nicht. Der frühere
Befehl `db:convert-type` ist entfernt (Changelog: „This experimental command is
untested and unsupported and therefore removed.“). Planen Sie MariaDB deshalb
gleich bei der Installation ein.

Für eine bestehende SQLite-Instanz bleibt nur, mit MariaDB neu aufzusetzen und
die Dateien neu einzulesen. Alles, was nur in der Datenbank steht, geht dabei
verloren, etwa Freigaben, Kommentare, Tags und App-Einstellungen. Den Ablauf und
die vollständige Liste beschreibt
[Umzug auf einen anderen Server](migration.md#datenbanktyp-wechseln).

## Wartung

Indizes und Spalten legt der Server über Migrationen an. Einen eigenen Befehl
zum Nachziehen fehlender Indizes gibt es nicht.

```bash
# Migrationsstand ansehen ("New Migrations" sollte 0 sein)
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:status core

# ausstehende Migrationen bis zum neuesten Stand ausführen
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:migrate core

# einzelne Migration nachziehen
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:execute core <Version>
```

Nach einem Wechsel auf MariaDB 10.6 oder neuer stellt
`db:restore-default-row-format` alle Tabellen, deren Zeilenformat vom Standard
des Servers abweicht (etwa das veraltete `COMPRESSED`), auf das Standardformat
um:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:restore-default-row-format
```

!!! warning "Migrationen nach jedem Update"
    Nach einem Code-Update muss `occ upgrade` laufen. Fehlen die Migrationen,
    erwartet der Code Spalten, die es in der Datenbank noch nicht gibt. Die
    Instanz antwortet dann an einzelnen Stellen mit Fehler 500. Siehe
    [Serverprotokoll und Fehlermeldungen](logging.md).

## Backup und Wiederherstellung

```bash
# Sicherung
mkdir -p /var/backups/owncloud.online
mysqldump --single-transaction -u root -p owncloud_online > /var/backups/owncloud.online/db-$(date +%F).sql

# Wiederherstellung (Instanz vorher in den Wartungsmodus)
mysql -u root -p owncloud_online < /var/backups/owncloud.online/db-JJJJ-MM-TT.sql
```

Sichern Sie Datenbank und Datenverzeichnis immer zum selben Zeitpunkt. Sonst
verweisen Metadaten auf Dateien, die es nicht (mehr) gibt. Den vollständigen
Ablauf beschreibt [Backups und Updates](backups-updates.md).

## Häufige Probleme

| Symptom | Ursache und Abhilfe |
| --- | --- |
| „Fehler 500“ nach einem Update | Migrationen fehlen → `occ upgrade` |
| Dateinamen mit Emojis schlagen fehl | Datenbank nicht auf `utf8mb4` → `db:convert-mysql-charset` |
| Sehr langsame Dateilisten | Indizes fehlen, weil Migrationen nicht gelaufen sind → `occ migrations:status core`, dann `occ migrations:migrate core` |
| „Database is locked“ | SQLite im Mehrbenutzerbetrieb → mit MariaDB neu aufsetzen, siehe oben |
| Verbindungsabbrüche unter Last | `max_connections` der Datenbank und PHP-FPM-Poolgröße aufeinander abstimmen, siehe [Performance](performance.md) |
