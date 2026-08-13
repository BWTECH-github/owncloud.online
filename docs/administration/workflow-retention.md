# Regeln und Aufbewahrung

owncloud.online kennt zwei Arten von Regeln, die getrennt voneinander
arbeiten: Die **Dateifirewall** (App `file_firewall`) prüft jede eingehende
Anfrage und weist sie ab, bevor etwas passiert. Die **Workflow-App**
(`workflow`) greift dagegen nicht in den Zugriff ein, sondern vergibt beim
Hochladen automatisch Tags und löscht später zeitgesteuert Dateien, die ein
Tag mit Aufbewahrungsfrist tragen.

## Die beiden Ebenen im Vergleich

| | Dateifirewall (`file_firewall`) | Workflow (`workflow`) |
| --- | --- | --- |
| Wirkung | weist die Anfrage ab (HTTP 403) | vergibt Tags, löscht getaggte Dateien |
| Zeitpunkt | synchron, während der Anfrage | Auto-Tagging beim Schreiben, Löschen im Hintergrund-Job |
| Verknüpfung | Bedingungen einer Gruppe mit UND, Gruppen untereinander mit ODER | Bedingungen eines Workflows mit UND |
| Verwaltung | Admin-Panel, `occ`, OCS-API | Admin-Panel |
| Testbetrieb | ja, pro Regelgruppe | nein |
| Trefferzähler | ja, pro Regelgruppe | nein |

Beide Apps aktivieren und den Zustand prüfen:

```bash
sudo -u www-data php8.4 occ app:enable file_firewall
sudo -u www-data php8.4 occ app:enable workflow
sudo -u www-data php8.4 occ app:list
```

Fehlt eine der Apps in der Instanz, führt der Weg über den Markt, siehe
[Apps und Marketplace](apps-market.md).

Die Bedienoberflächen liegen unter **Einstellungen → Administration →
Sicherheit** (Abschnitt *BW-Tech File Firewall*) und **Einstellungen →
Administration → Workflow**.

## Dateifirewall: Zugriffe abweisen

### Aufbau einer Regel

Eine Regel ist eine **Regelgruppe** mit einem Namen, den Schaltern *Aktiv*
und *Testmodus (nur protokollieren)* sowie einer oder mehreren Bedingungen.
Innerhalb einer Gruppe müssen **alle** Bedingungen zutreffen (im Panel als
*UND* zwischen den Zeilen dargestellt). Trifft eine Gruppe zu, wird die
Anfrage blockiert und die Auswertung endet — weitere Gruppen werden nicht
mehr geprüft.

Vier Dinge werden grundsätzlich nie blockiert:

* Anfragen von Administratoren.
* Alles, was auf der Kommandozeile läuft (`occ`, `cron.php`) — die
  Auswertung steigt bei SAPI `cli` sofort aus.
* Anfragen ohne angemeldeten Benutzer, sofern es sich nicht um einen
  öffentlichen Link handelt. Öffentliche Links werden ausgewertet.
* Anfragen, bei denen die Auswertung selbst einen Fehler wirft. Die
  Firewall lässt in diesem Fall durch (*fail open*).

Deaktivierte Gruppen werden vollständig übersprungen.

### Verfügbare Bedingungen und Operatoren

