# MCP Connector for owncloud.online (`oco_mcp`)

> **Deutsch?** Ausführliche deutsche Dokumentation: [docs/plugins/mcp-connector.md](../../docs/plugins/mcp-connector.md)

Turns owncloud.online into a **Model Context Protocol (MCP)** server so AI
assistants (Claude Desktop, editors, agents, custom clients) can work with a
user's files, shares, tags, comments and — for admins — users and groups.

Everything runs **as the authenticated owncloud.online user** and is subject to the
same permissions as the web UI. The connection is **read-only by default**.

- **Endpoint:** `POST /apps/oco_mcp/mcp` (Streamable HTTP, JSON-RPC 2.0)
- **Auth:** HTTP **Basic auth** with the owncloud.online login name and an
  **app/device token** as password. Every request revalidates these credentials;
  a browser cookie or a merely present `Authorization` header is insufficient.
- **Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).**

## Enabling

```bash
occ app:enable oco_mcp
# read-only by default; write + management tools need BOTH Schalter:
occ config:app:set oco_mcp enable_write --value=yes
# Pflicht: die Gruppen benennen, die schreiben duerfen
occ config:app:set oco_mcp write_groups --value='mcp-writers,admin'
```

Im Standardmodus werden schreibende Tools nicht in `tools/list` veröffentlicht.
Requests sind auf 2 MiB begrenzt; `files_write` akzeptiert maximal 1 MiB Inhalt.

**Achtung:** `enable_write=yes` allein genügt nicht. Schreibrechte bekommt
nur, wer in einer der unter `write_groups` genannten Gruppen steht
(kommagetrennte Gruppen-IDs); eine leere Liste erlaubt **niemandem** etwas.
Früher galt eine leere Liste als „instanzweit erlaubt" — damit konnte jedes
App- oder Gerätetoken schreiben und jedes Admin-Token die Benutzer- und
Gruppenverwaltung bedienen. Wer eine Instanz mit `enable_write=yes` und ohne
`write_groups` betreibt, muss die Gruppen jetzt nachtragen; bis dahin sind
die Schreib-Tools aus, und das Protokoll weist einmal täglich darauf hin.

## Tool catalog

| Tool | Read/Write | Notes |
|------|-----------|-------|
| `files_list`, `files_info`, `files_read`, `files_search` | read | browse & read the user's files |
| `files_view_image` | read | return an image file as visual content the model can see (≤ 5 MB) |
| `files_write`, `files_mkdir`, `files_move`, `files_copy`, `files_delete` | write | delete moves to trash |
| `shares_list` | read | shares created by the user |
| `shares_create_link`, `shares_create_user`, `shares_delete` | write | public links & user shares |
| `tags_list`, `tags_assign`, `tags_remove` | read / write | system tags on files |
| `comments_list`, `comments_add` | read / write | file comments |
| `users_list`, `users_get` | read (admin) | user management |
| `users_create`, `users_disable`, `users_enable`, `users_set_quota` | write (admin) | |
| `groups_list`, `groups_members` | read (admin) | |
| `groups_add_member`, `groups_remove_member` | write (admin) | |
| `whoami`, `quota`, `capabilities` | read | identity & limits |
| `ai_ask` | read | RAG question-answering over the user's documents — **only present when the optional `ai_documents` app is enabled** |

Tool visibility follows the connection's rights: when write access is disabled,
the write tools are not advertised in `tools/list` at all; the admin-only
`users_*` / `groups_*` tools are only advertised to administrators. Calling a
tool that is not exposed returns the standard JSON-RPC `-32601 (method not
found)` error. As a defence in depth every write/admin method still re-checks
the flag internally and refuses with a clear message if reached directly.

## Resources

Besides tools, the server exposes the user's files as MCP **resources**, so
clients can browse and attach them to context natively:

- `owncloud:///` — a JSON listing of the user's root folder; each entry carries
  a `uri` you can read.
- `owncloud:///{path}` — read any file (text or binary) or a folder listing by
  path. Because URI-template variables match a single non-slash segment, nested
  paths percent-encode `/` as `%2F` (RFC 6570), e.g.
  `owncloud:///Documents%2Freport.txt`.

Binary files come back as base64 blobs; text as UTF-8. Reads are bounded to 5 MB
and confined to the user's own storage.

## AI document search (`ai_ask`)

