# Installation auf Webhosting ohne Root-Zugriff

Diese Seite beschreibt die Installation von owncloud.online in einem
gewöhnlichen Webhosting-Paket: Sie haben ein Kundenmenü, einen Dateimanager
oder FTP-Zugang und eine Datenbankverwaltung — aber keinen Root-Zugriff, keine
SSH-Sitzung und keine Möglichkeit, Composer, npm oder `occ` auszuführen.

Der Weg dorthin führt über das fertige Release-Archiv: herunterladen,
Prüfsumme vergleichen, hochladen, entpacken, Datenbank im Kundenmenü anlegen,
Einrichtung im Browser abschließen. Gebaut werden muss nichts.

Zwei Dinge vorweg, die diese Variante von einem eigenen Server unterscheiden:

* Ohne Kommandozeile steht `occ` nicht zur Verfügung. Einige Wartungs- und
  Reparaturaufgaben sind damit nicht durchführbar, siehe
  [Was ohne SSH anders läuft](#was-ohne-ssh-anders-lauft).
* Sie können PHP nicht selbst konfigurieren. Alles, was owncloud.online an
  PHP-Einstellungen braucht, muss Ihr Hoster anbieten, siehe
  [PHP-Einstellungen](#php-einstellungen-die-der-hoster-bereitstellen-muss).

## Reicht das Hosting-Paket?

Prüfen Sie diese Punkte, **bevor** Sie 64 MB hochladen. Jede Zeile ist eine
Bedingung, an der die Installation sonst später scheitert.

| Anforderung | Warum | Woran Sie es erkennen |
| --- | --- | --- |
| PHP **8.4.0** oder neuer | `lib/versioncheck.php` bricht vorher mit „This version of owncloud.online requires at least PHP 8.4.0" ab | PHP-Auswahl im Kundenmenü |
| Linux-Server | derselbe Prüfschritt lehnt Windows-Hosting mit „owncloud.online does not support Microsoft Windows." ab | Angabe des Hosters |
| MySQL oder MariaDB | die Einrichtung bietet MySQL/MariaDB nur an, wenn der PDO-Treiber `mysql` geladen ist | Datenbankverwaltung im Kundenmenü |
| PHP-Erweiterungen (Liste unten) | fehlt eine, meldet der Server „PHP-Modul %s nicht installiert." und startet nicht | `phpinfo()` oder PHP-Übersicht des Hosters |
| Verzeichnis außerhalb des Webroots beschreibbar | Datenverzeichnis gehört nicht in den Webroot | Dateimanager: liegt neben `htdocs`/`public_html` noch etwas? |
| `.htaccess` mit `AllowOverride` wirksam | schützt Datenverzeichnis und `config/`, falls beides doch im Webroot liegt | Selbsttest nach der Einrichtung, siehe unten |
| ZIP im Dateimanager entpackbar | Upload einzelner Dateien per FTP dauert Stunden | Funktion „Entpacken"/„Extract" im Dateimanager |
| Cron über die Oberfläche | ohne Cron laufen Hintergrund-Jobs nie | Menüpunkt „Cronjobs" im Kundenmenü |

Diese PHP-Erweiterungen prüft der Server beim Start
(`OC_Util::checkServer()`); fehlt eine davon, kommt keine Seite zustande:

```text
zip, dom, XMLWriter, XMLReader, intl, libxml, mbstring, ctype, JSON,
GD, zlib, iconv, SimpleXML, hash, cURL, PDO
```

### Woran ein Paket sicher zu klein ist

| Grenze | Belegt in | Folge |
| --- | --- | --- |
| mehr als **50 Konten** | `lib/base.php`, `printUpgradePage()` | Der Aktualisierungsassistent im Browser verweigert den Dienst (HTTP 503) und verlangt `occ upgrade` — ohne Kommandozeile ist die Instanz dann nicht mehr aktualisierbar |
| App `user_ldap` oder `user_shibboleth` installiert | dito | derselbe Abbruch, unabhängig von der Kontenzahl |
| kein Redis, kein APCu | `settings/Panels/Admin/SecurityWarning.php` (Dateisperren), `core/js/setupchecks.js` (Meldung) | Dateisperren laufen über die Datenbank, die Verwaltungsseite meldet zusätzlich „Es wurde kein PHP Memory Cache konfiguriert." |
| SQLite statt MySQL | `core/templates/installation.php` | Die Einrichtung zeigt eine „Leistungswarnung"; mit Desktop-Client wird davon ausdrücklich abgeraten |
| niedriges Zeitlimit für PHP | — | Große Uploads und der Aktualisierungslauf im Browser brechen mittendrin ab |

Trifft einer der ersten beiden Punkte zu oder wird er absehbar zutreffen,
planen Sie von vornherein einen Server mit Kommandozeilenzugang ein, siehe
[Leerer Linux-Server](linux-server.md).

## 1. Release herunterladen

Alle Fassungen liegen unter
<https://github.com/BWTECH-github/owncloud.online/releases>. Aktuell ist
**11.0.13**. Ein Release enthält:

| Datei | Inhalt |
| --- | --- |
| `owncloud-online-11.0.13.zip` | vollständige Installation, rund 64 MB — **diese Datei brauchen Sie** |
| `owncloud-online-11.0.13.tar.gz` | derselbe Inhalt als tar-Archiv, rund 57 MB |
| `SHA256SUMS.txt` | Prüfsummen der Archive |
| `sbom-owncloud-online-11.0.13.cdx.json` | Stückliste der enthaltenen Komponenten (CycloneDX) |
| `release-manifest.json` | Fassung, Commit und PHP-Version des Bauvorgangs |
| `removed-release-files.txt` | Liste der beim Bau entfernten Entwicklungsdateien |

Beide Archive enthalten ein einzelnes Verzeichnis `owncloud/`. Darin stecken
der Server, die mitgelieferten Apps unter `apps/`, die Markt-App unter
`apps-external/market` sowie `config/` mit den beiden Beispieldateien
`config.sample.php` und `config.apps.sample.php`. Eine fertige
`config/config.php` ist **nicht** enthalten — die schreibt die Einrichtung.

## 2. Prüfsumme vergleichen

Vergleichen Sie die Prüfsumme auf Ihrem eigenen Rechner, bevor Sie hochladen.
Unter Linux und macOS:

```bash
sha256sum owncloud-online-11.0.13.zip
grep 'owncloud-online-11.0.13.zip' SHA256SUMS.txt
```

Beide Zeilen müssen denselben Wert zeigen. Unter Windows liefert
`certutil -hashfile owncloud-online-11.0.13.zip SHA256` dieselbe Prüfsumme.

Weicht der Wert ab, ist der Download unvollständig oder verändert — laden Sie
ihn erneut und laden Sie ihn nicht hoch.

## 3. Hochladen und entpacken

1. Laden Sie **die ZIP-Datei** in den Webspace, nicht die entpackten Dateien.
   Die Installation besteht aus mehreren zehntausend Einzeldateien; ein
   FTP-Upload davon dauert Stunden und bricht regelmäßig ab.
2. Entpacken Sie das Archiv im Dateimanager Ihres Hosters.
3. Verschieben Sie den **Inhalt** von `owncloud/` in das Verzeichnis, das Ihre
   Domain ausliefert — oder richten Sie die Domain direkt auf `owncloud/`.
4. Prüfen Sie, dass die Dateien mit Punkt am Anfang mitgekommen sind:
   `.htaccess` und `.user.ini` müssen im Wurzelverzeichnis der Installation
   liegen. Viele Dateimanager blenden sie aus; es gibt meist eine Einstellung
   „versteckte Dateien anzeigen".

Ohne `.htaccess` fehlen die Schutzregeln für `config/`, `lib/` und die
Fehlerseiten. Ohne `.user.ini` gelten die PHP-Vorgaben Ihres Hosters statt der
Werte, die owncloud.online mitbringt.

Löschen Sie das ZIP nach dem Entpacken aus dem Webspace.

## 4. Datenbank im Kundenmenü anlegen

Legen Sie im Kundenmenü eine leere Datenbank und einen Datenbankbenutzer an.
Notieren Sie vier Angaben — die Einrichtung fragt genau diese ab:

| Angabe | Hinweis |
| --- | --- |
| Datenbank-Name | oft mit festem Präfix, etwa `d0123456_owncloud` |
| Datenbank-Benutzer | häufig nicht frei wählbar |
| Datenbank-Passwort | vom Kundenmenü vergeben oder selbst gesetzt |
| Datenbank-Host | bei vielen Hostern **nicht** `localhost`, sondern ein eigener Name wie `db1234.hoster.example` |

Weicht der Port vom Standard ab, hängen Sie ihn mit Doppelpunkt an den Host an
(`db1234.hoster.example:3307`). Das Einrichtungsformular weist ausdrücklich
darauf hin.

Die Datenbank muss leer sein. Eine Datenbank, in der bereits Tabellen einer
anderen Anwendung liegen, führt zu Fehlern beim Anlegen des Schemas.

## 5. Datenverzeichnis festlegen

Das Datenverzeichnis enthält alle hochgeladenen Dateien und das
Serverprotokoll. Legen Sie es **außerhalb** des Webroots an, zum Beispiel:

```text
/home/kundennummer/owncloud-data
```

Der Pfad muss absolut sein; ein relativer Pfad wird mit „Dein Datenverzeichnis
muss ein absoluter Pfad sein" abgelehnt. Den ermittelten absoluten Pfad zeigt
Ihnen der Dateimanager oder der FTP-Zugang an.

Ist ein Verzeichnis außerhalb des Webroots nicht möglich, bleibt `data/`
innerhalb der Installation. Dann hängt der Schutz allein an der `.htaccess`:
Der Server legt beim Einrichten und bei jedem Update eine `data/.htaccess` mit
`Require all denied` an. Greift `.htaccess` bei Ihrem Hoster nicht, sind Ihre
Dateien öffentlich abrufbar. Der Selbsttest dafür steht in
[Nach der Einrichtung prüfen](#nach-der-einrichtung-prufen); fällt er negativ
aus, ist das Paket für owncloud.online ungeeignet.

## 6. Einrichtung im Browser

Rufen Sie Ihre Domain auf. Es erscheint das Einrichtungsformular:

| Feld | Eingabe |
| --- | --- |
| **Administrator-Konto anlegen** — Benutzername, Passwort | Ihr künftiges Verwaltungskonto. Ein eigenes, zufälliges Passwort; ein Passwort, das in einer Anleitung steht, ist keins |
| **Speicher & Datenbank → Datenverzeichnis** | der absolute Pfad aus Schritt 5 |
| **Datenbank einrichten** | Auswahl `MySQL/MariaDB` |
| Datenbank-Benutzer, Datenbank-Passwort, Datenbank-Name, Datenbank-Host | die vier Angaben aus Schritt 4 |

Der Abschnitt *Speicher & Datenbank* ist zunächst zugeklappt. Mit
*Installation abschließen* startet der Vorgang; er dauert je nach Hoster ein
bis zwei Minuten und darf nicht abgebrochen werden.

Dabei schreibt die Einrichtung `config/config.php` und setzt darin unter
anderem:

| Schlüssel | Wert |
| --- | --- |
| `datadirectory` | Ihr Pfad aus Schritt 5 |
| `dbtype`, `dbname`, `dbuser`, `dbpassword`, `dbhost` | Ihre Datenbankangaben |
| `trusted_domains` | der Hostname, unter dem Sie das Formular aufgerufen haben |
| `overwrite.cli.url` | dieselbe Adresse samt Protokoll und Unterverzeichnis |
| `passwordsalt`, `secret` | zufällig erzeugt |
| `apps_paths` | `apps/` (nicht beschreibbar) und `apps-external/` (beschreibbar) |

Damit das gelingt, muss `config/` für PHP beschreibbar sein. Andernfalls
bricht die Einrichtung mit „Das Schreiben in das „config"-Verzeichnis ist
nicht möglich" ab.

![Anmeldeseite von owncloud.online](../assets/screenshots/owncloud-online-login.png)

## Nach der Einrichtung prüfen

Rufen Sie `https://ihre-domain.example/status.php` auf. Die Antwort ist ein
JSON-Objekt und enthält unter anderem diese Felder:

```json
{
  "installed": true,
  "maintenance": false,
  "needsDbUpgrade": false,
  "version": "11.0.13",
  "versionstring": "11.0.13",
  "productname": "owncloud.online",
  "product": "owncloud.online"
}
```

Steht `installed` auf `false`, ist die Einrichtung nicht durchgelaufen; steht
`needsDbUpgrade` auf `true`, fehlt noch ein Aktualisierungslauf.

Melden Sie sich anschließend an und öffnen Sie *Einstellungen →
Administration → Allgemein*. Diese Seite führt beim Aufruf mehrere
Selbsttests aus. Erscheint dort

> Dein Datenverzeichnis und deine Dateien sind wahrscheinlich vom Internet aus
> erreichbar. Die .htaccess-Datei funktioniert nicht.

dann greift `.htaccess` nicht. Verschieben Sie das Datenverzeichnis aus dem
Webroot, oder wechseln Sie den Hoster — diese Warnung darf nicht stehen
bleiben. Weitere Meldungen derselben Seite betreffen fehlende
Internetverbindung des Servers, fehlenden Memory Cache und eine defekte
WebDAV-Schnittstelle; was dahintersteckt, steht unter
[Sicherheit und Setup-Warnungen](../administration/security-hardening.md).

## Cron einrichten

Ohne Hintergrund-Jobs werden Papierkorb und alte Dateiversionen nie geleert,
Freigaben laufen nicht ab und es gehen keine Benachrichtigungs-Mails hinaus.
Welche Aufgaben das im Einzelnen sind, steht unter
[Hintergrund-Jobs (Cron)](../administration/background-jobs.md).

Der Betriebsmodus steht unter *Einstellungen → Administration → Allgemein* im
Abschnitt **Cron**. Zur Auswahl stehen AJAX, Webcron und Cron. Welchen Sie
brauchen, hängt davon ab, was Ihr Hoster anbietet.

### Fall A: Der Hoster kann nur URLs aufrufen

Das ist der häufigste Fall. Tragen Sie im Cron-Menü des Hosters diese Adresse
ein:

```text
https://ihre-domain.example/cron.php
```

Stellen Sie den Modus in owncloud.online auf **Webcron**. Das ist zwingend:
Steht der Modus auf *Cron*, antwortet der Aufruf über HTTP nur mit
„Background jobs are using system cron!" und führt nichts aus.

Ein Aufruf über HTTP arbeitet genau **einen** Job ab
(`core/Controller/CronController.php`). Wählen Sie deshalb ein kurzes
Intervall — fünf Minuten, wenn Ihr Hoster es zulässt. Bei einem
15-Minuten-Takt räumt sich eine Warteschlange, die sich einmal aufgebaut hat,
nur sehr langsam ab.

### Fall B: Der Hoster kann PHP-Dateien ausführen

Manche Pakete bieten einen Cronjob, der eine PHP-Datei mit dem
Kommandozeilen-PHP aufruft, ohne dass Sie SSH haben. Tragen Sie dort die
`cron.php` Ihrer Installation ein, Intervall 15 Minuten. Dieser Weg arbeitet
pro Lauf so viele Jobs ab, wie in 14 Minuten hineinpassen, und ist damit dem
URL-Aufruf deutlich überlegen.

Zwei Eigenheiten: `cron.php` ruft auf der Kommandozeile intern `occ` über die
PHP-Funktion `system()` auf. Sperrt Ihr Hoster diese Funktion, passiert
nichts. Und der erste erfolgreiche Lauf stellt den Modus selbsttätig auf
**Cron** um — wechseln Sie später zurück auf den URL-Aufruf, müssen Sie den
Modus von Hand wieder auf *Webcron* stellen.

### Ob es läuft

Derselbe Abschnitt **Cron** zeigt „Letzte Cron-Job-Ausführung: …". Steht dort
„Cron wurde bis jetzt noch nicht ausgeführt!" oder liegt der Zeitpunkt mehr als eine
Stunde zurück, färbt sich die Anzeige rot.

## Was ohne SSH anders läuft

Die übrigen Seiten dieser Dokumentation schreiben Wartungsbefehle in der Form
`sudo -u www-data php8.4 occ …` (siehe
[occ – die Kommandozeile](../administration/occ-reference.md)). Das setzt einen
eigenen Linux-Server voraus. Im Webhosting gibt es weder `sudo` noch den
Benutzer `www-data`, und in aller Regel überhaupt keine Kommandozeile. Damit
entfällt eine Reihe von Aufgaben:

| Aufgabe | Befehl | Ersatz im Webhosting |
| --- | --- | --- |
| Zustand der Instanz abfragen | `sudo -u www-data php8.4 occ status` | `status.php` im Browser |
| Aktualisierung ausführen | `sudo -u www-data php8.4 occ upgrade` | Aktualisierungsassistent im Browser — bis 50 Konten |
| Wartungsmodus schalten | `sudo -u www-data php8.4 occ maintenance:mode --on` | `'maintenance' => true` von Hand in `config/config.php` |
| Einstellungen setzen | `sudo -u www-data php8.4 occ config:system:set …` | Eintrag von Hand in `config/config.php` |
| Apps aktivieren | `sudo -u www-data php8.4 occ app:enable <app_id>` | *Einstellungen → Administration → Apps* |
| Apps installieren | `sudo -u www-data php8.4 occ market:install <app_id>` | Markt in der Weboberfläche |
| Dateibestand neu einlesen | `sudo -u www-data php8.4 occ files:scan --all` | **kein Ersatz** |
| Dateien eines Kontos übertragen | `sudo -u www-data php8.4 occ files:transfer-ownership …` | **kein Ersatz** |
| Papierkorb vorzeitig leeren | `sudo -u www-data php8.4 occ trashbin:expire` | **kein Ersatz**, erledigt der Cron-Lauf |
| Reparaturschritte ausführen | `sudo -u www-data php8.4 occ maintenance:repair` | **kein Ersatz** |

Zwei weitere Unterschiede:

* **Adressen mit `index.php`.** Die Regeln, die `index.php` aus den Adressen
  entfernen, entstehen nur beim Einrichten und beim Aktualisieren aus dem
  Schlüssel `htaccess.RewriteBase` — nachträglich anstoßen ließe sich das nur
  mit `sudo -u www-data php8.4 occ maintenance:update:htaccess`. Lassen Sie
  den Schlüssel deshalb unangetastet.
* **Mailversand.** Die Vorgabe `mail_smtpmode => 'php'` ruft ein lokales
  `sendmail` auf, das es auf Webhosting meist nicht gibt. Tragen Sie den
  SMTP-Zugang Ihres Hosters unter *Einstellungen → Administration →
  Allgemein → E-Mail-Server* ein, siehe
  [E-Mail-Versand](../administration/email.md).

Was Sie stattdessen von Hand in `config/config.php` eintragen, beschreibt
[Konfiguration (config.php)](../administration/config-reference.md). Bearbeiten
Sie die Datei nur bei angehaltenem Betrieb und legen Sie vorher eine Kopie an —
ein Syntaxfehler darin macht die Instanz unerreichbar.

## PHP-Einstellungen, die der Hoster bereitstellen muss

owncloud.online bringt eine `.user.ini` mit. Wertet Ihr Hoster diese Datei aus
— bei PHP als FPM oder CGI ist das der Regelfall —, sind die wichtigsten Werte
damit bereits gesetzt:

```text
upload_max_filesize=513M
post_max_size=513M
memory_limit=512M
default_charset='UTF-8'
output_buffering=0
```

Andernfalls müssen Sie dieselben Werte im PHP-Menü des Kundenmenüs setzen. Die
Regeln im Einzelnen:

| Einstellung | Wert | Warum |
| --- | --- | --- |
| `memory_limit` | mindestens 512M | Vorschaubilder und der Aktualisierungslauf brauchen den Speicher |
| `upload_max_filesize` | so hoch wie die größte Datei | maßgeblich ist der **kleinere** der beiden Werte `upload_max_filesize` und `post_max_size` (`OC_Helper::uploadLimit()`) |
| `post_max_size` | derselbe Wert | dito |
| `max_execution_time` | großzügig | Uploads und der Aktualisierungslauf im Browser laufen sonst in einen Abbruch |
| `default_charset` | `UTF-8` | Abweichung meldet der Server als „PHP-Einstellung „default_charset" ist nicht auf „UTF-8" gesetzt." und startet nicht |
| `output_buffering` | `0` | so steht es in der mitgelieferten `.user.ini`: Der Server liefert Dateien im Strom aus, eine Zwischenpufferung hielte jede Datei vollständig im Arbeitsspeicher |
| `mbstring.func_overload` | `0` | jeder andere Wert wird als Fehler gemeldet |
| OPcache mit Kommentaren | `opcache.save_comments=1` | werden Dokumentationsblöcke entfernt, meldet der Server „PHP is apparently set up to strip inline doc blocks" und mehrere Apps sind nicht mehr erreichbar |
| `open_basedir` | muss das Datenverzeichnis einschließen | sonst „Das Erstellen des „data"-Verzeichnisses ist nicht möglich" |

Die `.htaccess` kann diese Werte auf PHP 8.4 **nicht** setzen: Die
`php_value`-Zeilen darin stehen in Blöcken für `mod_php5` und `mod_php7` und
greifen nur, wenn PHP als Apache-Modul dieser Generationen läuft.

## Apps nachinstallieren

Im Release stecken der Server, die mitgelieferten Apps unter `apps/` und die
Markt-App unter `apps-external/market`. Alles Weitere — etwa Aktivitäten,
Galerie, Texteditor, Gäste-Konten oder benutzerdefinierte Gruppen — kommt aus
dem Markt.

![Der Markt mit den installierten Apps](../assets/screenshots/owncloud-online-apps.png)

Für Verwaltungskonten erscheint im App-Menü links oben der Eintrag **Markt**.
Von dort werden Apps gesucht und installiert. Zwei Voraussetzungen:

* Der Server muss `https://marketplace.owncloud.online` erreichen dürfen.
  Sperrt Ihr Hoster ausgehende Verbindungen, bleibt der Katalog leer.
* `apps-external/` muss für PHP beschreibbar sein. Ist es das nicht, bricht
  die Installation mit „Installing apps is not supported because the app
  folder is not writable." ab.

Ist der Markt nicht nutzbar, geht es auch von Hand: App-Paket als `.tar.gz`
besorgen, örtlich entpacken, das enthaltene Verzeichnis nach `apps-external/`
hochladen und die App unter *Einstellungen → Administration → Apps*
aktivieren. Der Verzeichnisname muss der `<id>` aus `appinfo/info.xml`
entsprechen. Einzelheiten in [Apps verwalten](../administration/apps-market.md).

## Aktualisieren ohne SSH

1. Sicherung anlegen: Datenbank über das Kundenmenü exportieren, `config/`
   und das Datenverzeichnis herunterladen.
2. Wartungsmodus einschalten — dazu `'maintenance' => true,` in
   `config/config.php` eintragen.
3. Neues Release herunterladen, Prüfsumme vergleichen, hochladen, entpacken.
4. Alle Verzeichnisse aus dem Archiv — `apps/`, `core/`, `lib/`, `ocs/`,
   `ocs-provider/`, `ocm-provider/`, `resources/`, `settings/` und
   `apps-external/market` — sowie die Dateien im Wurzelverzeichnis durch die
   neuen ersetzen. `config/`, das Datenverzeichnis und selbst installierte
   Apps in `apps-external/` bleiben unangetastet.
5. Wartungsmodus wieder ausschalten.
6. Domain im Browser aufrufen. Es erscheint die Meldung, dass owncloud.online
   auf die neue Version aktualisiert wird, mit dem Knopf
   *Aktualisierung starten*.

Der Assistent verweigert den Dienst bei mehr als 50 Konten oder installierter
App `user_ldap`; dann führt nur `sudo -u www-data php8.4 occ upgrade` weiter,
was ohne Kommandozeile ausscheidet. Zum Ablauf im Übrigen siehe
[Backups und Updates](../administration/backups-updates.md).

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „This version of owncloud.online requires at least PHP 8.4.0" | PHP-Fassung des Hosters zu alt, oder die Auswahl gilt nur für einen anderen Ordner | PHP 8.4 im Kundenmenü für dieses Verzeichnis auswählen |
| „PHP-Modul %s nicht installiert." | Erweiterung fehlt | Erweiterung im PHP-Menü zuschalten; geht das nicht, reicht das Paket nicht |
| „Das Schreiben in das „config"-Verzeichnis ist nicht möglich" | `config/` gehört einem anderen Benutzer als dem, unter dem PHP läuft | Rechte im Dateimanager auf den eigenen Benutzer setzen |
| „Das Erstellen des „data"-Verzeichnisses ist nicht möglich" | Pfad falsch, nicht beschreibbar, oder durch `open_basedir` gesperrt | absoluten Pfad prüfen; Datenverzeichnis in einen von `open_basedir` erlaubten Bereich legen |
| „Dein Daten-Verzeichnis ist von anderen Benutzern lesbar" | Der Server setzt selbst `0770`, das Dateisystem übernimmt es nicht | Rechte im Dateimanager auf `0770` setzen |
| „Dein Daten-Verzeichnis ist ungültig" | die Markierungsdatei `.ocdata` fehlt — typisch nach einem Verschieben von Hand | leere Datei `.ocdata` im Datenverzeichnis anlegen |
| „Du greifst auf den Server über eine nicht vertrauenswürdige Domain zu." | Aufruf über eine Adresse, die nicht in `trusted_domains` steht — etwa die Vorschau-Adresse des Hosters | Adresse in `config/config.php` zu `trusted_domains` hinzufügen |
| „Dein Datenverzeichnis und deine Dateien sind wahrscheinlich vom Internet aus erreichbar." | `.htaccess` wird nicht ausgewertet | Datenverzeichnis aus dem Webroot verschieben; ist das nicht möglich, ist das Paket ungeeignet |
| Weiße Seite, HTTP 500, keine Meldung | PHP-Fehler ohne Ausgabe | Protokoll unter `<datadirectory>/owncloud.log` lesen, siehe [Serverprotokoll](../administration/logging.md) |
| „Cron wurde bis jetzt noch nicht ausgeführt!" bleibt stehen | Cron-Eintrag fehlt, oder der Modus passt nicht zum Aufrufweg | Bei URL-Aufruf Modus **Webcron** setzen; bei *Cron* führt der HTTP-Aufruf nichts aus |
| Warteschlange wird nicht kürzer | Der URL-Aufruf arbeitet nur einen Job je Aufruf ab | Intervall verkürzen oder auf Fall B (PHP-Cronjob) wechseln |
| Upload bricht bei großen Dateien ab | `upload_max_filesize` oder `post_max_size` zu klein — es gilt der kleinere Wert | beide Werte gleich setzen, dazu `max_execution_time` erhöhen |
| Markt bleibt leer | keine ausgehende Verbindung zum Katalog | Hoster fragen, ob ausgehendes HTTPS erlaubt ist |
| App lässt sich nicht installieren: „app folder is not writable" | `apps-external/` nicht beschreibbar | Rechte im Dateimanager setzen, sonst Apps von Hand hochladen |
| Aktualisierung im Browser endet mit HTTP 503 und Verweis auf `occ upgrade` | mehr als 50 Konten oder `user_ldap` installiert | Umzug auf einen Server mit Kommandozeilenzugang |
| Nach dem Update fehlen `.htaccess` oder `.user.ini` | Der Dateimanager hat die Dateien mit Punkt am Anfang nicht mitkopiert | versteckte Dateien einblenden und beide Dateien nachtragen |

Findet sich die Ursache nicht, steht sie fast immer im Protokoll:
`<datadirectory>/owncloud.log`, eine Zeile je Ereignis. Die Anfragekennung vom
Fehlerbildschirm führt zum passenden Eintrag.
