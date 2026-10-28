# MCP Connector

Der MCP Connector macht eine owncloud.online-Instanz zum Server für das Model
Context Protocol (MCP). KI-Assistenten, Editoren und eigene Agenten listen,
lesen und durchsuchen darüber Dateien. Schaltet ein Administrator den
Schreibzugriff ein, schreiben und teilen sie auch. Jeder Aufruf läuft mit den
Rechten des angemeldeten Kontos, ab Werk nur lesend.

!!! note "Verfügbarkeit"
    Im Kern enthalten. Der Server bringt die App unter `apps/oco_mcp` mit, ab
    Werk ist sie ausgeschaltet. App-ID `oco_mcp`, Version 1.0.3 in
    owncloud.online 11.0.25. Im Marketplace steht sie nicht.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 oder neuer |
| HTTPS | dringend empfohlen. Der Client schickt Anmeldename und App-Passwort bei jeder Anfrage mit. |
| Client | ein MCP-Client mit dem Transport „Streamable HTTP“. Clients, die nur den älteren Transport „HTTP+SSE“ kennen, verbinden sich nicht. |
| Konto | ein App-Passwort. Das normale Kennwort des Kontos weist der Endpunkt ab. |

## Installation

Die App liegt dem Server bei und wird nur eingeschaltet:

```bash
sudo -u www-data php8.4 occ app:enable oco_mcp
```

Der Endpunkt lautet `https://cloud.example.com/apps/oco_mcp/mcp`. Enthalten die
Adressen Ihrer Instanz `index.php`, heißt er
`https://cloud.example.com/index.php/apps/oco_mcp/mcp`.

## Einrichtung

### App-Passwort anlegen

Jedes Konto legt sein App-Passwort selbst an:

1. **Einstellungen → Persönlich → Sicherheit** öffnen, Abschnitt **App-Passwörter
   / Token**.
2. Unter **App-Name** einen Namen eintragen, etwa „MCP Laptop“, und **Neuen
   App-Passcode erstellen** klicken.
3. **Benutzername** und **Passwort / Token** in den Client übernehmen. Das
   Passwort zeigt die Seite nur dieses eine Mal. Mit **Erledigt** schließen.

Der Client meldet sich per HTTP Basic an: Anmeldename und App-Passwort, mit
Doppelpunkt verbunden und Base64-kodiert. Den Wert für die Kopfzeile erzeugen
Sie so:

```bash
# -w0 verhindert Zeilenumbrüche in der Ausgabe; unter macOS ohne -w0
printf '%s' 'alice:<app-passwort>' | base64 -w0
# Kopfzeile: Authorization: Basic <Ausgabe>
```

Das Papierkorb-Symbol (**Trennen**) neben dem Eintrag widerruft das
App-Passwort. Der Zugang endet sofort, weil der Server die Anmeldedaten bei
jeder Anfrage neu prüft.

### Schreibzugriff freischalten {#schreibzugriff}

Ab Werk bietet der Server nur lesende Werkzeuge an. Schreibende Werkzeuge und
die schreibende Verwaltung von Konten und Gruppen brauchen beide Werte:

```bash
sudo -u www-data php8.4 occ config:app:set oco_mcp enable_write --value yes
sudo -u www-data php8.4 occ config:app:set oco_mcp write_groups --value "mcp-schreiben,admin"
```

| Wert | Mögliche Werte | Standard | Wirkung |
| --- | --- | --- | --- |
| `enable_write` | `yes`, `no` | `no` | schaltet die schreibenden Werkzeuge grundsätzlich frei |
| `write_groups` | Gruppen-IDs, durch Kommas getrennt | leer | Nur Mitglieder dieser Gruppen bekommen die schreibenden Werkzeuge. Eine leere Liste gibt niemandem Schreibzugriff. |

Seit owncloud.online 11.0.14 gilt eine leere `write_groups` als „niemand“.
Vorher hieß sie „alle Konten“. Steht `enable_write` auf `yes` und
`write_groups` ist leer, schreibt der Server einmal am Tag eine Warnung ins
Protokoll: „MCP write tools are unavailable: "enable_write" is set but
"write_groups" is empty …“.

Die meisten Clients laden die Liste der Werkzeuge nur beim Verbinden. Verbinden
Sie den Client nach einer Änderung neu.

Weitere Einstellungen hat die App nicht. Anmeldung und Rechte kommen aus
owncloud.online selbst.

### Client anbinden

Clients mit „Streamable HTTP“ brauchen nur die Adresse des Endpunkts und die
Kopfzeile `Authorization: Basic …`.

Desktop-Clients, die MCP-Server nur als lokale Programme starten, binden den
Endpunkt über die Brücke `mcp-remote` an, ein npm-Paket. Dafür brauchen Sie
Node.js mit `npx`. Ein Eintrag im Abschnitt `mcpServers` der
Client-Konfiguration sieht so aus:

```json
{
  "mcpServers": {
    "owncloud-online": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://cloud.example.com/apps/oco_mcp/mcp",
        "--header", "Authorization:${AUTH_HEADER}",
        "--transport", "http-only"
      ],
      "env": {
        "AUTH_HEADER": "Basic <Base64 von alice:app-passwort>"
      }
    }
  }
}
```

Die Kopfzeile steht ohne Leerzeichen in `args`, der Wert mit Leerzeichen in
`env`. Manche Clients unter Windows reichen Leerzeichen in `args` nicht richtig
weiter. `--transport http-only` passt zum Server: Er antwortet mit JSON und
bietet keinen Ereignisstrom per GET an.

Den Base64-Wert erzeugen Sie unter Windows in der PowerShell so:

```powershell
[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes('alice:<app-passwort>'))
```

Weitere Clients und den Ablauf des Protokolls im Detail beschreibt die Seite
[MCP Connector (KI-Anbindung)](../mcp-connector.md).

### Verbindung testen

Eine Sitzung beginnt mit `initialize`. Die Antwort enthält die Kopfzeile
`Mcp-Session-Id`, die jede weitere Anfrage mitschicken muss.

```bash
curl -s -D - -u 'alice:<app-passwort>' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' \
  https://cloud.example.com/apps/oco_mcp/mcp

curl -s -u 'alice:<app-passwort>' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'Mcp-Session-Id: <ID aus der ersten Antwort>' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  https://cloud.example.com/apps/oco_mcp/mcp
```

Der Server kennt die Protokollversionen `2024-11-05`, `2025-03-26`,
`2025-06-18` und `2025-11-25` und antwortet mit der Version, die der Client
anfragt. Eine Sitzung verfällt nach einer Stunde ohne Anfrage. Ein `DELETE` auf
den Endpunkt mit der Sitzungs-ID beendet sie sofort.

## Nutzung

### Werkzeuge

| Werkzeug | Zugriff | Zweck |
| --- | --- | --- |
| `files_list`, `files_info`, `files_read`, `files_search` | lesen | Ordner auflisten, Angaben zu einer Datei, Datei lesen, nach Namen suchen |
| `files_view_image` | lesen | Bild an das Modell geben, bis 5 MiB |
| `files_write`, `files_mkdir`, `files_move`, `files_copy`, `files_delete` | schreiben | Datei schreiben (bis 1 MiB), Ordner anlegen, verschieben, kopieren, löschen |
| `shares_list` | lesen | eigene Freigaben |
| `shares_create_link`, `shares_create_user`, `shares_delete` | schreiben | öffentlichen Link anlegen, an ein Konto freigeben, eigene Freigabe entfernen |
| `tags_list` | lesen | Tags einer Datei |
| `tags_assign`, `tags_remove` | schreiben | Tag setzen oder entfernen |
| `comments_list` | lesen | Kommentare einer Datei |
| `comments_add` | schreiben | Kommentar schreiben |
| `users_list`, `users_get`, `groups_list`, `groups_members` | lesen, nur Administratoren | Konten und Gruppen ansehen |
| `users_create`, `users_disable`, `users_enable`, `users_set_quota`, `groups_add_member`, `groups_remove_member` | schreiben, nur Administratoren | Konten und Gruppen verwalten |
| `whoami`, `quota`, `capabilities` | lesen | angemeldetes Konto, Speicherplatz, Fähigkeiten des Servers |
| `ai_ask` | lesen | Frage an die eigenen Dokumente, nur mit eingeschalteter App [AI Documents](ai_documents.md) |

Werkzeuge, die ein Konto nicht nutzen darf, nennt der Server gar nicht erst.
Die genauen Parameter liefert `tools/list`.

- `files_read` liefert ab Werk bis 1 MiB, mit `max_bytes` bis 10 MiB.
- `files_list` gibt höchstens 1000 Einträge je Aufruf zurück, `shares_list`
  höchstens 500.
- `files_delete` löscht über die normale Datei-Schnittstelle. Ist die App
  `files_trashbin` aktiv, landet die Datei unter **Gelöschte Dateien**.
- Freigaben entstehen über die normale Freigabe-Logik. Die Vorgaben unter
  **Einstellungen → Administration → Teilen** gelten auch hier.

### Dateien als Ressourcen

Zusätzlich stellt der Server die Dateien des Kontos als MCP-Ressourcen bereit:

- `owncloud:///` liefert den Stammordner als JSON-Liste. Jeder Eintrag trägt
  eine `uri`, die sich direkt lesen lässt.
- `owncloud:///{path}` liest eine Datei oder einen Ordner. Schrägstriche in
  verschachtelten Pfaden werden als `%2F` geschrieben, etwa
  `owncloud:///Dokumente%2Fbericht.txt`.

Text kommt als UTF-8, Binärdaten als Base64, jeweils bis 5 MiB.

### Fragen an Dokumente

