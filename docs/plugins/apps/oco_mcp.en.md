# MCP Connector

The MCP Connector turns an owncloud.online instance into a server for the Model
Context Protocol (MCP). AI assistants, editors, and your own agents use it to
list, read, and search files. Once an administrator turns on write access, they
can also write and share. Every call runs with the permissions of the logged-in
account, and the connection is read-only by default.

!!! note "Availability"
    Included in the core. The server ships the app in `apps/oco_mcp`, and it is
    disabled by default. App ID `oco_mcp`, version 1.0.3 in owncloud.online
    11.0.25. It is not in the Marketplace.

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 or later |
| HTTPS | strongly recommended. The client sends the login name and app password with every request. |
| Client | an MCP client with the "Streamable HTTP" transport. Clients that only know the older "HTTP+SSE" transport cannot connect. |
| Account | an app password. The endpoint rejects the account's regular password. |

## Installation

The app ships with the server, so you only enable it:

```bash
sudo -u www-data php8.4 occ app:enable oco_mcp
```

The endpoint is `https://cloud.example.com/apps/oco_mcp/mcp`. If the addresses
of your instance contain `index.php`, it is
`https://cloud.example.com/index.php/apps/oco_mcp/mcp`.

## Setup

### Create an app password

Each account creates its own app password:

1. Open **Settings → Personal → Security**, section **App passwords / tokens**.
2. Enter a name under **App name**, for example "MCP laptop", and click **Create
   new app passcode**.
3. Copy **Username** and **Password / Token** into the client. The page shows
   the password only this once. Close it with **Done**.

The client logs in with HTTP Basic: login name and app password, joined by a
colon and Base64-encoded. Create the header value like this:

```bash
# -w0 prevents line breaks in the output; on macOS, leave out -w0
printf '%s' 'alice:<app-password>' | base64 -w0
# header: Authorization: Basic <output>
```

The trash icon (**Disconnect**) next to the entry revokes the app password.
Access ends at once, because the server checks the credentials again on every
request.

### Allow write access {#schreibzugriff}

By default, the server only offers read-only tools. Write tools, including the
ones that change accounts and groups, need both values:

```bash
sudo -u www-data php8.4 occ config:app:set oco_mcp enable_write --value yes
sudo -u www-data php8.4 occ config:app:set oco_mcp write_groups --value "mcp-writers,admin"
```

| Value | Possible values | Default | Effect |
| --- | --- | --- | --- |
| `enable_write` | `yes`, `no` | `no` | turns on the write tools |
| `write_groups` | group IDs, separated by commas | empty | Only members of these groups get the write tools. An empty list gives nobody write access. |

Since owncloud.online 11.0.14, an empty `write_groups` means "nobody". Before
that, it meant "all accounts". If `enable_write` is `yes` and `write_groups` is
empty, the server writes a warning to the log once a day: "MCP write tools are
unavailable: "enable_write" is set but "write_groups" is empty …".

Most clients load the list of tools only when they connect. Reconnect the
client after a change.

The app has no other settings. Login and permissions come from owncloud.online
itself.

### Connect a client

Clients that support "Streamable HTTP" only need the endpoint address and the
header `Authorization: Basic …`.

Desktop clients that only start MCP servers as local programs connect to the
endpoint through the `mcp-remote` bridge, an npm package. This requires Node.js
with `npx`. An entry in the `mcpServers` section of the client configuration
looks like this:

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
        "AUTH_HEADER": "Basic <Base64 of alice:app-password>"
      }
    }
  }
}
```

The header goes into `args` without a space, the value with the space into
`env`. Some clients on Windows do not pass spaces in `args` on correctly.
`--transport http-only` matches the server: it answers with JSON and offers no
event stream through GET.

On Windows, create the Base64 value in PowerShell like this:

```powershell
[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes('alice:<app-password>'))
```

The page [MCP Connector (`oco_mcp`)](../mcp-connector.en.md) describes more
clients and the protocol flow in detail.

### Test the connection

A session starts with `initialize`. The response contains the header
`Mcp-Session-Id`, which every later request must send.

```bash
curl -s -D - -u 'alice:<app-password>' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' \
  https://cloud.example.com/apps/oco_mcp/mcp

curl -s -u 'alice:<app-password>' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'Mcp-Session-Id: <ID from the first response>' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
  https://cloud.example.com/apps/oco_mcp/mcp
