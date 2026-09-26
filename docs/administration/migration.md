# Umzug auf einen anderen Server

Ein Umzug verschiebt eine bestehende Instanz auf neue Hardware oder einen
anderen Hoster. Drei Dinge gehören dabei untrennbar zusammen und müssen vom
**selben Zeitpunkt** stammen: das Datenverzeichnis, die Datenbank und
`config/config.php`. Passen sie nicht zusammen, verweisen Metadaten auf
Dateien, die es nicht gibt — oder umgekehrt.

Wechselt beim Umzug zugleich die Version — etwa von der Vorgängergeneration
(Server 10.x mit Theme-App 1.2.x und Selfservice 1.5/1.6) auf diese Fassung —,
gelten zusätzlich die Punkte unter
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
bleiben in der Datenbank. Seit der Fassung nach 11.0.20 richtet sich der
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

Der genaue Grund steht immer im Serverprotokoll, siehe
[Serverprotokoll und Fehlermeldungen](logging.md). Der vollständige Ablauf für
Sicherung und Rückweg ist unter [Backups und Updates](backups-updates.md)
beschrieben.

## Branding und App-Daten übernehmen {#branding}

Die Vorgängergeneration speicherte das Branding an anderer Stelle als diese
Fassung:

| | Vorgängergeneration (Theme-App 1.2.x, Selfservice 1.5/1.6) | Diese Fassung (Theme-App ab 3.0.0) |
| --- | --- | --- |
| Werte | `oc_appconfig`, `appid = 'oco_selfservice'` (`name`, `slogan`, `base_url`, `header_color`, `header_text_color`, `mail_header_color` …) | `oc_appconfig`, `appid = 'theme-owncloudonline'` (`name`, `slogan`, `base_url`, `header_color`, `primary_color`, `logo_url`, `login_background_url`) |
| Bilder | `<datadir>/oco_selfservice/img/` (`header-logo.svg`, `login-logo.svg`, `login-background.jpg`) | `<datadir>/appdata_<instanceid>/theme-owncloudonline/assets/` |
| Impressum, Datenschutz | `core/legal.imprint_url`, `core/legal.privacy_policy_url` | unverändert dieselben Schlüssel |

Die Theme-App ab 3.0.0 liest Farben und Bilder nur aus dem neuen Bereich. Ohne
Übernahme zeigt die umgezogene Instanz deshalb das Standard-Aussehen, nur
Impressum und Datenschutz kommen an.

### Was automatisch übernommen wird

