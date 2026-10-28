# occ: the command line

`occ` is the command-line administration tool of owncloud.online. The file `occ`
sits in the root directory of the installation. It starts the same environment
as the web interface and also loads the commands of all enabled apps. So the set
of commands is not fixed: it depends on whether the instance is installed,
whether it is in maintenance mode, and which apps are enabled.

## Running occ

```bash
# list all available commands
sudo -u www-data php8.4 /var/www/owncloud.online/occ list

# help for a single command, with all options
sudo -u www-data php8.4 /var/www/owncloud.online/occ user:add --help
```

You can run `occ` from any working directory as long as you give the full path:
`occ` changes to the installation directory by itself. The examples on this page
leave out the path for brevity.

`occ` requires the PHP extension **posix** and should have **pcntl** as well.
Without `posix`, `occ` stops immediately. Without `pcntl`, `occ` runs with a
warning, but long-running commands cannot be interrupted cleanly.

## Permissions: always as the web server user

Before every run, `occ` checks whether the calling user is the owner of
`config/config.php`. If not, it stops:

```text
Console has to be executed with the user that owns the file config/config.php
```

On a standard installation this user is `www-data`. So always run:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
```

Running `occ` as `root` is wrong even when it works, for example because
`config/config.php` belongs to `root` by mistake. Files and directories created
during the run (log file, previews, files in the data directory) then belong to
`root`, and the web server can no longer write to them. The same applies to the
cron entry, see [Background jobs](background-jobs.en.md).

## Global options

| Option | Effect |
| --- | --- |
| `--help`, `-h` | Show help for the command instead of running it |
| `-v`, `-vv`, `-vvv` | More detailed output |
| `--no-warnings` | Suppress global notices and print only the command output |
| `--output=plain|json|json_pretty` | Machine-readable output. Not every command has this option; `status`, `check`, `app:list`, `user:list`, `group:list`, and `config:list` are among those that do. `occ <command> --help` shows whether it is available. |

```bash
sudo -u www-data php8.4 occ status --output=json_pretty
```

## Maintenance mode

`occ` switches maintenance mode on and off. It writes the system value
`maintenance` to `config/config.php`:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
sudo -u www-data php8.4 occ maintenance:mode --off

# without an option: show the current state
sudo -u www-data php8.4 occ maintenance:mode
```

**In maintenance mode, `occ` does not load any apps.** Their commands disappear
from the list as well. `occ` prints this notice when it starts:

```text
owncloud.online is in maintenance mode - no app have been loaded
```

| Action | In maintenance mode |
| --- | --- |
| Core commands (`user:*`, `group:*`, `config:*`, `db:*`, `migrations:*`, `maintenance:*`, `upgrade`, `log:*`) | available |
| App commands (`files:*`, `trashbin:*`, `versions:*`, `dav:*`, `files_external:*`, `market:*`) | **not** available, the apps are not loaded |
| `occ system:cron`, also through `cron.php` on the command line (it only calls `occ system:cron`) | stops with "We are in maintenance mode, skipping cron" |
| Web interface (`index.php`) | HTTP 503 with `Retry-After: 120` and the maintenance page |
| Sync clients (`remote.php`, WebDAV) | HTTP 503 "System in maintenance mode." without `Retry-After` |

Two other states limit the set of commands in the same way:

* **Not installed:** Only `maintenance:install` is available, plus the few
  commands that do not need an installation (`status`, `check`, `integrity:*`,
  `app:check-code`, `l10n:createjs`).
* **Upgrade pending:** If the instance reports "owncloud.online or one of the
  apps require upgrade", the apps are not loaded. You get the same commands as
  in maintenance mode, so the core commands are still there. The way out is
  `occ upgrade`.

Single-user mode (`maintenance:singleuser`, system value `singleuser`) is
different: the apps stay loaded. `occ system:cron` stops with "We are in admin
only mode, skipping cron", the web interface returns HTTP 503 to accounts
outside the `admin` group, and the WebDAV endpoint rejects every request with
"System in single user mode.", including requests from administrators.

## Maintenance and upgrades