| Bedingung (Kennung) | Beschriftung | Operatoren | Wert |
| --- | --- | --- | --- |
| `user_group` | Benutzergruppe | `is`, `is_not` | Gruppen-ID |
| `user_agent` | User-Agent | `matches`, `not_matches` | Muster mit `*` |
| `device` | Benutzergerät | `is`, `is_not` | siehe Gerätetypen |
| `ip_range_ipv4` | IP-Bereich (IPv4) | `is`, `is_not` | CIDR oder einzelne Adresse |
| `ip_range_ipv6` | IP-Bereich (IPv6) | `is`, `is_not` | CIDR oder einzelne Adresse |
| `time` | Anfragezeit | `is`, `is_not` | Start/Ende, optional Zeitzone |
| `request_url` | Anfrage-URL | `contains`, `not_contains` | Zeichenkette |
| `request_type` | Anfragetyp | `is`, `is_not` | siehe Anfragetypen |
| `upload_size` | Dateigröße (Upload) | `less`, `greater_equal` | Bytes oder `10MB`, `2GB`, `1TB` |
| `file_mimetype` | Datei-MIME-Typ (Upload) | `is`, `is_not`, `begins_with`, `not_begins_with`, `ends_with`, `not_ends_with` | MIME-Typ |
| `system_tag` | Systemtag der Datei | `is`, `is_not` | Tag-Name |
| `regex` | Regulärer Ausdruck | `is`, `is_not` | Muster plus Zielfeld |

Beim `user_agent`-Muster steht `*` nur am Anfang und/oder am Ende:
`*BadBot*` sucht die Zeichenkette irgendwo, `mirall/*` verlangt den
Anfang, `*Firefox` das Ende, ein Muster ohne `*` verlangt exakte Gleichheit.
Der Vergleich erfolgt ohne Rücksicht auf Groß- und Kleinschreibung.

Für `regex` wird zusätzlich ein Zielfeld gewählt: `user_group`,
`user_agent`, `ip_range_ipv4`, `ip_range_ipv6`, `request_url` oder
`file_mimetype`. Beginnt das Muster mit `/`, `#`, `~` oder `@`, gilt es als
bereits begrenzt und wird unverändert übernommen. Andernfalls fasst die App es
selbst ein — bevorzugt als `#…#i`, bei einem `#` im Muster als `~…~i`, sonst
als `@…@i`.

Gerätetypen sind `android`, `ios`, `desktop` und `other`. Sind unter dem
Systemschlüssel `firewall.branded_clients` eigene Client-Kennungen
hinterlegt, kommen die Sammelwerte `all_branded` und `all_non_branded` hinzu
sowie je Plattform `android_branded`, `ios_branded` bzw. `desktop_branded` —
aber nur für die Plattformen, für die dort tatsächlich eine Kennung
eingetragen ist.

### Anfragetypen

| Wert | Beschriftung | Erkannt an |
| --- | --- | --- |
| `upload` | Datei-Upload (PUT/MKCOL/MOVE/COPY) | WebDAV mit PUT, MKCOL, MOVE, COPY, PATCH sowie POST auf `/remote.php/dav/bulk` |
| `download` | Datei-Download (GET) | GET über WebDAV |
| `webdav` | WebDAV-Durchsuchen (PROPFIND) | übrige WebDAV-Methoden, etwa PROPFIND und OPTIONS |
| `public` | Öffentliche Linkfreigabe | `/public.php`, `/s/…`, `/remote.php/dav/public-files/` |
| `api` | API-Anfrage | Pfade mit `/ocs/` oder `/api/` |
| `other` | Andere | alles Übrige, unter anderem DELETE über WebDAV |

Die Zuordnung `public` hat Vorrang: Ein Upload über einen öffentlichen Link
gilt als `public`, nicht als `upload`.

### Wo geprüft wird

Die Firewall hängt an drei Stellen im Ablauf:

1. Ein Sabre-DAV-Plugin läuft nach der Authentifizierung bei jeder
   WebDAV-Methode und beantwortet Treffer mit einem sauberen HTTP 403.
2. Ein Storage-Wrapper fängt Zugriffe ab, die nicht über WebDAV laufen. Er
   greift ausschließlich bei **schreibenden** Operationen (`mkdir`, `rmdir`,
   `fopen` im Schreibmodus, `file_put_contents`, `unlink`, `rename`, `copy`,
   `touch`) und bei den Rechteabfragen. Bei WebDAV-Anfragen hält er sich
   heraus, weil er dort zu früh liefe und aus einer beabsichtigten Sperre
   einen 503 machen würde.
