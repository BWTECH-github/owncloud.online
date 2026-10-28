# Backups and updates

Create a backup before every update of the core or an app. The backup is the
only way back to the old version: `occ upgrade` changes the database, and the
server refuses an older version after that ("Downgrading is not
supported …").

The examples use the same values as the
[installation guide](../installation/linux-server.en.md): installation
directory `/var/www/owncloud.online`, data directory `/var/oco-data`, database
`owncloud_online`, Apache with PHP-FPM. The commands run as `root`. To see the
actual data directory of your instance:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:get datadirectory
```

## Backup

Three parts belong together and must come from the same point in time: the
program directory with `config/config.php`, the data directory, and the
database. Maintenance mode stops web access and background jobs in the
meantime (`occ system:cron` skips its runs in maintenance mode).

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
systemctl stop apache2
systemctl stop php8.4-fpm

mkdir -p /var/backups/owncloud.online
cd /var/backups/owncloud.online
tar -czf code-$(date +%F).tar.gz -C /var/www owncloud.online
tar -czf data-$(date +%F).tar.gz -C /var oco-data
mysqldump --single-transaction -u root -p owncloud_online > db-$(date +%F).sql

systemctl start php8.4-fpm
systemctl start apache2
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

With nginx, the service is called `nginx` instead of `apache2`. If an update
follows right away, leave the services stopped and maintenance mode on.

The data directory also contains hidden files that the instance needs, such as
`.ocdata` and, with encryption enabled, the keys. Running `tar` on the whole
directory includes them. Keep a copy of the backup off the server.

## Update

An update swaps the program directory for the new release package and then
updates the database with `occ upgrade`. From the old directory, you only carry
over `config/config.php` and the apps from the Marketplace under
`apps-external/`. The package itself contains only the core apps and the
Market app.

If you have no SSH access, [Web hosting](../installation/webhosting.en.md)
describes the update over FTP and the update page in the browser.

**1. Download and verify the release.** The check must print `OK`:

```bash
cd /tmp
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/owncloud-online-11.0.25.tar.gz
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/SHA256SUMS.txt
sha256sum --ignore-missing -c SHA256SUMS.txt
```

**2. Back up** as described above, but skip the last three commands: the
services stay stopped and maintenance mode stays on.

**3. Swap the program directory.** The old directory stays in place as
`owncloud.online.alt` until the update has been checked:

```bash
mv /var/www/owncloud.online /var/www/owncloud.online.alt
tar -xzf /tmp/owncloud-online-11.0.25.tar.gz -C /var/www
mv /var/www/owncloud /var/www/owncloud.online
```

**4. Copy over the configuration and apps:**

```bash
cp -a /var/www/owncloud.online.alt/config/*config.php /var/www/owncloud.online/config/
rsync -a --exclude=/market /var/www/owncloud.online.alt/apps-external/ \
  /var/www/owncloud.online/apps-external/
chown -R www-data:www-data /var/www/owncloud.online
```

`*config.php` matches `config.php` and any other files named
`<name>.config.php`. `rsync` (package of the same name) copies all apps from
the Marketplace; only the Market app itself comes from the new package. If the
data directory happens to be inside the program directory (`data/`), move it
into the new directory as well before you start the services.

**5. Update the database and end maintenance mode:**

```bash
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:repair
systemctl start php8.4-fpm
systemctl start apache2
sudo -u www-data php8.4 occ maintenance:mode --off
```

`occ upgrade` leaves maintenance mode on if it was already on before. That is
why the last command turns it off. `occ maintenance:repair` only runs in
maintenance mode. If `htaccess.RewriteBase` is set, `occ upgrade` writes the
rewrite block back into the new `.htaccess`, provided that `overwrite.cli.url`
is set.

**6. Check:**

```bash
sudo -u www-data php8.4 occ status
sudo -u www-data php8.4 occ app:list
```

`occ status` must show the new version. If an app is missing from `app:list`,
its directory under `apps-external/` is usually missing. Then check the
[server log](logging.en.md) for new errors. Once everything works, delete
`/var/www/owncloud.online.alt`.

If you use OPcache preloading, put `build/preload.php` back before PHP-FPM
starts, see [Performance](performance.en.md).

Updates of individual apps from the Marketplace are covered in
[Managing apps](apps-market.en.md).

### Maintenance mode before replacing theme apps

Up to owncloud.online 11.0.11, the core loaded apps of type `theme` (such as
`theme-owncloudonline`), `session`, and `license` at the start of every
request, including the start of `occ` itself. If such an app directory was
replaced with a version that changed the major or minor version (for example
1.2.x to 3.0.0), **every** `occ` call failed with `OC\NeedsUpdateException`,
including the `occ upgrade` that is supposed to fix the situation:

```text
An unhandled exception has been thrown:
OC\NeedsUpdateException in lib/private/legacy/app.php:194
```

Since 11.0.12, the core loads these apps only once no update is pending, and
`occ upgrade` runs through. Patch versions were never affected.

Regardless of the version, for automated rollouts: turn on maintenance mode
**before** you replace an app directory. In maintenance mode,
`OC_App::loadApps()` skips loading apps, so `occ` starts on older versions as
well:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
# only now copy in the new app directory
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:mode --off
```

If `occ` is already blocked because the directory has been replaced, set the
value directly in `config/config.php`:

```php
'maintenance' => true,
```

If an app update stops because the database reports a **newer** version than
the deployed code, a wrong or older package was shipped. The message names both
versions; the version recorded in the database stays unchanged. Deploy the
correct package and update again.

## Rollback

1. Turn on maintenance mode and stop the services.
2. Restore the code backup.
3. Restore the database dump into an empty database.
4. Restore the data directory.
5. Run `occ maintenance:repair`.
6. Start the services and turn off maintenance mode.

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
systemctl stop apache2
systemctl stop php8.4-fpm

cd /var/backups/owncloud.online
mv /var/www/owncloud.online /var/www/owncloud.online.broken
tar -xzf code-YYYY-MM-DD.tar.gz -C /var/www

mysql -u root -p -e "DROP DATABASE owncloud_online;
  CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root -p owncloud_online < db-YYYY-MM-DD.sql

mv /var/oco-data /var/oco-data.broken
tar -xzf data-YYYY-MM-DD.tar.gz -C /var

sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:repair
systemctl start php8.4-fpm
systemctl start apache2
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

The database must be empty before the import: tables that only the update
created would otherwise remain next to the data from the dump. `DROP DATABASE`
does not remove the database user's privileges on `owncloud_online.*`. Delete
the directories ending in `.broken` once the instance is running again.