Ist die App [AI Documents](ai_documents.md) eingeschaltet, erscheint das
Werkzeug `ai_ask`. Es beantwortet eine Frage aus den analysierten Dokumenten des
Kontos und nennt die Quellen. Parameter: `question`, `scope` (`all`, `folder`
oder `selection`), `path` bei `folder`, `file_ids` (durch Kommas getrennt) bei
`selection` und `mode` (`qa`, `summary`, `extract` oder `report`). Ohne AI
Documents fehlt das Werkzeug, ein Fehler ist das nicht.

## Grenzen und Hinweise

- Der Endpunkt nimmt nur HTTP Basic mit App-Passwort an. Browser-Sitzungen
  (Cookies), Bearer-Token und das normale Kennwort weist er ab. Eine
  Zwei-Faktor-Pflicht lässt sich so nicht umgehen.
- Fehlversuche bremsen weitere Versuche von derselben Adresse und für denselben
  Anmeldenamen. Im Protokoll stehen sie als `MCP login failed: '<login>' (Remote
  IP: '<adresse>')`. Ein fail2ban-Filter auf „Login failed“ erfasst diese Zeilen
  nicht, ergänzen Sie ihn um „MCP login failed“. Hinter einem Reverse-Proxy
  braucht die Bremse gegen Passwort-Raten richtig gesetzte `trusted_proxies`,
  siehe [Sicherheit und Setup-Warnungen](../../administration/security-hardening.md).
- Steht der Host aus der Kopfzeile `Origin` oder, ohne sie, aus `Host` weder in
  `trusted_domains` noch in `overwritehost` und ist er nicht `localhost`,
  `127.0.0.1` oder `[::1]`, antwortet der Server mit 403. Einträge mit `*` in
  `trusted_domains` zählen dabei nicht. Clients, die im Browser auf einer
  fremden Website laufen, verbinden sich deshalb nicht.
- Eine Anfrage darf höchstens 2 MiB groß sein.
- Pfade mit `.` oder `..` lehnt der Server ab.
- Fehler aus AI Documents erreichen den Client nur als allgemeine Meldung. Die
  Ursache steht im [Serverprotokoll](../../administration/logging.md) unter
  `oco_mcp`.
- Sitzungen liegen als Dateien im Datenverzeichnis unter `oco_mcp-sessions`.
- Wie Sie eigene Werkzeuge ergänzen, beschreibt die
  [README der App](https://github.com/BWTECH-github/owncloud.online/blob/main/apps/oco_mcp/README.md)
  im Server-Repository.

## Fehlersuche

| Meldung oder Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| HTTP 401, „MCP requires HTTP Basic authentication with an owncloud.online app token.“ | Die Kopfzeile `Authorization` fehlt oder ist kein Basic, oder ein vorgeschalteter Proxy entfernt sie | Basic mit Anmeldename und App-Passwort senden. Prüfen, ob WebDAV mit demselben App-Passwort funktioniert. |
| HTTP 401, „Invalid MCP credentials.“ | normales Kennwort statt App-Passwort, App-Passwort widerrufen oder Anmeldename falsch | neues App-Passwort anlegen |
| HTTP 403, „Forbidden: Invalid Host header.“ oder „Forbidden: Invalid Origin header.“ | Hostname oder Origin nicht in `trusted_domains`, oder nur über einen Eintrag mit `*` erfasst | den Endpunkt über einen ausdrücklich eingetragenen Hostnamen aufrufen |
| HTTP 413, „MCP request body is too large.“ | Anfrage größer als 2 MiB | kleinere Anfragen |
| „A valid session id is REQUIRED for non-initialize requests.“ | Kopfzeile `Mcp-Session-Id` fehlt | erst `initialize` senden, dann die ID aus dessen Antwort bei jeder Anfrage mitschicken |
| Fehler `-32601`, etwa „Tool not found: "files_write".“ | Das Konto hat keinen Schreibzugriff, oder er wurde nach dem Verbinden entzogen | `enable_write` und `write_groups` setzen, Konto in eine der Gruppen aufnehmen, Client neu verbinden (siehe [Schreibzugriff freischalten](#schreibzugriff)) |
| Fehler `-32601` bei `users_*` und `groups_*` | Das Konto ist kein Administrator | mit einem Administrator-Konto verbinden |
| Werkzeug `ai_ask` fehlt | AI Documents ist nicht eingeschaltet | gewollt, bei Bedarf AI Documents einschalten |
| „AI query failed. Ask an administrator to check the server log.“ | Fehler in AI Documents | Serverprotokoll, Einträge unter `oco_mcp` |
| „Relative path segments (".", "..") are forbidden.“ | Pfad mit `.` oder `..` | Pfade relativ zum Stammordner des Kontos ohne `.` und `..` angeben |
| Der Client meldet einen Fehler beim Ereignisstrom | Der Client erwartet einen Strom per GET | Transport auf HTTP ohne SSE stellen, bei `mcp-remote` mit `--transport http-only` |
| HTTP 500, „Internal MCP error.“ | Ausnahme im Server | Serverprotokoll, Einträge unter `oco_mcp` |