3. Für öffentliche Links kommen Ereignis-Listener hinzu: `share.linkaccess`
   für die Seiten von `files_sharing` und die `dav.public.*.before`-Ereignisse
   für WebDAV über `public.php`.

### Regeln auf der Kommandozeile

```bash
# Alle Regelgruppen mit Protokollstufe und Trefferzählern anzeigen
sudo -u www-data php8.4 occ firewall:list

# Maschinenlesbar
sudo -u www-data php8.4 occ firewall:list --output=json
sudo -u www-data php8.4 occ firewall:list --output=json_pretty
```

Eine Gruppe anlegen. Bedingungen werden als `typ:operator:wert` übergeben,
mehrfach angegeben ergeben sie eine UND-Verknüpfung:

```bash
sudo -u www-data php8.4 occ firewall:add-rule "Fremde Netze sperren" \
  -c "ip_range_ipv4:is_not:10.0.0.0/8" \
  -c "request_type:is:upload"
```

Die Gruppe kann direkt abgeschaltet oder in den Testbetrieb gelegt werden:

```bash
sudo -u www-data php8.4 occ firewall:add-rule "Nur beobachten" \
  -c "user_agent:matches:*BadBot*" --test-mode

sudo -u www-data php8.4 occ firewall:add-rule "Noch nicht scharf" \
  -c "user_group:is:externe" --disabled
```

Zeitfenster brauchen einen JSON-Wert mit `start` und `end`, optional mit
`timezone`:

```bash
sudo -u www-data php8.4 occ firewall:add-rule "Ausserhalb der Bürozeit" \
  -c 'time:is:{"start":"18:00","end":"06:00","timezone":"Europe/Berlin"}'
```

Bei `regex` trennt ein `|` das Muster vom Zielfeld:

```bash
sudo -u www-data php8.4 occ firewall:add-rule "Bot-Kennungen" \
  -c "regex:is:/bot/i|user_agent"
```

Löschen, sichern und einspielen:

```bash
# Index ist 0-basiert: die erste Gruppe hat den Index 0
sudo -u www-data php8.4 occ firewall:delete-rule 0

# Sicherung (ohne Dateiargument nach stdout)
sudo -u www-data php8.4 occ firewall:export /var/backups/firewall.json

# Anhängen bzw. vollständig ersetzen
sudo -u www-data php8.4 occ firewall:import /var/backups/firewall.json
sudo -u www-data php8.4 occ firewall:import /var/backups/firewall.json --replace
sudo -u www-data php8.4 occ firewall:import - --replace < /var/backups/firewall.json
```

Der Export enthält neben den Regeln auch die Protokollstufe; beim Import
wird sie mitgesetzt.

### Testbetrieb ohne Wirkung

Jede Regelgruppe hat einen eigenen Schalter *Testmodus (nur
protokollieren)*. Trifft eine Gruppe im Testmodus zu, passiert dreierlei:

* Die Anfrage wird **nicht** blockiert.
* Der Treffer wird als Testtreffer gezählt.
* Der Treffer wird protokolliert — und zwar **unabhängig von der
  eingestellten Protokollstufe**, denn genau das ist der Zweck des
  Testbetriebs. Die Meldung lautet `BW-Tech File Firewall TEST MODE would
  have blocked request: …` und nennt Benutzer, IP, Methode, URL, Regelname
  und die ausgewerteten Bedingungen.

Anschließend läuft die Auswertung weiter, sodass in einem Durchgang mehrere
Testgruppen anschlagen können. Der Testbetrieb ist damit der richtige Weg,
eine neue Regel gegen echten Verkehr zu prüfen, bevor sie scharf gestellt
wird.

### Trefferzählung

