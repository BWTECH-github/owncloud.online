# Installation

owncloud.online runs on a Linux server with PHP 8.4, a web server, and a
database. There are three ways to a finished installation. The table helps you
choose.

## Which option fits? {#variante}

| Option | Use | What you need | Guide |
| --- | --- | --- | --- |
| Linux server | production, recommended | your own server or VPS with Ubuntu 24.04 LTS or Debian 12, SSH access with administrator rights | [Installing on a Linux server](linux-server.en.md) |
| Web hosting with Plesk | small teams without their own server | hosting plan with PHP 8.4, MySQL or MariaDB, and scheduled tasks | [Installing on web hosting with Plesk](webhosting.en.md) |
| Windows with WSL | trying it out on your own computer, not for production | Windows 10 version 2004 or later, or Windows 11 | [Testing owncloud.online on Windows](local-wsl.en.md) |

If in doubt, choose the Linux server. Only there are all maintenance commands
available. On web hosting without a command line, owncloud.online can only be
updated up to 50 accounts, see
[Limits of web hosting](webhosting.en.md#grenzen).

## Requirements at a glance {#voraussetzungen}

| Component | Requirement |
| --- | --- |
| Operating system | Linux, 64-bit. The guides cover Ubuntu 24.04 LTS and Debian 12. Windows only through WSL and only for testing. |
| PHP | 8.4 with PHP-FPM. Older versions do not start. PHP 8.5 is not supported. |
| Database | MariaDB or MySQL. SQLite only for testing. |
| Web server | Apache 2.4 or nginx |
| Memory | at least 2 GB |
| Disk space | about 120 MB for the program, plus space for apps and your files |
| Address | a name of your own such as `cloud.example.com` that points to the server |
| HTTPS | required for production. A certificate from Let's Encrypt is enough. |
| Background jobs | a schedule that runs a command every 15 minutes (cron or "Scheduled Tasks") |

Before you start, also have ready:

- access to the DNS settings of your domain,
- SMTP access for sending email (password resets, invitations,
  notifications),
- a place for backups outside the server.

## The release {#release}

All options use the finished release from
<https://github.com/BWTECH-github/owncloud.online/releases>. Nothing needs to
be built or compiled.

| File | Contents |
| --- | --- |
| `owncloud-online-<version>.tar.gz` | the program for Linux servers and WSL |
| `owncloud-online-<version>.zip` | the same contents for uploading to web hosting |
| `SHA256SUMS.txt` | checksums to verify the download |

Both archives contain a single folder `owncloud/` with the server, the bundled
apps, and the "Market" app, which you use to install more apps from the
Marketplace. A `config/config.php` is not included. The installation writes
it.

??? note "Other files in the release"
    | File | Contents |
    | --- | --- |
    | `sbom-owncloud-online-<version>.cdx.json` | bill of materials of the included components in CycloneDX format |
    | `release-manifest.json` | version and PHP version of the build |
    | `removed-release-files.txt` | development files removed during the build |

## After the installation {#danach}

1. Log in and check the *Security & setup warnings* section under
   *Settings → Admin → General*, see
   [Security and setup warnings](../administration/security-hardening.en.md).
2. Set up email, see [Sending email](../administration/email.en.md).
3. Set up backups, see
   [Backups and updates](../administration/backups-updates.en.md). That page
   also explains how to update owncloud.online.
4. Install more apps, see
   [Managing apps](../administration/apps-market.en.md).
5. Create accounts and groups, see
   [Users and groups](../administration/users-groups.en.md).
6. Roll out the [desktop client](../user/desktop-client.en.md).

## Quick check {#funktionstest}

Open `https://cloud.example.com/status.php` in your browser, with your own
address. A running installation answers like this:

```json
{"installed":true,"maintenance":false,"needsDbUpgrade":false,"version":"11.0.25","versionstring":"11.0.25","edition":"Community","productname":"owncloud.online","product":"owncloud.online"}
```

`installed` must be `true`, `maintenance` and `needsDbUpgrade` must be
`false`.
