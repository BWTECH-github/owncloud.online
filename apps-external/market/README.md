# Market — BW-Tech / owncloud.online fork

Marketplace / AppStore integration for owncloud.online, modernised for **PHP 8.4** and
shipped with a **fully self-contained local app catalog** (no external
marketplace required) plus a **refreshed UI** with dark-mode support and a
**live search**.

This is a community fork maintained by **BW-Tech GmbH** for
[owncloud.online](https://owncloud.online). Original code by
**ownCloud GmbH** (AGPL-3.0).

---

## Features

- **Local-first catalog** — apps live in `marketplace/apps.json` inside the
  plugin directory; no remote backend needed. Add an entry, point `download`
  to a GitHub release URL, you're done.
- **Drop-in remote mode** — set `appstoreurl` in `config.php` to use the
  classic owncloud.online marketplace API or your own HTTPS catalog instead.
- **PHP 8.4** — constructor property promotion, `readonly`, `#[\Override]`,
  typed properties, `match`, native first-class function syntax.
- **Modern UI** — refreshed tile cards, custom CSS variables, automatic
  dark mode via `prefers-color-scheme`, smoother animations.
- **Live search** — type to filter the visible app list across name,
  summary, description and category.
- **OCC commands** — install, uninstall, list and upgrade apps from the
  command line.
- **Background updates** — daily check for available updates, with
  per-group notifications.

## Requirements

- owncloud.online Server **10.x** (10.11+ recommended)
- **PHP 8.4** or newer
- Composer 2.x (only for development / building the dist artifact)
- Node.js 20 (only for building the JS bundle)

## Installation

```bash
cd /var/www/owncloud/apps
git clone https://github.com/BWTECH-github/market.git
cd market
composer install --no-dev
sudo chown -R www-data:www-data .
sudo -u www-data php /var/www/owncloud/occ app:enable market
```

If you prefer the prebuilt artifact, download the latest release archive from
the [Releases page](https://github.com/BWTECH-github/market/releases)
and extract it into `apps/market/`.

## Configuration

Everything is optional — out of the box the bundled local catalog at
`apps/market/marketplace/apps.json` is used.

Add to `config/config.php` to change the source:

```php
<?php
$CONFIG = [
    // (1) bundled catalog (default)              — `appstoreurl` unset or "local"
    // (2) catalog at a custom file-system path
    'appstoreurl' => 'file:///srv/owncloud-catalog',

    // (3) remote HTTPS marketplace (classic mode)
    // 'appstoreurl' => 'https://marketplace.owncloud.online',
    // 'marketplace.key' => 'your-api-key',
    // 'marketplace.ca' => '/etc/ssl/custom-ca.pem',

    // notification recipients
    // 'has_internet_connection' => true,  // disable to suppress remote calls
];
```

| Key                       | Default   | Description                                                       |
| ------------------------- | --------- | ----------------------------------------------------------------- |
| `appstoreurl`             | `local`   | `local` for the bundled catalog, `file://` for a local directory, or an HTTPS URL of a remote owncloud.online marketplace. |
| `marketplace.key`         | unset     | API key sent as `Authorization: apikey: …` for remote marketplaces. |
| `marketplace.ca`          | unset     | Path to a custom CA bundle for remote TLS validation.             |
| `marketplace.send_host`   | `true`    | If `false`, the instance's host name is not sent to the marketplace (see below). |
| `has_internet_connection` | `true`    | If `false`, remote calls are blocked. Local catalog mode ignores this. |

### Data sent to the marketplace

When an API key is configured, requests to the marketplace also identify
the instance:

| Header                 | Value                                                                 |
| ---------------------- | --------------------------------------------------------------------- |
| `X-Market-Instance`    | `instanceid` from `config.php`                                        |
| `X-Market-Server-Host` | host name from `overwrite.cli.url`, otherwise the first usable entry of `trusted_domains` (host only, no scheme, port or path; `localhost` and `127.x.x.x` are skipped); left out with `marketplace.send_host` = `false` |
| `X-Market-Platform`    | server version, e.g. `11.0.25.0`                                      |
| `X-Market-Client`      | `market/<version of this app>`                                        |
| `User-Agent`           | `owncloud.online-market/<version of this app>`                        |

These headers only go to the origin of `appstoreurl` (same scheme, host and
port), and only together with the key. Nothing of this is sent without a
key, in local catalog mode, or to download URLs on other hosts. If the
marketplace redirects to another origin, the key and these headers are
dropped before the redirect is followed. Values that do not have the
expected format are left out.

A host name can relate to a person (for example
`cloud.firstname-lastname.de`). Set `marketplace.send_host` to `false` to
leave it out; the instance ID is always sent, because without it the
marketplace cannot tell instances apart.

### Adding your own app to the local catalog

Edit `marketplace/apps.json` (see `marketplace/README.md` for the full schema):

```json
[
  {
    "id": "myapp",
    "name": "My App",
    "summary": "One-line summary",
    "categories": ["tools"],
    "publisher": { "name": "Me", "url": "https://example.com", "isPagePublic": true },
    "downloadable": true,
    "screenshots": [{ "url": "https://example.com/myapp.png" }],
    "releases": [
      {
        "version": "1.0.0",
        "platformMin": "10",
        "platformMax": "10.99",
        "license": "AGPL-3.0",
        "download": "https://github.com/me/myapp/releases/download/v1.0.0/myapp-1.0.0.tar.gz"
      }
    ]
  }
]
```

Then click **Clear cache** in the Market sidebar — the new app appears
immediately. The download URL can be HTTPS, a `file:///` path, or a path
relative to the catalog directory.

## OCC commands

| Command                                    | What it does                                                     |
| ------------------------------------------ | ---------------------------------------------------------------- |
| `occ market:list`                          | List all app ids known to the configured catalog.                |
| `occ market:install <id> [<id> …]`         | Install one or more apps by id (latest minor release).           |
| `occ market:install --local /path/to.tgz`  | Install an app from a local tar.gz package.                      |
| `occ market:uninstall <id>`                | Uninstall an app.                                                |
| `occ market:upgrade [<id> …]`              | Upgrade selected apps to the latest minor release.               |
| `occ market:upgrade --all`                 | Upgrade every installed app that has an update.                  |
| `occ market:upgrade --major <id>`          | Allow major-version upgrades for `<id>`.                         |
| `occ market:upgrade --list`                | Show available updates without installing anything.              |

| Argument / option | Type     | Description                                       |
| ----------------- | -------- | ------------------------------------------------- |
| `<id> …`          | string[] | One or more app ids; mutually exclusive with `--local`. |
| `--local, -l`     | string[] | Path to one or more local `.tar.gz` packages.     |
| `--major`         | flag     | Allow upgrading across a major version boundary.  |
| `--list`          | flag     | List updates only, do not install.                |
| `--all`           | flag     | Apply to every installed app with an update.      |

## Daily usage

1. Open owncloud.online and go to **Apps → Market**.
2. Use the search box at the top of the sidebar to find apps.
3. Click an app, then **Install**.
4. Updates appear under **Updates** in the sidebar with a counter badge;
   click an entry to read release notes and update.

The UI follows the operating-system colour scheme automatically — there's
nothing to toggle for dark mode.

## Troubleshooting

| Symptom                                                      | Cause                                                              | Fix                                                                                            |
| ------------------------------------------------------------ | ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| "Local marketplace catalog file is missing"                  | `appstoreurl` points at a directory without `apps.json`            | Place `apps.json` (and `categories.json`, `bundles.json`) at that path.                        |
| "Local marketplace catalog is invalid JSON"                  | Syntax error in `apps.json`                                        | Validate with `python -m json.tool < apps.json` or `jq . apps.json`.                            |
| "Installing apps is not supported" notice                    | Apps directory is not writable by the web user                     | `chown -R www-data:www-data apps/` or fix the cluster's shared-storage mount.                  |
| App appears in catalog but **Install** fails with 404        | `download` URL is wrong                                            | Open the URL in a browser; if it's a private GitHub release, attach the CDN-style download URL. |
| Categories are empty                                         | `marketplace/categories.json` not loaded yet (cache)               | Click **Clear cache** in the sidebar.                                                          |
| Dark mode looks broken on light background                   | Browser does not honour `prefers-color-scheme`                     | Force it with `<html data-theme="dark">` via a userstyle / extension.                          |
| `composer install` fails with "requires php >=8.4"           | Older PHP                                                          | Install PHP 8.4 (`apt install php8.4 php8.4-cli php8.4-curl`) or change interpreter.            |
| Update notifications never arrive                            | Background job hasn't run                                          | `occ background:cron` or check that cron / system-cron is configured.                          |

## Development

```bash
make build-dev               # build JS bundle once
npm run dev                  # webpack --watch
make test-php-style          # php-cs-fixer dry-run
make test-php-unit           # phpunit
```

CI runs the same checks on every push and pull request — see
`.github/workflows/`.

## Attribution

This is a fork of [`owncloud/market`](https://github.com/BWTECH-github/market)
(© ownCloud GmbH, AGPL-3.0). Modifications by **BW-Tech GmbH** for
[owncloud.online](https://owncloud.online). The licence remains AGPL-3.0.

## License

AGPL-3.0 — see [`LICENSE`](LICENSE).