Zu jeder Regelgruppe werden die Zahl der echten Treffer, die Zahl der
Testtreffer und der Zeitpunkt des letzten Treffers geführt. Der Zeitstempel
wird in **UTC** geschrieben und auch so angezeigt (Format
`Y-m-d H:i:s UTC`) — unabhängig davon, welche Zeitzone der Server oder die
Regel verwendet.

Pro Anfrage zählt eine Gruppe höchstens einmal, auch wenn DAV-Plugin,
Storage-Wrapper und Middleware die Auswertung mehrfach anstoßen. Die Zähler
stehen im Admin-Panel und in `occ firewall:list`; zurückgesetzt werden sie
über die Schaltfläche *Statistik zurücksetzen*.

Die Zähler hängen an der internen ID der Regelgruppe. Beim Speichern werden
Zählerstände verworfen, zu denen es keine Gruppe mehr gibt.

### Protokollierung

Die Protokollstufe gilt für die ganze App und kennt drei Werte:

| Wert | Beschriftung | Wirkung |
| --- | --- | --- |
| `0` | Aus | keine eigenen Meldungen (Testtreffer ausgenommen) |
| `1` | Nur blockierte Anfragen | jede Sperre als Warnung |
| `2` | Alle Anfragen | zusätzlich jede durchgelassene Anfrage als Info |

Umstellen im Panel oder direkt:

```bash
sudo -u www-data php8.4 occ config:app:set file_firewall log_level --value=1
sudo -u www-data php8.4 occ config:app:get file_firewall log_level
```

Stufe `2` schreibt eine Zeile pro Anfrage und ist nur zur Fehlersuche
gedacht. Wo die Meldungen landen, steht unter
[Serverprotokoll und Fehlermeldungen](logging.md).

### Zeitzonen

Die Bedingung *Anfragezeit* hat eine eigene Zeitzonenauswahl. Bleibt sie auf
*Serverzeit*, rechnet die Regel in der Zeitzone des PHP-Prozesses. Wird eine
IANA-Kennung wie `Europe/Berlin` gewählt, wird das Fenster in dieser Zone
ausgewertet; Sommer- und Winterzeit ergeben sich damit automatisch. Eine
unbekannte Kennung wird beim Speichern verworfen, und die Auswertung fällt
auf die Serverzeit zurück.

Liegt das Ende vor dem Beginn oder ist beides gleich, gilt das Fenster über
Mitternacht hinweg: `22:00`–`06:00` trifft abends **und** morgens zu.

## Workflow: Tags automatisch vergeben

Ein Workflow besteht aus einem Namen, beliebig vielen Bedingungen und einer
Aktion. Alle Bedingungen müssen zutreffen. Als Aktion steht *Tags hinzufügen*
zur Verfügung: Die genannten Tags werden der Datei zugewiesen, sobald sie
angelegt oder geschrieben wird. Die Tags müssen bereits existieren, sonst
lehnt die App den Workflow beim Speichern ab.

Trifft mehr als ein Workflow zu, werden alle ausgeführt — hier gibt es kein
„erster Treffer gewinnt" wie bei der Firewall.

### Verfügbare Bedingungen und Operatoren

| Kennung | Beschriftung | Operatoren |
| --- | --- | --- |
| `cidr` | Client-IP-Subnetz (IPv4) | `equals`, `not_equals` |
| `cidr6` | Client-IP-Subnetz (IPv6) | `equals`, `not_equals` |
| `subnet` | Server-IP-Subnetz (IPv4) | `equals`, `not_equals` |
| `subnet6` | Server-IP-Subnetz (IPv6) | `equals`, `not_equals` |
| `devicetype` | Gerätetyp | `equals`, `not_equals` |
| `filesize` | Dateigröße | `less`, `less_or_equal`, `greater`, `greater_or_equal` |
| `filetype` | Datei-MIME-Typ | `equals`, `not_equals`, `begins_with`, `not_begins_with`, `contains`, `not_contains`, `ends_with`, `not_ends_with` |
| `requesturl` | Anfrage-URL | wie `filetype` |
| `useragent` | User-Agent | wie `filetype` |
| `request` | Anfragetyp | `equals`, `not_equals` |
| `systemtag` | Systemtag der Datei | `equals`, `not_equals` |
| `time` | Anfragezeit | `equals`, `not_equals` |
| `usergroup` | Benutzergruppe | `equals`, `not_equals` |

