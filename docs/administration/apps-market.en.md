# Managing apps

Part of the functionality of owncloud.online lives in apps: the trash bin,
versions, external storage, and sharing are apps, just like extensions you
install later. An app gets into an instance in three ways: through the Market
app in the web interface, as a package by hand, and with `occ`. This page also
covers how to enable and disable apps, how to check version and compatibility,
and what happens to an app's data when you remove it.

There are two kinds of apps:

* **Shipped apps** are listed in `core/shipped.json`. They can be disabled but
  not removed. Three of them, `files`, `dav`, and `federatedfilesharing`, cannot
  even be disabled; `occ app:disable` stops with "can't be disabled." for them.
* **Apps installed later** come from the Marketplace or as a package and usually
  live in `apps-external/`. Only these can be deleted again.

The list in `core/shipped.json` and the release archive do not quite match:
`encryption` and `configreport` are on the list but not in the archive; they
come from the Marketplace. They still count as shipped and can only be
disabled. Conversely, `comments`, `systemtags`, and `oco_mcp` are in the archive
under `apps/` but not on the list.

## List installed apps

```bash
sudo -u www-data php8.4 occ app:list
```

The output is split into *enabled* and *disabled* and shows the directory of
each app; enabled apps also show their version, disabled apps only with
`--disabled`. Useful filters:

```bash
# enabled apps only
sudo -u www-data php8.4 occ app:list --enabled

# short list, name and version only
sudo -u www-data php8.4 occ app:list --minimal

# only apps that are not listed in core/shipped.json (opposite: --shipped true)
sudo -u www-data php8.4 occ app:list --shipped false

# directory of a single app
sudo -u www-data php8.4 occ app:getpath files_external
```

In the web interface, **Settings → Admin → Apps** shows the same list under the
heading **Apps Management**.

## Option 1: the Market app in the web interface

The Market is an app itself (`market`). When it is enabled, administrators see
the **Market** entry in the app menu at the top left.

The left column guides you through the catalog:

| Entry | Content |
| --- | --- |
| *Discover* | start page of the catalog |
| *Installed Apps* | all apps present in this instance with version, author, state, and compatibility; filters *All*, *Enabled*, *Disabled* |
| *App Bundles* | packages that combine several apps |
| *Updates* | apps for which the catalog has a newer version |
| *Categories* | the catalog by category |
| *Add API Key* | key for apps that are only visible with an API key, see [Apps that require an API key](#apps-that-require-an-api-key) |
| *Clear cache* | discards the cached catalog response |

<figure markdown="span">
  ![Market app, Discover view with app tiles and categories](../assets/screenshots/en/market.png)
  <figcaption>In the Market app you search for and install apps from the Marketplace.</figcaption>
</figure>

You install an app from its detail page (*Details*, then *install*). After that
it is in the writable app directory (see
[Write permissions](#write-permissions-on-the-app-directory)) and enabled.

The catalog that the Market app queries is set in `appstoreurl`. Without an
entry, it is `https://marketplace.owncloud.online`. Two other values are
possible:

```bash
# catalog built into the Market app, no network access
sudo -u www-data php8.4 occ config:system:set appstoreurl --value local

# your own catalog in the file system
sudo -u www-data php8.4 occ config:system:set \
    appstoreurl --value file:///srv/catalog
```

A `file://` path or the keyword `local` switches the Market app to a local
catalog; any other value is treated as the address of a catalog server.

## Apps that require an API key

The Marketplace delivers some apps only to instances that send an API key.
Without the key, these apps are missing from the catalog, and the download is
rejected with HTTP 401. You can request the key from BW-Tech GmbH.

With owncloud.online 11.0.25 this applies to the following apps:

| App ID | Name in the Market app |
| --- | --- |
| `audit_log` | Audit Log |
| `bwtalk` | BWTalk |
| `calendar` | Calendar |
| `file_firewall` | BW-Tech File Firewall |
| `oco_expire` | Auto Expire |
| `oco_office` | Office (Collabora Online) |
| `schwarzes_brett` | Schwarzes Brett |
| `theme-owncloudonline` | owncloud.online Theme |
| `user_shibboleth` | SAML/SSO Integration |
| `wnd` | Windows Network Drive (Custom) |
| `workflow` | Workflow |

To enter the key in the web interface:

1. Open **Market** in the app menu.
2. In the left column, under *Settings*, click **Add API Key**.
3. In the **Marketplace API Key** dialog, paste the key into the **Your personal
   API Key** field and click **Save**.

After saving, the Market app reloads the catalog, and the additional apps appear
in the list. If the key is wrong, the dialog reports "The API-Key is invalid!".
Once a key is stored, the entry reads **Edit API Key**. If the apps do not
appear, *Clear cache* helps.

<figure markdown="span">
  ![Market app with the Marketplace API Key dialog and its input field](../assets/screenshots/en/market-api-key.png)
  <figcaption>Apps that require an API key appear only after you enter the key. "Add API Key" opens this dialog; you get the key from BW-Tech GmbH.</figcaption>
</figure>

On the command line, set the key as an app value or in `config/config.php`:

```bash
sudo -u www-data php8.4 occ config:app:set market key --value '<KEY>'
```

```php
'marketplace.key' => '<KEY>',
```

The entry in `config.php` takes precedence. If it is set there, the key can no
longer be changed in the user interface; the Market app then reports "Can not
change api key because it is configured in config.php". After setting the key
on the command line, run *Clear cache* in the Market app so that the catalog is
reloaded.

Then install the apps like any other app from their detail page or with
`occ market:install <app_id>`.

## Option 2: place a package by hand

This option needs neither network access nor the Market app, and it is the only
one that also works in an instance with a read-only app directory: you place the
files yourself instead of the web server.

An app package is a `.tar.gz` that contains a single directory with the file
`appinfo/info.xml`. **The directory name must match the `<id>` in this file**,
because the server only looks for an app in a directory named after its ID.

```bash
cd /var/www/owncloud.online/apps-external
sudo tar -xzf /path/to/package.tar.gz
sudo chown -R www-data:www-data /var/www/owncloud.online/apps-external/<app_id>
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:enable <app_id>
```

If your instance has no `apps-external/`, set it up as described in
[Keep apps outside `apps/`](#keep-apps-outside-apps). The next server update
overwrites packages placed under `apps/`.

Enabling the app also does the setup: the app's database migrations,
`appinfo/install.php`, the registration of its background jobs, and the install
repair steps run at that point. Before that, the server checks the dependencies
and stops if one is not met.

When an existing app is replaced by a newer version, `app:enable` is not
enough. The migrations of the new version only run with:

```bash
sudo -u www-data php8.4 occ upgrade
```

This compares the version in `appinfo/info.xml` with the `installed_version`
value recorded in the database. If the major and minor version stay the same
(for example 1.3.10 and 1.3.11), the server only records the new number and runs
no migrations.

### Keep apps outside `apps/`

A server update replaces the code tree and with it everything under `apps/`. So
that apps installed later survive this, the configuration provides a second
directory. New installations add both directories to `config/config.php`
themselves during setup, with `apps-external/` as writable. In older instances,
add the entry by hand:

```php
'apps_paths' => [
	[
		'path' => OC::$SERVERROOT.'/apps',
		'url' => '/apps',
		'writable' => false,
	],
	[
		'path' => OC::$SERVERROOT.'/apps-external',
		'url' => '/apps-external',
		'writable' => true,
	],
],
```

Each entry needs all three keys: `path` as an absolute path in the file system,
`url` as a path relative to the web root with a leading slash, and `writable`.
New apps only go into the directory whose `writable` is `true`; if there are
several, the first one wins. If `apps_paths` is not set at all, the server uses
`apps/` and treats it as writable.

The directory must exist before you add it; otherwise the server no longer
starts and reports "App directory … not found!".

## Option 3: `occ`

The Market app provides the four `market:` commands. They are only available
while this app is enabled.

```bash
# IDs of all apps in the catalog
sudo -u www-data php8.4 occ market:list

# install and enable an app from the catalog
sudo -u www-data php8.4 occ market:install <app_id>

# several at once
sudo -u www-data php8.4 occ market:install <app_id> <app_id>

# install a package that is already in the file system
sudo -u www-data php8.4 occ market:install --local /path/to/package.tar.gz
```

`market:install` on an app that is already installed applies an available minor
update. If the catalog only has a new major version, nothing happens; instead
the message "Major update is available, use market:upgrade --major <app_id>."
appears.

Updates:

```bash
# only show which updates exist
sudo -u www-data php8.4 occ market:upgrade --list

# update a specific app
sudo -u www-data php8.4 occ market:upgrade <app_id>

# all apps with an available update
sudo -u www-data php8.4 occ market:upgrade --all

# explicitly allow a major version change
sudo -u www-data php8.4 occ market:upgrade --major <app_id>

# update from a local package
sudo -u www-data php8.4 occ market:upgrade --local /path/to/package.tar.gz
```

Without `--major`, the command refuses a change of the major version. That is
intentional: such a change can modify the app's settings and database schema and
belongs at a time when a backup exists.

`market:install`, `market:upgrade`, and `market:uninstall` first check whether
the app directory is writable. Otherwise `market:install` and `market:upgrade`
stop with "Installing apps is not supported because the app folder is not
writable.", and `market:uninstall` with "Un-Installing apps is not supported
because the app folder is not writable.".

The apps under `apps/` come with the server. They are part of the release
archive `owncloud-online-<version>.tar.gz` or `.zip` at
[github.com/BWTECH-github/owncloud.online/releases](https://github.com/BWTECH-github/owncloud.online/releases)
and are not installed one by one, but updated together with the server. Each
release also includes `SHA256SUMS.txt` to verify the archives,
`sbom-owncloud-online-<version>.cdx.json`, `release-manifest.json`, and
`removed-release-files.txt`. This page describes release 11.0.25.

## Enable and disable

```bash
sudo -u www-data php8.4 occ app:enable <app_id>
sudo -u www-data php8.4 occ app:disable <app_id>
```

An app can also be limited to specific groups. It is then only available to
members of these groups:

```bash
sudo -u www-data php8.4 occ app:enable <app_id> --groups accounting -g sales
```

In the web interface, **Settings → Admin → Apps** has the buttons *Enable* or
*Disable* on each app, and the option *Enable only for specific groups*.

<figure markdown="span">
  ![Admin settings, Apps: app tiles with version, author, and the Disable button](../assets/screenshots/en/admin-apps.png)
  <figcaption>Under "Apps" you enable and disable installed apps. Some apps can be limited to specific groups.</figcaption>
</figure>

Two points that regularly surprise people:

* An app only provides its `occ` commands while it is enabled. After
  `app:disable files_external`, all `files_external:*` commands disappear from
  `occ list`; after `app:disable market`, all `market:*` commands.
* The uninstall repair steps of an app only run when it is disabled in the web
  interface. `occ app:disable` sets the state directly and skips them. The
  app's data is kept in both cases, see [below](#getting-rid-of-an-app).

Apps of the type `filesystem`, `prelogin`, `authentication`, `logging`,
`prevent_group_restriction`, or `theme` cannot be limited to groups;
`app:enable --groups` stops with "can't be enabled for groups." for them. Only
one theme app can be active at a time: enabling a second one fails with "…
can't be enabled until … is disabled."

## Check version and compatibility

Every app declares in `appinfo/info.xml` which server and PHP versions it works
with. This is checked when the app is enabled with `occ app:enable` or under
**Settings → Admin → Apps**. Before enabling the app, `occ market:install` only
checks whether it fits the server version; missing PHP extensions or programs
then only show up during operation. If the check fails, the message names the
unmet condition:

| Message | Meaning |
| --- | --- |
| "Server version %s or higher is required." | The app requires a newer server version |
| "Server version %s or lower is required." | The app is not released for this server version |
| "PHP %s or higher is required." | The server's PHP version is too old |
| "PHP with a version lower than %s is required." | The app does not work with this PHP version |
| "The library %s is not available." | A PHP extension is missing |
| "The command line tool %s could not be found" | A required program is missing on the server |
| "Following databases are supported: %s" | The app does not support the database system in use |

In the Market app, the result is shown under *Installed Apps* in the
*Compatibility* column: either *OK* or *Missing dependencies* with a list of
what is missing. Under **Settings → Admin → Apps**, the *Enable* button is
grayed out in this case, with "This app cannot be installed because the
following dependencies are not fulfilled:" above it.

Two more notices appear there without preventing installation: if the minimum
or maximum server version is missing in `info.xml`, the user interface says so
explicitly. Such apps have not been tested against this server version.

Two tools on the command line:

```bash
# check an app's code against the rules of the server API,
# plus required fields in info.xml
sudo -u www-data php8.4 occ app:check-code <app_id>

# check an app's signature, if it is signed
sudo -u www-data php8.4 occ integrity:check-app <app_id>
```

If a package contains `appinfo/signature.json`, the server checks the signature
during installation and rejects the package if it does not match. Unsigned
packages are installed without this check.

## Write permissions on the app directory

If the Market app shows the box **"Installing and updating Apps is not
supported!"** with the note "This is a clustered setup or the web server has no
permissions to write to the apps folder.", one of two conditions is not met:

1. `operation.mode` is set to `single-instance`. With the value
   `clustered-instance`, installation from the catalog is skipped entirely,
   because it would have to happen on each node separately anyway.
2. The directory whose `writable` is `true` in `apps_paths` is readable **and**
   writable for the web server user. If no directory is marked as writable, the
   log records "No application directories are marked as writable."

The notice does not mean that the instance cannot get any apps. It only means
that the web server may not place them itself.
[Option 2](#option-2-place-a-package-by-hand) remains open: there, your own
account writes the files, not the web server.

To check both conditions:

```bash
sudo -u www-data php8.4 occ config:system:get operation.mode
sudo -u www-data test -w /var/www/owncloud.online/apps-external && echo writable
```

## Getting rid of an app

There are two levels, and they do different things.

**Disabling** leaves code and data in place and only hides the app:

```bash
sudo -u www-data php8.4 occ app:disable <app_id>
```

**Removing** also deletes the app's directory:

```bash
sudo -u www-data php8.4 occ market:uninstall <app_id>
```

In the web interface, this corresponds to the *Uninstall App* button under
**Settings → Admin → Apps**. It only appears on apps that are not shipped and
not currently enabled.

What happens to the data:

| Part | When disabling | When removing |
| --- | --- | --- |
| App directory | stays | is deleted |
| Uninstall repair steps of the app | only run when disabled in the web interface | do not run (`market:uninstall` only disables the app and deletes the directory) |
| Database tables of the app | stay | stay |
| Settings in `oc_appconfig` | stay | stay |
| Settings of individual users | stay | stay |
| Users' files | stay | stay |

So removing only clears away the code. The server explicitly does not delete
tables, settings, or user preferences. If the same app is installed again later,
it finds its old state. That is usually what you want; if you really want to
clean up, remove the leftovers by hand:

```bash
# show the app's settings
sudo -u www-data php8.4 occ config:list <app_id>

# delete a single value
sudo -u www-data php8.4 occ config:app:delete <app_id> <key>
```

Database tables of a removed app remain. To get rid of them, drop them directly
in the database after a backup, see [Database](database.en.md).

Three cases in which removal is refused:

* Shipped apps, including `encryption` and `configreport`: "Shipped apps
  cannot be uninstalled". For these, there is only `app:disable`.
* The Market app itself: "Market app can not uninstall itself."
* Apps whose directory contains a `.git`: "App … is a git clone - it will not be
  deleted." Such a directory is never deleted automatically.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| The Market app shows "Installing and updating Apps is not supported!" | The app directory is not writable for the web server, or `operation.mode` is `clustered-instance` | Set the permissions; otherwise unpack the package by hand, see [Option 2](#option-2-place-a-package-by-hand) |
| No *Market* entry in the app menu | The Market app is disabled, or the account is not an administrator | `occ app:enable market`; the entry is reserved for administrators |
| `occ market:…` reports "Command … is not defined" | The Market app is disabled and therefore does not provide its commands | `occ app:enable market` |
| An app from the documentation is missing in the Market app | It is only visible with an API key | Enter the key, see [Apps that require an API key](#apps-that-require-an-api-key) |
| The dialog reports "The API-Key is invalid!" | The key is wrong or was not copied completely | Paste the key again; ask BW-Tech GmbH if needed |
| The API key cannot be changed in the user interface | It is set as `marketplace.key` in `config/config.php` | Change the value in `config.php` |
| An unpacked app does not appear in `app:list` | The directory name differs from the `<id>` in `appinfo/info.xml`, or `info.xml` is missing | Rename the directory to exactly the ID |
| `occ app:enable` answers "&lt;app_id&gt; not found" | The app is in none of the directories from `apps_paths` | Check the location, use `occ app:getpath <app_id>` |
| `occ app:enable encryption` answers "encryption not found" | The app is not part of the release archive | `occ market:install encryption` |
| "App … cannot be installed because the following dependencies are not fulfilled" | Server or PHP version does not fit, or a library is missing | Read the message, choose a matching app version, or install the missing extension |
| *Enable* is grayed out, or the Market app reports *Missing dependencies* | The same cause, shown in the user interface instead of the console | See the previous row |
| "No apps found for your version" under **Settings → Admin → Apps** | The selected list (*Enabled* or *Not enabled*) is empty, for example because all present apps are enabled; the message is not about the catalog | Select the other list; new apps come through the Market app |
| New app files are in place, but the old version keeps running | The `installed_version` recorded in the database is still the old one; the migrations are missing | `sudo -u www-data php8.4 occ upgrade` |
| Apps installed later are missing after a server update | They were under `apps/` and were overwritten by the new code tree | Set up a second directory via `apps_paths` and install apps there |
| Error "can't be disabled." | `files`, `dav`, and `federatedfilesharing` are always enabled | These three apps cannot be disabled |
| Error "can't be enabled for groups." | The app is of the type `filesystem`, `prelogin`, `authentication`, `logging`, `prevent_group_restriction`, or `theme` | Enable such apps only for the whole instance |
| "Shipped apps cannot be uninstalled" | The app is listed in `core/shipped.json` | Only `app:disable`; it is not removed |
| "App … is a git clone - it will not be deleted." | The app directory contains a `.git` | Remove the directory by hand if you really want it gone |
| The app does not start, the log reports permission or read errors | After unpacking, the files belong to `root` | `sudo chown -R www-data:www-data` on the app directory |
| The Market app shows an outdated catalog list | The catalog response is cached | Run *Clear cache* in the Market app |

If the cause stays unclear, the server log helps, see
[Server log and error messages](logging.en.md). An overview of all `occ`
commands is in [occ: the command line](occ-reference.en.md).
