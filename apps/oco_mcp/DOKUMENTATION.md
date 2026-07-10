# MCP Connector für owncloud.online — Dokumentation (Deutsch)

`oco_mcp` macht aus einer owncloud.online-Instanz einen **MCP-Server**
(Model Context Protocol). KI-Assistenten wie **Claude** (Desktop/Code),
Editoren oder eigene Agenten verbinden sich damit und arbeiten mit den
Dateien, Freigaben, Tags und Kommentaren des angemeldeten Nutzers — mit
exakt dessen Berechtigungen, standardmäßig **nur lesend**.

> **English?** See [README.md](README.md) for the English version.

---

## Inhalt

1. [Was ist MCP?](#1-was-ist-mcp)
2. [Schnellstart](#2-schnellstart)
3. [Wie es funktioniert (Protokoll)](#3-wie-es-funktioniert-protokoll)
4. [Authentifizierung](#4-authentifizierung)
5. [Rechte-Modell](#5-rechte-modell)
6. [Werkzeug-Referenz (Tools)](#6-werkzeug-referenz-tools)
7. [Ressourcen (Dateien als Kontext)](#7-ressourcen-dateien-als-kontext)
8. [KI-Dokumentensuche `ai_ask` (optional)](#8-ki-dokumentensuche-ai_ask-optional)
9. [Clients anbinden](#9-clients-anbinden)
10. [Konfiguration](#10-konfiguration)
11. [Sicherheit](#11-sicherheit)
12. [Fehlerbehebung](#12-fehlerbehebung)
13. [Eigene Tools ergänzen](#13-eigene-tools-ergänzen)

---

## 1. Was ist MCP?

Das **Model Context Protocol** ist ein offener Standard, über den
KI-Modelle mit externen Systemen sprechen. Ein MCP-Server bietet dem
Modell drei Dinge an:

- **Tools** — aufrufbare Funktionen („liste meine Dateien", „lege einen
  öffentlichen Link an").
- **Ressourcen** — Inhalte, die der Client als Kontext anhängen kann
  (hier: die Dateien des Nutzers).
- **Sitzungen** — der Client initialisiert einmal und ruft dann beliebig
  viele Tools in dieser Sitzung auf.

`oco_mcp` implementiert die MCP-Transportvariante **Streamable HTTP**
(JSON-RPC 2.0 über `POST`). Das Modell sieht dadurch die Cloud des
Nutzers wie einen Werkzeugkasten: es kann suchen, lesen, (falls
freigeschaltet) schreiben und teilen — aber niemals mehr, als der
Nutzer selbst dürfte.

## 2. Schnellstart

```bash
# 1. App installieren (Ordner nach apps-external/ bzw. apps/ legen) und aktivieren
occ app:enable oco_mcp

# 2. Optional: Schreib- und Verwaltungs-Tools freischalten (Standard: nur lesen)
occ config:app:set oco_mcp enable_write --value=yes

# 3. In ownCloud ein App-Passwort erzeugen:
#    Einstellungen -> Sicherheit -> App-Passwörter
```

Endpoint: `POST https://<server>/apps/oco_mcp/mcp`
(ohne Rewrite-Regeln: `https://<server>/index.php/apps/oco_mcp/mcp`)

## 3. Wie es funktioniert (Protokoll)

Eine MCP-Sitzung besteht aus drei Schritten:

**Schritt 1 — `initialize`:** Der Client stellt sich vor, der Server
antwortet mit seinen Fähigkeiten und vergibt eine **Session-ID** im
Antwort-Header `Mcp-Session-Id`.

```bash
curl -u "benutzer:app-passwort" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -D - \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{
        "protocolVersion":"2025-11-25","capabilities":{},
        "clientInfo":{"name":"mein-client","version":"1.0"}}}' \
  https://cloud.example.com/apps/oco_mcp/mcp
```

Antwort (gekürzt) — die Session-ID steht im Header:

```
Mcp-Session-Id: f1fff858-70bc-49ed-bdfc-bed963d5b57b

{"jsonrpc":"2.0","id":1,"result":{
  "protocolVersion":"2025-11-25",
  "serverInfo":{"name":"owncloud.online","version":"1.0.0"},
  "instructions":"You are connected to an owncloud.online instance as user \"…\" …"}}
```

**Schritt 2 — Tools auflisten/aufrufen:** Jede weitere Anfrage schickt
die Session-ID im Header `Mcp-Session-Id` mit:

```bash
curl -u "benutzer:app-passwort" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "Mcp-Session-Id: f1fff858-…" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  https://cloud.example.com/apps/oco_mcp/mcp
```

Tool-Aufruf:

```bash
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{
        "name":"files_list","arguments":{"path":"/"}}}'
```

**Schritt 3 — Sitzung beenden (optional):** `DELETE` auf den Endpoint
mit der Session-ID beendet die Sitzung. Sitzungen verfallen sonst
automatisch nach **einer Stunde** Inaktivität (dateibasierter
Session-Store im ownCloud-Datenverzeichnis).

Fertige MCP-Clients (Claude, `mcp-remote`, MCP-SDKs) erledigen all das
automatisch — die curl-Beispiele zeigen nur, was unter der Haube
passiert.

## 4. Authentifizierung

Der Endpoint akzeptiert **ausschließlich**:

- **Basic-Auth mit App-Passwort** (empfohlen):
  `Authorization: Basic base64("benutzer:app-passwort")`.
  App-Passwörter erzeugt jeder Nutzer selbst unter
  *Einstellungen → Sicherheit → App-Passwörter*.
- **OAuth2-Access-Token** als `Bearer` — nur wenn die oauth2-App
  installiert ist. Ein „nacktes" Bearer-Token ohne oauth2 versteht der
  Core-Login-Pfad **nicht**.

**Abgelehnt** werden reine Browser-Cookie-Sitzungen. Dadurch ist der
Endpoint gegen CSRF (Cross-Site Request Forgery) geschützt: keine im
Browser mitgeschickte Session kann heimlich MCP-Aufrufe auslösen.

## 5. Rechte-Modell

| Ebene | Regel |
|---|---|
| Identität | Jeder Aufruf läuft **als der authentifizierte Nutzer** — gleiche Datei-Sichtbarkeit und -Rechte wie in der Web-Oberfläche. |
| Lesen/Schreiben | Standardmäßig **read-only**. Schreib-Tools (`files_write`, `shares_create_*`, `tags_assign`, …) liefern einen klaren Fehler, bis ein Admin `enable_write` auf `yes` setzt. |
| Verwaltung | `users_*`- und `groups_*`-Tools verlangen zusätzlich **Admin-Rechte** des angemeldeten Nutzers — für alle anderen kommt ein klarer Fehler. |
| Grenzen | `files_read` liefert standardmäßig **1 MB** (per `max_bytes` bis maximal **10 MB**); Bilder (`files_view_image`) und Ressourcen sind auf **5 MB** begrenzt. `files_delete` löscht über die normale Datei-API — mit aktiver Papierkorb-App (Standard) landet die Datei im **Papierkorb**. |

Fehler erscheinen als reguläre MCP-Tool-Fehler, die das Modell lesen
und dem Nutzer erklären kann — kein stiller Abbruch.

## 6. Werkzeug-Referenz (Tools)

**Dateien (lesend):**

| Tool | Zweck |
|---|---|
| `files_list` | Ordnerinhalt auflisten (`path`) |
| `files_info` | Metadaten einer Datei/eines Ordners |
| `files_read` | Textdatei lesen (Standard 1 MB, per `max_bytes` bis 10 MB) |
| `files_view_image` | Bild als visuellen Inhalt zurückgeben (das Modell „sieht" das Bild, bis 5 MB) |
| `files_search` | Dateien nach Name suchen |

**Dateien (schreibend, benötigt `enable_write`):**

| Tool | Zweck |
|---|---|
| `files_write` | Textdatei anlegen/überschreiben |
| `files_mkdir` | Ordner anlegen |
| `files_move` / `files_copy` | verschieben / kopieren |
| `files_delete` | löschen (mit aktiver Papierkorb-App: in den Papierkorb) |

**Freigaben:**

| Tool | Zweck |
|---|---|
| `shares_list` | eigene Freigaben auflisten (lesend) |
| `shares_create_link` | öffentlichen Link erzeugen (schreibend) |
| `shares_create_user` | mit einem Benutzer teilen (schreibend) |
| `shares_delete` | Freigabe entfernen (schreibend) |

**Tags & Kommentare:**

| Tool | Zweck |
|---|---|
| `tags_list` | Tags einer Datei (lesend) |
| `tags_assign` / `tags_remove` | Tag setzen/entfernen (schreibend) |
| `comments_list` | Kommentare lesen |
| `comments_add` | Kommentar hinzufügen (schreibend) |

**Verwaltung (nur Administratoren):**

| Tool | Zweck |
|---|---|
| `users_list` / `users_get` | Benutzer auflisten / Details (lesend) |
| `users_create` / `users_disable` / `users_enable` / `users_set_quota` | Benutzer verwalten (schreibend) |
| `groups_list` / `groups_members` | Gruppen / Mitglieder (lesend) |
| `groups_add_member` / `groups_remove_member` | Mitglieder verwalten (schreibend) |

**Meta:**

| Tool | Zweck |
|---|---|
| `whoami` | Wer bin ich? (Nutzer, Anzeigename, Admin ja/nein) |
| `quota` | Speicherplatz/Belegung |
| `capabilities` | Server-Fähigkeiten |
| `ai_ask` | KI-Frage über die eigenen Dokumente — nur vorhanden, wenn die App `ai_documents` aktiv ist (siehe unten) |

Die genauen Parameter jedes Tools liefert `tools/list` als
JSON-Schema — MCP-Clients zeigen sie automatisch an.

## 7. Ressourcen (Dateien als Kontext)

Zusätzlich zu Tools exponiert der Server die Nutzer-Dateien als
MCP-**Ressourcen**, sodass Clients sie nativ durchstöbern und an den
Kontext anhängen können:

- `owncloud:///` — JSON-Auflistung des Wurzelordners; jeder Eintrag
  trägt eine `uri`, die direkt lesbar ist.
- `owncloud:///{pfad}` — beliebige Datei (Text oder binär) oder
  Ordnerliste per Pfad. **Wichtig:** Da URI-Template-Variablen nur ein
  Segment ohne Schrägstrich matchen, werden verschachtelte Pfade mit
  `%2F` statt `/` geschrieben (RFC 6570), z. B.
  `owncloud:///Dokumente%2Fbericht.txt`.

Binärdateien kommen als Base64, Text als UTF-8. Lesegrenze 5 MB,
strikt auf den Speicher des angemeldeten Nutzers beschränkt.

## 8. KI-Dokumentensuche `ai_ask` (optional)

Ist die optionale App **`ai_documents`** installiert und aktiv,
erscheint zusätzlich das Tool `ai_ask`: Retrieval-Augmented Generation
über die **indizierten Dokumente des Nutzers** (mit dessen Rechten),
Antwort inklusive zitierter Quellen.

Parameter: `question`, `scope` (`all` | `folder` | `selection`),
`path` (bei `folder`), `file_ids` (kommagetrennt, bei `selection`),
`mode` (`qa` | `summary` | `extract` | `report`).

`oco_mcp` hat **keine harte Abhängigkeit** auf ai_documents — auf
Servern ohne die App fehlt das Tool einfach (kein Fehler).

## 9. Clients anbinden

### Claude Desktop (über `mcp-remote`)

`claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "owncloud": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://cloud.example.com/apps/oco_mcp/mcp",
        "--header", "Authorization: Basic <base64 von benutzer:app-passwort>",
        "--transport", "http-only"
      ]
    }
  }
}
```

`--transport http-only` passt zum Server: er antwortet mit
Streamable-HTTP-JSON und hat bewusst keinen SSE-GET-Stream.
End-to-end mit `mcp-remote` verifiziert (dieselbe Brücke, die Claude
Desktop nutzt): initialize → notifications/initialized → tools/list →
tools/call.

### Claude Code (CLI)

```bash
claude mcp add owncloud \
  --transport http \
  https://cloud.example.com/apps/oco_mcp/mcp \
  --header "Authorization: Basic <base64 von benutzer:app-passwort>"
```

### Eigener Client / Skript

Beliebige MCP-SDKs (TypeScript, Python, PHP, …) mit Transport
„Streamable HTTP" funktionieren; alternativ direkt JSON-RPC per HTTP
wie in [Abschnitt 3](#3-wie-es-funktioniert-protokoll).

**Base64 für den Basic-Header erzeugen:**

```bash
echo -n "benutzer:app-passwort" | base64
```

## 10. Konfiguration

| Schlüssel (occ `config:app:set oco_mcp …`) | Werte | Standard | Wirkung |
|---|---|---|---|
| `enable_write` | `yes` / `no` | `no` | Schaltet alle schreibenden Tools frei (Datei-Schreiben, Freigaben, Tags/Kommentare schreiben, Benutzer-/Gruppenverwaltung). |

Mehr ist nicht zu konfigurieren — Authentifizierung und Rechte kommen
vollständig aus ownCloud selbst.

## 11. Sicherheit

- **Kein Cookie-Zugriff:** Browser-Sessions werden abgelehnt → kein CSRF.
- **App-Passwörter statt Login-Passwort:** einzeln widerrufbar unter
  *Einstellungen → Sicherheit*.
- **Read-only als Standard:** Schreiben ist eine bewusste
  Admin-Entscheidung (`enable_write`).
- **Nutzer-Scope:** Jedes Tool läuft mit der Identität und den Rechten
  des authentifizierten Nutzers — niemals darüber hinaus.
- **Vendor-Isolation:** Die gebündelten Bibliotheken (MCP-SDK u. a.)
  werden **lazy** nur für MCP-Requests geladen und enthalten per
  composer-`replace` **kein Guzzle und keine psr/*-Duplikate** — sie
  können Core-Bibliotheken konstruktionsbedingt nicht überschatten.
- **Limits:** Lesegrenzen (1 MB Standard / 10 MB Maximum bei `files_read`,
  5 MB bei Bildern und Ressourcen), Löschen geht bei aktiver Papierkorb-App
  in den Papierkorb, Sitzungen verfallen nach 1 Stunde.

## 12. Fehlerbehebung

| Symptom | Ursache / Lösung |
|---|---|
| `A valid session id is REQUIRED for non-initialize requests.` | Header `Mcp-Session-Id` fehlt. Erst `initialize` aufrufen, die ID aus dem Antwort-Header übernehmen und bei jeder Folge-Anfrage mitschicken. |
| HTTP 401 | Zugangsdaten falsch oder Bearer-Token ohne oauth2-App. Basic-Auth mit App-Passwort verwenden. |
| `MCP requires an app token or Basic auth …` | Anfrage kam mit Browser-Cookie-Session. Authorization-Header setzen. |
| `Write access is disabled on this MCP connection.` | Read-only-Modus. Admin: `occ config:app:set oco_mcp enable_write --value=yes`. |
| `This tool requires ownCloud administrator privileges.` | `users_*`/`groups_*` als Nicht-Admin aufgerufen. |
| Tool `ai_ask` fehlt | App `ai_documents` ist nicht installiert/aktiv — gewollt, kein Fehler. |
| Client meldet SSE-/Stream-Fehler | Transport auf HTTP(-only) stellen; der Server bietet keinen SSE-GET-Stream an. |

## 13. Eigene Tools ergänzen

Zwei Schritte, kein Framework-Kleber — ausführlich (mit Codebeispiel)
im englischen [README](README.md#extending-it--add-your-own-tool):

1. **Public-Methode** in einer Klasse unter `lib/Tools/` anlegen —
   Signatur + DocBlock werden automatisch zum Tool-Schema.
   Schreib-/Admin-Gating über `$this->assertWrite()` bzw.
   `$this->assertAdmin($this->isAdmin)`.
2. In `lib/Mcp/ServerFactory.php` registrieren:
   `$builder->addTool([FilesTool::class, 'countFiles'], 'files_count');`

Für Tools, die eine **optionale Fremd-App** anbinden, zeigt
`AiDocumentsTool` das Muster: Klassenname nur als String, `class_exists`-
Guard, Registrierung nur bei aktivierter App.

---

**Lizenz:** AGPL-3.0-only · **Autor:** BW-Tech GmbH ·
Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