Die Auswahlliste des Admin-Panels wird aus genau dieser Aufstellung gebaut.
Die Regel-Registrierung der App kennt darüber hinaus eine `regex`-Bedingung
mit den Zielfeldern `cidr`, `subnet`, `requesturl`, `useragent` und
`usergroup` — für sie gibt es jedoch keinen Eintrag in der Oberfläche, sie
ist also nur programmatisch erreichbar.

Als Anfragetyp stehen hier nur *Public Share Link*, *WebDAV* und *Other* zur
Auswahl. Gerätetypen sind `android`, `ios`, `desktop` und `other`, ergänzt um
die Branded-Werte, sofern der Systemschlüssel `workflow.branded_clients`
gefüllt ist.

Die Bedingung `systemtag` prüft die Datei **und ihre übergeordneten Ordner**.
Existiert der genannte Tag nicht mehr, gilt die Bedingung als erfüllt — die
App entscheidet in diesem Fall bewusst zugunsten der Regel und nicht dagegen.

### Zeitzone der Workflow-Bedingung

Anders als bei der Dateifirewall gibt es hier **keine** Zeitzonenauswahl. Die
Bedingung *Anfragezeit* rechnet fest in **UTC**; die Oberfläche rechnet die
Eingabe in die lokale Zeit des Browsers um. Auch hier gilt ein Fenster, dessen
Ende vor dem Beginn liegt, als Fenster über Mitternacht.

### Grenzen des Auto-Taggings

* Die einzige mitgelieferte Aktion ist das Setzen von Tags. Weitere Typen
  lassen sich nur über die Ereignisschnittstelle der App durch eine eigene
  Erweiterung ergänzen.
* Geteilte Speicher (`OC\Files\Storage\Shared`) werden bewusst nicht
  eingebunden. Ein Upload in eine Freigabe löst beim Empfänger keinen
  Workflow aus.
* Es gibt weder Testmodus noch Trefferzähler. Ein Workflow wirkt sofort nach
  dem Speichern.
* Bei der Bedingung *Dateigröße* fällt die Prüfung durch, wenn die Größe des
  laufenden Transfers nicht bestimmbar ist — der Workflow trifft dann nicht
  zu.

## Aufbewahrung

### Wie eine Aufbewahrungsfrist entsteht

Unter **Einstellungen → Administration → Workflow** gibt es den Abschnitt
*Aufbewahrungsfristen* mit dem Text „Dateien mit den folgenden Tags nach der
angegebenen Zeit löschen". Eine Frist besteht aus genau drei Angaben: einem
Tag, einer Zahl und einer Einheit (*Tage*, *Wochen*, *Monate*, *Jahre*).

Pro Tag ist genau eine Frist möglich; ein zweiter Eintrag zum selben Tag wird
abgelehnt. Wird ein Tag gelöscht, entfernt die App die zugehörige Frist und
den zugehörigen Hintergrund-Job automatisch mit.

### Wie die Frist durchgesetzt wird

Die Aufbewahrung läuft ausschließlich in Hintergrund-Jobs. Ohne
funktionierenden Cron passiert nichts — siehe
[Hintergrund-Jobs (Cron)](background-jobs.md).

1. Ein Verwaltungs-Job der App prüft im Standardbetrieb stündlich, ob zu
   jeder Frist ein Arbeits-Job eingetragen ist, und trägt fehlende nach.
2. Pro Tag mit Frist läuft ein eigener Arbeits-Job in einem Abstand von
   24 Stunden.