| Command | Purpose |
| --- | --- |
| `status` | Print version, edition, and installation state |
| `check` | Check the dependencies of the server environment |
| `upgrade` | Run the upgrade routines after installing a new version |
| `maintenance:mode` | Turn maintenance mode on or off, or show its state |
| `maintenance:singleuser` | Turn single-user mode on or off, or show its state |
| `maintenance:repair` | Run repair steps (`--list`, `--single`, `--include-expensive`) |
| `maintenance:file-locks` | Show and clean up transactional file locks |
| `maintenance:data-fingerprint` | Renew the data fingerprint after restoring a backup |
| `maintenance:update:htaccess` | Rewrite the `.htaccess` file |
| `maintenance:mimetype:update-db` | Update MIME types in the database and the file cache |
| `maintenance:mimetype:update-js` | Regenerate `mimetypelist.js` |
| `maintenance:install` | Set up the instance (only available before installation) |
| `previews:cleanup` | Remove previews whose file no longer exists |
| `integrity:check-core` | Check the signature of the core code |
| `integrity:check-app` | Check the signature of an app |

To upgrade, install the new code first and then run `occ upgrade`:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:mode --off
```

In the shipped release, `integrity:check-core` and `integrity:check-app` check
no files and always report success: the core does not enforce the check for the
shipped channel, and the release package contains no signatures. How to verify a
package instead is described under
[Integrity check](security-hardening.en.md#integritaetspruefung).

This command releases file locks that were left behind after a crash:

```bash
# safe while the instance is running: expired locks only
sudo -u www-data php8.4 occ maintenance:file-locks --cleanup-expired

# all locks, requires maintenance mode
sudo -u www-data php8.4 occ maintenance:file-locks --all
```

With `--dry-run` the command only shows what it would delete. It works on the
database table `file_locks`. If `memcache.locking` is set, for example to Redis,
the locks live there and the command finds none.

## Accounts and groups

| Command | Purpose |
| --- | --- |
| `user:add` | Create an account |
| `user:delete` | Delete an account |
| `user:modify` | Change the details of an account |
| `user:disable` | Disable an account |
| `user:enable` | Enable a disabled account again |
| `user:list` | List accounts with their attributes |
| `user:list-groups` | List the groups of an account |
| `user:lastseen` | Show the last login of an account |
| `user:inactive` | Report accounts that have not logged in for N days |
| `user:report` | Print the number of accounts with access |
| `user:resetpassword` | Reset the password of an account |
| `user:setting` | Read and change the settings of an account |
| `user:sync` | Copy accounts from a backend into the accounts table |
| `user:move-home` | Move the home directory of an account |
| `user:home:list-dirs` | List all root directories in use for home directories |
| `user:home:list-users` | List all accounts whose home directory is below a path |
| `group:add` | Create a group |
| `group:delete` | Delete a group |
| `group:list` | List groups |
| `group:add-member` | Add members to a group |
| `group:remove-member` | Remove members from a group |
| `group:list-members` | List the members of a group |
| `twofactorauth:enable` | Turn on two-factor authentication for an account |
| `twofactorauth:disable` | Turn off two-factor authentication for an account |

`user:sync` is the command for connected directory services. Instead of the full
class name it accepts the short forms `ldap`, `samba`, and `shibboleth`, and
`--list` shows the active backends:

```bash
sudo -u www-data php8.4 occ user:sync --list
sudo -u www-data php8.4 occ user:sync ldap --missing-account-action=disable
```

`--missing-account-action` takes `disable` or `remove`. **`remove` deletes the
account together with its data and files.** For regular operation, use
`disable`.

## Files and file system

| Command | Purpose |
| --- | --- |
| `files:scan` | Check the file system for changes and update the file cache |
| `files:cleanup` | Remove orphaned entries from the file cache |
| `files:check-cache` | Check whether a file really exists in the primary storage |
| `files:remove-storage` | Remove a storage and its cache entries from the database |
| `files:checksums:verify` | Compare stored checksums with newly calculated ones |
| `files:transfer-ownership` | Transfer all files and folders of one account to another, shares included |
| `files:troubleshoot-transfer-ownership` | Look for problems left by an earlier transfer |
| `trashbin:cleanup` | Permanently remove files from the trash bin |
| `trashbin:expire` | Expire the trash bin according to the configured rules |
| `versions:cleanup` | Delete file versions |
| `versions:expire` | Expire file versions according to the configured rules |
| `files_external:list` | List configured external storages |
| `files_external:create` | Create an external storage |
| `files_external:delete` | Delete an external storage |
| `files_external:config` | Manage the backend configuration of a storage |
| `files_external:option` | Manage the mount options of a storage |
| `files_external:applicable` | Manage the accounts and groups a storage applies to |
| `files_external:backends` | Show the available storage and authentication backends |
| `files_external:verify` | Check the configuration of a storage against its target |
| `files_external:import` | Import storage configurations |
| `files_external:export` | Export storage configurations |

After every change made directly in the data directory you need `files:scan`.
Otherwise the file cache does not know about the new files:

```bash
# one account
sudo -u www-data php8.4 occ files:scan alice