When the optional `ai_documents` app is installed and enabled, an `ai_ask` tool
appears. It runs retrieval-augmented generation over the user's indexed
documents (as that user, with their permissions) and returns an answer plus
cited sources. Parameters: `question`, `scope` (`all` | `folder` | `selection`),
`path` (for `folder`), `file_ids` (comma-separated, for `selection`), and `mode`
(`qa` | `summary` | `extract` | `report`). oco_mcp keeps **no hard dependency**
on ai_documents — the tool is absent on servers without it.

## Connecting a client (example: Claude Desktop via `mcp-remote`)

```json
{
  "mcpServers": {
    "owncloud": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://cloud.example.com/apps/oco_mcp/mcp",
        "--header", "Authorization: Basic <base64 of username:app-password>",
        "--transport", "http-only"
      ]
    }
  }
}
```

Create the app password in owncloud.online under **Settings → Security → App passwords**
and send it via HTTP **Basic** auth (`base64("username:app-password")`). Bearer
authentication is not accepted by this endpoint. `--transport http-only` matches
this server: it speaks Streamable HTTP JSON responses and deliberately has no
SSE GET stream.

The normal account password is deliberately rejected. This keeps two-factor
authentication intact and lets users revoke the MCP connection independently.

Verified end-to-end with `mcp-remote` (the same bridge Claude Desktop uses):
initialize → notifications/initialized → tools/list → tools/call all pass.

## Architecture (one request, start to finish)

1. `McpController::handle()` (route `/mcp`) authenticates the user, refuses
   cookie-only requests, and **lazily** loads the app's `vendor/` (so a normal
   owncloud.online request never loads it and can never shadow a core library).
2. `ServerFactory::build()` constructs every tool with the acting user baked in,
   hands them to the MCP SDK through a tiny PSR-11 `InstanceContainer`, and
   registers each as an MCP tool. Input schemas are generated from each method's
   PHP signature and DocBlock.
3. The request is bridged to the SDK's `StreamableHttpTransport` via a PSR-7
   request built on core's `guzzlehttp/psr7`, and the PSR-7 response is returned
   as an owncloud.online `DataDisplayResponse`.

### Dependency isolation

`composer.json` uses `replace` to exclude every package core already provides
(all `psr/*`, `guzzlehttp/psr7`, `guzzlehttp/promises`, `doctrine/deprecations`),
so `vendor/` bundles **only** what core lacks (the MCP SDK + `symfony/uid`,
`opis/json-schema`, PSR server-handler/middleware, …) and contains **no Guzzle
at all** — eliminating the vendor-shadow class of bug by construction.

## Extending it — add your own tool

Two steps, no framework glue.

**1. Add a public method** to a tool class in `lib/Tools/` (or a new class). The
method's typed parameters and DocBlock become the tool's input schema and
description automatically:

```php
/**
 * Count the files in a directory (recursively).
 *
 * @param string $path Directory path relative to the user's root.
 * @return array The total file count.
 */
public function countFiles(string $path = '/'): array {
    $node = $this->getNode($path);
    // ... walk $node ...
    return ['path' => $path, 'files' => $count];
}
```

Throw `\Mcp\Exception\ToolCallException('message')` for user-facing errors
(the message reaches the model); call `$this->assertWrite()` /
`$this->assertAdmin($this->isAdmin)` to gate it.

**2. Register it** in `lib/Mcp/ServerFactory.php`:

```php
$builder->addTool([FilesTool::class, 'countFiles'], 'files_count');
```

That's it — the tool appears in `tools/list` and is callable immediately.

To add a **whole new tool group**, create `lib/Tools/MyTool.php`, add it to the
`InstanceContainer` map in `ServerFactory::build()` (constructed with whatever
OCP services it needs, wired in `lib/AppInfo/Application.php`), and register its
methods.

### Optional-app tools (pattern used by `ai_ask`)

`AiDocumentsTool` shows how to bridge an **optional** app without taking a hard
dependency on it: reference the foreign app only by string class name, guard
with `class_exists()`, resolve its service through its own app container
(`(new \OCA\Foo\AppInfo\Application())->getContainer()->query(...)`), and register
the tool in `ServerFactory` only when `IAppManager::isEnabledForUser()` is true.
On servers without that app the tool simply never appears — no fatal, no error.

Good further candidates: trash/versions restore, full-text search, calendar and
contacts (CardDAV), and generated document previews as image content.