```

The server supports the protocol versions `2024-11-05`, `2025-03-26`,
`2025-06-18`, and `2025-11-25` and answers with the version the client asks for.
A session expires after one hour without a request. A `DELETE` on the endpoint
with the session ID ends it at once.

## Usage

### Tools

| Tool | Access | Purpose |
| --- | --- | --- |
| `files_list`, `files_info`, `files_read`, `files_search` | read | list a folder, details of a file, read a file, search by name |
| `files_view_image` | read | pass an image to the model, up to 5 MiB |
| `files_write`, `files_mkdir`, `files_move`, `files_copy`, `files_delete` | write | write a file (up to 1 MiB), create a folder, move, copy, delete |
| `shares_list` | read | your own shares |
| `shares_create_link`, `shares_create_user`, `shares_delete` | write | create a public link, share with an account, remove your own share |
| `tags_list` | read | tags of a file |
| `tags_assign`, `tags_remove` | write | add or remove a tag |
| `comments_list` | read | comments on a file |
| `comments_add` | write | add a comment |
| `users_list`, `users_get`, `groups_list`, `groups_members` | read, administrators only | view accounts and groups |
| `users_create`, `users_disable`, `users_enable`, `users_set_quota`, `groups_add_member`, `groups_remove_member` | write, administrators only | manage accounts and groups |
| `whoami`, `quota`, `capabilities` | read | logged-in account, storage space, server capabilities |
| `ai_ask` | read | question about your own documents, only with the [AI Documents](ai_documents.en.md) app enabled |

The server does not list tools that an account may not use. `tools/list`
returns the exact parameters.

- `files_read` returns up to 1 MiB by default, up to 10 MiB with `max_bytes`.
- `files_list` returns at most 1000 entries per call, `shares_list` at most 500.
- `files_delete` deletes through the regular file API. If the `files_trashbin`
  app is enabled, the file goes to **Deleted files**.
- Shares are created through the regular sharing logic. The rules under
  **Settings → Admin → Sharing** apply here too.

### Files as resources

The server also offers the account's files as MCP resources:

- `owncloud:///` returns the root folder as a JSON list. Every entry has a
  `uri` that can be read directly.
- `owncloud:///{path}` reads a file or a folder. Slashes in nested paths are
  written as `%2F`, for example `owncloud:///Documents%2Freport.txt`.

Text comes as UTF-8, binary data as Base64, each up to 5 MiB.

### Questions about documents

If the [AI Documents](ai_documents.en.md) app is enabled, the `ai_ask` tool
appears. It answers a question from the account's analyzed documents and names
the sources. Parameters: `question`, `scope` (`all`, `folder`, or `selection`),
`path` for `folder`, `file_ids` (separated by commas) for `selection`, and
`mode` (`qa`, `summary`, `extract`, or `report`). Without AI Documents the tool
is missing, which is not an error.

## Limits and notes

- The endpoint only accepts HTTP Basic with an app password. It rejects browser
  sessions (cookies), bearer tokens, and the regular password. This way a
  two-factor requirement cannot be bypassed.
- Failed attempts slow down further attempts from the same address and for the
  same login name. They appear in the log as `MCP login failed: '<login>'
  (Remote IP: '<address>')`. A fail2ban filter for "Login failed" does not catch
  these lines, so add "MCP login failed" to it. Behind a reverse proxy, the
  brute-force protection needs correct `trusted_proxies`, see
  [Security and setup warnings](../../administration/security-hardening.en.md).
- The server answers with 403 if the host from the `Origin` header, or from the
  `Host` header when there is no `Origin`, is not listed in `trusted_domains` or
  `overwritehost` and is not `localhost`, `127.0.0.1`, or `[::1]`. Entries with
  `*` in `trusted_domains` do not count here. Clients that run in the browser on
  another website therefore cannot connect.
- A request can be at most 2 MiB.
- The server rejects paths with `.` or `..`.
- Errors from AI Documents only reach the client as a general message. The
  cause is in the [server log](../../administration/logging.en.md) under `oco_mcp`.
- Sessions are stored as files in the data directory under `oco_mcp-sessions`.
- The [README of the app](https://github.com/BWTECH-github/owncloud.online/blob/main/apps/oco_mcp/README.md)
  in the server repository describes how to add your own tools.

## Troubleshooting

| Message or symptom | Cause | Fix |
| --- | --- | --- |
| HTTP 401, "MCP requires HTTP Basic authentication with an owncloud.online app token." | The `Authorization` header is missing or not Basic, or a proxy in front removes it | send Basic with login name and app password. Check whether WebDAV works with the same app password. |
| HTTP 401, "Invalid MCP credentials." | regular password instead of an app password, app password revoked, or wrong login name | create a new app password |
| HTTP 403, "Forbidden: Invalid Host header." or "Forbidden: Invalid Origin header." | Host name or origin not in `trusted_domains`, or only covered by an entry with `*` | call the endpoint through a host name that is listed explicitly |
| HTTP 413, "MCP request body is too large." | Request larger than 2 MiB | send smaller requests |
| "A valid session id is REQUIRED for non-initialize requests." | The `Mcp-Session-Id` header is missing | send `initialize` first, then send the ID from its response with every request |
| Error `-32601`, for example "Tool not found: "files_write"." | The account has no write access, or it was removed after connecting | set `enable_write` and `write_groups`, add the account to one of the groups, reconnect the client (see [Allow write access](#schreibzugriff)) |
| Error `-32601` for `users_*` and `groups_*` | The account is not an administrator | connect with an administrator account |
| The `ai_ask` tool is missing | AI Documents is not enabled | intended; enable AI Documents if needed |
| "AI query failed. Ask an administrator to check the server log." | Error in AI Documents | server log, entries under `oco_mcp` |
| "Relative path segments (".", "..") are forbidden." | Path with `.` or `..` | give paths relative to the account's root folder, without `.` and `..` |
| The client reports an error with the event stream | The client expects a stream through GET | set the transport to HTTP without SSE, with `mcp-remote` through `--transport http-only` |
| HTTP 500, "Internal MCP error." | Exception in the server | server log, entries under `oco_mcp` |
