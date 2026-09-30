# Umzug auf einen anderen Server

Ein Umzug verschiebt eine bestehende Instanz auf neue Hardware oder einen
anderen Hoster. Drei Dinge gehören dabei untrennbar zusammen und müssen vom
**selben Zeitpunkt** stammen: das Datenverzeichnis, die Datenbank und
`config/config.php`. Passen sie nicht zusammen, verweisen Metadaten auf
Dateien, die es nicht gibt — oder umgekehrt.

Wechselt beim Umzug zugleich die Version — etwa von der Vorgängergeneration
(Server 10.x mit Theme-App bis 1.2.12 oder 1.3.0 bis unter 3.0.0, etwa 2.0.x,
jeweils mit dem Selfservice) oder aus der Zwischengeneration (Theme-App 1.2.13
bis 1.2.29) auf diese Fassung —, gelten zusätzlich die Punkte unter
[Umzug von der Vorgängergeneration](#vorgaengergeneration). Was dabei mit Logo,
Farben und Impressum geschieht, steht unter
[Branding und App-Daten übernehmen](#branding).

## Voraussetzungen auf dem Zielserver

| Punkt | Anforderung |
| --- | --- |
| Code-Version | identisch zur Quelle (`occ status`), sonst zusätzlich [Schritt 6](#schritt-upgrade) (`occ upgrade`) |
| PHP | 8.4 mit denselben Erweiterungen |
| Datenbank | derselbe Typ wie in `dbtype`, siehe [Sonderfall](#sonderfall-wechsel-des-datenbanktyps); eine **leere** Datenbank für den Dump |
| Dienste | alles, was in `config.php` referenziert wird: `memcache.local` (APCu), `memcache.locking` mit `redis`-Block |
| Pfade | am einfachsten die gleichen Pfade wie bisher — für Code und Datenverzeichnis. Dann entfallen die Home-Umzüge in Schritt 7 ganz |

Die alte `config/config.php` wird übernommen, nicht neu erzeugt. Vier Werte
darin binden Datenbank und Datenverzeichnis an genau diese Instanz und dürfen
sich beim Umzug **nicht** ändern:

| Wert | Wofür er gebraucht wird | Folge, wenn ein neuer Wert drinsteht |
| --- | --- | --- |
| `secret` | Schlüssel, mit dem `OC\Security\Crypto` gespeicherte Zugangsdaten (Tabelle `credentials`, etwa für externen Speicher) und Anmelde-Token (Tabelle `authtoken`) ver- und entschlüsselt | App-Passwörter werden mit HTTP 401 abgewiesen, die Zwei-Faktor-Anmeldung per TOTP bricht mit HTTP 500 („HMAC does not match") ab, externer Speicher meldet Anmeldefehler |
| `passwordsalt` | Prüfung älterer Passwort-Hashes | Konten mit solchen Hashes können sich nicht mehr anmelden |
| `instanceid` | Name des Ordners `appdata_<instanceid>` im Datenverzeichnis (Vorschaubilder, hochgeladene Theme-Bilder) und des Sitzungs-Cookies | hochgeladenes Logo und Anmelde-Hintergrund liefern HTTP 404, die Seiten verweisen aber weiter darauf; Vorschaubilder werden neu erzeugt |
| `version` | daran erkennt `occ upgrade`, von welcher Fassung die Datenbank kommt | bei einem Versionswechsel laufen Reparaturschritte, die nur beim Update von älteren Fassungen greifen, nicht mit, und der Markt wird nicht nach passenden App-Fassungen gefragt |

Eine frisch installierte oder neu bereitgestellte Instanz hat für alle vier
eigene Werte. Ihre `config.php` ist deshalb für einen Umzug unbrauchbar, auch
wenn alles andere darin stimmt.

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
die Datenbankdatei selbst dort. Instanzen der Vorgängergeneration haben dort
außerdem `oco_selfservice/` mit den einzigen Kopien der alten Logos, siehe
[Branding und App-Daten übernehmen](#branding).

!!! warning "Versteckte Dateien"
    `cp /alt/* /neu/` überträgt keine Dateien, die mit einem Punkt beginnen.
    Fehlt danach `.ocdata`, startet die Instanz mit „Dein Daten-Verzeichnis ist
    ungültig". Immer `tar` oder `rsync -a` auf das **Verzeichnis** verwenden,
    nicht auf dessen Inhalt.

Den Datenbankdump und die Sicherung des Datenverzeichnisses aufbewahren, bis
die Prüfungen am Ende dieser Seite erledigt sind. Bei einem Versionswechsel
verändert `occ upgrade` die Datenbank; was dabei verloren geht, lässt sich nur
aus dem alten Dump zurückholen.

## 3. Übertragung

```bash
# Benutzerdaten (Berechtigungen und ACLs erhalten)
rsync -aAX /var/owncloud-online-data/ neu.example.com:/var/owncloud-online-data/

# Datenbankdump und die alte config.php
scp /root/oco-db-*.sql neu.example.com:/root/
scp /root/oco-config-*.php neu.example.com:/root/
```

Auf dem Zielserver den Dump in eine **leere** Datenbank einspielen und die alte
`config.php` an ihren Platz legen:

```bash
mysql -u root -p -e "DROP DATABASE IF EXISTS owncloud_online;
  CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root -p owncloud_online < /root/oco-db-2026-08-13.sql
cp /root/oco-config-2026-08-13.php /var/www/owncloud.online/config/config.php
chown www-data:www-data /var/www/owncloud.online/config/config.php
```

`DROP DATABASE` nimmt dem Datenbankbenutzer seine Rechte auf
`owncloud_online.*` nicht; MariaDB und MySQL behalten Rechte, die für eine
Datenbank vergeben wurden, auch über das Löschen hinweg.

!!! warning "Nicht in eine befüllte Datenbank einspielen"
    Der Dump enthält nur die Tabellen, die es auf dem alten Server gab.
    Tabellen, die schon in der Zieldatenbank stehen — etwa aus einer
    Probeinstallation mit `occ maintenance:install` —, bleiben neben den
    importierten Daten liegen, und die App-Einträge des Dumps wissen nichts
    von ihnen. In der Nachstellung eines Umzugs blieben so 14 Tabellen der
    Frischinstallation stehen (darunter `oc_announcements` und
    `oc_files_antivirus`), und später scheiterte `occ app:enable` für genau
    diese Apps. Mit leerer Datenbank liefen alle durch.

!!! warning "Eigentümer von config.php"
    `occ` bricht ab, wenn es nicht unter dem Benutzer läuft, dem
    `config/config.php` gehört: „Console has to be executed with the user that
    owns the file config/config.php". Bleibt die kopierte Datei bei `root`,
    scheitert schon der erste `sudo -u www-data`-Aufruf in Schritt 4 — deshalb
    der `chown` direkt nach dem Kopieren.

Der Anwendungscode wird nicht kopiert, sondern auf dem Zielserver frisch
entpackt (siehe [Leerer Linux-Server](../installation/linux-server.md)). Eine
Installation über `occ maintenance:install` ist für den Umzug nicht nötig; wer
sie trotzdem ausführt, etwa um Webserver und PHP zu prüfen, leert die
Datenbank danach wie oben.

Das Verzeichnis `apps-external` nimmt alle über den Markt nachinstallierten
Apps auf (`apps_paths`, Eintrag mit `"writable" => true`). Es liegt im
Codebaum und ist damit in keiner Datensicherung enthalten:

- **Gleiche Version auf beiden Servern:** die Apps auf dem Zielserver erneut
  über den Markt installieren oder `apps-external` vom alten Server
  mitnehmen — beides ergibt denselben Stand.
- **Versionswechsel:** das alte `apps-external` **nicht** mitkopieren. Die
  Apps darin sind Fassungen für die alte Version. Eine App, deren Code da ist,
  die aber nicht zur neuen Version passt, bricht `occ upgrade` mit „Upgrade is
  not possible" ab, sofern der Markt keine passende Fassung liefert. Fehlt ihr
  Code dagegen, holt das Upgrade eine passende Fassung aus dem Markt oder
  schaltet die App ab (siehe
  [Umzug von der Vorgängergeneration](#vorgaengergeneration)).

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

`datadirectory` bleibt am besten der bisherige Pfad. Soll er sich ändern,
zunächst trotzdem den **alten** Pfad eintragen und das Datenverzeichnis dort
wiederherstellen; der Wechsel folgt in [Schritt 7](#schritt-home-pfade).

Die mitgebrachte Datei enthält auch `apps_paths` des alten Servers. Liegt der
Code auf dem Zielserver unter einem anderen Pfad, beide Einträge in derselben
Datei auf den neuen Code umstellen — sonst lädt der Server Apps aus einem
Verzeichnis, das es nicht gibt:

```php
'apps_paths' => [
  ['path' => '/var/www/owncloud.online/apps', 'url' => '/apps', 'writable' => false],
  ['path' => '/var/www/owncloud.online/apps-external', 'url' => '/apps-external', 'writable' => true],
],
```

`instanceid`, `passwordsalt`, `secret` und `version` bleiben unverändert
(siehe [Voraussetzungen](#voraussetzungen-auf-dem-zielserver)). Weil die Datei
im Wartungsmodus gesichert wurde, steht darin auch `'maintenance' => true` —
das ist gewollt, die Instanz bleibt bis Schritt 8 gesperrt.

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
| `apps_paths` | Nur wenn der Code woanders liegt als auf dem alten Server |
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

## 6. Upgrade bei einem Versionswechsel {#schritt-upgrade}

Stammt die Datenbank von einer älteren Version, meldet jeder `occ`-Aufruf
„… require upgrade - only a limited number of commands are available". Dann
jetzt das Upgrade ausführen; bei gleicher Version entfällt der Schritt:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ upgrade
```

`occ upgrade` läuft im Wartungsmodus und lässt ihn danach an. Die Ausgabe
aufheben: Sie nennt jede App, die mangels Code abgeschaltet wurde, und bei
Instanzen der Vorgängergeneration jede Übernahme des Brandings. Beides steht
zusätzlich im Serverprotokoll (siehe [Was danach zu prüfen ist](#was-danach-zu-prufen-ist)).

## 7. Home-Pfade und Reparaturschritte {#schritt-home-pfade}

Dieser Schritt läuft noch im Wartungsmodus.

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
`datadirectory` anpassen. Der Befehl kopiert nur Homes. Alles andere im
Datenverzeichnis zieht er **nicht** mit um und muss vor dem Umstellen von
`datadirectory` von Hand an den neuen Ort: `.ocdata`, `.htaccess`,
`index.html`, `appdata_<instanceid>/`, `avatars/`, `files_encryption/`,
`owncloud.log` und bei Instanzen der Vorgängergeneration `oco_selfservice/`.
Das alte Home bleibt liegen und muss von Hand gelöscht werden. Auf
S3-Primärspeicher verweigert der Befehl den Dienst. Am wenigsten Aufwand macht
weiterhin der unveränderte Pfad.

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

## 8. Wartungsmodus beenden und Dateibestand einlesen

Zuerst den Wartungsmodus beenden, **dann** den Dateibestand einlesen. Der
Befehl `files:scan` gehört zur App `files`, und im Wartungsmodus lädt `occ`
keine Apps — der Aufruf endet dort mit „There are no commands defined in the
"files" namespace.". Den Webserver erst danach starten, damit sich niemand
anmeldet, bevor der Dateicache wieder zum Dateisystem passt:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
sudo -u www-data php8.4 /var/www/owncloud.online/occ files:scan --all
systemctl start php8.4-fpm
systemctl start apache2
```

Fehlen einzelne Dateien danach weiterhin in der Oberfläche, hilft
`occ files:scan --all --repair` — der Lauf repariert abgehängte Cache-Einträge
und dauert deutlich länger.

## Was nicht mitgenommen wird

Datenverzeichnis, Datenbank und `config.php` decken die Instanz ab — nicht aber
ihre Umgebung. Diese Punkte müssen auf dem Zielserver eigens eingerichtet
werden:

| Nicht enthalten | Folge, wenn es vergessen wird |
| --- | --- |
| Webserver- und TLS-Konfiguration | Instanz nicht oder nur unverschlüsselt erreichbar |
| Cron-Eintrag für `cron.php` | Papierkorb, Versionen und Freigaben laufen nie ab, keine Mails |
| PHP-Erweiterungen und Dienste (APCu, Redis) | `config.php` verweist auf nicht vorhandene Caches |
| `apps-external` (über den Markt installierte Apps) | Apps fehlen oder sind deaktiviert. Bei gleicher Version neu installieren oder mitnehmen, bei einem Versionswechsel **nicht** mitnehmen (siehe Schritt 3) |
| Protokolldatei außerhalb des Datenverzeichnisses (`logfile`) | Zielpfad existiert nicht, Protokoll läuft ins Leere |
| Systempakete, Firewall, Backup-Aufträge | Betrieb ist nicht abgesichert |

Nicht mitgenommen werden dürfen dagegen `instanceid`, `passwordsalt`, `secret`
und `version` in geänderter Form — sie sind Teil der übernommenen `config.php`
und bleiben unverändert.

## Was danach zu prüfen ist

```bash
# Version, Installationszustand
sudo -u www-data php8.4 /var/www/owncloud.online/occ status

# Umgebungsabhängigkeiten (leere Ausgabe = in Ordnung)
sudo -u www-data php8.4 /var/www/owncloud.online/occ check

# Apps vollständig und aktiv
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:list

# Apps, die das Upgrade mangels Code abgeschaltet hat (je App eine Zeile)
grep 'Upgrade: disabled app' /var/owncloud-online-data/owncloud.log

# Impressum und Datenschutz: kein Verweis auf eine fremde Domain
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:get core legal.imprint_url
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:get core legal.privacy_policy_url

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
   korrekt übernommen wurde. Ebenso ein App-Passwort und, falls genutzt, die
   Zwei-Faktor-Anmeldung.
5. Einen Sync-Client verbinden und eine Änderung in beide Richtungen prüfen.
6. Die Setup-Warnungen unter **Einstellungen → Administration → Allgemein**
   durchgehen.
7. Bei Instanzen der Vorgängergeneration die
   [Prüfliste zum Branding](#branding-pruefliste).

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

## Umzug von der Vorgängergeneration {#vorgaengergeneration}

Wer beim Umzug zugleich die Version wechselt, bringt eine Datenbank mit, in der
Apps als eingeschaltet vermerkt sind, die es auf dem Ziel nicht gibt — auf
einer Instanz mit Enterprise-Apps sind das leicht ein Dutzend. Gegenüber dem
Umzug ohne Versionswechsel kommt es auf diese Punkte an:

| Punkt | Warum |
| --- | --- |
| Dump in eine **leere** Datenbank (Schritt 3) | Tabellen einer Probeinstallation bleiben sonst liegen und lassen später `occ app:enable` scheitern |
| `instanceid`, `passwordsalt`, `secret` **und** `version` aus der alten `config.php` (Voraussetzungen) | App-Passwörter, TOTP und hochgeladene Bilder hängen an den ersten drei; `version` sagt dem Upgrade, woher die Datenbank kommt |
| altes `apps-external` nicht mitkopieren (Schritt 3) | alte App-Fassungen mit Code brechen das Upgrade ab |
| `occ upgrade` vor den Reparaturschritten (Schritt 6) | vorher stehen nur wenige `occ`-Befehle zur Verfügung |
| Pfad des Datenverzeichnisses gleich lassen (Schritt 7) | `user:move-home` zieht nur Homes um, nicht `oco_selfservice/` und `appdata_<instanceid>/` |
| erst Wartungsmodus aus, dann `files:scan` (Schritt 8) | im Wartungsmodus gibt es den Befehl nicht |
| Impressum prüfen ([Branding](#branding)) | die alte Branding-Seite hat oft einen fremden Standardwert gespeichert |

Das Upgrade holt über den Markt passende Fassungen der Apps, deren Code fehlt.
Dafür braucht es eine Internetverbindung und den alten Wert von `version` in
`config.php` — nur dann erkennt der Reparaturschritt „Upgrade app code from
the marketplace" ein Kern-Update und fragt den Markt. Was der Markt nicht
liefern kann, wurde bis 11.0.18 zum Abbruchgrund: „Upgrade is not possible",
die Instanz blieb im Wartungsmodus, und für jede App war ein
`occ app:disable` von Hand fällig.

Seit 11.0.19 gilt: **Eine App ohne Code kann nichts tun** — der Eintrag
„eingeschaltet" ist alles, was von ihr übrig ist, und er blockiert nur. Der
Reparaturschritt setzt ihn auf „aus", nennt jede so behandelte App in der
Ausgabe und läuft weiter. Ihre Daten (Einstellungen, Tabellen, `installed_version`)
bleiben in der Datenbank. Seit 11.0.21 richtet sich der
Hinweis danach, was der Markt zu der App gesagt hat:

```
Repair warning: The following apps were enabled but have no code on this
server. They have been disabled so the upgrade can continue; their data stays
in the database: admin_audit, diagnostics, oco_selfservice, theme-owncloudonline
Repair warning: Not offered by the marketplace: admin_audit, diagnostics,
oco_selfservice, theme-owncloudonline. To use one of them again, put its code
into an app directory, then run occ app:enable <app> and occ upgrade.
```

| Antwort des Markts | Hinweis in Ausgabe und Serverprotokoll | Weg zurück |
| --- | --- | --- |
| führt die App nicht (etwa Theme, Selfservice, `admin_audit`) | „Not offered by the marketplace" | Code in ein App-Verzeichnis legen, `occ app:enable <app>`, dann `occ upgrade` |
| führt die App, hat aber keine Fassung für diesen Server | „Offered by the marketplace, but without a version for this server" samt Grund | sobald es eine passende Fassung gibt, aus dem Markt installieren, dann `occ upgrade` |
| nicht gefragt (kein Kern-Update, `upgrade.automatic-app-update` aus, keine Verbindung) | „The marketplace was not consulted for, or could not provide" | wie in der ersten Zeile |

11.0.19 und 11.0.20 schreiben stattdessen pauschal „install them from the
marketplace if you still need them" — auch für Apps, die kein Markt anbietet.

Nach `occ app:enable` bleibt die ganze Instanz im Zustand „Upgrade nötig", bis
`occ upgrade` gelaufen ist, weil `installed_version` noch auf der alten Fassung
steht. Apps, die **mit** Code vorhanden sind, aber nicht zur Version passen,
bleiben ein Abbruchgrund: dort gibt es etwas zu reparieren, und ein stilles
Abschalten würde es verdecken.

Abgeschaltet wird nur, was **wirklich** fehlt: Der Reparaturschritt prüft
vorher, dass jeder Pfad aus `apps_paths` lesbar ist und dass die App in keinem
davon einen Ordner hat. Ist ein Pfad nicht lesbar (Mount fehlt, Rechte) oder
liegt ein Ordner ohne lesbare `appinfo/info.xml` da, wird nichts abgeschaltet
und das Upgrade bricht wie früher ab — dann ist der Code nur gerade nicht
erreichbar, und ein stilles Abschalten würde den eigentlichen Fehler verdecken.
Jede Abschaltung steht zusätzlich mit dem bisherigen `enabled`-Wert im
Serverprotokoll (`owncloud.log`, App `core`, Zeile beginnt mit „Upgrade:
disabled app"), auch wenn `occ upgrade --no-warnings` die Konsolenwarnung
unterdrückt.

#### Nachfolger einschalten statt nachinstallieren

Manche Apps der alten Instanz gibt es hier nur noch als Nachfolger unter
anderem Namen. Ihre Einstellungen bleiben in der Datenbank liegen; der
Nachfolger übernimmt sie, wenn er **nach** dem Upgrade zum ersten Mal
eingeschaltet wird. Die Nachfolger sind nicht standardmäßig eingeschaltet,
`occ upgrade` installiert sie also nicht — ohne den Schritt unten gelten nach
dem Umzug weder die alten Sperrwerte (nur die eingebaute Drosselung des Kerns)
noch die alten Kennwortregeln.

| Alte App | Nachfolger | Übernommen wird |
| --- | --- | --- |
| `security` (ownCloud 10.0.3 bis 10.0.8) | `brute_force_protection` | Fehlversuche, Zeitfenster, Sperrdauer |
| `security` | `password_policy` | Mindestlänge, Groß- und Kleinbuchstaben, Ziffern, Sonderzeichen |
| `windows_network_drive` | `wnd` | Einhängungen mit ihren Anmeldearten, auch SFTP, WebDAV und SMB mit gespeicherten oder globalen Zugangsdaten (siehe [Externe Speicher aus Zusatz-Apps](#externe-speicher-aus-zusatz-apps)) |

```bash
sudo -u www-data php8.4 occ app:enable brute_force_protection
sudo -u www-data php8.4 occ app:enable password_policy
sudo -u www-data php8.4 occ app:enable wnd
```

Steht `security` in der Warnung oben, ist das genau dieser Fall: nicht über
den Markt suchen, sondern die Nachfolger einschalten. Was übernommen wurde,
steht im Serverprotokoll (App `brute_force_protection` bzw.
`password_policy`). Beide wenden die Werte auch auf Kennwörter öffentlicher
Links an, `security` nur auf Konten; Einzelheiten stehen in den READMEs der
beiden Apps. Für `windows_network_drive` gilt dasselbe: Die Einhängungen
bleiben in der Datenbank und greifen wieder, sobald `wnd` eingeschaltet ist.

#### Datenverzeichnis unter neuem Pfad

Der Pfad des Datenverzeichnisses steht an zwei Stellen in der Datenbank: in
`accounts.home` jedes Kontos und in den Kennungen pfadbasierter Speicher in
`storages` (`local::<pfad>/`). Liegen die Dateien auf dem Zielserver schon
unter dem neuen Pfad – der Regelfall, wenn der Zielserver eine eigene
Verzeichnisstruktur hat –, zeigen die Home-Pfade ins Leere: Die Konten melden
sich an, sehen aber keine Dateien, und unter dem alten Pfad entstehen leere
Home-Verzeichnisse. `user:move-home` hilft dann nicht mehr (siehe Schritt 6).

Stattdessen die Einträge **vor** `occ upgrade` umschreiben. Beispiel für
MariaDB mit altem Pfad `/var/www/owncloud/data`, neuem Pfad
`/var/owncloud-online-data` und Präfix `oc_`:

```sql
-- Home-Pfade: nur echte Präfixtreffer umschreiben, kein REPLACE() über die ganze Zeichenkette
UPDATE oc_accounts
   SET home = CONCAT('/var/owncloud-online-data', SUBSTR(home, CHAR_LENGTH('/var/www/owncloud/data') + 1))
 WHERE SUBSTR(home, 1, CHAR_LENGTH('/var/www/owncloud/data') + 1) = '/var/www/owncloud/data/';
```

Speicherkennungen, die länger als 64 Zeichen wären, legt der Server als
MD5-Wert ab. Ein `REPLACE()` auf `storages.id` findet solche Zeilen nicht und
erzeugt bei einem langen neuen Pfad eine Kennung, nach der der Server nie
sucht. Die Kennungen deshalb vorher so berechnen, wie der Server es tut:

```bash
sid() { php -r '$i = $argv[1]; echo strlen($i) > 64 ? md5($i) : $i, "\n";' "$1"; }
sid 'local::/var/www/owncloud/data/'       # bisherige Kennung der Wurzel
sid 'local::/var/owncloud-online-data/'    # neue Kennung der Wurzel
```

```sql
-- Wurzel des Datenverzeichnisses; entfällt dieser Schritt, liest der Server sie neu ein
UPDATE oc_storages SET id = '<neue Kennung>' WHERE id = '<bisherige Kennung>';
```

Nur umschreiben, solange es die neue Kennung noch nicht gibt; sonst lief der
Server schon mit dem neuen Pfad. Nicht angefasst werden müssen die
Home-Speicher (`home::<konto>`, unabhängig vom Pfad) und die
Upload-Zwischenablagen (`local::<pfad>/<konto>/uploads/`, nach dem Umzug
entstehen neue).

**Lokale Einhängungen:** Ändert sich der Pfad einer Einhängung vom Typ
`local` (Tabelle `external_config`, Schlüssel `datadir`), gehört ihre Kennung
in `storages` im selben Zug umgeschrieben – `local::<alter pfad>/` zu
`local::<neuer pfad>/`, ebenfalls nach der MD5-Regel. Sonst liest der Server die
Einhängung als neuen Speicher ein. Ist sie verschlüsselt, geht dabei die
Versionsangabe jeder Datei verloren, und Dateien, die öfter als einmal
geschrieben wurden, scheitern mit „Bad Signature“. Nachträglich hilft
`occ encryption:fix-encrypted-version <konto> -p <pfad>` (App encryption ab
2.0.9). Lokale Einhängungen werden außerdem nur eingehängt, wenn
`files_external_allow_create_new_local` auf `true` steht.

#### Externe Speicher aus Zusatz-Apps

Jede Einhängung steht mit ihrer Backend- und Anmeldekennung in
`external_mounts`. Einige Kennungen, die Instanzen der Version 10.x verwenden,
stellt der Kern nicht selbst bereit:

| Kennung | Spalte | stammte aus | hier |
| --- | --- | --- | --- |
| `password::logincredentials`, `password::global`, `password::userprovided`, `password::hardcodedconfigcredentials`, `kerberos::kerberos` | `auth_backend` | `windows_network_drive` | App `wnd`; sie übernimmt die Kennungen auch für SFTP, WebDAV und SMB |
| `windows_network_drive`, `windows_network_drive2` | `storage_backend` | `windows_network_drive` | App `wnd` |
| `ftp` | `storage_backend` | `files_external_ftp` | kein Nachfolger |
| `files_external_dropbox` | `storage_backend` | `files_external_dropbox` | kein Nachfolger |

Solange eine Kennung fehlt, bleibt die Zeile unverändert in der Datenbank, die
Einhängung ist aber unbrauchbar. `occ files_external:list` zeigt „Unknown auth
mechanism backend …“ oder „Unknown storage backend …“; Einhängungen mit
fehlender Anmeldeart erscheinen bei den Nutzern gar nicht, solche mit
fehlendem Backend als leerer Ordner, in den sich nicht schreiben lässt
(HTTP 503). Nach `occ app:enable wnd`
werden die Anmeldekennungen wieder aufgelöst. Welche Einhängungen betroffen
sind, schon vor dem Umzug in der alten Datenbank abfragen:

```sql
SELECT mount_id, mount_point, storage_backend, auth_backend
  FROM oc_external_mounts
 WHERE auth_backend IN ('password::logincredentials', 'password::global', 'password::userprovided',
                        'password::hardcodedconfigcredentials', 'kerberos::kerberos')
    OR storage_backend IN ('windows_network_drive', 'windows_network_drive2', 'ftp', 'files_external_dropbox');
```

Die SharePoint-Einbindung der Version 10.x legte ihre Einhängungen in eigenen
Tabellen ab (`sp_*`); auch dafür gibt es keinen Nachfolger.

#### Kein Rückweg auf die Version 10.x

Nach dem Upgrade schreibt der Server Daten in Formaten, die die Version 10.x
nicht lesen kann:

- Gespeicherte Geheimnisse – Kennwörter externer Speicher, Tabelle
  `credentials`, App-Kennwörter in `authtoken` – verschlüsselt der Kern im
  Format `v3`; 10.x kennt nur `v2` und das ältere dreiteilige Format.
- Mit der App encryption bekommt jede neu geschriebene oder neu geteilte Datei
  einen Schlüsselumschlag im Format v2 und eine Signatur des letzten Blocks ohne
  den Zusatz „end“. Beides lehnt die Verschlüsselungs-App der Version 10.x ab.

Zurück geht es deshalb nur über die Sicherung von Datenbank,
Datenverzeichnis und `config.php` von **vor** dem Umzug (Schritt 2); was danach
geschrieben wurde, fehlt dort. Für einen Probebetrieb die Altinstanz
unverändert weiterlaufen lassen und den Umzug an einer Kopie üben.

#### Neue Adresse: Fremdfreigaben und vertrauenswürdige Server

Partnerserver speichern die Adresse, unter der diese Instanz beim Anlegen
einer Fremdfreigabe erreichbar war; vertrauenswürdige Server speichern sie
ebenso. Ändert sich die Adresse beim Umzug, laufen die Rückmeldungen der
Partner (Freigabe annehmen, ablehnen, aufheben), deren Zugriff auf von hier
geteilte Ordner und der Adressbuchabgleich zwischen vertrauenswürdigen Servern
ins Leere. Am sichersten bleibt die alte Adresse: in `trusted_domains` und
`overwrite.cli.url` eintragen und den Namen auf den neuen Server zeigen
lassen. Muss sich die Adresse ändern, die Partner vorher informieren und
Fremdfreigaben neu anlegen. Ob Partnerserver einer Weiterleitung von der alten
Adresse folgen, ist nicht geprüft.

#### Marktzugang der Altinstanz

`upgrade.automatic-app-update` steht ohne Eintrag in `config.php` auf `true`.
Dann fragt der Reparaturschritt „Upgrade app code from the marketplace“
während `occ upgrade` den Markt unter `appstoreurl` ab und schickt den
übernommenen Marktschlüssel mit (App-Wert `market`/`key` oder
`marketplace.key` aus `config.php`, als Kopfzeile `Authorization: apikey: …`).
Steht in der alten `config.php` noch die Adresse des früheren Markts, geht die
Anfrage samt Schlüssel dorthin, ohne `appstoreurl` an
marketplace.owncloud.online. Den alten Schlüssel deshalb vor dem Upgrade
entfernen und `appstoreurl` prüfen – die Befehle stehen auch im
Upgrade-Zustand zur Verfügung:

```bash
sudo -u www-data php8.4 occ config:app:delete market key
sudo -u www-data php8.4 occ config:system:delete marketplace.key
sudo -u www-data php8.4 occ config:system:get appstoreurl
```

Der genaue Grund steht immer im Serverprotokoll, siehe
[Serverprotokoll und Fehlermeldungen](logging.md). Der vollständige Ablauf für
Sicherung und Rückweg ist unter [Backups und Updates](backups-updates.md)
beschrieben.

## Branding und App-Daten übernehmen {#branding}

Die Vorgängergeneration speicherte das Branding an anderer Stelle als diese
Fassung:

| | Vorgängergeneration (Theme-App bis 1.2.12 und 1.3.0 bis unter 3.0.0, etwa 2.0.x; Selfservice etwa 1.5/1.6 oder 2.0.x) | Diese Fassung (Theme-App ab 3.0.0) |
| --- | --- | --- |
| Werte | `oc_appconfig`, `appid = 'oco_selfservice'` (`name`, `slogan`, `base_url`, `header_color`, `header_text_color`, `mail_header_color` …) | `oc_appconfig`, `appid = 'theme-owncloudonline'` (`name`, `slogan`, `base_url`, `header_color`, `primary_color`, `header_text_color`, `logo_url`, `login_logo_url`, `login_background_url`) |
| Bilder | `<datadir>/oco_selfservice/img/` (`header-logo.svg`, `login-logo.svg`, `login-background.jpg`), dazu die ausgelieferten Bilder im App-Ordner der Theme-App | `<datadir>/appdata_<instanceid>/theme-owncloudonline/assets/` |
| Impressum, Datenschutz | `core/legal.imprint_url`, `core/legal.privacy_policy_url` | unverändert dieselben Schlüssel |

Die Theme-App ab 3.0.0 liest Farben und Bilder nur aus dem neuen Bereich. Ohne
Übernahme zeigt die umgezogene Instanz deshalb das Standard-Aussehen, nur
Impressum und Datenschutz kommen an.

### Was automatisch übernommen wird

Ab Theme-App 3.0.2 (Linie 3.0) bzw. 3.1.1 übernimmt `occ upgrade` das alte
Branding, wenn die Theme-App von einer Fassung vor 3.0.0 kommt. Theme-App
3.0.3 bzw. 3.1.2 bringt dazu die Sekundärfarbe, ein eigenes Anmeldelogo, eine
zweite Bildquelle (den [mitgenommenen Theme-Ordner](#mitgenommener-theme-ordner)),
einen Hinweis bei fehlenden Bildern und einen
[Befehl zum Nachholen](#branding-nachholen). Die
Übernahme füllt nur leere Felder, überschreibt nichts, was schon gesetzt ist,
und ein zweiter Lauf ändert nichts. Sie liest die alten Werte direkt aus der
Datenbank — auch wenn der Selfservice abgeschaltet ist — und die Bilder direkt
am Speicher des Datenverzeichnisses, also auch aus einem Object Storage, ohne
den Datei-Cache zu verändern. Jede
übernommene und jede ausgelassene Angabe steht als Zeile in der Ausgabe von
`occ upgrade` (beginnend mit „Branding-Übernahme:"), Probleme zusätzlich als
Warnung in `owncloud.log`.

Wie viel übernommen wird, hängt davon ab, von welcher Fassung die Theme-App
kommt (`installed_version` in der [Bestandsaufnahme](#was-vorher-gesichert-werden-muss)):

| Theme-App vor dem Upgrade | Generation | Übernommen |
| --- | --- | --- |
| bis 1.2.12 | Vorgängergeneration | alles: Name, Slogan, Website-Link, Kopffarbe, Logo und Anmelde-Hintergrund aus `<datadir>/oco_selfservice/img/` |
| 1.2.13 bis 1.2.29 | Zwischengeneration (SaaS bis 11.0.11) | nur Name, Slogan und Website-Link, siehe unten |
| 1.3.0 bis unter 3.0.0, etwa 2.0.x der alten Plattform | Vorgängergeneration | alles wie in der ersten Zeile |
| ab 3.0.0 | diese Generation | nichts, die Übernahme läuft nicht |

Der Schritt `ImportLegacyBranding` läuft nur bei dem Upgrade, das die
Theme-App von einer Fassung unter 3.0.0 hebt. Danach steht
`installed_version` auf 3.0.x oder 3.1.x, und jeder weitere Lauf meldet nur
„Already on the 3.0.0 generation, nothing to import.". Auch einen Sprung nur
an der dritten Stelle (etwa 3.0.2 → 3.0.3) schreibt der Kern ohne
Reparaturschritte fort. Der Ordner `oco_selfservice/` gehört deshalb **vor**
diesem Upgrade in das Datenverzeichnis, auf das `datadirectory` beim Upgrade
zeigt. Fehlt er, kommen Texte und Farben an, Logo und Hintergrund aber nicht.
Ab 3.0.3 bzw. 3.1.2 steht dann eine Zeile „Hinweis:“ in der Ausgabe, mit den
erwarteten Orten und dem Befehl zum Nachholen; das ist bewusst keine Warnung,
denn nicht jeder Kunde hatte eigene Bilder, und nach „Zurücksetzen“ im alten
Selfservice ist der Bildordner gewollt leer (der Schlüssel `cssFileHash`
bleibt dabei stehen und zählt deshalb nicht als eigenes Branding). 3.0.2 und
3.1.1 schwiegen dazu. Nachgeholt wird mit
[`occ theme-owncloudonline:import-legacy`](#branding-nachholen), sobald die
Bilder am erwarteten Ort liegen; mit 3.0.2 oder 3.1.1 nur von Hand: Logo,
Hintergrund und Farben in den Theme-Einstellungen der Administration, Name,
Slogan und Website-Link mit `occ config:app:set theme-owncloudonline
<name|slogan|base_url> --value=…`.

Bei voller Übernahme gilt im Einzelnen:

| Alt | Neu | Hinweis |
| --- | --- | --- |
| `header_color` (Primärfarbe) | `header_color` und `primary_color` | Kopfleiste, Knöpfe und der Farbstreifen im Kopf der Mails folgen damit der alten Kopffarbe |
| `header_text_color` (Sekundärfarbe), ersatzweise das Feld `hex` von `header_text_color_obj` | `header_text_color` | ab 3.0.3 bzw. 3.1.2, 1:1, siehe [Primär- und Sekundärfarbe](#primar-und-sekundarfarbe) |
| `name`, `slogan`, `base_url` | gleichnamige Schlüssel | alte Standardwerte der Plattform werden übersprungen; Name und Slogan nur als reiner Text (ohne `<`, `>` und Steuerzeichen), sonst Warnung |
| `img/header-logo.svg` (Kopflogo) | `logo_url` | Kopfleiste; gibt es kein eigenes Anmeldelogo, auch die Anmeldeseite. SVG wird vorher bereinigt, siehe unten |
| `img/login-logo.svg` (Anmeldelogo), wenn es vom Kopflogo abweicht | `login_logo_url` | ab 3.0.3 bzw. 3.1.2, nur die Anmeldeseite; gibt es nur dieses Logo, steht es als `logo_url` an beiden Stellen |
| `img/login-background.jpg` | `login_background_url` | höchstens 5 MB |
| `core/legal.imprint_url` mit dem alten Standardwert `https://owncloud.com/imprint` | wird entfernt | es gilt das Impressum des Themes; eigene Werte und `legal.privacy_policy_url` bleiben unverändert |

Nicht übernommen werden der eigene Schlüssel `mail_header_color`, eigenes CSS
(`branding.css`), `entity` und `title` sowie Favicon und weitere Bilder des
alten Themes (dafür hat die Theme-App kein Ziel). Der Kopf der Mails zeigt die
übernommene Kopffarbe als Streifen; das Logo im Mail-Kopf bleibt aber das
owncloud.online-Logo (`logo-mail.gif`), und die Fußzeile der Mails nennt
weiter BW-Tech mit Anschrift und Registerangaben. Trägt die Instanz einen
eigenen Namen, steht dort „owncloud.online – A trademark of BW-TECH GMBH“.

Das alte System hatte ein Logo für die farbige Kopfleiste (oft weiß) und eines
für die weiße Anmeldekarte. Ab 3.0.3 bzw. 3.1.2 kommen beide an ihre Stelle.
Gibt es nur eines der beiden oder sind beide gleich, gilt es an beiden Stellen
— anders als im alten System, das an der anderen Stelle das Logo der Plattform
zeigte. Ist das Logo auf der Anmeldekarte dann fast nur weiß, steht eine
Warnung in der Ausgabe; ein dunkleres Logo lädt ein Admin in den
Theme-Einstellungen hoch. Wer dort ein neues Logo setzt oder es entfernt,
entfernt damit auch das übernommene Anmeldelogo. 3.0.2 und 3.1.1 kannten nur
ein Logo: War das Kopflogo fast nur weiß, nahmen sie stattdessen das
Anmeldelogo.

Kommt die Theme-App aus der Zwischengeneration (1.2.13 bis 1.2.29), bleiben
deren Werte erhalten, und aus dem Selfservice kommen nur Name, Slogan und
Website-Link hinzu. Farben und Bilder las schon diese Generation nur aus dem
eigenen Bereich; die alten Selfservice-Werte waren dort nie zu sehen und
werden deshalb nicht nachgefüllt – auch nicht, wenn jemand dort
„Zurücksetzen“ gewählt hatte. Diese Ausnahme gilt nur für 1.2.13 bis 1.2.29:
Die alte Plattform hat eine eigene Versionslinie über 1.2.x hinaus, und
Theme-App 2.0.x hält das Branding wie die Fassungen bis 1.2.12 nur unter
`oco_selfservice`; der Bereich der Theme-App ist dort leer.

SVG-Logos werden vor der Übernahme bereinigt: Skripte, `foreignObject`,
Ereignis-Attribute, externe Verweise und eingebettete Entitäten fliegen
heraus, interne Verweise (`url(#…)`, `<use href="#…">`) und `<style>` ohne
externe Quellen bleiben. Bleibt ein Logo danach unsicher, wird es nicht
übernommen; die Ausgabe sagt dann „Logo bitte neu hochladen", und bis dahin
gilt das Standardlogo. Dasselbe gilt für Dateien in UTF-16/32 oder UTF-7 und
für Bilder über 5 MB.

!!! warning "Theme-App ist nicht Teil dieses Pakets"
    Das Paket dieser Fassung enthält weder die Theme-App noch den Selfservice,
    und der Markt bietet beide nicht an (Stand 11.0.20). `occ upgrade` schaltet
    sie deshalb ab („Not offered by the marketplace"); die alten Werte bleiben
    in der Datenbank, die Bilder im Datenverzeichnis. Die Übernahme lässt sich
    nachholen, solange `installed_version` der Theme-App noch unter 3.0.0
    steht (etwa 1.2.x oder 2.0.x):
    Code der Theme-App (ab 3.0.2 bzw. 3.1.1, besser 3.0.3 bzw. 3.1.2) nach
    `apps-external/` legen, `occ app:enable theme-owncloudonline`, dann
    `occ upgrade`. Den Selfservice in einer Fassung vor 3.0.1 dabei **nicht**
    einschalten — dessen Upgrade löscht die alten Branding-Werte, bevor die
    Theme-App sie lesen kann (Apps werden beim Upgrade alphabetisch
    abgearbeitet, `oco_selfservice` vor `theme-owncloudonline`). Steht
    `installed_version` schon auf 3.0.x oder 3.1.x, holt der
    [Befehl zum Nachholen](#branding-nachholen) die Übernahme nach, solange
    die alten Werte unter `oco_selfservice` noch in der Datenbank stehen.

### Primär- und Sekundärfarbe {#primar-und-sekundarfarbe}

Das alte System kannte zwei Farben. Ab Theme-App 3.0.3 bzw. 3.1.2 kommen beide
1:1 mit (3.0.2 und 3.1.1 übernahmen nur die Primärfarbe und verwarfen die
Sekundärfarbe mit einer Hinweiszeile):

| Farbe | alt (`oco_selfservice`) | neu (`theme-owncloudonline`) | gilt für |
| --- | --- | --- | --- |
| Primärfarbe | `header_color` | `header_color` und `primary_color` | Hintergrund der Kopfleiste, auch auf Link-Freigaben; Knöpfe; Farbstreifen der Mails |
| Sekundärfarbe | `header_text_color` | `header_text_color` | Schrift und gezeichnete Symbole **auf** der Primärfarbe |

Die Sekundärfarbe färbt dieselben Stellen wie im alten System: Name der App,
Benutzername, Burger-Symbol und Dreieck am Benutzermenü in der Kopfleiste,
dazu die Glocke der Benachrichtigungen; die Schrift auf Hauptknöpfen, auf dem
Anmeldeknopf (auch unter dem Zeiger) und auf den Knöpfen alternativer
Anmeldungen; die Punkte der Ladeanzeige im Anmeldeknopf; die Meldungen der
Anmeldeseite (etwa „Falsches Passwort“) auf der Primärfarbe. Eingebundene
Bilder (mitgeliefertes Logo, Symbole der Menüs, Profilbild), das offene
Suchfeld und die Mails färbt sie nicht. Die Lupe der Suche wird weiß, wenn die
Symbole der Kopfleiste hell sind; auf der Linie 3.1 bleibt sie im
Redesign-Rahmen dunkel, weil das Suchfeld dort weiß unter der Kopfleiste
liegt.

Regeln der Übernahme:

- Leer heißt Automatik: Die Schrift wird dunkel oder weiß, je nach Farbe
  darunter — so wie bisher.
- Gesetzt gilt die Farbe 1:1, auch bei schwachem Kontrast. Liegt der Kontrast
  zur Primärfarbe unter 4,5:1, steht er als Zeile „Hinweis:“ in der Ausgabe
  (etwa „nur einen Kontrast von 3,1:1“). Das ist keine Warnung und keine
  Blockade: Übernommen wird trotzdem, korrigiert wird nichts.
- Der alte Standardwert `#FFF` kommt nur mit, wenn er auf einer hellen
  Primärfarbe stand, auf der die Automatik dunkle Schrift nähme; dann wird
  `#ffffff` übernommen, und die Zeile sagt das. Auf dunklen Farben wählt die
  Automatik ohnehin Weiß, der Wert bleibt leer.
- Ein ungültiger Wert wird nicht übernommen und steht als Warnung da.
- Zwischengeneration 1.2.13 bis 1.2.29: nichts aus dem Altbestand. Liegt dort
  noch ein Wert aus 1.2.13 bis 1.2.18 im Bereich der Theme-App, wirkt er ab
  3.0.3 bzw. 3.1.2 wieder; die Ausgabe nennt ihn und den Befehl zum Entfernen.

In den Theme-Einstellungen der Administration steht die Farbe als
„Sekundärfarbe (Text auf der Primärfarbe)“, der Kontrast neben dem Feld. Der
Schalter „Automatisch“ leert den Wert. Von Hand:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:set theme-owncloudonline header_text_color --value=#ffffff
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:delete theme-owncloudonline header_text_color
```

Erlaubt ist nur `#rrggbb`; alles andere gilt als nicht gesetzt.

Noch offen und bewusst nicht nachgebildet sind einige Stellen der alten
`branding.css`: Feldbeschriftungen der Anmeldung und Schrift im Suchfeld in
der Primärfarbe, die Fußzeile der Anmeldeseite, das Anmeldelogo als
Wasserzeichen hinter der Dateiliste sowie Kundenlogo und farbiger Knopf in den
Mails. Die Gegenüberstellung aller Stellen steht im README der Theme-App
(`README.md` im App-Ordner `theme-owncloudonline`, Abschnitt „Altsystem und
neues System im Vergleich“).

### Mitgenommener Theme-Ordner {#mitgenommener-theme-ordner}

Ab Theme-App 3.0.3 bzw. 3.1.2 darf der alte App-Ordner `theme-owncloudonline`
mit umziehen, als zweite Bildquelle neben `oco_selfservice/img/`. Er gehört
kopiert und umbenannt ins Datenverzeichnis, **nie** nach `apps/` oder
`apps-external/`: Ein zweiter Ordner mit derselben App-Kennung bringt die
App-Verwaltung durcheinander, und die alten Vorlagen passen nicht zu 11.0.x.

```bash
rsync -a <alter-app-ordner>/theme-owncloudonline/ <datadir>/theme-owncloudonline_alt/
chown -R www-data:www-data <datadir>/theme-owncloudonline_alt
```

`<alter-app-ordner>` ist das App-Verzeichnis der alten Instanz (`apps/` oder
`apps-external/`), `<datadir>` das neue Datenverzeichnis
(`occ config:system:get datadirectory`). Der Schrägstrich hinter der Quelle
kopiert den Inhalt; mit `cp -a` darf das Ziel vorher nicht existieren, sonst
liegt der Stand eine Ebene zu tief.

| Quelle | Ort auf dem neuen System | Dateien |
| --- | --- | --- |
| Uploads des Kunden (Regelfall) | `<datadir>/oco_selfservice/img/` | `header-logo.svg`, `login-logo.svg`, `login-background.jpg` |
| mitgenommener Theme-Ordner | `<datadir>/theme-owncloudonline_alt/core/img/` | `header-logo.svg` (Kopflogo), `logo.svg` (Anmeldelogo), `background.jpg` (Anmelde-Hintergrund) |

- **Nur Abweichendes:** Ein Bild aus dem Ordner wird nur übernommen, wenn sein
  Inhalt vom ausgelieferten Stand der Theme-App abweicht (Vergleich der
  Prüfsumme); sonst steht in der Ausgabe „identisch mit dem Standard, nichts zu
  übernehmen“. Ein unveränderter Ordner bringt also kein Branding mit — die
  eigenen Bilder des Kunden liegen in aller Regel unter
  `oco_selfservice/img/`, denn der alte Selfservice speicherte Uploads im
  Datenverzeichnis, nicht im App-Ordner.
- **Rangfolge:** Die Uploads unter `oco_selfservice/img/` gewinnen, der Ordner
  füllt nur, was danach noch leer ist. Liefern die Uploads ein Logo, kommt aus
  dem Ordner keines dazu. Ist ein Upload unbrauchbar (kein Bild, zu groß, nicht
  lesbar), steht eine Warnung da, und das Bild aus dem Ordner springt ein. Das
  alte System kannte keine Rangfolge: Dort entschied die Stil-Datei
  `oco_selfservice/css/branding.css`, die erst beim „Speichern“ im Selfservice
  entstand und mit „Zurücksetzen“ verschwand, ob die Uploads oder die Bilder
  des Theme-Ordners sichtbar waren. Die Übernahme bildet das nicht nach; das
  macht nur einen Unterschied, wenn jemand Bilder hochgeladen, aber nie
  gespeichert, oder im Theme-Ordner Dateien ausgetauscht hat.
- **Weitere Dateien** — `defaults.php`, `core/css/styles.css`,
  `core/css/header.css`, Favicon und App-Symbole, `logo.png`, `logo-icon.*`,
  `logo-mail.gif` — werden nur mit den ausgelieferten Fassungen verglichen,
  nie ausgewertet. Weicht eine ab, steht ein Hinweis da, was von Hand zu
  prüfen ist (bei `defaults.php` Name, Slogan und Links).
- **Sicherheit:** Aus dem Ordner wird nichts eingebunden oder ausgeführt,
  gelesen werden nur die genannten Dateien, symbolischen Verknüpfungen wird
  nicht gefolgt. Der Ordner wird weder verändert noch in den Datei-Cache
  aufgenommen. Das Datenverzeichnis muss wie immer gegen Zugriff aus dem Web
  gesperrt sein; der Ordner enthält PHP-Dateien der alten Plattform.
- **Aufräumen:** Am Ende sagt die Ausgabe, ob der Ordner gelöscht werden kann
  („kann gelöscht werden“) oder ob erst die genannten Zeilen zu klären sind
  („bitte noch nicht löschen“).
- **Falsch abgelegt:** Enthält der Ordner keines der Bilder und keine
  `defaults.php`, liegt der Stand eine Ebene zu tief
  (`theme-owncloudonline_alt/theme-owncloudonline/`) oder liegt der Ordner
  unter seinem alten Namen `<datadir>/theme-owncloudonline`, steht eine
  Warnung da; gelesen wird dann nichts.
- **Object Storage als Hauptspeicher:** `oco_selfservice/img/` liegt dann im
  Bucket. Bilder, die nur als Dateien vorliegen, in den Theme-Ordner legen
  (Namen wie in der Tabelle); er wird immer von der Platte gelesen.
- **Benutzerkennung `theme-owncloudonline_alt`:** Gäbe es ein Konto mit genau
  dieser Kennung, wäre der Ordner dessen Benutzerordner. Dann den Ordner
  nicht ablegen und die Bilder unter `oco_selfservice/img/` bereitstellen.

Unmittelbar vor dem Upgrade prüfen, ob der Webserver die Bilder lesen kann;
jede vorhandene Datei muss „lesbar“ zeigen:

```bash
cd <datadir>
for f in oco_selfservice/img/header-logo.svg oco_selfservice/img/login-logo.svg oco_selfservice/img/login-background.jpg \
         theme-owncloudonline_alt/core/img/header-logo.svg theme-owncloudonline_alt/core/img/logo.svg theme-owncloudonline_alt/core/img/background.jpg; do
  [ -e "$f" ] && { sudo -u www-data test -r "$f" && echo "lesbar: $f" || echo "NICHT lesbar: $f"; }
done
```

### Befehl zum Nachholen {#branding-nachholen}

Ab Theme-App 3.0.3 bzw. 3.1.2 führt ein Befehl dieselbe Übernahme wie das
Upgrade aus, jederzeit und auch dann, wenn `installed_version` längst auf 3.0.x
oder 3.1.x steht — etwa wenn die Bilder erst nach dem Upgrade ins
Datenverzeichnis kamen oder die Theme-App nur an der dritten Stelle der
Version sprang:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ theme-owncloudonline:import-legacy --dry-run
sudo -u www-data php8.4 /var/www/owncloud.online/occ theme-owncloudonline:import-legacy
```

| Option | Wirkung |
| --- | --- |
| `--dry-run` | zeigt Zeile für Zeile, was übernommen würde; ändert weder Werte noch Bilder noch den Datei-Cache und schreibt nichts ins Log |
| `--force-images` | übernimmt Logos und Hintergrund aus den Altquellen auch dann, wenn schon welche gesetzt sind; sonst überschreibt es nichts. Liefern die Altquellen kein eigenes Anmeldelogo, wird `login_logo_url` geleert. Das bisherige Bild bleibt als Datei liegen, sein alter Wert steht in der Ausgabe |

Rückgabewert `0`, auch wenn Altwerte abgelehnt wurden (das steht als Warnung
in der Ausgabe); `1` nur bei technischen Fehlern — Datei nicht lesbar, Bild
nicht ablegbar, Datenbank nicht erreichbar. Nach dem Beheben den Befehl
erneut ausführen.

Für die Zwischengeneration 1.2.13 bis 1.2.29 vermerkt der Schritt beim
Upgrade die Vorversion (`legacy_previous_version`), damit der Befehl später
ebenfalls nur Name, Slogan und Website-Link übernimmt. Ohne Vermerk — das
Upgrade lief mit Theme-App 3.0.0 bis 3.0.2 bzw. 3.1.1 — gilt die volle
Übernahme in leere Felder.

!!! warning "Geleerte Werte kommen wieder"
    Der Befehl füllt jedes leere Feld, auch eines, das nach der Übernahme
    bewusst geleert wurde (Logo entfernt, Sekundärfarbe auf „Automatisch“).
    Vorher `--dry-run` ausführen.

Hat Selfservice 3.0.0 beim Upgrade die alten Werte gelöscht (so im SaaS-Paket
11.0.19 und 11.0.20), meldet der Befehl „Hinweis: Unter oco_selfservice steht
kein Branding-Wert, die App steht aber schon auf 3.0.… “. Die Bilder unter
`oco_selfservice/img/` sind noch da. Nachholen mit dem Dump der alten
Plattform:

```bash
# 1. Dump in eine Hilfsdatenbank laden
mysql -e 'CREATE DATABASE branding_hilfe'
mysql branding_hilfe < dump.sql
# 2. Nur die Branding-Zeilen des alten Selfservice übertragen
mysqldump --no-create-info --replace --skip-extended-insert branding_hilfe oc_appconfig \
  --where="appid='oco_selfservice' AND (configkey IN ('name','slogan','base_url','entity','title','mail_header_color','cssFileHash') OR configkey LIKE 'header\_%')" \
  | mysql <neue-datenbank>
# 3. Hilfsdatenbank wieder löschen
mysql -e 'DROP DATABASE branding_hilfe'
# 4. Probelauf, dann echt
sudo -u www-data php8.4 /var/www/owncloud.online/occ theme-owncloudonline:import-legacy --dry-run
sudo -u www-data php8.4 /var/www/owncloud.online/occ theme-owncloudonline:import-legacy
```

Übertragen werden nur Zeilen der App `oco_selfservice`; `installed_version`
und die übrigen Schlüssel der neuen Generation bleiben, wie sie sind. Der
Präfix `oc_` muss in beiden Datenbanken gleich sein.

### Ausweg: Bilder von der laufenden Altinstanz holen {#branding-bilder-per-http}

Fehlen `oco_selfservice/img/` und der alte Theme-Ordner, läuft die alte
Instanz aber noch, liefert der alte Selfservice die Bilder ohne Anmeldung aus
(`/index.php/apps/oco_selfservice/img/<datei>`). Das muss **vor** dem
Wartungsmodus auf dem alten Server geschehen (Schritt 1); danach antwortet er
mit 503.

```bash
mkdir -p <datadir>/oco_selfservice/img
cd <datadir>/oco_selfservice/img
for f in header-logo.svg login-logo.svg login-background.jpg; do
  curl -fsS -o "$f" "https://<alte-adresse>/index.php/apps/oco_selfservice/img/$f"
done
sha256sum header-logo.svg login-logo.svg login-background.jpg
```

!!! warning "Die Route liefert Standardbilder statt 404"
    Für ein Bild, das der Kunde nie hochgeladen hat, antwortet die Route nicht
    mit 404, sondern mit HTTP 200 und dem Standardbild des alten Selfservice
    (gleich dem Plattformbild der Theme-App). Ein Download klappt deshalb
    immer. Jede Datei mit einer dieser Prüfsummen ist **kein** Kundenbild und
    wird gelöscht, bevor die Übernahme läuft — sonst käme das Plattformbild
    als „eigenes“ Bild an und belegte das Feld:

    | Datei | Prüfsumme des Standardbilds (SHA-256) |
    | --- | --- |
    | `header-logo.svg` | `fc9970e7e1e9701b3b8c627a9ee19eb0e421d407bda58a2f601e4b43c3689a57` |
    | `login-logo.svg` | `6d22e6c98e8e56455da51b8b1f596a1b39f453f11c49ca96598197d4209b0df0` |
    | `login-background.jpg` | `bff004a62e18e0ada1a0b22bf36dfe8ef12cccefbc329d914990e6a88d368adc` |

    Nach „Zurücksetzen“ im alten Selfservice ist der Weg nutzlos: Das
    Zurücksetzen löscht den ganzen Ordner `oco_selfservice/` samt den Bildern,
    die Route liefert danach nur noch die Standardbilder.

Danach Besitzer setzen und die Übernahme nachholen:

```bash
chown -R www-data:www-data <datadir>/oco_selfservice
sudo -u www-data php8.4 /var/www/owncloud.online/occ theme-owncloudonline:import-legacy --dry-run
sudo -u www-data php8.4 /var/www/owncloud.online/occ theme-owncloudonline:import-legacy
```

### Was vorher gesichert werden muss

- **`<datadir>/oco_selfservice/`** — dort liegen die einzigen Kopien der alten
  Logos und des Anmelde-Hintergrunds. Mit dem Datenverzeichnis zieht der
  Ordner automatisch um; bei einem Wechsel des Datenpfads muss er von Hand mit
  (Schritt 7). Er sollte schon beim `occ upgrade` (Schritt 6) in dem
  Datenverzeichnis liegen, auf das `datadirectory` in diesem Moment zeigt,
  denn beim Upgrade läuft die Übernahme nur einmal (siehe oben); danach holt
  sie ab Theme-App 3.0.3 bzw. 3.1.2 nur noch der
  [Befehl zum Nachholen](#branding-nachholen) nach. Bei Object Storage als
  Hauptspeicher liegen die Bilder im Bucket, nicht im Datenverzeichnis.
- **Der alte App-Ordner `theme-owncloudonline`** (optional) — nur nötig, wenn
  dort Bilder ausgetauscht wurden; er zieht als
  [mitgenommener Theme-Ordner](#mitgenommener-theme-ordner) um.
- **Der alte Datenbankdump** — bis die Prüfliste unten abgehakt ist. Er ist
  die einzige Quelle, falls eine Fassung ohne Übernahme die Werte löscht.
- **Eine Bestandsaufnahme** auf dem alten Server, um nachher vergleichen zu
  können (enthält keine personenbezogenen Daten):

```sql
SELECT appid, configkey, configvalue FROM oc_appconfig
 WHERE configkey IN ('installed_version', 'enabled', 'types')
    OR appid IN ('oco_selfservice', 'theme-owncloudonline')
    OR (appid = 'core' AND configkey LIKE 'legal.%');
```

### Prüfliste nach dem Umzug {#branding-pruefliste}

1. **Ausgabe von `occ upgrade`:** Zeilen „Branding-Übernahme:" durchsehen;
   Warnungen wie „Logo bitte neu hochladen" abarbeiten. Steht dort ein
   „Hinweis:“ zu fehlenden Bildern, die Bilder an den genannten Ort legen und
   den [Befehl zum Nachholen](#branding-nachholen) ausführen.
2. **Logo:** Anmeldeseite und Kopfzeile nach der Anmeldung zeigen das
   Kundenlogo, nicht das Standardlogo; gab es im alten System zwei Logos,
   jedes an seiner Stelle. Das Bild muss mit HTTP 200 kommen — ein
   404 deutet auf eine geänderte `instanceid`. Auf der weißen Anmeldekarte
   muss es sichtbar sein; meldet die Ausgabe ein „fast nur weißes“ Logo, ein
   dunkleres in den Theme-Einstellungen hochladen.
3. **Farben:** Kopfzeile und Knöpfe in der Primärfarbe des Kunden, Schrift
   darauf in seiner Sekundärfarbe (ohne Sekundärfarbe dunkel oder weiß).
4. **Name und Slogan:** Browser-Titel und Fußzeile tragen den Kundennamen.
   Name, Slogan und Website-Link lassen sich in den Theme-Einstellungen der
   Administration weder ändern noch zurücksetzen („Zurücksetzen“ lässt sie
   stehen). Ändern mit `occ config:app:set theme-owncloudonline
   <name|slogan|base_url> --value=…`, zurück zum Standard mit
   `occ config:app:delete theme-owncloudonline <name|slogan|base_url>`.
5. **Anmelde-Hintergrund:** wie auf dem alten Server.
6. **Impressum und Datenschutz:** Fußzeile anklicken.
   `occ config:app:get core legal.imprint_url` darf nicht auf die Domain
   `owncloud.com` zeigen; sonst mit `occ config:app:set core legal.imprint_url
   --value=<Impressum des Kunden>` setzen oder mit `occ config:app:delete core
   legal.imprint_url` entfernen. `https://owncloud.online/privacy-policy/` war
   der alte Standard für den Datenschutz-Link und bleibt stehen, bis jemand ihn
   ändert.
7. **Abgeschaltete Apps:** `grep 'Upgrade: disabled app'` im `owncloud.log`
   mit der Bestandsaufnahme vergleichen. Jede Zeile nennt den bisherigen
   `enabled`-Wert (bei Gruppenfreigaben die Gruppenliste) und den Weg zurück.
8. **Mail:** eine Benachrichtigung auslösen (etwa „Passwort zurücksetzen") und
   ansehen: Der Kopf trägt die übernommene Kopffarbe, das Logo darin bleibt
   das owncloud.online-Logo, die Fußzeile nennt BW-Tech.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Du greifst auf den Server über eine nicht vertrauenswürdige Domain zu.", HTTP 400 | Neuer Hostname fehlt in `trusted_domains` | `occ config:system:set trusted_domains 0 --value=cloud.example.com`; die Prüfung greift nicht in `occ`, der Befehl läuft also trotzdem |
| „Dein Daten-Verzeichnis ist ungültig" | `.ocdata` fehlt — versteckte Dateien wurden beim Kopieren ausgelassen oder beim Wechsel des Datenpfads nicht mitgenommen | Datenverzeichnis erneut mit `tar` oder `rsync -a` übertragen |
| „Dein Datenverzeichnis muss ein absoluter Pfad sein" | `datadirectory` relativ eingetragen | Absoluten Pfad in `config/config.php` setzen |
| „Dein Daten-Verzeichnis ist von anderen Benutzern lesbar" | Rechte nach dem Kopieren zu offen | `chmod 0770` auf das Datenverzeichnis |
| `occ` bricht mit Datenbankfehler ab | `dbhost`/`dbname`/`dbuser` zeigen noch auf den alten Server | Werte in `config/config.php` korrigieren, dann erneut aufrufen |
| „There are no commands defined in the "files" namespace." bei `files:scan` | Wartungsmodus ist noch an, `occ` lädt dann keine Apps | `occ maintenance:mode --off`, dann `files:scan` wiederholen |
| Konten vorhanden, Dateien aber leer | Gespeicherte Home-Pfade zeigen auf das alte Datenverzeichnis | `occ user:home:list-dirs` prüfen; `occ user:move-home` nur, solange die Dateien noch am alten Pfad liegen, danach `occ files:scan --all` |
| Dateien liegen auf der Platte, fehlen aber in der Oberfläche | Dateicache kennt sie nicht | `occ files:scan --all`, bei abgehängten Einträgen `--repair` ergänzen |
| App-Passwörter mit HTTP 401 abgewiesen, TOTP-Anmeldung mit HTTP 500 „HMAC does not match" | `secret`/`passwordsalt` stammen nicht aus der alten `config.php` | Ursprüngliche Werte eintragen; ohne sie müssen App-Passwörter und TOTP neu eingerichtet werden |
| Externer Speicher meldet Anmeldefehler | `secret` weicht ab, weil `config.php` neu erzeugt wurde | Ursprüngliche `config.php` einspielen; sonst Zugangsdaten neu hinterlegen |
| Logo und Anmelde-Hintergrund fehlen, Bildaufruf liefert HTTP 404 | `instanceid` geändert — die Bilder liegen unter `appdata_<alte instanceid>/` | Alte `instanceid` eintragen |
| Standard-Aussehen statt Kunden-Branding | Theme-App fehlt, ist abgeschaltet oder kam ohne Übernahme (vor 3.0.2) | siehe [Branding und App-Daten übernehmen](#branding) |
| Name und Farbe übernommen, Logo und Anmelde-Hintergrund fehlen; die Upgrade-Ausgabe sagt dazu nichts (Theme-App 3.0.2 bzw. 3.1.1) oder nennt als „Hinweis:“ die erwarteten Orte (ab 3.0.3 bzw. 3.1.2) | `oco_selfservice/img/` lag beim Upgrade nicht im Datenverzeichnis | ab Theme-App 3.0.3 bzw. 3.1.2: Bilder an den erwarteten Ort legen, Besitzer setzen, dann [`occ theme-owncloudonline:import-legacy`](#branding-nachholen); sonst die Bilder aus der Sicherung in den Theme-Einstellungen hochladen. Ein weiteres `occ upgrade` holt sie nicht nach |
| `import-legacy` meldet „Unter oco_selfservice steht kein Branding-Wert, die App steht aber schon auf 3.0.…“ | Selfservice 3.0.0 hat die alten Werte beim Upgrade gelöscht | Branding-Zeilen aus dem alten Dump nachtragen, siehe [Befehl zum Nachholen](#branding-nachholen) |
| Name und Slogan übernommen, Farben und Bilder der alten Selfservice-Seite nicht | Theme-App kam aus der Zwischengeneration 1.2.13 bis 1.2.29, die diese Werte nie angezeigt hat | so gewollt, siehe [Was automatisch übernommen wird](#was-automatisch-ubernommen-wird) |
| Fußzeile verlinkt auf ein fremdes Impressum | alter Standardwert in `core/legal.imprint_url` | `occ config:app:set core legal.imprint_url --value=…` oder `config:app:delete` |
| Kundenname fehlt, Warnung „name enthält spitze Klammern oder Steuerzeichen“ | alter Name enthält `<`, `>`, einen Zeilenumbruch oder kaputtes UTF-8 | Namen als reinen Text setzen: `occ config:app:set theme-owncloudonline name --value=…` |
| Anmeldekarte zeigt kein Logo, in der Kopfleiste ist es da | weißes Logo auf weißer Karte; die Ausgabe meldet „fast nur weiß“ | dunkleres Logo in den Theme-Einstellungen hochladen |
| `occ app:enable <app>` scheitert nach dem Import | Tabellen einer Probeinstallation liegen neben dem Dump | Dump erneut in eine leere Datenbank einspielen (Schritt 3) |
| Links in Mails zeigen auf den alten Server | `overwrite.cli.url` nicht angepasst | Wert setzen; die Links werden beim nächsten Cron-Lauf neu erzeugt |
| Papierkorb wächst, keine Benachrichtigungen | Cron-Eintrag wurde nicht übernommen | Cron auf dem neuen Server einrichten, `occ config:app:get core lastcron` prüfen |
| „Turn on maintenance mode to use this command." | `maintenance:repair` ohne Wartungsmodus aufgerufen | `occ maintenance:mode --on`, Befehl wiederholen |
| Oberfläche zeigt dauerhaft den Wartungsmodus | `maintenance` steht noch auf `true` | `occ maintenance:mode --off` |
| Uploads scheitern mit Sperrfehlern | Sperreinträge aus der abgebrochenen Sitzung im Dump (nur ohne `memcache.locking`) | `occ maintenance:file-locks --cleanup-expired` |
| `occ upgrade` bricht mit „Upgrade is not possible" ab und nennt Apps, die es auf dem neuen Server nicht gibt | Die Datenbank kommt von einer älteren Instanz mit Apps, deren Code auf dem Ziel fehlt | Ab 11.0.19 schaltet der Reparaturschritt solche Apps ab und nennt sie in der Ausgabe; das Upgrade läuft durch. Davor: je App `occ app:disable <app>` — der Befehl steht auch im Upgrade-Zustand zur Verfügung —, dann `occ upgrade` erneut |
| `occ upgrade` bricht mit „Upgrade is not possible" ab und nennt Apps, deren Ordner es gibt | alte App-Fassungen aus dem mitkopierten `apps-external` passen nicht zur neuen Version | altes `apps-external` entfernen, frisches aus dem Paket verwenden, `occ upgrade` wiederholen |
