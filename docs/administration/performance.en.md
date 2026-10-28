# Performance

Maintained by BW-Tech GmbH.

Always compare measurements before and after a change with the same data set,
the same hardware, and the same benchmark parameters. Single measurements from SQLite development
instances say nothing about production.

## Step-by-step plan

1. Record baseline values with API, WebDAV, upload, and download measurements.
2. Test PHP-FPM, OPcache, MariaDB, Redis locking, APCu, system cron, and
   limited retention on an isolated staging instance.
3. Measure an anonymized production data set with at least one million file
   cache entries. Only then evaluate additional indexes based on `EXPLAIN` and
   the slow query log.
4. Profile desktop sync with an unchanged test data set. Measure server and
   client changes separately.
5. Roll out changes to a canary instance first and monitor the error rate,
   database latency, lock wait times, PHP-FPM load, and sync duration.

Each step has its own before-and-after comparison and a documented way to roll
back.

## Reference measurement

Steps 1 to 3 were measured with PHP 8.4, nginx, PHP-FPM, MariaDB, Redis, and
APCu on an isolated instance. The data set for step 3 contained 1,000 folders
with 1,000 files each, and therefore more than one million file cache entries.

| Measurement | SQLite/PHP server | MariaDB/Redis/nginx | With 1 million file cache entries |
| --- | ---: | ---: | ---: |
| 100 small uploads | 41.07 s | 7.53 s | 7.45 s |
| Upload 16 MiB | 26.12 MiB/s | 34.95 MiB/s | 33.99 MiB/s |
| Download 16 MiB | 42.83 MiB/s | 54.84 MiB/s | 44.62 MiB/s |
| PROPFIND p95 | 350.43 ms | 338.89 ms | 322.47 ms |

With one million valid and 10,000 orphaned entries, `files:cleanup` first took
9.33 seconds. Since the command deletes orphaned entries per storage, the same
run takes 0.81 seconds.

The measurement does not justify additional default indexes:

- `(storage, path_hash)` serves exact path lookups.
- `(parent, name)` serves folder listings, including sorting by name.
- A separate index on `parent` alone would be redundant.
- The core's sync queries do not sort globally by `mtime`. An additional
  `mtime` index would increase write costs without speeding up the measured
  sync path.
- Searches with a leading wildcard such as `%term%` cannot use a normal B-tree
  index efficiently. Full-text search would be a separate feature that would
  have to be built for every supported database.

## Recommended production setup

- PHP 8.4 FPM with OPcache enabled
- MariaDB or MySQL instead of SQLite
- Redis for transactional file locking
- APCu as the local cache
- System cron instead of AJAX or Webcron
- enough RAM for the database and PHP-FPM without the system swapping
- low latency between web server, database, Redis, and storage

Check Redis and the PHP extensions:

```bash
redis-cli ping
php8.4 -m | grep -E 'apcu|redis|Zend OPcache'
```

Check the owncloud.online configuration:

```bash
sudo -u www-data php8.4 occ config:system:get dbtype
sudo -u www-data php8.4 occ config:system:get memcache.local
sudo -u www-data php8.4 occ config:system:get memcache.locking
sudo -u www-data php8.4 occ background:queue:status
```

## OPcache sizing

The default `opcache.max_accelerated_files=10000` is not enough for
owncloud.online. The 11.0.25 release package alone contains 5,878 PHP files,
every app from the Marketplace adds more, and some apps bring over 1,000 with
their libraries. With all apps, the count is over 14,000. Once the limit is
exceeded, OPcache evicts entries and keeps recompiling files under changing
load. Count the files and set the limit above that number:

```bash
find /var/www/owncloud.online -name '*.php' | wc -l
```

Recommended values for `php.ini` or the FPM pool:

```ini
opcache.enable=1
opcache.memory_consumption=256      ; MB, 512 with very many apps
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=24000 ; must exceed the file count
opcache.validate_timestamps=0       ; only with an OPcache reset on update
opcache.enable_cli=0                ; CLI/cron does not need a persistent OPcache
```

With `validate_timestamps=0`, code changes only become visible after reloading
PHP-FPM or resetting OPcache. Reload PHP-FPM after every update
(`systemctl reload php8.4-fpm`), or old classes keep running.

## OPcache preloading (PHP 8.4)

Building on the sizing above, OPcache can compile and link the stable server
code when PHP-FPM starts (preloading). Workers then no longer link classes on
every request and need no warm-up phase after a reload. The script
`build/preload.php` loads `lib/private`, `lib/public`, `core/`, and the
Composer libraries under `lib/composer`. It deliberately skips app
directories, because apps are enabled, disabled, and updated while the server
is running.

The script is in the source repository, not in the release package. For an
installation from the release package, place it under `build/` in the
installation directory, matching the installed version. The script finds the
installation directory from its own location, so it must be exactly there:

```bash
sudo -u www-data mkdir -p /var/www/owncloud.online/build
sudo -u www-data curl -fLo /var/www/owncloud.online/build/preload.php \
  https://raw.githubusercontent.com/BWTECH-github/owncloud.online/v11.0.25/build/preload.php
```

