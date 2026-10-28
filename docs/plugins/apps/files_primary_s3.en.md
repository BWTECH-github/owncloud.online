# S3 Primary Object Storage

!!! note "Available on the Marketplace"
    The app is listed on the Marketplace without an API key and installs
    directly from the Market app. This page covers version 1.6.5.

With this app, owncloud.online stores the content of every file in an S3
bucket instead of the data directory. File names, shares, and permissions stay
in the database. The app is meant for admins who build a new instance on
Amazon S3 or an S3-compatible object store such as Ceph RGW, MinIO, or
Scality. Users do not notice any difference.

If S3 should only appear as an additional folder, use
[External Storage: S3](files_external_s3.en.md).

!!! warning "Switch only on an empty instance"
    owncloud.online does not move existing files into the bucket. Accounts that
    had files before the switch no longer see them afterwards. The files stay
    in the data directory. Set up the object store before accounts store any
    files.

## Requirements {#voraussetzungen}

| Component | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 or later, with the extensions `curl`, `json`, `mbstring`, `xml`, and `openssl` |
| Object store | Amazon S3 or S3-compatible |
| Bucket | created in advance, versioning turned on |
| Credentials | Access key and secret key for the bucket |

## Installation {#installation}

1. Install the app. `market:install` also enables it:

    ```bash
    sudo -u www-data php8.4 occ market:install files_primary_s3

    # only needed if the app is present but disabled
    sudo -u www-data php8.4 occ app:enable files_primary_s3
    ```

2. Create the bucket with your provider's tools and turn on versioning there.
   You decide on region, encryption, lifecycle rules, and CORS at this point.
3. Add the `objectstore` entry to `config/config.php`, see
   [Setup](#einrichtung).
4. Without OPcache, the next request already uses the new storage. If PHP-FPM
   runs with OPcache, reload it once:

    ```bash
    sudo systemctl reload php8.4-fpm
    ```

5. Check: run `occ s3:list <bucket>`, upload a file, and run the command
   again. The new file shows up as the object `urn:oid:<file ID>`.

## Setup {#einrichtung}

The app has no settings page of its own. You configure it in
`config/config.php`:

```php
'objectstore' => [
    'class' => 'OCA\\Files_Primary_S3\\S3Storage',
    'arguments' => [
        'bucket' => 'oco-data',
        'options' => [
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'https://s3.example.com',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => '<ACCESS_KEY>',
                'secret' => '<SECRET_KEY>',
            ],
        ],
        // optional
        'serversideencryption' => 'AES256',
    ],
],
```

| Key | Required | Meaning |
| --- | --- | --- |
| `class` | yes | exactly `OCA\Files_Primary_S3\S3Storage` |
| `bucket` | yes | Name of the existing bucket |
| `options.version` | yes | API version of the S3 client, usually `latest` |
| `options.region` | yes | Bucket region, for example `us-east-1` |
| `options.endpoint` | for every service except AWS | Full endpoint address including `https://` |
| `options.use_path_style_endpoint` | no | `true` for Ceph and MinIO, `false` for AWS |
| `options.credentials.key` and `.secret` | yes | Credentials |
| `serversideencryption` | no | Encryption in the bucket, for example `AES256` |
| `part_size` | no | Part size in bytes for uploading large files, at least 5 MiB. Default 5 MiB |
| `concurrency` | no | Number of parts uploaded at the same time. Default 3 |

The app uploads large files in parts (multipart upload). This also allows
files larger than 5 GB.

### Commands

| Command | Effect |
| --- | --- |
| `occ s3:list` | All buckets of the account with their versioning and CORS status. The key must be allowed to list buckets |
| `occ s3:list <bucket>` | Objects in the bucket |
| `occ s3:list <bucket> <object>` | All versions and delete markers of one object |
| `occ s3:create-bucket <bucket>` | Creates a bucket and turns on versioning. Meant for tests only |

`s3:create-bucket` asks for confirmation first. `--accept-warning` skips the
question. `--update-configuration` turns on versioning for a bucket that
already exists.

```bash
sudo -u www-data php8.4 occ s3:list oco-data
sudo -u www-data php8.4 occ s3:list oco-data urn:oid:42
```

## Usage {#nutzung}

Nothing changes for users. The web interface, the desktop client, mobile apps,
and WebDAV work as before. Older states of a file come from the bucket's
versioning and appear in the *Versions* tab as usual.

## Limits and notes {#grenzen}

- **Encryption:** owncloud.online encryption (app `encryption`) does not work
  with primary object storage. **Settings → Admin → Encryption** then shows
  "Storage encryption is not compatible with S3 Object Storage." and you
  cannot turn encryption on. Encrypt in the bucket with
  `serversideencryption` instead.
- **Versions:** Versions exist only if versioning is turned on in the bucket.
  owncloud.online never deletes older versions in the bucket. Even files that
  were deleted permanently stay there as older versions. A lifecycle rule at
  your provider that removes older versions after a set time keeps the bucket
  small.
- **Backups:** The database and the bucket belong together. Back up both at the
  same point in time, or use your provider's replication and versioning. See
  [Backups and updates](../../administration/backups-updates.en.md).
- **Disk usage:** `du` on the data directory shows almost nothing. Your
  provider reports the space in use.
- **External storage:** Primary object storage and additionally mounted
  external storage work side by side. The comparison is under
  [External storage](../../administration/external-storage.en.md).

## Troubleshooting {#fehlersuche}

| Symptom | Cause | Fix |
| --- | --- | --- |
| "No S3 ObjectStore available" | The endpoint is not reachable or answers with an error | Check `endpoint` and `region`, then run `occ s3:list <bucket>` |
| Uploading and opening files fail, the server log shows an access error (403) | Wrong access key or secret key, or the key lacks permissions. The check on first access lets access errors pass, so that keys without permission for `HeadBucket` also work | Check `credentials` and the key's permissions on the bucket |
| "Bucket <…> does not exist." | The endpoint knows no bucket with this name, for example because of a typo or because `endpoint` points to another service | Check `bucket` and `endpoint` |
| "Connection options and bucket must be configured." | `options` or `bucket` is missing in the `objectstore` entry | Add the missing keys |
| "Upload failed. …", `MultipartUploadException` in the server log | Network problem or an outage at the provider | Check the server log. With Backblaze B2, the app retries the upload once by itself |
| `S3 Primary Object Storage dependencies are missing` | The app's `vendor/` directory is missing, for example after an install from Git | Install the app from the Market |
| `occ s3:list` without a bucket reports an access error | The key may not list buckets | Use `occ s3:list <bucket>` |
| `No object store is configured.` from `s3:list` | The `objectstore` entry is missing in `config/config.php` | Add the entry |
| `Cannot modify readonly property` after an update | Stale OPcache | `sudo systemctl reload php8.4-fpm` |

The full error message of the S3 client is in the server log, see
[Server log and error messages](../../administration/logging.en.md).
