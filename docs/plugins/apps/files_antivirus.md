# Anti-Virus

Die App `files_antivirus` lässt jede hochgeladene Datei von einem Virenscanner
prüfen, bevor owncloud.online sie speichert. Meldet der Scanner Schadsoftware,
lehnt die App den Upload ab. Ein Hintergrund-Job prüft zusätzlich Dateien, die
schon gespeichert sind. Sinnvoll ist das überall, wo Dateien aus fremden Quellen
ankommen, etwa über öffentliche Links oder von Gästen.

!!! note "Verfügbarkeit"
    Öffentlich im Marketplace, ohne API-Schlüssel. App-ID `files_antivirus`,
    Version 1.3.11. Name im Markt: „Anti-Virus“.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 oder neuer |
| Virenscanner | ClamAV, entweder als Dienst `clamd` (TCP oder Unix-Socket) oder als Programm `clamscan` |
| Cron | für die Prüfung gespeicherter Dateien, siehe [Hintergrund-Jobs (Cron)](../../administration/background-jobs.md) |

Im Produktivbetrieb verwenden Sie `clamd`. Der Dienst hält die Signaturen im
Speicher. `clamscan` lädt sie für jede Datei neu und verlangsamt so jeden Upload
deutlich.

Unter Debian und Ubuntu bringt das Paket `clamav-daemon` den Dienst `clamd` und
die automatische Aktualisierung der Signaturen mit:

```bash
sudo apt install clamav-daemon
sudo systemctl status clamav-daemon
```

Direkt nach der Installation startet der Dienst erst, wenn `freshclam` die
Signaturen heruntergeladen hat. Meldet `systemctl status` ihn noch als inaktiv,
warten Sie einige Minuten und starten Sie ihn dann mit
`sudo systemctl start clamav-daemon`.

Der Webserver-Benutzer (meist `www-data`) muss den Socket des Dienstes öffnen
dürfen.

## Installation

Installieren Sie ClamAV vor der App. Dann findet die App den Scanner selbst: Sie
probiert bei der Installation zuerst `localhost:3310` (TCP), dann den Socket
`/var/run/clamav/clamd.ctl` und bleibt sonst bei „ClamAV ausführbar“. Diese
Suche läuft nur einmal. Lief `clamd` zu dem Zeitpunkt noch nicht, stellen Sie
den Modus danach von Hand ein (siehe „Einrichtung“).

In der Weboberfläche: im App-Menü links oben **Markt** öffnen, die App
„Anti-Virus“ aufrufen und **Installieren** klicken.

Auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ market:install files_antivirus
```

`market:install` lädt die App, installiert sie und schaltet sie ein. Ist die App
schon vorhanden und nur abgeschaltet, genügt
`sudo -u www-data php8.4 occ app:enable files_antivirus`.

## Einrichtung

Die Einstellungen stehen unter **Einstellungen → Administration → Sicherheit**,
Abschnitt „Antivirus-Konfiguration“. **Speichern** übernimmt die Werte und
startet sofort eine Testprüfung mit der EICAR-Testdatei, einer harmlosen Datei,
die jeder Scanner als Fund meldet. Erkennt der Scanner sie nicht, erscheint
„Test war nicht erfolgreich. Bitte überprüfe die Antivirus-Einstellungen“.

<figure markdown="span">
  ![Administration, Sicherheit: Antivirus-Konfiguration mit Modus ClamAV ausführbar](../../assets/screenshots/de/admin-antivirus.png)
  <figcaption>Unter „Antivirus-Konfiguration“ wählen Sie den Modus und was mit infizierten Dateien geschieht. Der Pfad zu clamscan steht in der config.php.</figcaption>
</figure>

| Feld | Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- | --- |
| Modus | `av_mode` | `executable` | „ClamAV Daemon (Unix Socket)“ (`socket`), „ClamAV Daemon (TCP Socket)“ (`daemon`) oder „ClamAV ausführbar“ (`executable`) |
| Socket | `av_socket` | `/var/run/clamav/clamd.ctl` | Socket des `clamd`, nur im Modus `socket` |
| Host, Port | `av_host`, `av_port` | `localhost`, `3310` | Adresse des `clamd`, nur im Modus `daemon` |
| Stream-Länge | `av_stream_max_length` | `26214400` | Bytes je Verbindung zum Scanner; darf nicht größer sein als `StreamMaxLength` in der `clamd.conf` |
| Dateigrößenbeschränkung, -1 bedeutet keine Beschränkung | `av_max_file_size` | `-1` | Grenze in Bytes; größere Uploads prüft die App nicht |
| Hintergrund-Scan | `av_scan_background` | `true` | „Aktiviert“ oder „Deaktiviert“ |
| Wenn infizierte Dateien bei einem Hintergrund-Scan gefunden werden | `av_infected_action` | `only_log` | „Nur protokollieren“ (`only_log`) oder „Datei löschen“ (`delete`) |

Die Schlüssel sind App-Werte der App `files_antivirus`. Auf der Kommandozeile,
zum Beispiel für den Socket-Betrieb:

```bash
sudo -u www-data php8.4 occ config:app:set files_antivirus av_mode --value socket
sudo -u www-data php8.4 occ config:app:set files_antivirus av_socket --value /var/run/clamav/clamd.ctl
```

Die Testprüfung läuft nur beim Speichern in der Oberfläche, nicht nach
`config:app:set`.

Zwei Werte stehen nur in `config/config.php`. Die Oberfläche zeigt sie an
(„Pfad zu clamscan“, „Zusätzliche Kommandozeilenoptionen (Kommata-getrennt)“),
ändern lassen sie sich dort nicht:

```php
'files_antivirus.av_path' => '/usr/bin/clamscan',
'files_antivirus.av_cmd_options' => '',
```

`av_cmd_options` nimmt mehrere Optionen durch Kommas getrennt auf.

### Regeln

Unter **Fortgeschritten** → „Regeln“ steht, wie die App die Antwort des Scanners
deutet: im Modus „ClamAV ausführbar“ nach dem Rückgabewert von `clamscan`, in den
Daemon-Modi nach der Ausgabe von `clamd`. Jede Regel ordnet einem Wert einen
Status zu: „Säubern“, „Infiziert“ oder „Ungeprüft“. Die mitgelieferten Regeln
passen zu ClamAV. Nach versehentlichen Änderungen stellt **Auf Standard
rücksetzen** sie wieder her.

## Nutzung

Anwender merken die App nur, wenn ein Upload abgewiesen wird:

| Situation | Meldung |
| --- | --- |
| Der Scanner meldet Schadsoftware. | „Der Virus %s wurde in der Datei gefunden. Das Hochladen konnte nicht abgeschlossen werden.“ |
| Die Prüfung liefert kein Ergebnis. | „The file could not be checked for viruses. Upload cannot be completed, please try again.“ (nicht übersetzt) |
| Der Scanner ist nicht erreichbar. | „Die owncloud.online antivirus App ist entweder falsch konfiguriert, oder der externe Virenscanner Dienst ist nicht erreichbar. …“ |

Ist die App `activity` eingeschaltet, sieht der Eigentümer zusätzlich den
Eintrag „Datei %s ist infiziert mit %s“.

## Was bei einem Fund passiert

| Weg | Reaktion |
| --- | --- |
| Upload eines angemeldeten Kontos | Upload abgewiesen, geschriebene Teile gelöscht, Warnung im Protokoll mit Virusname, Konto und Pfad, Aktivitätseintrag |
| Upload über einen öffentlichen Link | Upload abgewiesen, bevor etwas geschrieben wird; Warnung im Protokoll, kein Aktivitätseintrag |
| Hintergrund-Scan mit „Nur protokollieren“ | Datei bleibt liegen; Protokolleintrag „File is infected.“ und Aktivitätseintrag, bei jedem Lauf erneut |
| Hintergrund-Scan mit „Datei löschen“ | Datei wird gelöscht; Protokolleintrag „Infected file deleted.“ und Aktivitätseintrag |

Beim Upload gilt „Wenn infizierte Dateien bei einem Hintergrund-Scan gefunden
werden“ nicht: Ein infizierter Upload wird immer abgewiesen.

## Grenzen und Hinweise

- **Scanner-Ausfall sperrt Uploads.** Ist der Scanner nicht erreichbar oder
  bricht er mitten in der Prüfung ab, weist die App den Upload ab. Das betrifft
  auch das Speichern im Texteditor und das Erzeugen von Vorschaubildern.
  Überwachen Sie `clamd` wie den Webserver.
- **Größengrenze.** Uploads über `av_max_file_size` speichert die App ungeprüft.
  Bei Uploads über öffentliche Links prüft sie nur den Anfang bis zu dieser
  Grenze. Der Hintergrund-Scan nimmt größere Dateien nicht auf.
- **Chunk-Uploads.** Die App prüft nicht die einzelnen Teile, sondern die
  zusammengesetzte Datei.
- **Hintergrund-Scan.** Der Job läuft höchstens alle 15 Minuten und prüft je Lauf
  10 Dateien, also rund 960 am Tag. Ein großer Altbestand braucht entsprechend
  lange. Geänderte Dateien kommen über ihren ETag erneut an die Reihe. Er prüft
  nur Dateien im Hauptspeicher der Konten, keine externen Speicher, und
  überspringt leere Dateien und Ordner.
- **Infizierte Dateien mit „Nur protokollieren“** prüft der Job bei jedem Lauf
  erneut und schreibt jedes Mal einen Protokoll- und einen Aktivitätseintrag.
  Weil er je Lauf nur 10 Dateien schafft, bremsen solche Dateien den übrigen
  Bestand; ab zehn liegengebliebenen Funden kann der Hintergrund-Scan ganz
  stehen bleiben. Entfernen Sie gemeldete Dateien zügig oder wählen Sie
  „Datei löschen“.
- **S3 als Primärspeicher.** Ein Objektspeicher kennt keine Teildateien. Wird
  beim Überschreiben einer Datei die neue Fassung abgewiesen, entfernt die App
  die Datei selbst, ohne Papierkorb. Bis zum erneuten Upload fehlt sie.
- **Termine und Kontakte** liegen in der Datenbank und werden nicht geprüft.
- **ICAP.** Die Auswahl „Modus“ enthält auch drei ICAP-Betriebsarten. Sie
  verlangen einen Lizenzschlüssel, für den es in owncloud.online keinen
  Bezugsweg gibt. Ohne gültige Lizenz laufen sie höchstens 24 Stunden, danach
  arbeitet die App im Modus „ClamAV ausführbar“. Ist `clamscan` nicht
  installiert, scheitert dann jeder Upload. Nutzen Sie ClamAV.
- **Umzug.** Betriebsart, Adressen und Regeln kommen mit der Datenbank mit. Zeigen
  sie auf einen Scanner, den der neue Server nicht erreicht, scheitert jeder
  Upload. Prüfen Sie die Einstellungen direkt nach dem Umzug. `av_path` und
  `av_cmd_options` aus sehr alten Datenbanken übernimmt die App nicht; tragen Sie
  sie bei Bedarf selbst in `config/config.php` ein.

Mehr zu Betriebsarten, Hintergrundprüfung und Uploadgeschwindigkeit:
[Virenprüfung](../../administration/antivirus.md).

## Fehlersuche

Die App schreibt unter der Kennung `files_antivirus` ins Serverprotokoll.
Größen, Übersprungsgründe und Antworten des Scanners stehen nur auf Stufe
Debug:

```bash
sudo -u www-data php8.4 occ log:manage --level debug
```

Stellen Sie die Stufe danach wieder zurück, siehe
[Serverprotokoll und Fehlermeldungen](../../administration/logging.md).

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Test war nicht erfolgreich. Bitte überprüfe die Antivirus-Einstellungen“ | Der Scanner ist nicht erreichbar oder erkennt die EICAR-Datei nicht. | Prüfen, ob `clamd` läuft, ob Socket oder Host und Port stimmen und ob Signaturen geladen sind |
| Protokoll: „Could not connect to socket …“ oder „Could not connect to host …“ | `clamd` läuft nicht, oder der Webserver-Benutzer darf den Socket nicht öffnen. | Dienst starten, Rechte am Socket prüfen |
| Protokoll: „Unexpected response to ping …“ oder „Unexpected response to version …“ | An der Adresse antwortet kein ClamAV-Dienst. | Socket-Pfad oder Host und Port korrigieren |
| Protokoll: „The antivirus executable could not be found at path …“ | `files_antivirus.av_path` zeigt ins Leere. | Pfad in `config/config.php` korrigieren |
| Protokoll: „Failed to write a chunk. Check if Stream Length matches StreamMaxLength in ClamAV daemon settings“ | Die Stream-Länge ist größer als `StreamMaxLength` des `clamd`. | Beide Werte angleichen |
| Protokoll: „No matching rules. Please check antivirus rules.“ | Die Regeln sind leer oder verstellt. | **Fortgeschritten** → **Auf Standard rücksetzen** |
| Protokoll: „No valid license found for icap scanner …“ | Ein ICAP-Modus ist eingestellt. | ClamAV-Modus wählen |
| Debug-Protokoll: „Scanning is skipped.“ | Die Datei ist größer als die Dateigrößenbeschränkung, oder ein einzelner Teil eines Chunk-Uploads kommt an. | Grenze anheben oder `-1` setzen; bei Chunk-Uploads ist das normal, geprüft wird die zusammengesetzte Datei |
