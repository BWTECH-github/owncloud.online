# Database

owncloud.online runs on MariaDB/MySQL, PostgreSQL, or SQLite. For production,
**MariaDB** is the tested standard. SQLite is only suitable for local tests and
breaks down under concurrent access.

## Recommended settings (MariaDB/MySQL)

The database must use `utf8mb4`. Otherwise file names with emojis or rare
characters fail:

```sql
CREATE DATABASE owncloud_online
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```

`config.php` needs:

```php
'dbtype' => 'mysql',
'mysql.utf8mb4' => true,
```

To check whether an existing instance has already been converted, run the
following command. The expected output is `true`:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:get mysql.utf8mb4
```

`db:convert-mysql-charset` does the conversion. It first checks whether the
database server supports `utf8mb4`, then sets `mysql.utf8mb4` in `config.php`
and converts all tables. Make a backup first and turn on maintenance mode:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:convert-mysql-charset
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

## Switching from SQLite to MariaDB

This version has no in-place conversion. The former `db:convert-type` command
has been removed (changelog: "This experimental command is untested and
unsupported and therefore removed."). Plan for MariaDB when you install.

For an existing SQLite instance, the only way is a fresh installation with
MariaDB and a new scan of the files. Everything that exists only in the
database is lost, such as shares, comments, tags, and app settings.
[Moving to another server](migration.en.md#datenbanktyp-wechseln) describes the
procedure and the full list.

## Maintenance

The server creates indexes and columns through migrations. There is no
separate command for adding missing indexes.

```bash
# show the migration state ("New Migrations" should be 0)
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:status core

# run pending migrations up to the latest version
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:migrate core

# run a single migration
sudo -u www-data php8.4 /var/www/owncloud.online/occ migrations:execute core <version>
```

After moving to MariaDB 10.6 or later, `db:restore-default-row-format` converts
all tables whose row format differs from the server default (such as the
deprecated `COMPRESSED`) to the default format:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ db:restore-default-row-format
```

!!! warning "Migrations after every update"
    After a code update, `occ upgrade` must run. If migrations are missing, the
    code expects columns that do not exist in the database yet, and the
    instance answers with error 500 in some places. See
    [Server log and error messages](logging.en.md).

## Backup and restore

```bash
# backup
mkdir -p /var/backups/owncloud.online
mysqldump --single-transaction -u root -p owncloud_online > /var/backups/owncloud.online/db-$(date +%F).sql

# restore (put the instance into maintenance mode first)
mysql -u root -p owncloud_online < /var/backups/owncloud.online/db-YYYY-MM-DD.sql
```

Always back up the database and the data directory at the same point in time.
Otherwise metadata points to files that do not exist (anymore).
[Backups and updates](backups-updates.en.md) describes the full procedure.

## Common problems

| Symptom | Cause and fix |
| --- | --- |
| "Error 500" after an update | Migrations are missing → `occ upgrade` |
| File names with emojis fail | The database is not on `utf8mb4` → `db:convert-mysql-charset` |
| Very slow file lists | Indexes are missing because migrations did not run → `occ migrations:status core`, then `occ migrations:migrate core` |
| "Database is locked" | SQLite with several users at once → reinstall with MariaDB, see above |
| Connection drops under load | Match the database's `max_connections` to the PHP-FPM pool size, see [Performance](performance.en.md) |
