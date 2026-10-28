# Configuration (config.php)

The main configuration is in `/var/www/owncloud.online/config/config.php`. The
file is a PHP array, belongs to `www-data`, and has the permissions `0640`
because it contains the database credentials and the instance secret.

`config/config.sample.php` describes all available keys. The tables below list
the ones you need in day-to-day operation.

## Reading and setting values

Change the configuration of a running instance with `occ`, not in an editor.
`occ` checks the type of the value and writes the file under an exclusive file
lock:

```bash
# show all values (passwords and secrets hidden, --private shows them)
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:list system

# set a single value
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:set loglevel --value 2 --type integer

# delete a value
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:delete maintenance
```

Do not forget `--type` (`integer`, `double`, `boolean`, `json`). Without it, the
value is stored as a string and evaluated incorrectly.

## Basic settings

| Key | Meaning |
| --- | --- |
| `instanceid` | Instance ID, **never change** |
| `passwordsalt`, `secret` | Cryptographic values, **never change**, always include in backups |
| `datadirectory` | Location of the user data |
| `dbtype`, `dbhost`, `dbname`, `dbuser`, `dbpassword` | Database access, see [Database](database.en.md) |
| `trusted_domains` | Allowed host names. Requests for any other name are refused |
| `overwrite.cli.url` | Base URL for cron and command-line calls |
| `maintenance` | Maintenance mode (better: `occ maintenance:mode`) |

## Running behind a reverse proxy

```php
'trusted_proxies' => ['10.0.0.5'],
'overwriteprotocol' => 'https',
```

`trusted_proxies` is **security-relevant**: the server only evaluates
`X-Forwarded-For` for these addresses. If the value is wrong, or if the PHP-FPM
server can be reached directly from the network, an attacker can choose any
client IP and bypass IP-based protections such as brute-force protection. See
[Security and setup warnings](security-hardening.en.md).

## Logging

| Key | Recommendation |
| --- | --- |
| `loglevel` | `2` (warnings) in production, `0` only briefly for troubleshooting |
| `logfile` | Custom path; default is `<datadirectory>/owncloud.log` |
| `log_rotate_size` | Rotation size in bytes, default `104857600` (100 MB); `0` or `false` turns rotation off |

Details in [Server log and error messages](logging.en.md).

## Performance

| Key | Recommendation |
| --- | --- |
| `memcache.local` | `\OC\Memcache\APCu` |
| `memcache.locking` | `\OC\Memcache\Redis` (with a `redis` block) |
| `filelocking.enabled` | `true` |
| `enable_previews` | `true`; `preview_max_x`/`preview_max_y` limit the size |

See [Performance](performance.en.md).

## Maintenance and updates

| Key | Meaning |
| --- | --- |
| `updatechecker` | Update check; set to `false` if updates are managed centrally |
| `upgrade.disable-web` | Allow upgrades only through `occ` (recommended) |
| `skeletondirectory` | Skeleton folder for new accounts; an empty string (`''`) starts new accounts without any files |
| `user.home_base_dirs` | Additional allowed base directories for user homes that come from the backend (for example LDAP) |

## After every change

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:list system
```

A syntax error in `config.php` takes the whole instance down. Make a copy
before editing and check the file afterwards with `php8.4 -l config/config.php`.
