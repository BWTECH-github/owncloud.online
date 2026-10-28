# Installation im Webhosting mit Plesk

owncloud.online lässt sich auch in einem Hosting-Paket mit dem Kundenmenü
Plesk installieren. Sie brauchen weder Root-Zugang noch Kommandozeile: Sie laden
eine ZIP-Datei hoch, legen eine Datenbank an und schließen die Einrichtung im
Browser ab. Andere Kundenmenüs wie cPanel funktionieren nach demselben Muster,
nur heißen die Menüpunkte anders.

Am Ende liegen im Heimatverzeichnis Ihres Hosting-Pakets, neben `httpdocs`,
zwei neue Ordner:

| Ordner | Inhalt |
| --- | --- |
| `owncloud` | das Programm. Die Domain zeigt auf diesen Ordner. |
| `oco-data` | das Datenverzeichnis mit allen hochgeladenen Dateien. Es liegt außerhalb der Website und ist aus dem Internet nicht erreichbar. |

In den Beispielen heißt die Domain `cloud.example.com`. Setzen Sie Ihre eigene
ein.

## Voraussetzungen prüfen {#voraussetzungen}

Prüfen Sie diese Punkte, bevor Sie anfangen. An jedem davon scheitert die
Installation sonst später.

| Anforderung | Warum | Wo Sie es sehen |
| --- | --- | --- |
| PHP 8.4 | Ältere Versionen starten nicht, PHP 8.5 wird nicht unterstützt | *PHP-Einstellungen* der Domain |
| Linux-Server | Hosting auf Windows lehnt owncloud.online ab | Angabe des Hosters |
| MySQL oder MariaDB | Datenbank für Konten, Freigaben und Dateiliste | *Datenbanken* |
| PHP darf 512 MB Arbeitsspeicher nutzen | Vorschaubilder und Aktualisierungen brauchen ihn | *PHP-Einstellungen*, Wert `memory_limit` |
| Ordner außerhalb der Website | für das Datenverzeichnis | Dateimanager: Heimatverzeichnis mit `httpdocs` darin |
| *Geplante Aufgaben* | für die Hintergrund-Jobs | Menüpunkt im Kundenmenü |
| Let's Encrypt | für HTTPS | *SSL/TLS-Zertifikate* |
| ausgehende HTTPS-Verbindungen | für Apps aus dem Marketplace, nicht zwingend | beim Hoster erfragen |

owncloud.online prüft beim Start außerdem diese PHP-Erweiterungen: zip, dom,
XMLWriter, XMLReader, intl, libxml, mbstring, ctype, JSON, GD, zlib, iconv,
SimpleXML, hash, cURL und PDO mit dem Treiber für MySQL. In Plesk sind sie
üblicherweise vorhanden.

## Grenzen des Webhostings {#grenzen}

Im Webhosting fehlen meist die Kommandozeile mit dem Befehl `occ` und Redis.
Damit gelten diese Grenzen:

| Grenze | Folge |
| --- | --- |
| mehr als 50 Konten | Die Aktualisierung im Browser verweigert den Dienst und verlangt den Befehl `occ upgrade`. |
| App `user_ldap` oder `user_shibboleth` installiert | dieselbe Sperre, unabhängig von der Zahl der Konten |
| kein Redis | Dateisperren laufen über die Datenbank. Die Einstellungsseite zeigt dazu dauerhaft eine Warnung. |
| Wartungsaufgaben | Dateibestand neu einlesen, Dateien zwischen Konten übertragen und Reparaturschritte gehen nur mit `occ`. |

