# Virenprüfung

Die Virenprüfung ist keine Kernfunktion, sondern die App `files_antivirus`
(Herkunft: ownCloud GmbH, für owncloud.online auf PHP 8.4 fortgeführt unter
<https://github.com/BWTECH-github/files_antivirus>). Sie hängt sich als
Storage-Wrapper in den Schreibpfad und reicht jeden Upload an einen externen
Scanner weiter — ClamAV direkt oder ein ICAP-fähiges Produkt. Zusätzlich prüft
ein Hintergrund-Job bereits gespeicherte Dateien nach.

## App aktivieren

```bash
sudo -u www-data php8.4 occ app:enable files_antivirus
```

Bei der Installation probiert die App nacheinander die Modi `daemon` und
`socket` durch und übernimmt den ersten, mit dem ein Testscan gelingt;
schlägt beides fehl, bleibt `executable` stehen
(`appinfo/install.php`, `Application::autoProbe()`). Dass diese Prüfung schon
gelaufen ist, merkt sich die App im Schlüssel `autoprobe`.

Die Oberfläche liegt unter **Einstellungen → Administration → Sicherheit**,
Abschnitt *Antivirus-Konfiguration* (`AdminPanel::getSectionID()` liefert
`security`). Im owncloud.online-Bundle ist dieses Panel bewusst nicht
registriert — die mitgelieferte `appinfo/info.xml` enthält keinen
`<settings><admin>`-Eintrag. Dort wird ausschließlich über `occ` und
`config/config.php` konfiguriert; Storage-Wrapper, DAV-Plugin und Cron-Job
laufen unverändert.

![Verwaltungseinstellungen mit dem Eintrag „Sicherheit"](../assets/screenshots/owncloud-online-admin-settings.png)

## Betriebsarten

Der Modus steht im Schlüssel `av_mode`. Welche Klasse er auswählt, entscheidet
`ScannerFactory::getScannerClass()`; ein unbekannter Wert führt zu einer
Fehlermeldung „Please check the settings at the admin page. Invalid mode".

| Wert | Beschriftung in der Oberfläche | Ablauf | Benötigte Schlüssel |
| --- | --- | --- | --- |
| `executable` | ClamAV ausführbar | startet pro Datei `clamscan` über `proc_open`, schiebt die Daten in dessen STDIN und wertet den Exit-Code aus | `files_antivirus.av_path`, optional `files_antivirus.av_cmd_options` |
| `daemon` | ClamAV Daemon (TCP Socket) | verbindet auf `av_host:av_port`, prüft zuerst `PING` (erwartet `PONG`) und `VERSION` (Antwort muss mit `ClamAV` beginnen), sendet dann `nINSTREAM` | `av_host`, `av_port` |
| `socket` | ClamAV Daemon (Unix Socket) | wie `daemon`, aber über den Unix-Socket | `av_socket` |
| `icap` | ClamAV & Kaspersky (ICAP) | ICAP-**REQMOD** an `av_host:av_port` | `av_host`, `av_port`, `av_request_service`, `av_response_header` |
| `fortinet` | Fortinet (ICAP) | ICAP-**RESPMOD** mit Fortinet-spezifischen Kopfzeilen | wie `icap` |
| `mawgw` | McAfee Webgateway / Skyhigh Secure Web Gateway (ICAP) | ICAP-**RESPMOD** mit McAfee-spezifischen Kopfzeilen | wie `icap` |

Die drei ICAP-Modi sind lizenzpflichtig: `AppConfig::validateValue()` fragt den
Lizenzmanager (`ILicenseManager::checkLicenseFor`). Ohne gültige Lizenz —
nach Ablauf der Kulanzzeit von 24 Stunden — protokolliert die App
„No valid license found for icap scanner, resetting mode to executable" und
liefert beim Lesen `executable` zurück. Der Lizenzschlüssel wird in
`config/config.php` unter `'license-key'` hinterlegt.

## Einstellungen und ihre Schlüssel

Alle Werte in der folgenden Tabelle sind App-Konfiguration der App
`files_antivirus` (`AppConfig::$defaults`).

| Schlüssel | Beschriftung | Standard | Bedeutung |
| --- | --- | --- | --- |
| `av_mode` | Modus | `executable` | Betriebsart, siehe oben |
| `av_socket` | Socket | `/var/run/clamav/clamd.ctl` | Pfad zum Unix-Socket des clamd |
| `av_host` | Host | `localhost` | Ziel für `daemon` und die ICAP-Modi |
| `av_port` | Port | `3310` | zugehöriger Port |
| `av_stream_max_length` | Stream-Länge | `26214400` | nach so vielen Bytes baut die App die Scanner-Verbindung neu auf; muss zu `StreamMaxLength` in der clamd-Konfiguration passen |
| `av_max_file_size` | Dateigrößenbeschränkung, -1 bedeutet keine Beschränkung | `-1` | Grenze in Bytes, siehe eigenen Abschnitt |
| `av_infected_action` | Wenn infizierte Dateien bei einem Hintergrund-Scan gefunden werden | `only_log` | `only_log` = nur protokollieren, `delete` = Datei löschen |
| `av_scan_background` | Hintergrund-Scan | `true` | schaltet den Cron-Job an oder aus |
| `av_request_service` | „ICAP Dienstanforderung. Mögliche Werte sind: …" (in den Modi Fortinet/McAfee: „ICAP Anfrage-Dienst. …") | `avscan` | `avscan` für ClamAV, `req` für Kaspersky ScanEngine, `respmod` bei Fortinet und McAfee Webgateway |
| `av_response_header` | „ICAP Antwort-Kopfzeile mit Informationen zum Virus. …" (in den Modi Fortinet/McAfee: „ICAP Antwort-Kopfzeilen. …") | `X-Infection-Found` | Alternativen laut Oberfläche: `X-Virus-ID` (Fortinet), `X-Virus-Name` (McAfee Webgateway) |

Auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ config:app:set files_antivirus av_mode --value daemon
sudo -u www-data php8.4 occ config:app:get files_antivirus av_mode
```

Zwei Werte wirken **nicht** über die Oberfläche oder `config:app:set`:
`AppConfig::getAppValue()` liest sie aus der Systemkonfiguration, und
`AppConfig::setAppValue()` verwirft sie stillschweigend. Ein per
`config:app:set` geschriebener App-Wert landet zwar in der Datenbank, wird von
der App aber nie gelesen. Sie gehören in `config/config.php`:

```php
'files_antivirus.av_path' => '/usr/bin/clamscan',
'files_antivirus.av_cmd_options' => '',
```

`av_cmd_options` ist eine kommaseparierte Liste; jeder Eintrag wird einzeln
maskiert (`escapeshellarg`) an den Aufruf angehängt.

## Was bei einem Fund passiert

Der Ablauf hängt davon ab, auf welchem Weg die Datei ankommt.

| Weg | Reaktion |
| --- | --- |
| Upload eines angemeldeten Kontos | Protokolleintrag (Warnung) „Infected file deleted." mit Befund, Konto und Pfad; Aktivitätseintrag `virus_detected`; die geschriebene Datei wird gelöscht; der Upload bricht mit „Der Virus %s wurde in der Datei gefunden. Das Hochladen konnte nicht abgeschlossen werden." ab |
| Upload über einen öffentlichen Link | Protokolleintrag (Warnung) „Infected file deleted after uploading to the public folder."; die Anfrage wird mit derselben Meldung abgewiesen |
| Hintergrundprüfung, `av_infected_action = delete` | Aktivitätseintrag; Datei wird gelöscht; Protokolleintrag „Infected file deleted." |
| Hintergrundprüfung, `av_infected_action = only_log` | Aktivitätseintrag; Protokolleintrag „File is infected." — die Datei bleibt liegen |

Der Aktivitätseintrag lautet in der Oberfläche „Eine **infizierte Datei** wurde
**gefunden**" beziehungsweise „Datei %s ist infiziert mit %s"; er ist nur
sichtbar, wenn die App `activity` installiert ist.

Wichtig ist der dritte mögliche Ausgang: Antwortete der Scanner, ließ sich seine
Antwort aber keiner Regel zuordnen (Status *ungeprüft*), bleibt die Datei
gespeichert. Der Hintergrundlauf protokolliert das als „Not Checked." mit Grund;
beim Upload wertet der Storage-Wrapper nur den Befund *infiziert* aus, ein
ungeprüftes Ergebnis führt dort zu keiner Reaktion.

Ist der Scanner dagegen nicht erreichbar, wird der Upload **abgewiesen**: Schon
das Öffnen der Verbindung scheitert, es fliegt eine `InitException`, und der
Upload endet mit „Die owncloud.online antivirus App ist entweder falsch
konfiguriert, oder der externe Virenscanner Dienst ist nicht erreichbar. Bitte
kontaktiere deine System-Administration!". Im ICAP-Betrieb gilt dasselbe für
eine Antwort, die die App nicht auswerten kann. Ein Scanner-Ausfall blockiert
Uploads also.

## Größengrenze und größere Dateien

`av_max_file_size` ist eine Byte-Angabe; `-1` (Standard) bedeutet keine Grenze.
Die Grenze wirkt an drei Stellen unterschiedlich:

- **Beim Upload** vergleicht `AvirWrapper::isScannableSize()` die Uploadgröße
  mit der Grenze. Ist die Datei größer, wird der Scan **vollständig
  übersprungen** und die Datei ungeprüft gespeichert (Debug-Protokoll:
  „Scanning is skipped."). Es wird also nicht etwa der Anfang geprüft.
- **Beim Hintergrundlauf** nimmt die Auswahlabfrage nur Dateien auf, die
  kleiner als die Grenze sind. Dateien der Größe 0 werden nie geprüft.
- **Beim Lesen des Datenstroms** (Hintergrundlauf, Upload über öffentlichen
  Link) schneidet der Scanner nach `av_max_file_size` Bytes ab und bewertet nur
  diesen Anfang.

Lässt sich die Uploadgröße nicht bestimmen, wird ebenfalls nicht geprüft
(Debug-Protokoll: „No upload in progress or chunk is being uploaded."). Das
betrifft insbesondere Chunk-Uploads: Einzelne Chunks unterhalb von `uploads/`
werden übersprungen, geprüft wird erst die zusammengesetzte Datei beim
abschließenden `MOVE`. Deren Größe ermittelt die App serverseitig aus der Summe
der gespeicherten Chunks — nicht aus einer vom Client geschickten Kopfzeile, die
sonst zum Umgehen der Grenze taugen würde.

Wer eine Grenze setzt, braucht deshalb eine Antwort auf die Frage, was mit den
größeren Dateien passieren soll: Sie bleiben in owncloud.online ungeprüft.

## Hintergrundprüfung per Cron

Die App registriert den Job `OCA\Files_Antivirus\Cron\Task`. Er läuft nur, wenn
die App aktiviert ist und `av_scan_background` auf `true` steht.

| Eigenschaft | Wert |
| --- | --- |
| Intervall | 15 Minuten (`setInterval(60 * 15)`) |
| Dateien pro Lauf | 10 erfolgreich geprüfte (`BATCH_SIZE`) |
| Auswahl | Dateien unterhalb von `files/`, keine Verzeichnisse, Größe ungleich 0, noch nie geprüft **oder** mit geändertem ETag |
| Buchführung | Tabelle `oc_files_antivirus` mit `fileid`, `check_time`, `etag` |

Daraus folgt die Obergrenze von rund 960 Dateien pro Tag und Instanz. Ein
großer Altbestand ist damit nicht in Tagen durchgeprüft; die Hintergrundprüfung
ist eine Nacharbeit, kein Ersatz für die Prüfung beim Upload. Geänderte Dateien
kommen über den ETag-Vergleich automatisch erneut an die Reihe.

Der Job läuft nur, wenn die Hintergrund-Jobs überhaupt ausgeführt werden, siehe
[Hintergrund-Jobs (Cron)](background-jobs.md). Abschalten:

```bash
sudo -u www-data php8.4 occ config:app:set files_antivirus av_scan_background --value false
```

## Auswirkung auf die Uploadgeschwindigkeit

Die Prüfung läuft synchron im Schreibpfad: Der Upload gilt erst als
abgeschlossen, wenn der Scanner geantwortet hat. Jede Datei durchläuft den
Scanner zusätzlich zum normalen Schreiben, die Uploadzeit steigt entsprechend.
Die Modi unterscheiden sich dabei deutlich:

- `executable` startet für **jede** Datei einen eigenen `clamscan`-Prozess, der
  die Signaturdatenbank neu lädt. Das ist die mit Abstand teuerste Variante und
  für Produktivsysteme ungeeignet — dort gehören `daemon` oder `socket` hin,
  weil der Dienst die Signaturen einmalig im Speicher hält.
- Bei `daemon` und `socket` wird die Verbindung nach jeweils
  `av_stream_max_length` Bytes geschlossen und neu aufgebaut. Ein zu kleiner
  Wert erzeugt viele Verbindungswechsel pro Datei.
- Die ICAP-Modi sammeln die Datei **vollständig im Arbeitsspeicher** und senden
  sie erst am Ende. Mit `av_max_file_size = -1` bestimmt also die größte
  hochgeladene Datei den PHP-Speicherbedarf des Requests. Auf Instanzen mit
  großen Dateien ist eine Grenze hier Pflicht — mit der oben beschriebenen
  Nebenwirkung, dass darüber nichts geprüft wird.
- Läuft der Scanner auf derselben Maschine, konkurriert er mit PHP-FPM um CPU
  und Speicher. Ein eigener Scan-Host entlastet den Anwendungsserver, kostet
  aber Netzwerklatenz pro Datei.

Zusätzlich schreibt die Hintergrundprüfung Lesezugriffe auf den Datenbestand —
sie ist bei Speichersystemen mit Abrechnung nach Zugriffen ein eigener Posten.

## Verbindung prüfen

Wo das Admin-Panel registriert ist, löst das Speichern einen echten Testscan mit
der EICAR-Testdatei aus (`ScannerFactory::testConnection()`). Erkennt der Scanner
sie nicht als infiziert, erscheint „Test war nicht erfolgreich. Bitte überprüfe
die Antivirus-Einstellungen". Der Test sagt damit mehr aus als eine reine
Erreichbarkeitsprüfung: Er schlägt auch fehl, wenn die Regeln oder die
Signaturdatenbank nicht greifen. Ohne Panel — also im owncloud.online-Bundle —
gibt es diesen Selbsttest nicht; dort bleibt nur ein Testupload und das
Protokoll.

Für die Fehlersuche das Protokoll auf `debug` stellen — die App schreibt
Dateigrößen, Übersprungsgründe und Scanner-Antworten auf dieser Stufe:

```bash
sudo -u www-data php8.4 occ log:manage --level debug
```

Danach wieder zurückstellen (`--level warning`), siehe
[Serverprotokoll und Fehlermeldungen](logging.md).

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Nach dem Speichern: „Test war nicht erfolgreich. Bitte überprüfe die Antivirus-Einstellungen" | Der EICAR-Testscan lief nicht durch — Scanner nicht erreichbar, falscher Modus oder Regeln greifen nicht | Protokoll lesen; bei Verbindungs- und Konfigurationsfehlern steht die Ursache dort als Warnung (Meldung der `InitException`), bei greifenden, aber falschen Regeln meldet der Test nur den Misserfolg |
| Protokoll: „Unexpected response to ping" oder „Unexpected response to version" | Auf `av_host:av_port` bzw. am Socket antwortet kein clamd | Ziel korrigieren; die App akzeptiert nur eine `VERSION`-Antwort, die mit `ClamAV` beginnt |
| Protokoll: „Could not connect to host … on port …" / „Could not connect to socket …" | Dienst läuft nicht, Pfad falsch, oder der Webserver-Benutzer darf den Socket nicht öffnen | Dienst starten, `av_socket` prüfen, Rechte am Socket prüfen |
| Protokoll: „The antivirus executable could not be found at path …" | `av_path` zeigt ins Leere | `'files_antivirus.av_path'` in `config/config.php` setzen — über die Oberfläche geht das bewusst nicht |
| Uploads scheitern mit „Die owncloud.online antivirus App ist entweder falsch konfiguriert, oder der externe Virenscanner Dienst ist nicht erreichbar. …" | Der Scanner ließ sich nicht initialisieren; in diesem Fall wird der Upload abgewiesen | Scanner reparieren oder die App vorübergehend mit `occ app:disable files_antivirus` abschalten |
| Protokoll: „Failed to write a chunk. Check if Stream Length matches StreamMaxLength in ClamAV daemon settings" | `av_stream_max_length` ist größer als `StreamMaxLength` im clamd | Beide Werte angleichen |
| Protokoll: „No matching rules. Please check antivirus rules." oder „No matching rule for exit code …" | Die Regeltabelle ist leer oder verstellt; die Datei gilt dann als ungeprüft | In der Oberfläche unter *Fortgeschritten* → *Auf Standard rücksetzen* — im Bundle ohne Admin-Panel nicht verfügbar |
| Modus fällt ohne Zutun auf „ausführbar" zurück, Protokoll: „No valid license found for icap scanner" | ICAP-Modi sind lizenzpflichtig, die Kulanzzeit ist abgelaufen | Lizenz in `config/config.php` unter `'license-key'` hinterlegen oder einen ClamAV-Modus verwenden |
| Dateien werden nicht geprüft, Debug-Protokoll: „Scanning is skipped." | Die Datei ist größer als `av_max_file_size` | Grenze anheben oder auf `-1` setzen |
| Dateien werden nicht geprüft, Debug-Protokoll: „No upload in progress or chunk is being uploaded." | Die Größe des Uploads ist an dieser Stelle nicht bestimmbar (typisch für einzelne Chunks) | Normalfall bei Chunk-Uploads — geprüft wird die zusammengesetzte Datei beim `MOVE`; bleibt es dabei, Hintergrundprüfung aktiv lassen |
| Im ICAP-Betrieb füllt sich das Protokoll mit „ICAP request:" und „ICAP resp:" auf Stufe *error* | Die App protokolliert jede ICAP-Kommunikation auf Fehlerstufe, obwohl es keine Fehler sind | Kein Fehlerzustand — beim Dimensionieren von Logrotation und Speicher berücksichtigen |
| Protokoll: „ICAP response unusable" und der Upload wird abgewiesen | Der ICAP-Server antwortet mit einem Status, den die App nicht auswerten kann | `av_request_service` und `av_response_header` gegen die Dokumentation des Scanners prüfen |
