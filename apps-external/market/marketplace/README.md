# Local Marketplace Catalog

This directory is the bundled, self-contained app catalog used by the
BW-Tech fork of the Market plugin. There is no remote backend involved —
all metadata lives in three JSON files in this folder, and app archives
are downloaded directly from whatever URL you put under
`releases[].download` (HTTPS, GitHub releases, or `file://` paths).

## Files

| File              | Purpose                                                |
| ----------------- | ------------------------------------------------------ |
| `apps.json`       | List of apps with releases and metadata.               |
| `categories.json` | Categories shown in the sidebar.                       |
| `bundles.json`    | Optional app bundles. Use `[]` if not needed.          |

## Adding your own app

1. Build your app archive (`tar.gz`).
2. Upload it somewhere reachable by the ownCloud instance — a GitHub release,
   an internal HTTPS server, or a `file:///` path on the same host.
3. Add an entry to `apps.json`:

   ```json
   {
     "id": "myapp",
     "name": "My App",
     "summary": "One-line summary",
     "description": "Markdown description.",
     "categories": ["tools"],
     "rating": 0,
     "publisher": { "name": "Me", "url": "https://example.com", "isPagePublic": true },
     "downloadable": true,
     "marketplace": "https://example.com/myapp",
     "screenshots": [{ "url": "https://example.com/myapp.png" }],
     "releases": [
       {
         "version": "1.0.0",
         "platformMin": "10",
         "platformMax": "10.99",
         "license": "AGPL-3.0",
         "created": 1714694400000,
         "download": "https://github.com/me/myapp/releases/download/v1.0.0/myapp-1.0.0.tar.gz"
       }
     ]
   }
   ```

4. Open the Market UI in your ownCloud admin panel and click **Clear cache** in the
   sidebar — your new app appears immediately.

## Schema reference

### `apps.json` — array of objects

| Field          | Type     | Required | Description                                          |
| -------------- | -------- | -------- | ---------------------------------------------------- |
| `id`           | string   | yes      | App-id; must match `appinfo/info.xml` of the package |
| `name`         | string   | yes      | Display name                                         |
| `summary`      | string   | no       | Short tagline                                        |
| `description`  | string   | no       | Long description (Markdown)                          |
| `categories`   | string[] | yes      | Must reference ids from `categories.json`            |
| `rating`       | number   | no       | 0–5; controls the star widget                        |
| `publisher`    | object   | no       | `{ name, url, isPagePublic }`                        |
| `marketplace`  | string   | no       | URL shown by the **View in marketplace** button      |
| `downloadable` | bool     | yes      | If `false`, the install button is disabled           |
| `screenshots`  | object[] | no       | `[ { url } ]`                                        |
| `releases`     | object[] | yes      | At least one release; see below                      |

### `releases[]`

| Field          | Type   | Required | Description                                |
| -------------- | ------ | -------- | ------------------------------------------ |
| `version`      | string | yes      | Semver version of this release             |
| `platformMin`  | string | yes      | Minimum ownCloud version                   |
| `platformMax`  | string | yes      | Maximum ownCloud version                   |
| `license`      | string | yes      | SPDX license id                            |
| `created`      | number | no       | Unix epoch ms                              |
| `download`     | string | yes      | HTTPS URL or `file:///abs/path/to/app.tgz` |

### `categories.json` — array of objects

| Field          | Type   | Description                                 |
| -------------- | ------ | ------------------------------------------- |
| `id`           | string | Used in `apps[].categories`                 |
| `translations` | object | `{ <locale>: { name } }` — `en` is required |

### `bundles.json`

Either `[]` or an array of bundles `{ id, name, products: [<app>...] }` where
each product has the same shape as an entry in `apps.json`. Most installs do
not need bundles — just keep this file as `[]`.

## Pointing somewhere else

If you prefer hosting the catalog outside the plugin tree, set this in
`config/config.php`:

```php
'appstoreurl' => 'file:///srv/owncloud-catalog',
```

The plugin then reads `apps.json`, `categories.json`, and `bundles.json` from
that directory instead of the bundled one.

If you want a remote HTTPS marketplace, set `appstoreurl` to its base URL
(e.g. `https://marketplace.owncloud.com`) and the plugin falls back to the
classic `/api/v1/...` endpoints.