3. Der Arbeits-Job berechnet den Stichtag (jetzt minus Frist) und holt die
   getaggten Objekte in Stapeln zu je zehn Einträgen. Ein getaggter Ordner
   wird von dort aus nach unten durchlaufen: Ist der Ordner selbst noch nicht
   fällig, werden seine Kinder geprüft.
4. Maßgeblich ist standardmäßig die **Änderungszeit** der Datei.

Optional kann statt der Änderungszeit die Upload-Zeit herangezogen werden:

```bash
sudo -u www-data php8.4 occ config:system:set \
  workflow.tag-based-retention.use-upload-time --value=true --type=boolean
```

Dieser Schalter wirkt nur für Dateien, für die überhaupt eine Upload-Zeit
gespeichert ist. Fehlt sie, wird die Datei nicht gelöscht, und im Protokoll
steht eine Warnung `No upload time stored for file id …`.

### Die beiden Motoren

Welcher Motor die Aufbewahrung ausführt, steuert ein Systemschlüssel:

```bash
sudo -u www-data php8.4 occ config:system:get workflow.retention_engine
sudo -u www-data php8.4 occ config:system:set workflow.retention_engine \
  --value=tagbased
```

| Wert | Vorgehen | Takt des Verwaltungs-Jobs |
| --- | --- | --- |
| `tagbased` (Standard) | ein Arbeits-Job je Tag mit Frist, ausgehend von den getaggten Objekten | stündlich |
| `userbased` | ein Arbeits-Job je Benutzer, der dessen Dateibaum durchläuft | täglich |

Ein unbekannter Wert führt zu keiner Verarbeitung; die App schreibt in diesem
Fall eine kritische Meldung ins Protokoll und nennt die zulässigen Werte.

Nur im Motor `userbased` erscheint im Panel zusätzlich die Option „Leere
Ordner löschen, die seit … Tagen nicht geändert wurden". Sie liegt in der
App-Konfiguration:

```bash
sudo -u www-data php8.4 occ config:app:get workflow folder_retention
sudo -u www-data php8.4 occ config:app:get workflow folder_retention_period
```

`folder_retention` ist der Schalter (`0`/`1`), `folder_retention_period` die
Anzahl Tage; ohne Eintrag gilt `7`.

### Was die Aufbewahrung heute nicht kann

* **Nur löschen.** Es gibt keine Archivstufe, kein Verschieben auf einen
  anderen Speicher und keinen Zwischenzustand. Eine fällige Datei wird
  gelöscht.
* **Keine Vorwarnung.** Die App verschickt keine Mails; Betroffene erfahren
  vom Löschen nur über den Aktivitätsstrom, in dem der Vorgang als
  automatische Aktion vermerkt wird.
* **Kein Probelauf.** Es gibt keine Vorschau, welche Dateien der nächste Lauf
  treffen würde, und keinen Testmodus wie bei der Dateifirewall. Neue Fristen
  sollten deshalb zuerst an einem eigens angelegten Tag mit wenigen Dateien
  erprobt werden.
* **Eine Frist je Tag.** Fristen lassen sich nicht kombinieren oder nach
  Benutzer, Gruppe oder Ordner staffeln; die einzige Stellschraube ist, wo
  der Tag hängt.
* **Kein sofortiges Wirken.** Eine gerade eingetragene Frist greift
  frühestens beim nächsten Lauf des Arbeits-Jobs, im Motor `tagbased` also
  innerhalb von 24 Stunden.
* **Keine Steuerung über `occ`.** Fristen werden ausschließlich im
  Admin-Panel gepflegt; die App bringt keine eigenen `occ`-Befehle mit.

## Zustand prüfen

```bash
# Regeln, Protokollstufe und Trefferzähler der Firewall
sudo -u www-data php8.4 occ firewall:list

# Laufen die Aufbewahrungs-Jobs? Spalte "Job" zeigt die Klassennamen
sudo -u www-data php8.4 occ background:queue:status

# Einen bestimmten Job zur Kontrolle sofort ausführen
sudo -u www-data php8.4 occ background:queue:execute <Job-ID> --force
```