The file cannot be fetched from outside: `.htaccess` and the nginx
configuration from the [installation guide](../installation/linux-server.en.md)
block `build/`.

Enable it in the FPM pool configuration or `php.ini`:

```ini
opcache.preload=/var/www/owncloud.online/build/preload.php
opcache.preload_user=www-data
```

Notes:

- Preloading requires the same discipline as `validate_timestamps=0`: reload
  PHP-FPM after every update, or old code keeps running.
- An update replaces the program directory, so `build/preload.php` is gone
  afterwards. Put the file from the new release back before PHP-FPM restarts.
  If it is missing, PHP stops right at startup with "Failed opening
  required …".
- Messages such as `Can't preload unlinked class` at startup are harmless.
  These classes are cached unlinked and linked on first use.
- The script is fault-tolerant: it skips individual files that cannot be
  compiled, and they do not prevent PHP-FPM from starting. It writes a line
  `owncloud.online preload: compiled N of M files` to the PHP error log.
- Enable preloading on a staging instance first and check the startup
  (`systemctl restart php8.4-fpm && systemctl status php8.4-fpm`).

## Frontend delivery

The web interface loads JavaScript and CSS as many individual files. Since
11.0.12, the files of the core and the bundled apps are minified, and under
Apache the shipped `.htaccess` compresses them with `mod_brotli` or
`mod_deflate`. The files are not bundled. At the TLS terminator, these points
therefore reduce load time the most:

- enable HTTP/2: with multiplexing, the many individual files travel over one
  connection at the same time instead of waiting for each other's responses
- keepalive to the PHP-FPM upstream
- gzip or brotli for `text/*`, `application/javascript`, and
  `application/json`, if the files are not served by Apache with the shipped
  `.htaccess`

## Limits of individual server optimizations

On an instance with a warm OPcache, the compiled route cache (`route.cache`)
makes no measurable difference to response times: OPcache already holds the
route files, and the router only builds the routes of apps that are already
loaded. The route cache helps mainly with a cold or disabled OPcache and with
freshly started FPM workers.

Most of the cost of an authenticated request comes from repeated password
hashing (bcrypt) and from setting up the session and the file system. A client
that keeps its session cookie across requests is many times faster than one
that logs in again with HTTP basic auth on every request. Sync and API clients
should therefore reuse the session cookie.

For the desktop and mobile clients, the bcrypt problem is already solved: they
log in with a device token (`oc_authtoken`, app password), not with the
account password. `OC\User\Session::checkTokenCredentials()` checks bcrypt
only when the token's `last_check` column is older than `last_check_timeout`
(default 5 minutes), not on every request. owncloud.online deliberately does
not keep a cache of password checks keyed on the plain-text password: it would
have to store password material and would undermine brute-force protection,
rate limiting, and token revocation. The remaining per-request bcrypt only
affects clients without a token that send the account password without a
persistent session (for example third-party WebDAV integrations).

## Retention

Old activities, items in the trash bin, and versions increase the load on the
database and storage over time. The following values are a starting point for
installations without special retention requirements:

```bash
sudo -u www-data php8.4 occ config:system:set \
  activity_expire_days --type integer --value 64

sudo -u www-data php8.4 occ config:system:set \
  trashbin_retention_obligation --value 'auto, 30'

sudo -u www-data php8.4 occ config:system:set \
  versions_retention_obligation --value 'auto, 30'
```

Check contractual and legal retention rules first. The commands do not delete
anything right away. Background jobs do the cleanup.

Back to the default automatic behavior:

```bash
sudo -u www-data php8.4 occ config:system:delete activity_expire_days
sudo -u www-data php8.4 occ config:system:set \
  trashbin_retention_obligation --value auto
sudo -u www-data php8.4 occ config:system:set \
  versions_retention_obligation --value auto
```

## File cache maintenance

Back up the database and the data directory before running maintenance
commands, see [Backups and updates](backups-updates.en.md).

```bash
sudo -u www-data php8.4 occ files:cleanup
```

`files:cleanup` only removes file cache entries whose storage no longer
exists. It does not replace trash bin or version cleanup.

Watch the table sizes:

```sql
SELECT COUNT(*) FROM oc_filecache;
SELECT COUNT(*) FROM oc_activity;
SELECT COUNT(*) FROM oc_file_locks;
```

Do not add indexes blindly. Measure `EXPLAIN`, write load, memory use, and a
real desktop sync before and after. The existing index on `(parent, name)`
already serves plain `parent` queries.

## Reproducible benchmark

The benchmark is in the source repository, not in the release package:

```text
tests/performance/benchmark.py
```

It needs only Python 3, without any extra packages. It creates its own folder
in the account and deletes it again after the measurement (unless you pass
`--keep-data`).

Example:

```bash
export OC_BASE_URL=https://staging.example.com
export OC_USERNAME=performance
export OC_PASSWORD='local-test-password'

python3 tests/performance/benchmark.py \
  --latency-requests 50 \
  --small-files 10000 \
  --large-size 536870912 \
  --concurrency 8 \
  --output build/performance/staging.json
```

The test account must not contain production data. For a before-and-after
comparison, the amount of data, the list of apps, network latency,
concurrency, and server configuration must stay the same.
