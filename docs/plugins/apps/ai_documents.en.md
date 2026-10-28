# AI Documents

AI Documents analyzes documents with an AI service that you choose. The app
assigns a category, writes a summary, and detects deadlines, risks, and details
such as people, companies, amounts, or IBANs. In the Files app, it adds an
assistant for questions about your own documents and an analysis per file. Only
accounts that can read the original file see a result.

!!! note "Availability"
    Public in the Marketplace, no API key needed. App ID `ai_documents`,
    version 1.15.1. Name in the Market: "AI Documents".

!!! warning "Data protection"
    The app sends document text to the services for text AI and embeddings, and
    page images of scanned documents to the vision service. The assistant can
    also search the web. If a service runs outside your network, this data
    leaves the instance. Before you enable the app, check whether this is
    compatible with your data protection requirements. What goes where is listed
    under [Data flow](#datenfluss).

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4. The app cannot be enabled on PHP 8.5. |
| AI service | an OpenAI-compatible API for chat completions and embeddings, such as OpenAI, Azure OpenAI, Ollama, or your own service. For scanned documents, also a model that reads images. |
| Background jobs | System cron. Analysis runs in the background. |
| Text extraction | an Apache Tika server, for example the `apache/tika` container on port 9998. Without Tika, office files and email stay without text. The app reads PDF files without Tika as well, using `pdftotext`. |
| Programs on the server | `pdftotext` and `pdftoppm` from the `poppler-utils` package. For local text recognition, also `ocrmypdf` and `tesseract` with language packs. |
| PHP functions | `exec` and `shell_exec` must not be listed in `disable_functions`, otherwise PDF text and text recognition fail. |
| Optional | Qdrant or OpenSearch as an external vector index for large collections |

On Debian and Ubuntu:

```bash
sudo apt install poppler-utils
# only for local text recognition
sudo apt install ocrmypdf tesseract-ocr tesseract-ocr-deu tesseract-ocr-eng
```

The app analyzes PDF, DOCX, XLSX, PPTX, ODT, ODS, ODP, TXT, HTML, CSV, XML, EML,
PNG, JPEG, and TIFF.

## Installation

The app starts working right away. During the installation it queues up to 500
existing files, and from the next cron run on it also analyzes new uploads.
Until you have set up the AI connection, the documents go to the prefilled
addresses from [1. Connect AI](#ki-verbinden). Turn the analysis off before the
installation and turn it back on after the setup:

```bash
# 1. Turn the analysis off for now. You can set this value before the installation.
sudo -u www-data php8.4 occ config:app:set ai_documents ai_enabled --value false

# 2. Install the app from the Marketplace and enable it
sudo -u www-data php8.4 occ market:install ai_documents
```

Step 2 also works in the Market in the web interface; step 1 needs the command
line. Then set up the AI connection (next section). Finally, select **Enable AI
Documents** under "2. Analyze documents" and click **Save settings**.

## Setup

The settings are under **Settings → Admin → Additional** in the **AI
Documents** section. At the top, three labels show the status: **Plugin active**
or **Plugin off**, **Auto analysis** or **Manual only**, **AI Assist** or **Chat
off**. Four steps follow.

**Save settings** in step "2. Analyze documents" saves the fields of all steps.
It skips empty fields, so the old value stays in place. Stored keys are shown
masked only. If you leave a key field empty, the stored key remains in use. To
remove a stored value, use the command line; the default then applies again:

```bash
sudo -u www-data php8.4 occ config:app:delete ai_documents brave_api_key
```

### 1. Connect AI {#ki-verbinden}

| Block | Purpose | Fields |
| --- | --- | --- |
| **Text AI** | summaries, categories, entities, assistant | **Provider** (OpenAI, Azure OpenAI, Ollama, **Custom API**), **API URL**, **API Key**, **Model** |
| **Vision OCR** | scanned PDFs and images without a text layer | **Vision API URL**, **Vision API Key**, **Vision model**, **Enable OCR**, **Use Vision for OCR** |
| **Semantic search** | embeddings for sources and ranking in the assistant | **Embedding API URL**, **Embedding API Key**, **Embedding model** |

1. Enter the address and key for each block. All three address fields take the
   base address of the service, for example `https://api.openai.com/v1` or, for
   Ollama, `http://ollama.example.com:11434/v1`. The app adds
   `/chat/completions` or `/embeddings` itself. A full address with that ending
   works as well.
2. **Load models** fetches the list from the service. Select a model.
3. Check the connection with **Test Text AI**, **Test Vision OCR**, and **Test
   Embeddings**.
4. **Save settings**.

The address and model fields are prefilled when you install the app. Enter the
values of your own service in all three blocks, even if you use the same service
for everything. If you leave a prefilled address in place, the app uses it. The
**Vision API Key** field is empty by default. If it stays empty, the app sends
the key of the text AI to the **Vision API URL**.

### 2. Analyze documents

| Field | Value | Default | Effect |
| --- | --- | --- | --- |
| **Enable AI Documents** | `ai_enabled` | on | main switch for analysis, search, and background jobs |
| **Analyze uploads automatically** | `auto_analyze_enabled` | on | When cleared, the background jobs pause. Files are then only analyzed on request through **AI summary**. |
| **Enable KI Assist** | `prompt_enabled` | on | questions about documents in the assistant |
| **PDF OCR pages** | `vision_ocr_pdf_max_pages` | `30` | Vision OCR reads at most this many pages per PDF. `0` means all pages. |

Under **Advanced settings**:

| Field | Value | Default | Effect |
| --- | --- | --- | --- |
| **Max file size (MB)** | `max_file_size_mb` | `100` | larger files are not analyzed |
| **Chunk size** | `chunk_size_tokens` | `512` | length of the text sections used for search |
| **Web search route** | `web_search_mode` | `auto` | **AI gateway first, direct search as fallback**, **AI gateway only (required route)**, or **Direct search only (no gateway)**. "AI gateway" is the service under **API URL**. With the first two routes, every search query goes to this service first. If it cannot search the web itself, select **Direct search only (no gateway)**. |
| **Web search API key (Brave, optional)** | `brave_api_key` | empty | With a key, the direct search asks the Brave Search API first. Without a key or without results, it searches DuckDuckGo and then Wikipedia. |
| **Assistant requests per user and day (0 = unlimited)** | `agent_daily_turn_limit` | `300` | daily quota per account. The window starts with the first request and lasts 24 hours. |
| **Web requests per user and day (0 = unlimited)** | `agent_daily_egress_limit` | `100` | daily quota for web searches, fetching web pages, and weather queries |
| **Vector index** | `vector_backend` | `internal` | **Internal fallback**, Qdrant, or OpenSearch, together with **Vector API URL**, **Vector API key**, and **Vector index name** (default `ai_documents`) |
| **OCR provider** | `ocr_provider` | `vision` | **Vision OCR** or **Local OCR** with `ocrmypdf` and `tesseract` |
| **OCR languages** | `ocr_languages` | `deu+eng` | languages for local text recognition |
| **Enable Apache Tika**, **Tika URL** | `tika_enabled`, `tika_url` | on, `http://localhost:9998` | check with **Test Tika** |
| **Backfill existing files** | `backfill_enabled` | on | keeps queuing existing files |
| **LLM requests/min**, **Embedding requests/min** | `llm_requests_per_minute`, `embedding_requests_per_minute` | `0` | upper limit per minute, `0` means none |
| **Alert after errors**, **Error window minutes**, **Alert cooldown minutes** | `alert_threshold`, `alert_window_minutes`, `alert_cooldown_minutes` | `5`, `10`, `30` | This many analysis errors within the window send a [notification](notifications.en.md) to the `admin` group. The alert then pauses for the cooldown time. **Alert after errors** set to `0` turns the alert off. |

With **Backfill user**, **Backfill path**, **Backfill limit**, **Force
re-analysis**, **OCR candidates only**, and **Start backfill now**, you queue
files once, the same way as with `occ ai-documents:backfill`.

More switches in this area control search and the assistant. All of them are on
by default:

| Field | Value |
| --- | --- |
| **Analyze complete documents in sections** | `hierarchical_analysis_enabled` |
| **Enable hybrid full-text and semantic search** | `hybrid_search_enabled` |
| **Rerank the best search results** | `reranker_enabled` |
| **Learn ranking from user feedback** | `learning_reranker_enabled` |
| **Enable confirmed user memory** | `memory_enabled` |
| **Enable Knowledge Graph** | `knowledge_graph_enabled` |
| **Execute approved actions in the background** | `async_actions_enabled` |
| **Show exact evidence and coverage** | `evidence_enabled` |
| **Enable Smart Action Inbox** | `smart_inbox_enabled` |
| **Enable version and duplicate intelligence** | `version_intelligence_enabled` |
| **Use context-aware suggestions** | `context_suggestions_enabled` |
| **Route tasks to specialized models** | `model_routing_enabled` |

With **Model routing policy** (**Balanced**, **Lowest cost**, **Fastest**,
**Highest quality**) and the fields **Fast model**, **Balanced model**,
**Quality model**, and **Economy model**, you spread tasks across different
models. Empty fields mean the model of the text AI.

Three values exist only on the command line:

| Value | Default | Effect |
| --- | --- | --- |
| `web_search_enabled` | `1` | `0` turns off web search, fetching web pages, and weather queries of the assistant |
| `scope_folders` | empty | folders relative to the root folder of each account, separated by commas. Only files in these folders are analyzed. Matching is case-sensitive. Empty means all folders. |
| `embedding_dimensions` | `1024` | vector length for Qdrant and OpenSearch. It must match the embedding model. |

```bash
sudo -u www-data php8.4 occ config:app:set ai_documents web_search_enabled --value 0
sudo -u www-data php8.4 occ config:app:set ai_documents scope_folders --value "Contracts,Invoices"
```

After switching to Qdrant or OpenSearch, the new index only contains files that
are analyzed afterwards. `occ ai-documents:backfill --force` adds the existing
files, split with `--user` or `--path` if there are many. This costs tokens
again.

### 3. Results and health

This step shows the progress of the queue and the areas **Recently processed**,
**Statistics**, and **Recent errors**.

### 4. Index quality and evaluation

At the top you see the index coverage: how many documents are fully analyzed,
outdated, or failed. In the **Evaluation catalog** you add test cases, each with
**Test name**, **Question**, **Expected file IDs**, **Expected answer terms**,
and **Forbidden answer terms**. **Run all enabled tests** measures whether the
search finds the expected files. The hallucination rate is a fixed check based
on forbidden terms and invalid citations, not a review of the content.

### Background jobs

| Job | Interval | Task |
| --- | --- | --- |
| `OCA\AiDocuments\BackgroundJob\AnalyzeJob` | every 5 minutes | works through the queue, at most 240 seconds per run (`analyze_job_budget_seconds`, at most 600) |
| `OCA\AiDocuments\BackgroundJob\BackfillJob` | every 15 minutes | queues existing files, 200 per run. After a full pass over all accounts it pauses for 6 hours. |

If system cron runs every 15 minutes as usual, the app also analyzes only every
15 minutes. To shorten the wait, run `cron.php` every 5 minutes from the
crontab of `www-data`:

```
*/5 * * * * /usr/bin/php8.4 -f /var/www/owncloud.online/cron.php
```

### Command line

```bash
# queue existing files, by default at most 500 per call
sudo -u www-data php8.4 occ ai-documents:backfill

# only one account and one folder, more files per call
sudo -u www-data php8.4 occ ai-documents:backfill --user alice --path /Invoices --limit 2000

# analyze files again that were already analyzed (costs tokens again)
sudo -u www-data php8.4 occ ai-documents:backfill --force

# only PDF files and images for text recognition
sudo -u www-data php8.4 occ ai-documents:backfill --ocr

# token usage of the instance, also for a period or as JSON
sudo -u www-data php8.4 occ ai-documents:token-usage
sudo -u www-data php8.4 occ ai-documents:token-usage --from 2026-10-01 --to 2026-10-31 --json
```

`ai-documents:backfill` only queues files; the analysis happens in the next cron
run. One call queues at most 10,000 files. `ai-documents:token-usage` lists
requests and tokens by service, task, and model.

## Usage

| Where in the Files app | Entry | Function |
| --- | --- | --- |
| left navigation | **AI Assistant** | opens the assistant for all readable files |
| action menu of a file | **AI summary** | analyzes the file right away and shows the result as a card in the chat |
| action menu of a file | **Ask AI about this file** | chat with this file as context |
| action menu of a folder | **Ask AI** | chat about the contents of the folder |
| sidebar of a file | **AI Analysis** tab | classification with confidence, summary, deadlines, risks, key figures, and extracted entities |

The assistant has the tabs **Chat**, **Tasks**, **Versions**, **Briefing**, and
**Intelligence**. In the chat you choose the context: **All readable files**,
**Current folder**, or **Selected files**. Answers name their sources. **Open
at page** and **Open file** jump to the passage.

- **AI summary** finishes large files in the background: more than 20 MB, more
  than about 36,000 characters of text, or more than 5 scanned pages. The view
  updates itself.
- **Analyze again** requires write permission on the file.
- **Tasks** lists detected deadlines, tasks, and risks. The app does not carry
  out any of them. **Done** and **Dismiss** apply to your own account only.
- When the assistant suggests a change, such as a share, a move, or a rename, it
  only runs after **Yes, execute**. You can undo shares, moves, and renames in
  the **Action history** under **Intelligence** with **Rollback**. Files the
  assistant deletes go to **Deleted files** if the `files_trashbin` app is
  enabled.
- The assistant acts with the permissions of the logged-in account. For
  administrators, this includes creating accounts and groups and installing or
  disabling apps from the Market, each after confirmation.

## Limits and notes

### Data flow {#datenfluss}

| Data | Destination |
| --- | --- |
| document text and questions | text AI (**API URL**) |
| text sections | embedding service (**Embedding API URL**), with an external vector index also Qdrant or OpenSearch |
| page images of scanned PDFs, images | vision service (**Vision API URL**) |
| all analyzed files except images | Tika server (**Tika URL**) while **Enable Apache Tika** is on |
| search terms of the assistant | depending on **Web search route**, the AI service, DuckDuckGo, and Wikipedia, with a key the Brave Search API |
| requests for web pages the assistant opens | the website in question |
| locations in weather questions | Open-Meteo |

`web_search_enabled` set to `0` turns off the last three rows.

### Other limits

- The app cannot be limited to groups.
  `occ app:enable ai_documents --groups …` stops with "ai_documents can't be
  enabled for groups." Use `scope_folders` to limit the analysis to certain
  folders.
- Vision OCR reads every page as an image. **PDF OCR pages** set to `0` and a
  backfill over all existing files create many requests.
  `occ ai-documents:token-usage` shows the usage. **LLM requests/min** and
  **Embedding requests/min** slow down the analysis; the daily quotas only
  apply to the assistant.
- The app cannot read password-protected PDF and office files.
- The results come from an AI model and can be wrong. Check important details
  in the source.
- When a file is deleted, the app only marks its analysis as deleted. Summary,
  entities, and text sections stay in the `oc_ai_doc_*` tables, also after an
  account is deleted. Keep this in mind for data deletion requirements.
- The app stores conversations per account until the account deletes them.
- `occ app:disable ai_documents` disables the app. Settings and tables are kept.
- With the [MCP Connector](oco_mcp.en.md), MCP clients query the documents
  through the `ai_ask` tool.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| No analysis | Cron does not run, **Enable AI Documents** or **Analyze uploads automatically** is off | check `occ background:queue:status`, select both options |
| **Load models** returns no list | Wrong address or key, service not reachable | check the values, use the test buttons |
| Error 401 or 403 from the AI service | Wrong key or key without permission | enter a new key |
| Scanned PDFs stay without text | Vision OCR is off, `pdftoppm` is missing, or the page limit is reached | turn on Vision OCR, install `poppler-utils`, check **PDF OCR pages** |
| Office files stay without text | Tika is not reachable, or **Enable Apache Tika** is off | start Tika, select the option, **Test Tika** |
| An account sees no result | File not analyzed yet, no read permission, or folder outside `scope_folders` | check the status in the **AI Analysis** tab |
| "You have reached the daily limit of assistant requests." | Daily quota used up | raise the value under **Assistant requests per user and day** |
| The app cannot be enabled, `occ` shows "PHP with a version lower than 8.4 is required." | The server runs PHP 8.5 | use PHP 8.4 |
| High costs | Vision OCR over many pages, backfill over all existing files | lower **PDF OCR pages**, limit requests per minute, set `scope_folders` |

The app writes its messages to the
[server log](../../administration/logging.en.md) under `ai_documents`.