`background:queue:execute` ist ein Wartungsbefehl und fragt vor der
Ausführung nach; die regelmäßige Einplanung bleibt davon unberührt.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Regel greift beim Testen nicht, obwohl sie stimmt | Als Administrator angemeldet — Administratoren werden nie blockiert | Mit einem normalen Konto prüfen |
| Regel greift bei `occ`- oder Cron-Aufrufen nicht | Die Auswertung steigt im CLI-Betrieb sofort aus | Über HTTP prüfen; die Firewall schützt keine Kommandozeilenaufrufe |
| Zeitfenster über die Kommandozeile bleibt wirkungslos | `time:is:18:00-06:00` wird als Zeichenkette gespeichert, die Bedingung erwartet aber `start`/`end` | Wert als JSON übergeben: `time:is:{"start":"18:00","end":"06:00"}` |
| Regulärer Ausdruck über die Kommandozeile ist abgeschnitten | `firewall:add-rule` trennt am ersten senkrechten Strich das Zielfeld ab, eine Alternation wird dadurch zerschnitten | Muster im Admin-Panel eintragen oder über `firewall:import` einspielen |
| `firewall:delete-rule` löscht die falsche Gruppe | `firewall:list` nummeriert ab 1, das Löschargument ist 0-basiert | Vorher `--output=json_pretty` prüfen und die Position ab 0 zählen |
| Trefferzähler nach dem Speichern zurückgesetzt | Über die API gespeicherte Gruppen ohne gültige ID bekommen eine neue ID; die alten Zählerstände werden verworfen | Beim Speichern über die API die vorhandene `id` mitschicken |
| Bedingung *Systemtag der Datei* greift im Browser nicht | Der Dateibezug wird nur aus `/remote.php/webdav` und `/remote.php/dav/files/<Benutzer>` aufgelöst | Für Web-Zugriffe eine andere Bedingung wählen |
| Sperre im Browser erscheint als allgemeiner Fehler statt als 403 | Außerhalb von WebDAV greift der Storage-Wrapper, der die Operation nur scheitern lässt | Erwartetes Verhalten; die Ursache steht bei Protokollstufe `1` im Serverprotokoll |
| Testtreffer stehen im Protokoll, obwohl die Protokollierung auf *Aus* steht | Testtreffer werden bewusst immer protokolliert | Testmodus abschalten, wenn die Meldungen stören |
| Zeitfenster passt nicht zur Ortszeit | Ohne Zeitzonenauswahl rechnet die Firewall in der Serverzeit, die Workflow-App immer in UTC | In der Firewall die IANA-Zeitzone setzen; bei Workflows die UTC-Verschiebung einrechnen |
| Auto-Tagging bleibt bei Uploads in eine Freigabe aus | Geteilte Speicher werden vom Workflow-Wrapper nicht eingebunden | Kein Umgehungsweg vorhanden |
| Aufbewahrung löscht nichts | Cron läuft nicht, oder der Arbeits-Job wurde noch nicht wieder fällig | `background:queue:status` prüfen, siehe [Hintergrund-Jobs (Cron)](background-jobs.md) |
| Aufbewahrung löscht nichts, Protokoll meldet `No upload time stored for file id` | `workflow.tag-based-retention.use-upload-time` ist aktiv, für die Datei ist aber keine Upload-Zeit gespeichert | Schalter entfernen, damit wieder die Änderungszeit gilt |
| Protokoll meldet eine unbekannte Aufbewahrungs-Engine | `workflow.retention_engine` enthält einen anderen Wert als `tagbased` oder `userbased` | Wert korrigieren |
| Option für leere Ordner fehlt im Panel | Sie erscheint nur im Motor `userbased` | `workflow.retention_engine` umstellen oder auf die Option verzichten |
