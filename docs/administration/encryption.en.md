# Encryption

owncloud.online can store files encrypted on the server side. This protects the
content on the storage, especially on external storage that is not under your
own control. It is **not** end-to-end encryption: the server handles the keys,
and an attacker with access to the running server can read the content.

!!! warning "Read before you enable it"
    Encryption is hard to undo later: the way back costs all file versions and
    all trash bins (see [Disable](#disable)). If the keys are lost, the data is
    **permanently** unreadable. Before you enable it, make a full backup of the
    data **and** the database (see [Backups and updates](backups-updates.en.md))
    and test the restore first.

## When it makes sense

| Case | Recommendation |
| --- | --- |
| External storage (S3, SMB, other providers) | useful: the third-party storage only sees encrypted data |
| Your own server, your own disks | usually prefer disk encryption (LUKS): simpler, no key management in the application |
| S3 as primary storage (app `files_primary_s3`) | not possible: encryption cannot be turned on, and `encryption:enable` reports "Storage encryption is not compatible with S3 Object Storage." Use the bucket's server-side encryption instead (`serversideencryption`) |
| Protection against server administrators | not suitable: the server holds the keys |

## Enable

The `encryption` app is called "Default encryption module" in the Market app.
It is not part of the server release archive; it comes from the Marketplace
(public, no API key needed). `market:install` downloads, installs, and enables
it:

```bash
# Install and enable the app from the Marketplace
sudo -u www-data php8.4 /var/www/owncloud.online/occ market:install encryption

# Turn on encryption
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:enable

# Select the master key mode
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:set encryption useMasterKey --value 1

# Check the status
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:status
```

At the end, `encryption:enable` reports the default module
(`OC_DEFAULT_MODULE`). If encryption is on and no mode has been selected yet,
the app selects the master key mode itself on the next request to the server.
The `config:app:set` command above sets it explicitly.

In the web interface, the same is under **Settings → Admin → Encryption**: check
**Enable server-side encryption**, select **Master Key** under **Default
encryption module**, and confirm with **Permanently select this mode**.

<figure markdown="span">
  ![Admin settings, Encryption with the Enable server-side encryption option](../assets/screenshots/en/admin-encryption.png)
  <figcaption>Turn on server-side encryption here. Read the notes on keys and backups first.</figcaption>
</figure>

Anyone who is logged in during the change has to log out and log back in once,
so that the keys are available in the session. Until then, the page shows a
notice that the keys are not initialized.

Existing files are **not** encrypted automatically, only files written from now
on. To encrypt existing files afterwards:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:encrypt-all
```

The command asks for confirmation before it starts (`-y` skips the question).
While it runs, it switches on single-user mode itself, in which only members of
the `admin` group can reach the server, and it disables the trash bin
temporarily. Do **not** turn on maintenance mode for this: in maintenance mode,
`occ` loads no apps, so the encryption module is missing and the command
aborts.

Depending on the amount of data, the run takes a long time. Start it in a
session that is not interrupted (`screen`, `tmux`, or a systemd unit).

## Master key or per-user keys

| | Master key | Per-user keys |
| --- | --- | --- |
| Selection in the user interface | the only mode offered | no longer selectable, considered deprecated |
| A user changes the password | no issue | the private key is re-encrypted with the new password |
| Password forgotten | data stays readable | data is lost without a recovery key |
| Login without a password (OAuth2, OpenID Connect) | works | encrypted files cannot be read in that session |
| Shares, federation | no issue | limited |
| Recommendation | **default for owncloud.online** | only for existing installations |

Per-user keys remain only for installations that already use them. If the app
value `userSpecificKey` is set, the panel shows a notice that this mode is no
longer supported.

With per-user keys, always enable a recovery key; otherwise every forgotten
password leads to data loss. The recovery key is created in the **Default
encryption module** section; each account agrees to recovery itself under
**Settings → Personal → Encryption**.

## Disable

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:decrypt-all
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:disable
```

`encryption:decrypt-all` turns encryption off at the start, decrypts the files
of all accounts, and, like `encrypt-all`, works in single-user mode, also
without maintenance mode. With per-user keys, the command needs the account
passwords or the recovery key.

The command only processes the files in the `files/` area of each account. File
versions and the trash bin stay encrypted. `encryption:disable` stops as long
as any encrypted file is known and reports "The system still has
encrypted files. Please decrypt them all before disabling encryption." The way
back therefore means deleting all file versions and emptying all trash bins.
Without an account argument, both commands affect **all** accounts, and they
cannot be undone:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ versions:cleanup
sudo -u www-data php8.4 /var/www/owncloud.online/occ trashbin:cleanup
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:disable
```

Only then disable the app: without the enabled app, any remaining encrypted
files can no longer be read.

## Operating notes

- **Back up the keys**: they are in the data directory under
  `files_encryption/` and `<user>/files_encryption/`. If the key storage was
  moved with `encryption:change-key-storage-root`, they are there instead;
  `encryption:show-key-storage-root` shows the location.
- **Back up `secret`**: the value `secret` in `config/config.php` is the
  password of the private master key. A backup without the key storage or
  without `secret` is worthless.
- **Disk usage** grows slightly (block structure and a header per file).
- **Previews** have to be decrypted on the server. That costs CPU, see
  [Performance](performance.en.md).
- Errors during encryption and decryption are in the log, see
  [Server log and error messages](logging.en.md).
