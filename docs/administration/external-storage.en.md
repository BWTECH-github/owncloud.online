# External storage

owncloud.online can mount other storage targets, such as SMB shares, SFTP and
WebDAV servers, or S3 buckets, as folders in the file tree. The files stay on
the source system; owncloud.online only keeps the metadata and passes access
through. The `files_external` app provides this feature; the
`files_external_s3` and `wnd` apps add more backends. The installation and
settings of these two apps are described on their plugin pages
[External Storage: S3](../plugins/apps/files_external_s3.en.md) and
[Windows Network Drive (Custom)](../plugins/apps/wnd.en.md).

!!! info "API key required"
    The `wnd` app (Windows Network Drive) is visible in the Marketplace only
    with an API key. You can request the key from BW-Tech GmbH. Enter it in the
    **Market** app under **Settings** → **Add API Key**. If the app still does
    not show up, reload the catalog with **Clear cache**. `files_external_s3`
    is available in the Marketplace without a key. For details, see
    [Apps that require an API key](apps-market.en.md#apps-that-require-an-api-key).

## Turning the feature on

The `files_external` app ships with owncloud.online and is enabled by default.
The feature itself is not: it depends on the app value
`enable_external_storage` of the `core` app, which defaults to `no`.

In the web interface: **Settings → Admin → Storage**, section
*External Storage*, checkbox *Enable external storage*.

On the command line:

```bash
sudo -u www-data php8.4 occ config:app:set core enable_external_storage --value yes
```

Only then does the file view show the navigation entry *External storage*. If
you turn the feature off again, all mounted storages disappear from all
accounts; the configuration stays in the database.

## Storage backends

A backend is the driver for one protocol. The identifier in the second column
is the value that `occ files_external:create` expects. It must match exactly,
including upper and lower case.

| Display name | Identifier | Provided by | Matching authentication schemes |
| --- | --- | --- | --- |
| WebDAV | `dav` | `files_external` | `password` |
| owncloud.online | `owncloud` | `files_external` | `password` |
| SFTP | `sftp` | `files_external` | `password`, `publickey` |
| Google Drive | `googledrive` | `files_external` | `oauth2` |
| SFTP with secret key login | `\OC\Files\Storage\SFTP_Key` | `files_external` | `publickey` |
| SMB Personal (unique file IDs) | `smb` | `files_external` | `password` |
| SMB Collaborative (shared file IDs) | `smb-coll` | `files_external` | `password::sessioncredentials` |
| SMB / CIFS using OC login | `\OC\Files\Storage\SMB_OC` | `files_external` | `password` |
| Local | `local` | `files_external` | `null` |
| Amazon S3 compatible (SDK v3) | `files_external_s3` | `files_external_s3` | `amazons3_accesskey` |
| Windows Network Drive | `wnd_custom` | `wnd` | `password`, `wnd_userprovided_db`, `wnd_logincredentials_db`, `wnd_kerberos` |
| Windows Network Drive (Collaborative) | `wnd_custom_collaborative` | `wnd` | `password`, `password::sessioncredentials`, `wnd_userprovided_db`, `wnd_logincredentials_db`, `wnd_kerberos` |

Notes on individual backends:

* *SFTP with secret key login* and *SMB / CIFS using OC login* are marked as
  deprecated and point to `sftp` and `smb`. The web interface no longer offers
  them under *Add storage*; use the successors for new mounts.
* *Local* is limited to administrators. It is also disabled until
  `config/config.php` contains `'files_external_allow_create_new_local' => true`.
  Accounts can never use this backend, whatever the settings.
* *SMB Collaborative* and *Windows Network Drive (Collaborative)* are limited to
  administrators as well.
* Not every entry in the last column is a scheme. `smb-coll` lists only
  `password::sessioncredentials`, and `wnd_custom_collaborative` lists it in
  addition. That value is the *identifier* of the mechanism *Log-in
  credentials, save in session*, whose scheme is `password`. The web interface
  matches backend and mechanism by scheme **or** identifier, so *SMB
  Collaborative* offers exactly this one mechanism.
* `wnd_userprovided_db` and `wnd_logincredentials_db`, on the other hand, have
  no effect: the matching mechanisms of the `wnd` app (`wnd::userprovided` and
  `wnd::logincredentials_db`) use the scheme `password`, and no mechanism has
  these values as its scheme or identifier. They do not change the choices
  offered.

## Authentication mechanisms

Each mechanism belongs to exactly one scheme. For a given backend, the web
interface offers the mechanisms whose scheme **or** identifier appears in the
column above. `occ files_external:create`, in contrast, compares schemes only.
That is why you cannot create an `smb-coll` storage on the command line: the
command stops with "Authentication backend … not valid for storage backend …".

| Display name | Identifier | Scheme | Provided by |
| --- | --- | --- | --- |
| None | `null::null` | `null` | Core |
| Builtin | `builtin::builtin` | `builtin` | Core |
| Username and password | `password::password` | `password` | Core |
| Log-in credentials, save in session | `password::sessioncredentials` | `password` | Core |
| OAuth1 | `oauth1::oauth1` | `oauth1` | `files_external` |
| OAuth2 | `oauth2::oauth2` | `oauth2` | `files_external` |
| RSA public key | `publickey::rsa` | `publickey` | `files_external` |
| OpenStack | `openstack::openstack` | `openstack` | `files_external` |
| Rackspace | `openstack::rackspace` | `openstack` | `files_external` |
| Access key | `amazons3::accesskey` | `amazons3_accesskey` | `files_external_s3` |
| Global credentials | `wnd::global_credentials` | `password` | `wnd` |
| User entered, stored in database | `wnd::userprovided` | `password` | `wnd` |
| Log-in credentials, saved in database | `wnd::logincredentials_db` | `password` | `wnd` |
| Credentials hardcoded in config file | `wnd::hardcoded_config` | `password` | `wnd` |
| Kerberos | `wnd::kerberos` | `wnd_kerberos` | `wnd` |

In practice:

* The four password mechanisms of the `wnd` app use the scheme `password`. They
  are therefore available for every backend with the password scheme, not just
  for the two Windows network drives. Only *Kerberos* has its own scheme and
  appears only there. *Credentials hardcoded in config file* is also limited to
  the admin view and is missing from the personal settings.
* This release ships no backend for `oauth1` or `openstack`. The three
  matching mechanisms are registered, but no backend offers them.
* *Log-in credentials, save in session* stores the username and password
  encrypted in the session as soon as the account logs in. Without a password
  login, for example WebDAV access with an app password, single sign-on, or a
  cron run, the credentials are missing and the storage is mounted as
  unavailable.

## Mounting in the web interface

**Settings → Admin → Storage**, section *External Storage*. Each row of the
table has the columns *Folder name*, *External storage*, *Authentication*,
*Configuration*, and *Available for*. The last column exists only in the admin
view.

<figure markdown="span">
  ![Admin settings, External Storage with two new entries: Windows Network Drive and Amazon S3, configuration fields filled in](../assets/screenshots/en/admin-external-storage.png)
  <figcaption>Pick the storage type under "Add storage". Each type has its own fields; empty required fields are marked red.</figcaption>
</figure>

1. Under *External storage*, choose the backend from *Add storage*.
2. Enter the *Folder name*. This is the mount point in the file tree.
3. Under *Authentication*, choose one of the mechanisms that match the backend
   and fill in the fields under *Configuration*.
4. Under *Available for*, enter accounts or groups. If you leave the field
   empty, the storage applies to everyone.

The gear icon of a row opens the *Advanced settings* with the mount options.
They correspond to these keys, which `occ files_external:option` sets as well:

| Label | Key | Default |
| --- | --- | --- |
| Enable encryption | `encrypt` | `true` |
| Set read-only | `read_only` | `false` |
| Enable previews | `previews` | `true` |
| Enable sharing | `enable_sharing` | `false` |
| Check for changes | `filesystem_check_changes` | `1` (*Once every direct access*; `0` is *Never*) |
| Compatibility with Mac NFD encoding (slow) | `encoding_compatibility` | `false` |

*Enable encryption* only appears if encryption is active on the instance.
*Enable sharing* always appears in the admin view; in the personal settings it
appears only while `allow_user_mount_sharing` is `yes`. With *Log-in
credentials, save in session*, *Enable sharing* is locked, with the hint
"Sharing cannot be enabled due to the chosen authentication method".

## Mounting with occ

The commands belong to the `files_external` app and are only available while
it is enabled.

First look at the available identifiers. The command lists backends,
mechanisms, and their parameters:

```bash
sudo -u www-data php8.4 occ files_external:backends
sudo -u www-data php8.4 occ files_external:backends storage smb
sudo -u www-data php8.4 occ files_external:backends authentication
```

Then create the mount. Without `--user` you get a storage for the whole
system; with `--user` you get a personal one:

```bash
sudo -u www-data php8.4 occ files_external:create \
  /Projects smb password::password \
  -c host=fileserver.example.com -c share=projects -c domain=EXAMPLE \
  -c user=svc_cloud -c password=secret
```

`--dry` creates nothing and only shows what would be created. On success the
command prints the storage ID; all following commands work with this ID. A
password passed with `-c` ends up in your shell history.

Set the accounts and groups a storage applies to. This only works for system
storages:

```bash
sudo -u www-data php8.4 occ files_external:applicable 3 --add-group projectteam
sudo -u www-data php8.4 occ files_external:applicable 3 --remove-user testaccount
sudo -u www-data php8.4 occ files_external:applicable 3 --remove-all
```

Read and change the backend configuration and mount options. Without a value,
the command prints the current one:

```bash
sudo -u www-data php8.4 occ files_external:config 3 host
sudo -u www-data php8.4 occ files_external:config 3 root /department/projects
sudo -u www-data php8.4 occ files_external:option 3 read_only true
```

Check the connection to the target. `-c` supplies values that are missing from
the stored configuration. For mechanisms that take the credentials from the
session this is mandatory, because `occ` has no session:

```bash
sudo -u www-data php8.4 occ files_external:verify 3
sudo -u www-data php8.4 occ files_external:verify 3 -c user=svc -c password=secret
```

List, back up, restore, and delete:

```bash
# system storages
sudo -u www-data php8.4 occ files_external:list
# personal storages of one account
sudo -u www-data php8.4 occ files_external:list jdoe
# all, including passwords
sudo -u www-data php8.4 occ files_external:list --all --show-password

sudo -u www-data php8.4 occ files_external:export > storages.json
sudo -u www-data php8.4 occ files_external:import storages.json
sudo -u www-data php8.4 occ files_external:delete 3 --yes
```

`files_external:export` is `files_external:list` with fixed options
(`--show-password`, `--full`, `--importable-format`, JSON), so the file contains
credentials in plain text. Handle it accordingly. `files_external:import` reads
from a file or, with `-`, from standard input; it rejects the old `mount.json`
format.

## Admin mounts and personal mounts

Both kinds use the same backends but behave differently.

| | Mounted by an administrator | Personal |
| --- | --- | --- |
| Created in | the admin settings, `files_external:create` without `--user` | the personal settings, `files_external:create --user <uid>` |
| Applies to | all accounts, or the accounts and groups set under *Available for* | only the account that created it |
| Can be moved in the file tree | no | yes |
| Can be removed by the account | no | yes |
| `files_external:applicable` | yes | no, the command stops with a message |

Before accounts can mount storage themselves, two app values are needed. The
checkbox *Allow users to mount external storage* writes `allow_user_mounting` in
the `files_external` app. The backends checked below it, under *Allow users to
mount the following external storage*, are stored as a list of their
identifiers in `user_mounting_backends`. If the list is empty, mounting stays
blocked even when `allow_user_mounting` is `yes`.

| App | Key | Default | Effect |
| --- | --- | --- | --- |
| `core` | `enable_external_storage` | `no` | turns the feature on as a whole |
| `files_external` | `allow_user_mounting` | `no` | lets accounts create their own mounts |
| `files_external` | `user_mounting_backends` | empty | identifiers of the backends allowed for this, comma-separated |
| `core` | `allow_user_mount_sharing` | `yes` | allows shares on storage that accounts mounted themselves (in the web interface: *Allow sharing on user-mounted external storages*) |

```bash
sudo -u www-data php8.4 occ config:app:set files_external allow_user_mounting --value yes
sudo -u www-data php8.4 occ config:app:set files_external user_mounting_backends --value smb,sftp,dav
sudo -u www-data php8.4 occ config:app:get core allow_user_mount_sharing
```

If `allow_user_mount_sharing` is `no`, owncloud.online sets `enable_sharing` to
`false` whenever it mounts a personal storage, regardless of the mount options.

The occ commands do not check these settings. `files_external:create --user`
creates a personal mount even when `allow_user_mounting` is `no`.

## External storage or primary object storage

Both are storage outside the local file system, but the two have nothing to do
with each other.

| | External storage | Primary object storage |
| --- | --- | --- |
| App | `files_external` (+ `files_external_s3`, `wnd`) | `files_primary_s3` |
| Covers | individual folders mounted in addition | all files of all accounts |
| Set up in | the web interface or `occ files_external:*` | `'objectstore'` in `config/config.php` |
| Can be changed in operation | yes, at any time | no, decide before going live |
| Switching later | not needed | existing files are not moved automatically |

The primary object storage replaces the root file system of the instance. If
`objectstore` or `objectstore_multibucket` is set, owncloud.online sets up the
object storage instead of the local root storage when it builds the file
system, and every account gets its home directory in the object storage.

With `files_external_s3` you can also mount an S3 bucket as an ordinary
external storage. That is the difference between "S3 is a folder" and "S3 is
where the instance keeps its data". The web interface and the storages API
explicitly refuse to configure an object storage as external storage; the
request is rejected with *Objectstore forbidden*.

`files_primary_s3` adds two commands:

```bash
sudo -u www-data php8.4 occ s3:list
sudo -u www-data php8.4 occ s3:list my-bucket
sudo -u www-data php8.4 occ s3:create-bucket my-bucket
```

`s3:create-bucket` is meant for testing. For production, create the bucket with
your provider's tools so that you keep control of region, encryption, and
lifecycle rules. The setup is described in
[S3 Primary Object Storage](../plugins/apps/files_primary_s3.en.md).

## SMB specifics

The following also applies to SMB and to the Windows network drives of the
`wnd` app:

* You need either the PHP extension `smbclient` (libsmbclient-php) or the
  command-line program `smbclient` in the `PATH`. If both are missing, the
  storage page reports that `smbclient` is not installed, and the backend
  disappears from the list.
* If the *Domain* field is filled in, owncloud.online builds the username as
  `DOMAIN\user` before it connects. So do not add the domain to the username
  as well.
* `'smb.logging.enable' => true` in `config/config.php` turns on debug output
  for SMB access; for the `wnd` app, the key is `'wnd.logging.enable' => true`.
  The messages only reach the log if `loglevel` is `0`.

About upper and lower case: SMB servers usually treat file names as
case-insensitive, while owncloud.online is case-sensitive. This matters in two
places.

First, the identifiers. `files_external:create` looks up the backend and the
mechanism as exact strings. `smb` works, `SMB` does not: the command stops with
"Storage backend with identifier … not found". The same applies to the list in
`user_mounting_backends`.

Second, the file names. If the server returns no match for a single lookup,
owncloud.online reads the parent directory instead and compares the names
exactly, that is, case-sensitively. If the requested spelling differs from the
one on the share, this fallback does not find the file. This is the same for the
PHP extension and for the `smbclient` program.

Besides its operational commands (`wnd:listen`, `wnd:notifications`,
`wnd:process-queue`), the `wnd` app has a command that tests an SMB share
independently of any mount:

```bash
sudo -u www-data php8.4 occ wnd:test fileserver.example.com projects svc_cloud --domain EXAMPLE --list
```

If you leave out the password, the command asks for it without echoing it.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| No *External Storage* section in the settings, no `files_external:*` in `occ list` | The `files_external` app is disabled | `occ app:enable files_external` |
| Section is there, but no entry in the file view | `enable_external_storage` is `no` | `occ config:app:set core enable_external_storage --value yes` |
| "Note: "smbclient" is not installed. Mounting of … is not possible." | Neither the PHP extension `smbclient` nor the program of the same name is installed | Install the extension or the program, reload PHP-FPM, reload the storage page |
| A backend is missing from the list although the app is enabled | Missing dependency; the storage page hides backends with unmet dependencies | Install the dependency; read the message above the table |
| *Local* is not offered | `files_external_allow_create_new_local` is not set | Set the key to `true` in `config/config.php`, or choose another backend |
| Red row, dialog *Credentials required* | The mechanism expects credentials per account and has none for this account yet | Enter the credentials in the dialog. It only appears for mechanisms that take input per account, such as *User entered, stored in database*. For other mechanisms the server refuses to save with "Storage with id "…" is not user editable". |
| Red row in the file list; clicking it reports "Insufficient data" | Mechanism *Log-in credentials, save in session*, and the session holds no credentials | Log out and log in again with a password; for access without a password login, choose a mechanism with stored credentials |
| `files_external:verify` reports missing data, the web interface shows the storage as green | `occ` has no session that a session-based mechanism could read | Supply the values with `-c user=… -c password=…` |
| `Storage backend with identifier "…" not found` | Identifier misspelled; it is case-sensitive | Copy the spelling from `occ files_external:backends storage` |
| `Authentication backend "…" not valid for storage backend "…"` | The scheme of the mechanism does not match the backend | `occ files_external:backends storage <identifier>` lists the allowed values under `supported_authentication_backends` |
| Accounts do not see the section for mounting their own storage | `allow_user_mounting` is `no`, or `user_mounting_backends` is empty | Set both values |
| A storage an account mounted itself cannot be shared | `allow_user_mount_sharing` is `no` and overrides `enable_sharing` | Set the app value to `yes` |
| *Enable sharing* is grayed out | Mechanism *Log-in credentials, save in session* | Choose a mechanism with stored credentials if the storage needs to be shared |
| `files_external:applicable` stops with "Can't change applicables on personal mounts" | The ID belongs to a personal storage | A personal storage always applies to exactly one account; use a system storage |
| Files stay local although `objectstore` is set | The primary object storage was set up afterwards | owncloud.online does not move existing data by itself |

Connection errors and their causes are in the log, see
[Server log and error messages](logging.en.md). All `occ` commands are listed in
the [occ reference](occ-reference.en.md); how to install the additional apps is
described in [Apps and Marketplace](apps-market.en.md).