Für mehr als 50 Konten oder eine Anbindung an LDAP planen Sie von Anfang an
einen [Linux-Server](linux-server.md) oder ein Paket mit SSH-Zugang ein, siehe
[Mit SSH-Zugang](#ssh).

## 1. Domain und HTTPS vorbereiten {#domain}

1. Legen Sie in Plesk unter *Websites & Domains* die Domain oder Subdomain
   `cloud.example.com` an, falls es sie noch nicht gibt.
2. Stellen Sie unter *SSL/TLS-Zertifikate* ein kostenloses Zertifikat von
   Let's Encrypt für die Domain aus.
3. Schalten Sie in den *Hosting-Einstellungen* der Domain die dauerhafte
   Weiterleitung von HTTP auf HTTPS ein.

Rufen Sie `https://cloud.example.com` auf. Der Browser zeigt die Seite ohne
Warnung zum Zertifikat an.

## 2. PHP einstellen {#php}

Öffnen Sie die *PHP-Einstellungen* der Domain:

| Einstellung | Wert |
| --- | --- |
| PHP-Version | 8.4, als FPM-Anwendung über Apache. Bei der Variante über nginx greift die mitgelieferte `.htaccess` nicht. |
| `memory_limit` | `512M` oder mehr |
| `max_execution_time` | `3600` |
| `open_basedir` | muss das ganze Heimatverzeichnis umfassen. Die Vorgabe von Plesk `{WEBSPACEROOT}{/}{:}{TMP}{/}` tut das. |

Speichern Sie die Einstellungen.

??? note "Hintergrund: Werte aus der .user.ini"
    Das Release bringt eine Datei `.user.ini` mit, die PHP als FPM-Anwendung
    bei jeder Anfrage liest. Sie setzt `upload_max_filesize` und
    `post_max_size` auf `513M`, `memory_limit` auf `512M`, `default_charset`
    auf `UTF-8` und `output_buffering` auf `0`. Diese Werte haben Vorrang vor
    den PHP-Einstellungen in Plesk, solange der Hoster sie nicht ausdrücklich
    sperrt.

    Die Grenze von 513 MB gilt für eine einzelne Anfrage. Die Weboberfläche und
    der Desktop-Client laden große Dateien in Teilen hoch und sind davon nicht
    betroffen.

## 3. Herunterladen und prüfen {#herunterladen}

Laden Sie von <https://github.com/BWTECH-github/owncloud.online/releases> auf
Ihren eigenen Rechner:

- `owncloud-online-11.0.25.zip`, rund 65 MB, oder die neuere Version, die dort
  angeboten wird,
- `SHA256SUMS.txt` mit den Prüfsummen.

Prüfen Sie, ob die ZIP-Datei unverändert angekommen ist. Unter Windows öffnen
Sie PowerShell im Download-Ordner und geben ein:

```powershell
(Get-FileHash .\owncloud-online-11.0.25.zip -Algorithm SHA256).Hash -eq ((Select-String 'owncloud-online-11.0.25.zip' .\SHA256SUMS.txt).Line -split '\s+')[0]
```

Die Antwort lautet `True`. Unter Linux lautet der Befehl
`sha256sum --ignore-missing -c SHA256SUMS.txt`, die Antwort
`owncloud-online-11.0.25.zip: OK`. Unter macOS zeigt
`shasum -a 256 owncloud-online-11.0.25.zip` die Prüfsumme an. Vergleichen Sie
sie mit der Zeile für die ZIP-Datei in `SHA256SUMS.txt`.

Lautet die Antwort `False` oder weicht die Prüfsumme ab, ist die Datei
beschädigt oder verändert. Laden Sie sie neu herunter und laden Sie sie nicht
hoch.

## 4. Hochladen und auspacken {#hochladen}

1. Öffnen Sie in Plesk den Dateimanager. Er zeigt das Heimatverzeichnis mit
   dem Ordner `httpdocs`.
2. Laden Sie die ZIP-Datei in dieses Heimatverzeichnis hoch, nicht in
   `httpdocs`. Laden Sie die ZIP-Datei hoch, nicht die ausgepackten Dateien.
   Das Programm besteht aus über 11 000 Dateien, einzeln hochgeladen dauert
   das Stunden.
3. Entpacken Sie die ZIP-Datei über ihr Menü im Dateimanager. Es entsteht der
   Ordner `owncloud`.
4. Legen Sie daneben einen leeren Ordner `oco-data` an.
5. Löschen Sie die ZIP-Datei.

Öffnen Sie den Ordner `owncloud`. Darin liegen unter anderem die Ordner `apps`,
`config` und `core` sowie die Dateien `.htaccess` und `.user.ini`.

## 5. Datenbank anlegen {#datenbank}

Legen Sie unter *Datenbanken* mit *Datenbank hinzufügen* eine neue, leere
Datenbank an, zum Beispiel `owncloud_online`, dazu einen Datenbankbenutzer mit
einem eigenen, zufälligen Passwort. Notieren Sie vier Angaben:

| Angabe | Hinweis |
| --- | --- |
| Datenbankname | manchmal mit einem festen Präfix, etwa `p123_owncloud_online` |
| Datenbankbenutzer | |
| Passwort | |
| Datenbankserver | meist `localhost`. Zeigt Plesk einen anderen Namen oder einen Port wie `localhost:3306`, notieren Sie ihn genau so. |

Die Datenbank muss leer sein. Tabellen einer anderen Anwendung führen zu
Fehlern bei der Einrichtung.

## 6. Domain auf den Programmordner richten {#programmordner}

Stellen Sie in den *Hosting-Einstellungen* von `cloud.example.com` das
Verzeichnis, aus dem die Website ausgeliefert wird (Document Root), auf den
Ordner `owncloud`. Speichern Sie.

Rufen Sie `https://cloud.example.com` auf. Es erscheint der
Einrichtungsassistent von owncloud.online.

!!! warning "Einrichtung sofort abschließen"
    Bis Sie den nächsten Schritt abgeschlossen haben, kann jeder, der die
    Adresse aufruft, die Installation übernehmen. Machen Sie ohne Pause
    weiter.

## 7. Einrichtung im Browser {#einrichtung}

Füllen Sie das Formular aus:

| Feld | Eingabe |
| --- | --- |
| *Administrator-Konto anlegen*: *Benutzername* und *Passwort* | Ihr künftiges Administratorkonto. Ein eigenes, langes Passwort, das Sie sonst nirgends verwenden. |
| *Speicher & Datenbank* | Zum Aufklappen anklicken. |
| *Datenverzeichnis* | Vorgeschlagen ist ein Ordner `data` im Programmordner, etwa `/var/www/vhosts/example.com/owncloud/data`. Ersetzen Sie `owncloud/data` durch `oco-data`, im Beispiel also `/var/www/vhosts/example.com/oco-data`. |
| *Datenbank einrichten* | Stehen mehrere Datenbanken zur Wahl, wählen Sie *MySQL/MariaDB*. |
| *Datenbank-Benutzer*, *Datenbank-Passwort*, *Datenbank-Name*, *Datenbank-Host* | die vier Angaben aus Schritt 5 |

Klicken Sie auf *Installation abschließen*. Die Einrichtung dauert je nach
Hoster bis zu zwei Minuten. Brechen Sie sie nicht ab. Danach erscheint die
Dateiansicht oder die Anmeldeseite.

??? note "Hintergrund: was der Assistent einträgt"
    Der Assistent schreibt `config/config.php`. Dafür muss der Ordner `config`
    beschreibbar sein, was in Plesk der Fall ist.

    | Schlüssel | Wert |
    | --- | --- |
    | `datadirectory` | der Pfad zu `oco-data` |
    | `dbtype`, `dbname`, `dbuser`, `dbpassword`, `dbhost` | Ihre Datenbankangaben |
    | `trusted_domains` | die Adresse, unter der Sie den Assistenten aufgerufen haben |
    | `overwrite.cli.url` | dieselbe Adresse mit `https://` |
    | `passwordsalt`, `secret` | zufällig erzeugt |
    | `apps_paths` | `apps/` (fest) und `apps-external/` (für Apps aus dem Marketplace) |

    Im Programmordner bleibt ein fast leerer Ordner `data` zurück. Der
    Assistent legt ihn an, um den Schutz durch die `.htaccess` zu prüfen.

## 8. Hintergrund-Jobs einrichten {#cron}

owncloud.online erledigt alle 15 Minuten Arbeiten im Hintergrund: Papierkorb
und alte Versionen aufräumen, abgelaufene Freigaben entfernen,
Benachrichtigungen und Mails verschicken. Legen Sie dafür unter *Geplante
Aufgaben* eine neue Aufgabe an:

| Feld | Eingabe |
| --- | --- |
| Aufgabentyp | *PHP-Skript ausführen* |
| Skriptpfad | `owncloud/cron.php`, über das Ordnersymbol auswählen |
| PHP-Version | 8.4 |
| Ausführen | alle 15 Minuten, im Cron-Format `*/15 * * * *` |

Stellen Sie danach in owncloud.online unter *Einstellungen → Administration →
Allgemein* im Abschnitt *Cron* die Option *Cron* ein.

![Administration, Allgemein: Cron mit den Optionen AJAX, Webcron und Cron, darunter das Log](../assets/screenshots/de/admin-background-jobs.png)

Die Option *Cron* passt zu einer geplanten Aufgabe, die `cron.php` mit PHP
ausführt. Nach spätestens 15 Minuten steht dort „Letzte Cron-Job-Ausführung: …“ mit
einem grünen Punkt.

??? note "Wenn Ihr Hoster nur Adressen aufrufen kann"
    Manche Pakete bieten nur Aufgaben vom Typ *URL abrufen*. Tragen Sie dann
    diese Adresse ein, möglichst alle 5 Minuten:

    ```text
    https://cloud.example.com/index.php/cron
    ```

    Stellen Sie in owncloud.online die Option *Webcron* ein. Bei der Option
    *Cron* antwortet die Adresse nur mit `Background jobs are using system
    cron!` und erledigt nichts. Jeder Aufruf arbeitet genau einen Job ab.
    Deshalb ist der kurze Abstand wichtig.

    Die Einstellungsseite nennt die Datei `cron.php`. Über das Web leitet sie
    nur auf die Adresse oben weiter.

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | „Cron wurde bis jetzt noch nicht ausgeführt!“ bleibt stehen | Aufgabe fehlt, läuft mit einer anderen PHP-Version oder die Option passt nicht zur Aufgabe | Aufgabe und PHP-Version prüfen; *PHP-Skript ausführen* gehört zu *Cron*, *URL abrufen* zu *Webcron* |
    | Die Aufgabe läuft, aber es passiert nichts | Der Hoster sperrt die PHP-Funktion `system()`, über die `cron.php` den eigentlichen Lauf startet | auf *URL abrufen* und *Webcron* wechseln |
    | Die Liste der Jobs wird nicht kürzer | *Webcron* arbeitet nur einen Job je Aufruf ab | Abstand verkürzen oder *PHP-Skript ausführen* nutzen |

## 9. Anmelden und prüfen {#pruefen}

Rufen Sie `https://cloud.example.com/status.php` auf. Die Antwort enthält
`"installed":true` und `"maintenance":false`.

Melden Sie sich an und öffnen Sie *Einstellungen → Administration →
Allgemein*. Die Seite prüft beim Öffnen die Einrichtung und zeigt Probleme im
Abschnitt *Sicherheits- & Einrichtungswarnungen*. Erscheint dort

> Dein Datenverzeichnis und deine Dateien sind wahrscheinlich vom Internet aus
> erreichbar. Die .htaccess-Datei funktioniert nicht.

liegt das Datenverzeichnis im Programmordner, und die `.htaccess` schützt es
nicht. Diese Warnung darf nicht stehen bleiben. Bei einer frischen Installation
ohne Daten beginnen Sie neu: Löschen Sie `owncloud/config/config.php`, legen Sie
die Datenbank in Plesk neu an und wiederholen Sie Schritt 7 mit `oco-data`. Was die übrigen Meldungen bedeuten, steht unter
[Sicherheit und Setup-Warnungen](../administration/security-hardening.md).

Richten Sie danach den [E-Mail-Versand](../administration/email.md) ein.
Tragen Sie dort den SMTP-Zugang Ihres Postfachs ein. Ohne ihn kommen keine
Mails zum Zurücksetzen von Passwörtern und keine Einladungen an.

## Zwischenspeicher {#zwischenspeicher}

Ohne Zwischenspeicher liest owncloud.online bei jeder Anfrage alle Sprach- und
App-Dateien neu ein. Bietet Ihr Hoster die PHP-Erweiterung APCu an, schalten
Sie sie als Zwischenspeicher ein. Öffnen Sie dazu `owncloud/config/config.php`
im Dateimanager und fügen Sie vor der letzten Zeile `);` diese Zeile ein:

```php
  'memcache.local' => '\OC\Memcache\APCu',
```

Erscheint danach die Fehlerseite `Memcache \OC\Memcache\APCu not available for
local cache`, fehlt APCu. Entfernen Sie die Zeile wieder.

!!! warning "config.php vorsichtig bearbeiten"
    Ein Tippfehler in `config/config.php` macht die Instanz unerreichbar.
    Laden Sie die Datei vor jeder Änderung herunter, damit Sie sie
    zurückspielen können.

## Mit SSH-Zugang {#ssh}

Erlaubt Ihr Paket einen SSH-Zugang mit vollständiger Shell, steht auch `occ`
zur Verfügung. Melden Sie sich an und rufen Sie es mit dem PHP von Plesk auf:

```bash
/opt/plesk/php/8.4/bin/php owncloud/occ status
```

Damit entfallen die [Grenzen des Webhostings](#grenzen), die an `occ` hängen.
Die Befehle aus den
übrigen Seiten dieser Dokumentation schreiben Sie dann ohne `sudo -u www-data`
und mit `/opt/plesk/php/8.4/bin/php owncloud/occ` statt `php8.4 occ`.

??? note "Was ohne Kommandozeile anders läuft"
    | Aufgabe | Mit `occ` | Ersatz im Webhosting |
    | --- | --- | --- |
    | Zustand abfragen | `occ status` | `status.php` im Browser |
    | Aktualisieren | `occ upgrade` | Aktualisierung im Browser, bis 50 Konten |
    | Wartungsmodus schalten | `occ maintenance:mode --on` | `'maintenance' => true,` in `config/config.php` |
    | Einstellungen setzen | `occ config:system:set …` | Eintrag in `config/config.php`, siehe [Konfiguration (config.php)](../administration/config-reference.md) |
    | Apps ein- und ausschalten | `occ app:enable <app>` | *Einstellungen → Administration → Apps* |
    | Apps installieren | `occ market:install <app>` | App „Market“ in der Weboberfläche |
    | Dateibestand neu einlesen | `occ files:scan --all` | kein Ersatz |
    | Dateien eines Kontos übertragen | `occ files:transfer-ownership …` | kein Ersatz |
    | Reparaturschritte | `occ maintenance:repair` | kein Ersatz |

## Apps nachinstallieren {#apps}

Das Release enthält den Server mit den Grundfunktionen und die App „Market“.
Weitere Apps wie Aktivität, Galerie, Texteditor oder Gäste installieren Sie aus
dem Marketplace. Für Administratorkonten erscheint dazu im App-Menü oben links
der Eintrag *Market*. Zwei Voraussetzungen:

- Der Server darf `https://marketplace.owncloud.online` erreichen. Sperrt der
  Hoster ausgehende Verbindungen, bleibt die Liste leer.
- Der Ordner `owncloud/apps-external` ist beschreibbar, was in Plesk der Fall
  ist.

Ohne Marketplace geht es von Hand: App-Paket herunterladen, auf dem eigenen
Rechner entpacken, den Ordner nach `owncloud/apps-external` hochladen und die
App unter *Einstellungen → Administration → Apps* einschalten. Einzelheiten
unter [Apps und Marketplace](../administration/apps-market.md).

## Aktualisieren {#aktualisieren}

Für ein Update tauschen Sie den Programmordner gegen den des neuen Release.
Datenverzeichnis und Datenbank bleiben, wo sie sind.

1. **Sichern.** Exportieren Sie die Datenbank unter *Datenbanken* und laden Sie
   `owncloud/config/config.php` und den Ordner `oco-data` herunter.
2. **Wartungsmodus einschalten.** Ändern Sie in `owncloud/config/config.php`
   die Zeile `'maintenance' => false,` in `'maintenance' => true,`. Fehlt die
   Zeile, fügen Sie sie vor der letzten Zeile `);` ein.
3. **Neues Release hochladen.** Laden Sie die ZIP-Datei der neuen Version
   herunter, prüfen Sie sie wie in Schritt 3 und benennen Sie in Plesk den
   alten Ordner `owncloud` in `owncloud-alt` um. Laden Sie die ZIP-Datei über
   den Dateimanager oder per FTP ins Heimatverzeichnis hoch und entpacken Sie
   sie im Dateimanager. Es entsteht ein neuer Ordner `owncloud`.
4. **Eigene Dateien übernehmen.** Kopieren Sie `owncloud-alt/config/config.php`
   nach `owncloud/config/`. Kopieren Sie aus `owncloud-alt/apps-external` alle
   Ordner außer `market` nach `owncloud/apps-external`.
5. **Wartungsmodus ausschalten.** Ändern Sie in der übernommenen
   `config.php` die Zeile wieder in `'maintenance' => false,`.
6. **Aktualisierung starten.** Rufen Sie `https://cloud.example.com` auf. Die
   Seite meldet, dass owncloud.online auf die neue Version aktualisiert wird.
   Klicken Sie auf *Aktualisierung starten*.

Läuft alles, löschen Sie `owncloud-alt`. Klappt die Aktualisierung nicht,
benennen Sie die Ordner zurück und spielen Sie die gesicherte Datenbank ein.
Mehr zu Sicherungen unter
[Backups und Updates](../administration/backups-updates.md).

## Fehlersuche {#fehlersuche}

Die meisten Fehler stehen mit Ursache im Protokoll `oco-data/owncloud.log`.
Die Anfragekennung von der Fehlerseite führt zum passenden Eintrag, siehe
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).

| Was Sie sehen | Ursache | Lösung |
| --- | --- | --- |
| `This version of owncloud.online requires at least PHP 8.4.0` | PHP-Version zu alt oder für eine andere Domain eingestellt | PHP 8.4 in den *PHP-Einstellungen* dieser Domain wählen |
| „PHP-Modul … nicht installiert.“ | Eine Erweiterung fehlt | Erweiterung beim Hoster einschalten lassen |
| `Das Schreiben in das „config“-Verzeichnis ist nicht möglich` | `config` gehört einem anderen Benutzer als PHP | Rechte im Dateimanager prüfen |
| „Das Datenverzeichnis … kann nicht erstellt oder es kann darin nicht geschrieben werden.“ | Pfad falsch oder außerhalb von `open_basedir` | Pfad aus dem Dateimanager übernehmen, `open_basedir` prüfen |
| „Dein Datenverzeichnis muss ein absoluter Pfad sein“ | Pfad beginnt nicht mit `/` | vollständigen Pfad eintragen |
| „Dein Daten-Verzeichnis ist ungültig“ | Die Datei `.ocdata` im Datenverzeichnis fehlt, etwa nach einem Verschieben | leere Datei `.ocdata` in `oco-data` anlegen |
| „Du greifst auf den Server über eine nicht vertrauenswürdige Domain zu.“ | Aufruf über eine Adresse, die nicht in `trusted_domains` steht, etwa eine Vorschau-Adresse des Hosters | Adresse in `config/config.php` unter `trusted_domains` ergänzen |
| Weiße Seite oder Fehler 500 ohne Meldung | PHP-Fehler | Protokoll lesen, siehe oben |
| Die App „Market“ zeigt keine Apps | Keine ausgehende Verbindung zum Marketplace | Hoster fragen, ob ausgehendes HTTPS erlaubt ist |
| Die Aktualisierung im Browser verlangt `occ upgrade` | mehr als 50 Konten oder `user_ldap` installiert | SSH-Zugang nutzen oder auf einen [Linux-Server](linux-server.md) umziehen |
| Nach dem Update fehlen `.htaccess` oder `.user.ini` | Dateien mit Punkt am Anfang wurden nicht mitkopiert | ZIP-Datei auf dem Server entpacken statt einzelne Dateien hochzuladen |