# a subpath only
sudo -u www-data php8.4 occ files:scan --path="/alice/files/Music"

# all accounts
sudo -u www-data php8.4 occ files:scan --all
```

There are also `--group`, `--unscanned` (only files that were not scanned
completely), and `--repair` for detached cache entries. `--repair` is much
slower; run it in a maintenance window.

## Shares

| Command | Purpose |
| --- | --- |
| `sharing:cleanup-remote-storages` | Remove `shared::` storages that no longer have an entry in `shares_external` |
| `incoming-shares:poll` | Poll incoming federated shares for changes manually |
| `federation:trusted-servers:add` | Add a trusted server |
| `federation:trusted-servers:list` | List trusted servers |
| `federation:trusted-servers:remove` | Remove a trusted server |

When you transfer an account, its shares move along. `files:transfer-ownership`
handles this, see the previous section.

## Configuration

| Command | Purpose |
| --- | --- |
| `config:list` | Print all values (`system`, an app name, or `all`) |
| `config:system:get` | Read a value from `config.php` |
| `config:system:set` | Set a value in `config.php` |
| `config:system:delete` | Remove a value from `config.php` |
| `config:app:get` | Read an app value from the database |
| `config:app:set` | Set an app value in the database |
| `config:app:delete` | Remove an app value from the database |
| `config:import` | Import a list of values |

```bash
sudo -u www-data php8.4 occ config:list system
sudo -u www-data php8.4 occ config:system:set loglevel --value 2 --type integer
```

`config:list` replaces a fixed set of confidential **system values** with
`***REMOVED SENSITIVE VALUE***`. Among them are `dbpassword`, `passwordsalt`,
`secret`, `mail_smtppassword`, `ldap_agent_password`, `marketplace.key`, the
client secret under `openid-connect`, and the credentials under `redis` and
`objectstore`. Only `--private` prints them in plain text. **The command does
not filter app values from the database.** They appear unchanged in the output
of `config:list all` and `config:list <app>`, even without `--private`. Never
paste the output into a ticket or a repository without checking it first. The
individual keys are described in [Configuration (config.php)](config-reference.en.md).

## Background jobs

| Command | Purpose |
| --- | --- |
| `background:cron` | Set the execution mode to system cron |
| `background:ajax` | Set the execution mode to AJAX |
| `background:webcron` | Set the execution mode to webcron |
| `background:queue:status` | Show the state of the job queue |
| `background:queue:execute` | Run a single job from the queue |
| `background:queue:delete` | Delete a job from the queue |
| `system:cron` | Process the background jobs once, like a cron run |

The three `background:` commands write the same app value
`core` / `backgroundjobs_mode`; you can read it with
`config:app:get core backgroundjobs_mode`. The recommended mode is `cron`. Setup
and checks are described in [Background jobs](background-jobs.en.md).

`system:cron` stops if an upgrade is pending, if maintenance mode or single-user
mode is on, or if the execution mode is `none`. With `--progress` it shows a
progress bar. Never use this option in a crontab entry.

## Database

| Command | Purpose |
| --- | --- |
| `db:convert-mysql-charset` | Convert the character set of a MySQL/MariaDB database to `utf8mb4` |
| `db:restore-default-row-format` | Restore the default row format of the MySQL/MariaDB tables |
| `migrations:status` | Show the state of the migrations |
| `migrations:migrate` | Run migrations up to a given version or the latest one |
| `migrations:execute` | Run a single migration |

Back up the database before any of these operations, see
[Backups and updates](backups-updates.en.md). In regular operation you do not
call `migrations:*` directly; `occ upgrade` takes care of it.

## Logging

| Command | Purpose |
| --- | --- |
| `log:manage` | Set and show the logging backend, level, and time zone |
| `log:owncloud` | Turn on the file-based backend, set its path and rotation size |

```bash
# level warning, output to a file
sudo -u www-data php8.4 occ log:manage --level warning
sudo -u www-data php8.4 occ log:owncloud --enable --rotate-size 100M
```

`log:manage` writes the system values `log_type` (`owncloud`, `syslog`, or
`errorlog`), `loglevel` (`debug`, `info`, `warning`, `error`, `fatal`), and
`logtimezone`. `log:owncloud` writes `logfile` and `log_rotate_size` (default
`104857600`; `0` turns rotation off). To read and analyze the log, see
[Server log and error messages](logging.en.md).

## Apps

| Command | Purpose |
| --- | --- |
| `app:list` | List all installed apps with their state |
| `app:enable` | Enable an app |
| `app:disable` | Disable an app |
| `app:getpath` | Print the absolute path of an app directory |
| `app:check-code` | Check the code of an app against the coding rules |
| `market:list` | List the apps available in the catalog |
| `market:install` | Install an app from the catalog, update an installed one if needed |
| `market:upgrade` | Install new app versions from the catalog |
| `market:uninstall` | Remove an app |

The four `market:` commands come from the Market app and are only available
while it is enabled. The catalog and its configuration are described in
[Apps and Marketplace](apps-market.en.md).

In general, an app provides its commands only while it is enabled.
After `app:disable files_external`, all `files_external:*` commands disappear
from `occ list`.

## Other registered command groups

| Command | Purpose |
| --- | --- |
| `encryption:status` | Show the state of server-side encryption |
| `encryption:enable` | Turn encryption on |
| `encryption:disable` | Turn encryption off |
| `encryption:list-modules` | List the available encryption modules |
| `encryption:set-default-module` | Set the default module |
| `encryption:encrypt-all` | Encrypt all files of all accounts |
| `encryption:decrypt-all` | Turn encryption off and decrypt all files |
| `encryption:show-key-storage-root` | Show the current root directory of the key storage |
| `encryption:change-key-storage-root` | Change the root directory of the key storage |
| `security:certificates` | List trusted certificates |
| `security:certificates:import` | Import a trusted certificate |
| `security:certificates:remove` | Remove a trusted certificate |
| `security:routes` | List the routes in use |
| `security:sign-key:create` | Create or renew the signing key of an account for signed URLs |
| `dav:cleanup-chunks` | Remove leftover upload chunks |
| `dav:create-addressbook` | Create an address book |
| `dav:create-calendar` | Create a calendar |
| `dav:sync-birthday-calendar` | Synchronize the birthday calendar |
| `dav:sync-system-addressbook` | Copy accounts into the system address book |
| `federation:sync-addressbooks` | Synchronize the address books of all federated instances |
| `integrity:sign-core` | Sign the core with a private key |
| `integrity:sign-app` | Sign an app with a private key |
| `l10n:createjs` | Generate the JavaScript translation files of an app |
| `migrations:generate` | Create the skeleton of a new migration (development) |

Details on encryption are in [Encryption](encryption.en.md).

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| "Console has to be executed with the user that owns the file config/config.php" | Run as the wrong user, usually `root` or your own user account on the server | Run again with `sudo -u www-data` |
| "The posix extensions are required" | The PHP extension `posix` is missing from the CLI configuration | Enable `posix` in the CLI `php.ini` |
| Notice about missing PCNTL | The PHP extension `pcntl` is missing | Install the extension, or suppress the notice with `--no-warnings` |
| App commands such as `files:scan` are missing from `occ list` | Maintenance mode is on, or the app is disabled | Check `occ maintenance:mode`, otherwise `occ app:list` |
| "owncloud.online or one of the apps require upgrade" | The code is newer than the database | Run `occ upgrade` |
| "owncloud.online is not installed" | There is no installation yet | Use `occ maintenance:install` |
| "Environment not properly prepared." | `occ` found environment errors at startup and stops | Run `occ check`. It skips this check and lists the errors one by one. |
| "Refusing to delete all file locks while maintenance mode is disabled." | `maintenance:file-locks --all` without maintenance mode | Run `occ maintenance:mode --on` first, or use `--cleanup-expired` instead |
| `maintenance:file-locks` finds no locks, files stay locked | The locks are in the cache (`memcache.locking`), not in the database | The locks expire by themselves after `filelocking.ttl` seconds (default 3600 or the higher PHP `max_execution_time`). |
| "We are in maintenance mode, skipping cron" | `system:cron` while maintenance mode is on | Turn maintenance mode off |
| "We are in admin only mode, skipping cron" | Single-user mode is on | `occ maintenance:singleuser --off` |
| "Background Jobs are disabled!" | `backgroundjobs_mode` is set to `none` | Run `occ background:cron` |
| After running `occ`, new files belong to `root` | An earlier run used `root` | Reset the owner (`chown -R www-data:www-data`) and use `sudo -u www-data` from now on |
| New files in the data directory do not show up in the web interface | The file cache does not know them | `occ files:scan` for the affected account or path |
