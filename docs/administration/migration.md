# Umzug auf einen anderen Server

Ein Umzug verschiebt eine bestehende Instanz auf neue Hardware oder einen
anderen Hoster. Drei Dinge gehören dabei untrennbar zusammen und müssen vom
**selben Zeitpunkt** stammen: das Datenverzeichnis, die Datenbank und
`config/config.php`. Passen sie nicht zusammen, verweisen Metadaten auf
Dateien, die es nicht gibt — oder umgekehrt.

## Voraussetzungen auf dem Zielserver

| Punkt | Anforderung |
| --- | --- |
| Code-Version | identisch zur Quelle (`occ status`), sonst zusätzlich `occ upgrade` einplanen |
| PHP | 8.4 mit denselben Erweiterungen |
| Datenbank | derselbe Typ wie in `dbtype`, siehe [Sonderfall](#sonderfall-wechsel-des-datenbanktyps) |
| Dienste | alles, was in `config.php` referenziert wird: `memcache.local` (APCu), `memcache.locking` mit `redis`-Block |
| Pfade | am einfachsten die gleichen Pfade wie bisher — dann entfallen die Home-Umzüge in Schritt 6 ganz |

Die alte `config/config.php` wird übernommen, nicht neu erzeugt. Sie enthält
`instanceid`, `passwordsalt` und `secret`. `secret` ist der Schlüssel, mit dem
`OC\Security\Crypto` gespeicherte Zugangsdaten (Tabelle `credentials`, etwa für
externen Speicher) und Anmelde-Token (Tabelle `authtoken`) ver- und
entschlüsselt. Eine neu erzeugte `config.php` bedeutet: Alle diese Werte sind
unbrauchbar.

## 1. Wartungsmodus auf dem alten Server

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
```

Der Befehl setzt `maintenance` auf `true`; jede Web-Anfrage wird danach mit
HTTP 503 und der Wartungsseite beantwortet. Ein Abmelden erzwingt er nicht,
bestehende Sitzungen werden nicht verworfen. `occ` weist zusätzlich darauf hin,
den Webserver anzuhalten — für eine konsistente Sicherung ist das der sichere
Weg:

```bash
systemctl stop apache2
systemctl stop php8.4-fpm
```

Ebenso den Cron-Eintrag des alten Servers stilllegen, damit während der
Übertragung kein Hintergrund-Job mehr schreibt (siehe
[Hintergrund-Jobs (Cron)](background-jobs.md)).

## 2. Sicherung von Datenverzeichnis, Datenbank und config.php

```bash
tar -czf /root/oco-data-$(date +%F).tar.gz /var/owncloud-online-data
mysqldump --single-transaction -u root -p owncloud_online \
  > /root/oco-db-$(date +%F).sql
cp /var/www/owncloud.online/config/config.php /root/oco-config-$(date +%F).php
```

Im Datenverzeichnis liegen nicht nur die Benutzerdateien, sondern auch
versteckte Dateien, die zur Funktion gehören: die Marker-Datei `.ocdata`, die
`.htaccess` und die `index.html` aus dem Setup-Schutz sowie — je nach
Konfiguration — `owncloud.log` und bei aktiver Verschlüsselung die
Schlüsselverzeichnisse `files_encryption/`. Bei `dbtype` `sqlite` liegt auch
die Datenbankdatei selbst dort.

!!! warning "Versteckte Dateien"
    `cp /alt/* /neu/` überträgt keine Dateien, die mit einem Punkt beginnen.
    Fehlt danach `.ocdata`, startet die Instanz mit „Dein Daten-Verzeichnis ist
    ungültig". Immer `tar` oder `rsync -a` auf das **Verzeichnis** verwenden,
    nicht auf dessen Inhalt.

## 3. Übertragung

```bash
# Benutzerdaten (Berechtigungen und ACLs erhalten)
rsync -aAX /var/owncloud-online-data/ neu.example.com:/var/owncloud-online-data/

# Datenbankdump und die alte config.php
scp /root/oco-db-*.sql neu.example.com:/root/
scp /root/oco-config-*.php neu.example.com:/root/
```

Auf dem Zielserver einspielen:

```bash
mysql -u root -p owncloud_online < /root/oco-db-2026-08-13.sql
cp /root/oco-config-2026-08-13.php /var/www/owncloud.online/config/config.php
chown www-data:www-data /var/www/owncloud.online/config/config.php
```

!!! warning "Eigentümer von config.php"
    `occ` bricht ab, wenn es nicht unter dem Benutzer läuft, dem
    `config/config.php` gehört: „Console has to be executed with the user that
    owns the file config/config.php". Bleibt die kopierte Datei bei `root`,
    scheitert schon der erste `sudo -u www-data`-Aufruf in Schritt 4 — deshalb
    der `chown` direkt nach dem Kopieren.

Der Anwendungscode wird nicht kopiert, sondern auf dem Zielserver regulär
installiert (siehe [Leerer Linux-Server](../installation/linux-server.md)) —
die dabei frisch erzeugte Datenbank und `config.php` werden anschließend durch
den Dump und die mitgebrachte Datei ersetzt. Eine Ausnahme ist das
Verzeichnis `apps-external`: Dort landen alle über den Markt nachinstallierten
Apps (`apps_paths`, Eintrag mit `"writable" => true`). Es liegt im Codebaum und
ist damit in keiner Datensicherung enthalten.

## 4. Anpassung der Konfiguration

Zwei Werte gehören **vor** den ersten `occ`-Aufruf in die Datei: der
Datenbankzugang und das Datenverzeichnis. Ohne gültigen Datenbankzugang bricht
jeder Befehl beim ersten Zugriff ab. Ein falsches `datadirectory` hält `occ`
dagegen nicht auf — die Verzeichnisprüfung läuft auf der Konsole nicht mit —,
jeder Befehl, der Dateien anfasst, arbeitet dann aber am falschen Ort. Beides
direkt in `config/config.php` eintragen:

```php
'datadirectory' => '/var/owncloud-online-data',
'dbhost' => '127.0.0.1',
'dbname' => 'owncloud_online',
'dbuser' => 'owncloud_user',
'dbpassword' => 'CHANGE_ME',
```

Danach lassen sich die übrigen Werte mit `occ` setzen:

```bash
# neuer Hostname (Index 0 überschreibt den ersten Eintrag,
# ein weiterer Index ergänzt die Liste)
sudo -u www-data php8.4 /var/www/owncloud.online/occ \
  config:system:set trusted_domains 0 --value=cloud.example.com

# Basis-URL für Cron, occ und alle darin erzeugten Links
sudo -u www-data php8.4 /var/www/owncloud.online/occ \
  config:system:set overwrite.cli.url --value=https://cloud.example.com

# Kontrolle
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:list system
```

| Schlüssel | Warum er beim Umzug angefasst werden muss |
| --- | --- |
| `trusted_domains` | Wird der neue Hostname nicht gelistet, beantwortet der Server jede Anfrage mit HTTP 400 und „Du greifst auf den Server über eine nicht vertrauenswürdige Domain zu.". Die Prüfung greift nur im Web, nicht in `occ`. |
| `datadirectory` | Absoluter Pfad zum Datenverzeichnis auf dem **neuen** Server |
| `dbhost`, `dbname`, `dbuser`, `dbpassword` | Zugang zur neuen Datenbank; `dbtableprefix` unverändert lassen |
| `overwrite.cli.url` | Sonst zeigen Links aus Cron-Mails und Benachrichtigungen weiter auf den alten Server |

Ändert sich zusätzlich der Unterpfad (Webroot), gehört danach ein Lauf für die
`.htaccess` dazu — sie enthält die `ErrorDocument`-Pfade, die im CLI-Betrieb aus
`overwrite.cli.url` abgeleitet werden:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:update:htaccess
```

Steht die Instanz hinter einem Reverse-Proxy, gehören `trusted_proxies`,
`overwriteprotocol` und gegebenenfalls `overwritehost` ebenfalls geprüft, siehe
[Sicherheit und Setup-Warnungen](security-hardening.md).

## 5. Rechte setzen

Der Webserver-Benutzer muss in das Datenverzeichnis schreiben können; das
Verzeichnis selbst darf für andere Benutzer nicht lesbar sein — der Server prüft
das beim Start und meldet sonst „Dein Daten-Verzeichnis ist von anderen
Benutzern lesbar".

```bash
chown -R www-data:www-data /var/owncloud-online-data
chown -R www-data:www-data /var/www/owncloud.online
chmod 0770 /var/owncloud-online-data
chmod 0640 /var/www/owncloud.online/config/config.php
```

## 6. Dateiverzeichnis neu einlesen

Das Home-Verzeichnis jedes Kontos steht als absoluter Pfad in der Tabelle
`accounts` (Spalte `home`). Der Wert wird nur beim Anlegen des Kontos
geschrieben; ein späterer `user:sync` korrigiert ihn nicht. Hat sich der Pfad
des Datenverzeichnisses geändert, zeigen diese Einträge also weiter auf den
alten Ort. Zuerst prüfen, welche Wurzelverzeichnisse tatsächlich hinterlegt
sind:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ user:home:list-dirs
sudo -u www-data php8.4 /var/www/owncloud.online/occ user:home:list-users /alter/pfad
```

Stimmt der Pfad nicht, zieht `user:move-home` das Home um. Als Argument wird das
**übergeordnete** Verzeichnis angegeben; der Befehl deaktiviert das Konto,
kopiert per `rsync`, trägt den neuen Pfad ein und aktiviert das Konto wieder:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ \
  user:move-home alice /var/owncloud-online-data
```

Zwei Bedingungen des Befehls bestimmen die Reihenfolge des Umzugs: Das bisherige
Home muss auf der Platte **noch vorhanden** sein — sonst bricht er mit „Current
user home … does not exist. Not mounted? Non local storage?" ab —, und unter dem
Zielverzeichnis darf noch **kein gefüllter** Ordner des Kontos liegen, sonst
meldet er „New user folder … is either not readable or not empty". Liegen die
Dateien bereits am neuen Pfad, hilft der Befehl deshalb nicht mehr.

Daraus folgt: Soll sich der Pfad des Datenverzeichnisses wirklich ändern, das
Datenverzeichnis zuerst unter dem **alten** Pfad wiederherstellen, dann Konto für
Konto mit `user:move-home` auf den neuen Pfad umziehen und erst danach
`datadirectory` anpassen. Der Befehl kopiert nur — das alte Home bleibt liegen
und muss von Hand gelöscht werden. Auf S3-Primärspeicher verweigert er den
Dienst. Am wenigsten Aufwand macht weiterhin der unveränderte Pfad.

Danach den Dateibestand einlesen, damit der Dateicache wieder zum
Dateisystem passt:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ files:scan --all
```

Fehlen einzelne Dateien danach weiterhin in der Oberfläche, hilft
`occ files:scan --all --repair` — der Lauf repariert abgehängte Cache-Einträge
und dauert deutlich länger.

Anschließend die Reparaturschritte laufen lassen. Sie funktionieren **nur** im
Wartungsmodus; ohne ihn bricht der Befehl mit „Turn on maintenance mode to use
this command." ab:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:repair
```

Stammt der Datenbankdump aus einem laufenden Betrieb, können Sperreinträge aus
abgebrochenen Übertragungen enthalten sein. Das betrifft nur Instanzen ohne
`memcache.locking`: Steht dort Redis, liegen die Sperren im Cache und ziehen gar
nicht erst mit um.

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:file-locks --cleanup-expired
```

## 7. Wartungsmodus beenden

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
systemctl start php8.4-fpm
systemctl start apache2
```

## Was nicht mitgenommen wird

Datenverzeichnis, Datenbank und `config.php` decken die Instanz ab — nicht aber
ihre Umgebung. Diese Punkte müssen auf dem Zielserver eigens eingerichtet
werden:

| Nicht enthalten | Folge, wenn es vergessen wird |
| --- | --- |
| Webserver- und TLS-Konfiguration | Instanz nicht oder nur unverschlüsselt erreichbar |
| Cron-Eintrag für `cron.php` | Papierkorb, Versionen und Freigaben laufen nie ab, keine Mails |
| PHP-Erweiterungen und Dienste (APCu, Redis) | `config.php` verweist auf nicht vorhandene Caches |
| `apps-external` (über den Markt installierte Apps) | Apps fehlen oder sind deaktiviert |
| Protokolldatei außerhalb des Datenverzeichnisses (`logfile`) | Zielpfad existiert nicht, Protokoll läuft ins Leere |
| Systempakete, Firewall, Backup-Aufträge | Betrieb ist nicht abgesichert |

Nicht mitgenommen werden dürfen dagegen `instanceid`, `passwordsalt` und
`secret` in geänderter Form — sie sind Teil der übernommenen `config.php` und
bleiben unverändert.

## Was danach zu prüfen ist

```bash
# Version, Installationszustand
sudo -u www-data php8.4 /var/www/owncloud.online/occ status

# Umgebungsabhängigkeiten (leere Ausgabe = in Ordnung)
sudo -u www-data php8.4 /var/www/owncloud.online/occ check

# Apps vollständig und aktiv
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:list

# Job-Liste mit "Last Run" je Job (ISO-Zeitstempel)
sudo -u www-data php8.4 /var/www/owncloud.online/occ background:queue:status

# Zeitpunkt des letzten Cron-Laufs (Unix-Zeit, sollte frisch sein)
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:get core lastcron

# Home-Pfade zeigen auf das neue Datenverzeichnis
sudo -u www-data php8.4 /var/www/owncloud.online/occ user:home:list-dirs
```

Dazu die Prüfungen, die nur im Betrieb sichtbar werden:

1. Anmeldung über den neuen Hostnamen.
2. Eine Datei hoch- und wieder herunterladen.
3. Einen bestehenden öffentlichen Link öffnen.
4. Externen Speicher öffnen, sofern eingerichtet — dort zeigt sich, ob `secret`
   korrekt übernommen wurde.
5. Einen Sync-Client verbinden und eine Änderung in beide Richtungen prüfen.
6. Die Setup-Warnungen unter **Einstellungen → Administration → Allgemein**
   durchgehen.

`occ maintenance:data-fingerprint` gehört **nicht** zum normalen Umzug. Der
Befehl signalisiert allen Clients, dass eine Sicherung eingespielt wurde, und
löst dort Konfliktdialoge aus. Er ist nur dann richtig, wenn Sie auf einen
älteren Datenstand zurückgegriffen haben.

## Sonderfall: Wechsel des Datenbanktyps

Ein Umzug ist kein guter Anlass, gleichzeitig den Datenbanktyp zu wechseln. Der
frühere Befehl `db:convert-type` ist in dieser Fassung **entfernt** worden
(Changelog: „This experimental command is untested and unsupported and therefore
removed."). Eine unterstützte In-Place-Konvertierung gibt es damit nicht.

Praktisch bleiben zwei Wege:

**Typ beibehalten.** Der Umzug läuft wie oben; nur `dbhost`, `dbname`, `dbuser`
und `dbpassword` ändern sich. Bei `dbtype` `sqlite` liegt die Datenbank im
Datenverzeichnis und zieht mit dem `rsync` aus Schritt 3 automatisch um. Für den
produktiven Betrieb ist SQLite ungeeignet, siehe [Datenbank](database.md).

**Neu aufsetzen.** Eine frische Installation mit dem Zieltyp anlegen …

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:install \
  --database mysql --database-name owncloud_online \
  --database-host 127.0.0.1 --database-user owncloud_user \
  --admin-user admin --data-dir /var/owncloud-online-data
```

Die Passwörter für Datenbank- und Administratorkonto fragt der Befehl
interaktiv ab, wenn `--database-pass` und `--admin-pass` fehlen. Anschließend die
Benutzerdateien in die Home-Verzeichnisse legen und mit `occ files:scan --all`
einlesen. Der Preis ist hoch und muss vorher bekannt
sein: Alles, was ausschließlich in der Datenbank steht, ist danach weg —
Freigaben (Tabelle `share`), Kommentare, Tags, Konten des internen Backends,
Konfiguration von externem Speicher sowie sämtliche App-Einstellungen. Auch
`instanceid`, `passwordsalt` und `secret` werden neu erzeugt, womit gespeicherte
Zugangsdaten und Anmelde-Token ungültig sind. Planen Sie diesen Weg getrennt vom
Serverumzug und nicht in derselben Wartung.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Du greifst auf den Server über eine nicht vertrauenswürdige Domain zu.", HTTP 400 | Neuer Hostname fehlt in `trusted_domains` | `occ config:system:set trusted_domains 0 --value=cloud.example.com`; die Prüfung greift nicht in `occ`, der Befehl läuft also trotzdem |
| „Dein Daten-Verzeichnis ist ungültig" | `.ocdata` fehlt — versteckte Dateien wurden beim Kopieren ausgelassen | Datenverzeichnis erneut mit `tar` oder `rsync -a` übertragen |
| „Dein Datenverzeichnis muss ein absoluter Pfad sein" | `datadirectory` relativ eingetragen | Absoluten Pfad in `config/config.php` setzen |
| „Dein Daten-Verzeichnis ist von anderen Benutzern lesbar" | Rechte nach dem Kopieren zu offen | `chmod 0770` auf das Datenverzeichnis |
| `occ` bricht mit Datenbankfehler ab | `dbhost`/`dbname`/`dbuser` zeigen noch auf den alten Server | Werte in `config/config.php` korrigieren, dann erneut aufrufen |
| Konten vorhanden, Dateien aber leer | Gespeicherte Home-Pfade zeigen auf das alte Datenverzeichnis | `occ user:home:list-dirs` prüfen; `occ user:move-home` nur, solange die Dateien noch am alten Pfad liegen, danach `occ files:scan --all` |
| Dateien liegen auf der Platte, fehlen aber in der Oberfläche | Dateicache kennt sie nicht | `occ files:scan --all`, bei abgehängten Einträgen `--repair` ergänzen |
| Externer Speicher meldet Anmeldefehler | `secret` weicht ab, weil `config.php` neu erzeugt wurde | Ursprüngliche `config.php` einspielen; sonst Zugangsdaten neu hinterlegen |
| Links in Mails zeigen auf den alten Server | `overwrite.cli.url` nicht angepasst | Wert setzen; die Links werden beim nächsten Cron-Lauf neu erzeugt |
| Papierkorb wächst, keine Benachrichtigungen | Cron-Eintrag wurde nicht übernommen | Cron auf dem neuen Server einrichten, `occ config:app:get core lastcron` prüfen |
| „Turn on maintenance mode to use this command." | `maintenance:repair` ohne Wartungsmodus aufgerufen | `occ maintenance:mode --on`, Befehl wiederholen |
| Oberfläche zeigt dauerhaft den Wartungsmodus | `maintenance` steht noch auf `true` | `occ maintenance:mode --off` |
| Uploads scheitern mit Sperrfehlern | Sperreinträge aus der abgebrochenen Sitzung im Dump (nur ohne `memcache.locking`) | `occ maintenance:file-locks --cleanup-expired` |

Der genaue Grund steht immer im Serverprotokoll, siehe
[Serverprotokoll und Fehlermeldungen](logging.md). Der vollständige Ablauf für
Sicherung und Rückweg ist unter [Backups und Updates](backups-updates.md)
beschrieben.
