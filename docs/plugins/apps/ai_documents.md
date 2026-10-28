# AI Documents

AI Documents analysiert Dokumente mit einem KI-Dienst, den Sie selbst
festlegen. Die App bestimmt die Kategorie, schreibt eine Zusammenfassung und
erkennt Fristen, Risiken und Angaben wie Personen, Firmen, Beträge oder IBAN. In
der Dateien-App kommen ein Assistent für Fragen an die eigenen Dokumente und
eine Analyse je Datei hinzu. Ein Ergebnis sieht nur, wer die Ursprungsdatei
lesen darf.

!!! note "Verfügbarkeit"
    Öffentlich im Marketplace, ohne API-Schlüssel. App-ID `ai_documents`,
    Version 1.15.1. Name im Markt: „AI Documents“.

!!! warning "Datenschutz"
    Die App schickt Dokumenttexte an die Dienste für Text-KI und Embeddings und
    Seitenbilder gescannter Dokumente an den Vision-Dienst. Der Assistent kann
    außerdem im Web suchen. Liegt ein Dienst außerhalb Ihres Netzes, verlassen
    diese Daten die Instanz. Klären Sie vor dem Einschalten, ob das mit Ihren
    Datenschutzvorgaben vereinbar ist. Welche Daten wohin gehen, steht unter
    [Datenfluss](#datenfluss).

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4. Unter PHP 8.5 lässt sich die App nicht einschalten. |
| KI-Dienst | eine Schnittstelle im Format von OpenAI für Chat Completions und Embeddings, etwa OpenAI, Azure OpenAI, Ollama oder ein eigener Dienst. Für gescannte Dokumente zusätzlich ein Modell, das Bilder liest. |
| Hintergrund-Jobs | System-Cron. Analysiert wird im Hintergrund. |
| Textextraktion | ein Server mit Apache Tika, zum Beispiel der Container `apache/tika` auf Port 9998. Ohne Tika bleiben Office-Dateien und E-Mails ohne Text. PDF liest die App auch ohne Tika mit `pdftotext`. |
| Programme auf dem Server | `pdftotext` und `pdftoppm` aus dem Paket `poppler-utils`. Für lokale Texterkennung zusätzlich `ocrmypdf` und `tesseract` mit Sprachpaketen. |
| PHP-Funktionen | `exec` und `shell_exec` dürfen nicht in `disable_functions` stehen, sonst fallen PDF-Text und Texterkennung aus. |
| Optional | Qdrant oder OpenSearch als externer Vektorindex für große Bestände |

Unter Debian und Ubuntu:

```bash
sudo apt install poppler-utils
# nur für lokale Texterkennung
sudo apt install ocrmypdf tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng
```

Analysiert werden PDF, DOCX, XLSX, PPTX, ODT, ODS, ODP, TXT, HTML, CSV, XML,
EML, PNG, JPEG und TIFF.

## Installation

Die App legt sofort los. Bei der Installation reiht sie bis zu 500 vorhandene
Dateien ein, ab dem nächsten Cron-Lauf analysiert sie auch neue Uploads.
Solange Sie die KI-Verbindung nicht eingerichtet haben, gehen die Dokumente
dabei an die vorbelegten Adressen aus [1. KI verbinden](#ki-verbinden).
Schalten Sie die Analyse deshalb vor der Installation ab und erst nach der
Einrichtung wieder ein:

```bash
# 1. Analyse vorerst aus. Der Wert lässt sich schon vor der Installation setzen.
sudo -u www-data php8.4 occ config:app:set ai_documents ai_enabled --value false

# 2. App aus dem Marketplace installieren und einschalten
sudo -u www-data php8.4 occ market:install ai_documents
```

Über den Markt in der Weboberfläche geht Schritt 2 ebenso, Schritt 1 braucht
die Kommandozeile. Danach richten Sie die KI-Verbindung ein (nächster
Abschnitt), setzen zum Schluss unter „2. Dokumente analysieren“ den Haken
**AI Documents aktivieren** und klicken auf **Einstellungen speichern**.

## Einrichtung

Die Einstellungen liegen unter **Einstellungen → Administration → Zusätzlich**
im Abschnitt **AI Documents**. Oben zeigen drei Statusanzeigen den Zustand:
**Plugin aktiv** oder **Plugin aus**, **Automatische Analyse** oder **Nur
manuell**, **KI-Assist** oder **Chat aus**. Darunter folgen vier Schritte.

Gespeichert wird mit **Einstellungen speichern** im Schritt „2. Dokumente
analysieren“. Der Knopf sichert die Felder aller Schritte. Leere Felder
übernimmt er nicht, der alte Wert bleibt dann stehen. Gespeicherte Schlüssel
zeigt die Seite nur maskiert. Bleibt ein Schlüsselfeld leer, gilt der
gespeicherte Schlüssel weiter. Einen gespeicherten Wert entfernen Sie deshalb
nur auf der Kommandozeile, danach gilt wieder der Standard:

```bash
sudo -u www-data php8.4 occ config:app:delete ai_documents brave_api_key
```

### 1. KI verbinden {#ki-verbinden}

| Block | Wofür | Felder |
| --- | --- | --- |
| **Text-KI** | Zusammenfassungen, Kategorien, Entitäten, Assistent | **Anbieter** (OpenAI, Azure OpenAI, Ollama, **Eigene API**), **API-URL**, **API-Schlüssel**, **Modell** |
| **Vision-OCR** | gescannte PDFs und Bilder ohne Textebene | **Vision-API-URL**, **Vision-API-Schlüssel**, **Vision-Modell**, **OCR aktivieren**, **Vision für OCR verwenden** |
| **Semantische Suche** | Embeddings für Quellen und Ranking im Assistenten | **Embedding-API-URL**, **Embedding-API-Schlüssel**, **Embedding-Modell** |

1. Adresse und Schlüssel je Block eintragen. Alle drei Adressfelder nehmen die
   Basisadresse des Dienstes, etwa `https://api.openai.com/v1` oder bei Ollama
   `http://ollama.example.com:11434/v1`. Die App hängt `/chat/completions`
   beziehungsweise `/embeddings` selbst an. Eine vollständige Adresse mit
   diesem Ende funktioniert ebenso.
2. **Modelle laden** fragt die Liste beim Dienst ab. Modell auswählen.
3. Mit **Text-KI testen**, **Vision-OCR testen** und **Embeddings testen** die
   Verbindung prüfen.
4. **Einstellungen speichern**.

Die Felder für Adressen und Modelle sind bei der Installation vorbelegt.
Tragen Sie in allen drei Blöcken die Werte Ihres eigenen Dienstes ein, auch wenn
Sie für alles denselben Dienst nutzen. Eine vorbelegte Adresse, die Sie stehen
lassen, benutzt die App. Das Feld **Vision-API-Schlüssel** ist ab Werk leer.
Bleibt es leer, schickt die App den Schlüssel der Text-KI an die
**Vision-API-URL**.

### 2. Dokumente analysieren

| Feld | Wert | Standard | Wirkung |
| --- | --- | --- | --- |
| **AI Documents aktivieren** | `ai_enabled` | an | Hauptschalter für Analyse, Suche und Hintergrund-Jobs |
| **Uploads automatisch analysieren** | `auto_analyze_enabled` | an | Ohne Haken ruhen die Hintergrund-Jobs. Analysiert wird dann nur auf Anforderung über **KI-Zusammenfassung**. |
| **KI-Assist aktivieren** | `prompt_enabled` | an | Fragen an Dokumente im Assistenten |
| **PDF-OCR-Seiten** | `vision_ocr_pdf_max_pages` | `30` | Höchstens so viele Seiten je PDF liest Vision-OCR. `0` heißt alle Seiten. |

Unter **Erweiterte Einstellungen**:

| Feld | Wert | Standard | Wirkung |
| --- | --- | --- | --- |
| **Maximale Dateigröße (MB)** | `max_file_size_mb` | `100` | größere Dateien bleiben unanalysiert |
| **Chunk-Größe** | `chunk_size_tokens` | `512` | Länge der Textabschnitte für die Suche |
| **Websuche-Weg** | `web_search_mode` | `auto` | **Zuerst KI-Gateway, Direktsuche nur als Ausweichweg**, **Nur KI-Gateway (Pflichtweg)** oder **Nur Direktsuche (ohne Gateway)**. „KI-Gateway“ ist der Dienst unter **API-URL**. Mit den ersten beiden Wegen geht jede Suchanfrage zuerst an diesen Dienst. Kann er nicht selbst im Web suchen, wählen Sie **Nur Direktsuche (ohne Gateway)**. |
| **Websuche-API-Schlüssel (Brave, optional)** | `brave_api_key` | leer | Mit Schlüssel fragt die Direktsuche zuerst die Brave Search API. Ohne Schlüssel oder ohne Treffer sucht sie über DuckDuckGo und zuletzt über Wikipedia. |
| **Assistenten-Anfragen pro Nutzer und Tag (0 = unbegrenzt)** | `agent_daily_turn_limit` | `300` | Tageskontingent je Konto. Das Zeitfenster beginnt mit der ersten Anfrage und dauert 24 Stunden. |
| **Web-Anfragen pro Nutzer und Tag (0 = unbegrenzt)** | `agent_daily_egress_limit` | `100` | Tageskontingent für Websuche, Abruf von Webseiten und Wetterabfragen |
| **Vektorindex** | `vector_backend` | `internal` | **Interner Fallback**, Qdrant oder OpenSearch, dazu **Vektor-API-URL**, **Vektor-API-Schlüssel** und **Name des Vektorindex** (Standard `ai_documents`) |
| **OCR-Anbieter** | `ocr_provider` | `vision` | **Vision-OCR** oder **Lokale OCR** mit `ocrmypdf` und `tesseract` |
| **OCR-Sprachen** | `ocr_languages` | `deu+eng` | Sprachen für die lokale Texterkennung |
| **Apache Tika aktivieren**, **Tika-URL** | `tika_enabled`, `tika_url` | an, `http://localhost:9998` | Prüfen mit **Tika testen** |
| **Bestehende Dateien nachträglich analysieren** | `backfill_enabled` | an | reiht vorhandene Dateien laufend ein |
| **LLM-Anfragen/Min.**, **Embedding-Anfragen/Min.** | `llm_requests_per_minute`, `embedding_requests_per_minute` | `0` | Obergrenze je Minute, `0` heißt keine |
| **Alarm nach Fehlern**, **Fehlerfenster in Minuten**, **Alarm-Cooldown in Minuten** | `alert_threshold`, `alert_window_minutes`, `alert_cooldown_minutes` | `5`, `10`, `30` | So viele Analysefehler im Fenster lösen eine [Benachrichtigung](notifications.md) an die Gruppe `admin` aus, danach ruht der Alarm für die Cooldown-Zeit. **Alarm nach Fehlern** auf `0` schaltet den Alarm ab. |

Mit **Backfill-Benutzer**, **Backfill-Pfad**, **Backfill-Limit**, **Erneute
Analyse erzwingen**, **Nur OCR-Kandidaten** und **Backfill jetzt starten** reihen
Sie einmalig Dateien ein, wie mit `occ ai-documents:backfill`.

Weitere Schalter in diesem Bereich steuern Suche und Assistent. Ab Werk sind
alle an:

| Feld | Wert |
| --- | --- |
| **Vollständige Dokumente abschnittsweise analysieren** | `hierarchical_analysis_enabled` |
| **Hybride Volltext- und semantische Suche aktivieren** | `hybrid_search_enabled` |
| **Beste Suchergebnisse neu bewerten** | `reranker_enabled` |
| **Ranking aus Benutzerfeedback lernen** | `learning_reranker_enabled` |
| **Bestätigtes Benutzergedächtnis aktivieren** | `memory_enabled` |
| **Knowledge Graph aktivieren** | `knowledge_graph_enabled` |
| **Freigegebene Aktionen im Hintergrund ausführen** | `async_actions_enabled` |
| **Genaue Belege und Abdeckung anzeigen** | `evidence_enabled` |
| **Smart Action Inbox aktivieren** | `smart_inbox_enabled` |
| **Versions- und Dublettenerkennung aktivieren** | `version_intelligence_enabled` |
| **Kontextbezogene Vorschläge verwenden** | `context_suggestions_enabled` |
| **Aufgaben an spezialisierte Modelle leiten** | `model_routing_enabled` |

Mit **Modell-Routing-Richtlinie** (**Ausgewogen**, **Niedrigste Kosten**, **Am
schnellsten**, **Höchste Qualität**) und den Feldern **Schnelles Modell**,
**Ausgewogenes Modell**, **Qualitätsmodell** und **Sparmodell** verteilen Sie
Aufgaben auf verschiedene Modelle. Leere Felder bedeuten das Modell der Text-KI.

Drei Werte gibt es nur auf der Kommandozeile:

| Wert | Standard | Wirkung |
| --- | --- | --- |
| `web_search_enabled` | `1` | `0` schaltet Websuche, Abruf von Webseiten und Wetterabfragen des Assistenten ab |
| `scope_folders` | leer | Ordner relativ zum Stammordner jedes Kontos, durch Kommas getrennt. Nur Dateien darin werden analysiert. Groß- und Kleinschreibung zählt. Leer heißt alle Ordner. |
| `embedding_dimensions` | `1024` | Länge der Vektoren für Qdrant und OpenSearch. Sie muss zum Embedding-Modell passen. |

```bash
sudo -u www-data php8.4 occ config:app:set ai_documents web_search_enabled --value 0
sudo -u www-data php8.4 occ config:app:set ai_documents scope_folders --value "Verträge,Rechnungen"
```

Nach dem Wechsel auf Qdrant oder OpenSearch enthält der neue Index nur Dateien,
die danach analysiert werden. Den Bestand bringt
`occ ai-documents:backfill --force` hinein, bei vielen Dateien aufgeteilt mit
`--user` oder `--path`. Das kostet erneut Token.

### 3. Ergebnisse und Status

Dieser Schritt zeigt den Fortschritt der Warteschlange und die Bereiche
**Zuletzt verarbeitet**, **Statistiken** und **Aktuelle Fehler**.

### 4. Indexqualität und Evaluation

Oben steht die Indexabdeckung: wie viele Dokumente vollständig, veraltet oder
fehlerhaft analysiert sind. Im **Evaluationskatalog** legen Sie Testfälle an,
jeweils mit **Testname**, **Frage**, **Erwartete Datei-IDs**, **Erwartete
Antwortbegriffe** und **Verbotene Antwortbegriffe**. **Alle aktiven Tests
ausführen** misst, ob die Suche die erwarteten Dateien findet. Die
Halluzinationsrate ist ein fester Prüfwert aus verbotenen Begriffen und
ungültigen Quellenangaben, keine inhaltliche Bewertung.

### Hintergrund-Jobs

| Job | Takt | Aufgabe |
| --- | --- | --- |
| `OCA\AiDocuments\BackgroundJob\AnalyzeJob` | alle 5 Minuten | arbeitet die Warteschlange ab, je Lauf höchstens 240 Sekunden (`analyze_job_budget_seconds`, höchstens 600) |
| `OCA\AiDocuments\BackgroundJob\BackfillJob` | alle 15 Minuten | reiht vorhandene Dateien ein, 200 je Lauf. Nach einem vollständigen Durchgang über alle Konten ruht er 6 Stunden. |

Läuft der System-Cron wie üblich alle 15 Minuten, analysiert die App auch nur
alle 15 Minuten. Für kürzere Wartezeiten rufen Sie `cron.php` alle 5 Minuten
auf, in der Crontab von `www-data`:

```
*/5 * * * * /usr/bin/php8.4 -f /var/www/owncloud.online/cron.php
```

### Kommandozeile

```bash
# vorhandene Dateien einreihen, Standard höchstens 500 je Aufruf
sudo -u www-data php8.4 occ ai-documents:backfill

# nur ein Konto und ein Ordner, mehr Dateien je Aufruf
sudo -u www-data php8.4 occ ai-documents:backfill --user alice --path /Rechnungen --limit 2000

# schon analysierte Dateien neu analysieren (kostet erneut Token)
sudo -u www-data php8.4 occ ai-documents:backfill --force

# nur PDF und Bilder für die Texterkennung
sudo -u www-data php8.4 occ ai-documents:backfill --ocr

# Tokenverbrauch der Instanz, auch für einen Zeitraum oder als JSON
sudo -u www-data php8.4 occ ai-documents:token-usage
sudo -u www-data php8.4 occ ai-documents:token-usage --from 2026-10-01 --to 2026-10-31 --json
```

`ai-documents:backfill` reiht nur ein, analysiert wird beim nächsten
Cron-Lauf. Ein Aufruf reiht höchstens 10 000 Dateien ein.
`ai-documents:token-usage` listet Anfragen und Token nach Dienst, Aufgabe und
Modell.

## Nutzung

| Ort in der Dateien-App | Eintrag | Funktion |
| --- | --- | --- |
| linke Navigation | **KI-Assistent** | öffnet den Assistenten für alle lesbaren Dateien |
| Aktionsmenü einer Datei | **KI-Zusammenfassung** | analysiert die Datei sofort und zeigt das Ergebnis als Karte im Chat |
| Aktionsmenü einer Datei | **KI zu dieser Datei fragen** | Chat mit dieser Datei als Kontext |
| Aktionsmenü eines Ordners | **KI fragen** | Chat über den Inhalt des Ordners |
| Seitenleiste einer Datei | Reiter **KI-Analyse** | Klassifizierung mit Konfidenz, Zusammenfassung, Fristen, Risiken, Kennzahlen und erkannte Entitäten |

Der Assistent hat die Reiter **Chat**, **Aufgaben**, **Versionen**,
**Briefing** und **Intelligenz**. Im Chat wählen Sie den Kontext: **Alle
lesbaren Dateien**, **Aktueller Ordner** oder **Markierte Dateien**. Antworten
nennen ihre Quellen. **An Seite öffnen** und **Datei öffnen** springen zur
Fundstelle.

- Große Dateien analysiert **KI-Zusammenfassung** im Hintergrund zu Ende: mehr
  als 20 MB, mehr als rund 36 000 Zeichen Text oder mehr als 5 Scan-Seiten. Die
  Ansicht aktualisiert sich selbst.
- **Erneut analysieren** braucht Schreibrecht auf die Datei.
- **Aufgaben** listet erkannte Fristen, Aufgaben und Risiken. Die App führt
  davon nichts aus. **Erledigt** und **Ausblenden** gelten nur für das eigene
  Konto.
- Schlägt der Assistent eine Änderung vor, etwa eine Freigabe, ein Verschieben
  oder Umbenennen, läuft sie erst nach **Ja, ausführen**. Freigaben, Verschieben
  und Umbenennen nehmen Sie im **Aktionsverlauf** unter **Intelligenz** mit
  **Rückgängig machen** zurück. Was der Assistent löscht, landet unter
  **Gelöschte Dateien**, sofern die App `files_trashbin` aktiv ist.
- Der Assistent handelt mit den Rechten des angemeldeten Kontos. Administratoren
  können über ihn auch Konten und Gruppen anlegen und Apps aus dem Markt
  installieren oder abschalten, jeweils nach Bestätigung.

## Grenzen und Hinweise

### Datenfluss {#datenfluss}

| Daten | Ziel |
| --- | --- |
| Dokumenttext und Fragen | Text-KI (**API-URL**) |
| Textabschnitte | Embedding-Dienst (**Embedding-API-URL**), mit externem Vektorindex auch Qdrant oder OpenSearch |
| Seitenbilder gescannter PDFs, Bilder | Vision-Dienst (**Vision-API-URL**) |
| alle analysierten Dateien außer Bildern | Tika-Server (**Tika-URL**), solange **Apache Tika aktivieren** an ist |
| Suchbegriffe des Assistenten | je nach **Websuche-Weg** der KI-Dienst, DuckDuckGo und Wikipedia, mit Schlüssel die Brave Search API |
| Abrufe von Webseiten, die der Assistent öffnet | die jeweilige Website |
| Ortsangaben bei Wetterfragen | Open-Meteo |

`web_search_enabled` auf `0` schaltet die letzten drei Zeilen ab.

### Weitere Grenzen

- Die App lässt sich nicht auf Gruppen beschränken.
  `occ app:enable ai_documents --groups …` bricht mit „ai_documents can't be
  enabled for groups.“ ab. Die Analyse begrenzen Sie mit `scope_folders` auf
  bestimmte Ordner.
- Vision-OCR liest jede Seite als Bild. **PDF-OCR-Seiten** auf `0` und ein
  Backfill über den ganzen Bestand erzeugen viele Anfragen. Den Verbrauch zeigt
  `occ ai-documents:token-usage`. **LLM-Anfragen/Min.** und
  **Embedding-Anfragen/Min.** bremsen die Analyse, die Tageskontingente gelten
  nur für den Assistenten.
- Passwortgeschützte PDF- und Office-Dateien kann die App nicht lesen.
- Die Ergebnisse stammen von einem KI-Modell und können falsch sein. Prüfen Sie
  wichtige Angaben an der Quelle.
- Wird eine Datei gelöscht, markiert die App ihre Analyse nur als gelöscht.
  Zusammenfassung, Entitäten und Textabschnitte bleiben in den Tabellen
  `oc_ai_doc_*`, auch nach dem Löschen eines Kontos. Berücksichtigen Sie das bei
  Löschpflichten.
- Unterhaltungen speichert die App je Konto, bis das Konto sie löscht.
- `occ app:disable ai_documents` schaltet die App ab. Einstellungen und
  Tabellen bleiben erhalten.
- Mit dem [MCP Connector](oco_mcp.md) fragen MCP-Clients die Dokumente über das
  Werkzeug `ai_ask` ab.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Keine Analyse | Cron läuft nicht, **AI Documents aktivieren** oder **Uploads automatisch analysieren** ist aus | `occ background:queue:status` prüfen, Haken setzen |
| **Modelle laden** bringt keine Liste | Adresse oder Schlüssel falsch, Dienst nicht erreichbar | Werte prüfen, Test-Knöpfe nutzen |
| Fehler 401 oder 403 vom KI-Dienst | Schlüssel falsch oder ohne Berechtigung | neuen Schlüssel eintragen |
| Gescannte PDFs bleiben ohne Text | Vision-OCR aus, `pdftoppm` fehlt oder Seitenlimit erreicht | Vision-OCR einschalten, `poppler-utils` installieren, **PDF-OCR-Seiten** prüfen |
| Office-Dateien bleiben ohne Text | Tika nicht erreichbar oder **Apache Tika aktivieren** aus | Tika starten, Haken setzen, **Tika testen** |
| Ein Konto sieht kein Ergebnis | Datei noch nicht analysiert, kein Leserecht oder Ordner außerhalb von `scope_folders` | Status im Reiter **KI-Analyse** prüfen |
| „Du hast das Tageslimit für Assistenten-Anfragen erreicht.“ | Tageskontingent verbraucht | Wert unter **Assistenten-Anfragen pro Nutzer und Tag** erhöhen |
| Die App lässt sich nicht einschalten, `occ` meldet „PHP with a version lower than 8.4 is required.“ | Server läuft mit PHP 8.5 | PHP 8.4 verwenden |
| Hohe Kosten | Vision-OCR über viele Seiten, Backfill über den ganzen Bestand | **PDF-OCR-Seiten** senken, Anfragen pro Minute begrenzen, `scope_folders` setzen |

Meldungen der App stehen im
[Serverprotokoll](../../administration/logging.md) unter `ai_documents`.