Ab Theme-App 3.0.2 (Linie 3.0) bzw. 3.1.1 übernimmt `occ upgrade` das alte
Branding, wenn die Theme-App von einer Fassung vor 3.0.0 kommt. Die
Übernahme füllt nur leere Felder, überschreibt nichts, was schon gesetzt ist,
und ein zweiter Lauf ändert nichts. Sie liest die alten Werte direkt aus der
Datenbank — auch wenn der Selfservice abgeschaltet ist — und die Bilder über
die Datei-Schicht des Servers, also auch aus einem Object Storage. Jede
übernommene und jede ausgelassene Angabe steht als Zeile in der Ausgabe von
`occ upgrade` (beginnend mit „Branding-Übernahme:"), Probleme zusätzlich als
Warnung in `owncloud.log`.

| Alt | Neu | Hinweis |
| --- | --- | --- |
| `header_color` | `header_color` und `primary_color` | die Akzentfarbe (Knöpfe) folgt damit der alten Kopffarbe |
| `name`, `slogan`, `base_url` | gleichnamige Schlüssel | alte Standardwerte der Plattform werden übersprungen |
| `img/header-logo.svg`, ersatzweise `img/login-logo.svg` | `logo_url` | SVG wird vorher bereinigt, siehe unten |
| `img/login-background.jpg` | `login_background_url` | |
| `core/legal.imprint_url` mit dem alten Standardwert `https://owncloud.com/imprint` | wird entfernt | es gilt das Impressum des Themes; eigene Werte und `legal.privacy_policy_url` bleiben unverändert |

Nicht übernommen werden die Kopf-Textfarbe (die Theme-App berechnet den
Kontrast selbst), die Farbe des Mail-Kopfs, eigenes CSS (`branding.css`) sowie
`entity` und `title`. Werte einer Zwischengeneration der Theme-App (1.2.13 bis
1.2.29), die schon im neuen Bereich stehen, bleiben erhalten.

SVG-Logos werden vor der Übernahme bereinigt: Skripte, `foreignObject`,
Ereignis-Attribute, externe Verweise und eingebettete Entitäten fliegen
heraus, interne Verweise (`url(#…)`, `<use href="#…">`) und `<style>` ohne
externe Quellen bleiben. Bleibt ein Logo danach unsicher, wird es nicht
übernommen; die Ausgabe sagt dann „Logo bitte neu hochladen", und bis dahin
gilt das Standardlogo.

!!! warning "Theme-App ist nicht Teil dieses Pakets"
    Das Paket dieser Fassung enthält weder die Theme-App noch den Selfservice,
    und der Markt bietet beide nicht an (Stand 11.0.20). `occ upgrade` schaltet
    sie deshalb ab („Not offered by the marketplace"); die alten Werte bleiben
    in der Datenbank, die Bilder im Datenverzeichnis. Die Übernahme lässt sich
    nachholen, solange `installed_version` der Theme-App noch auf 1.2.x steht:
    Code der Theme-App (ab 3.0.2 bzw. 3.1.1) nach `apps-external/` legen,
    `occ app:enable theme-owncloudonline`, dann `occ upgrade`. Den Selfservice
    in einer Fassung vor 3.0.1 dabei **nicht** einschalten — dessen Upgrade
    löscht die alten Branding-Werte, bevor die Theme-App sie lesen kann (Apps
    werden beim Upgrade alphabetisch abgearbeitet, `oco_selfservice` vor
    `theme-owncloudonline`).

### Was vorher gesichert werden muss

- **`<datadir>/oco_selfservice/`** — dort liegen die einzigen Kopien der alten
  Logos und des Anmelde-Hintergrunds. Mit dem Datenverzeichnis zieht der
  Ordner automatisch um; bei einem Wechsel des Datenpfads muss er von Hand mit
  (Schritt 7). Bei Object Storage als Hauptspeicher liegen die Bilder im
  Bucket, nicht im Datenverzeichnis.
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
   Warnungen wie „Logo bitte neu hochladen" abarbeiten.
2. **Logo:** Anmeldeseite und Kopfzeile nach der Anmeldung zeigen das
   Kundenlogo, nicht das Standardlogo. Das Bild muss mit HTTP 200 kommen — ein
   404 deutet auf eine geänderte `instanceid`.
3. **Farben:** Kopfzeile und Knöpfe in der Kundenfarbe.
4. **Name und Slogan:** Browser-Titel und Fußzeile tragen den Kundennamen.
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
   den Kopf ansehen — seine alte Farbe wird nicht übernommen.

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
| Fußzeile verlinkt auf ein fremdes Impressum | alter Standardwert in `core/legal.imprint_url` | `occ config:app:set core legal.imprint_url --value=…` oder `config:app:delete` |
| `occ app:enable <app>` scheitert nach dem Import | Tabellen einer Probeinstallation liegen neben dem Dump | Dump erneut in eine leere Datenbank einspielen (Schritt 3) |
| Links in Mails zeigen auf den alten Server | `overwrite.cli.url` nicht angepasst | Wert setzen; die Links werden beim nächsten Cron-Lauf neu erzeugt |
| Papierkorb wächst, keine Benachrichtigungen | Cron-Eintrag wurde nicht übernommen | Cron auf dem neuen Server einrichten, `occ config:app:get core lastcron` prüfen |
| „Turn on maintenance mode to use this command." | `maintenance:repair` ohne Wartungsmodus aufgerufen | `occ maintenance:mode --on`, Befehl wiederholen |
| Oberfläche zeigt dauerhaft den Wartungsmodus | `maintenance` steht noch auf `true` | `occ maintenance:mode --off` |
| Uploads scheitern mit Sperrfehlern | Sperreinträge aus der abgebrochenen Sitzung im Dump (nur ohne `memcache.locking`) | `occ maintenance:file-locks --cleanup-expired` |
| `occ upgrade` bricht mit „Upgrade is not possible" ab und nennt Apps, die es auf dem neuen Server nicht gibt | Die Datenbank kommt von einer älteren Instanz mit Apps, deren Code auf dem Ziel fehlt | Ab 11.0.19 schaltet der Reparaturschritt solche Apps ab und nennt sie in der Ausgabe; das Upgrade läuft durch. Davor: je App `occ app:disable <app>` — der Befehl steht auch im Upgrade-Zustand zur Verfügung —, dann `occ upgrade` erneut |
| `occ upgrade` bricht mit „Upgrade is not possible" ab und nennt Apps, deren Ordner es gibt | alte App-Fassungen aus dem mitkopierten `apps-external` passen nicht zur neuen Version | altes `apps-external` entfernen, frisches aus dem Paket verwenden, `occ upgrade` wiederholen |
