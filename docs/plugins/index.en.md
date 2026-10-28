# Plugins

owncloud.online consists of a core and apps. Some of these apps are part of the
server and ship with it, such as files, sharing, deleted files, versions and
DAV. Everything else is a plugin: an app that is maintained and versioned on its
own and that you install, update and remove through the Marketplace without
replacing the server. The details are in [Managing apps](../administration/apps-market.en.md).

!!! info "Apps that require an API key"
    Some apps only show up in the Marketplace when an API key is entered in the
    Market app. You get the key from BW-Tech GmbH; how to enter it is described
    under [Apps that require an API key](../administration/apps-market.en.md#apps-that-require-an-api-key).
    In the overview, these apps are marked "API key".

## Overview

Every app has its own page with requirements, installation and setup.

### Security and login

| App | What it does | Availability |
| --- | --- | --- |
| [2-Factor Authentication](apps/twofactor_totp.en.md) | With this app, owncloud.online asks for a six-digit code after the password when someone logs in through the browser. | Marketplace |
| [Password Policy](apps/password_policy.en.md) | The app defines what passwords must look like: for user accounts and for passwords on public links. | Marketplace |
| [Brute-Force Protection](apps/brute_force_protection.en.md) | The app temporarily blocks logins when too many failed attempts for one account come from the same IP address. | Marketplace |
| [OAuth2](apps/oauth2.en.md) | The app turns owncloud.online into an OAuth 2.0 authorization server. | Marketplace |
| [OpenID Connect](apps/openidconnect.en.md) | The app logs people in through an external identity provider that speaks OpenID Connect, for example Keycloak, AD FS, or Microsoft Entra ID. The provider then manages passwords, multi-factor rules, and account locks; owncloud.online only checks its tokens. | Marketplace |
| [SAML/SSO Integration](apps/user_shibboleth.en.md) | With this app, people log in with single sign-on through a SAML 2.0 identity provider (IdP), for example a university's or a company's. | API key |
| [LDAP Integration](apps/user_ldap.en.md) | The app connects owncloud.online to an LDAP directory or Active Directory. | Marketplace |
| [Impersonate](apps/impersonate.en.md) | With this app, administrators, and optionally group admins, temporarily log in as another account. | Marketplace |

### Data protection

| App | What it does | Availability |
| --- | --- | --- |
| [Default encryption module](apps/encryption.en.md) | The `encryption` app encrypts the content of every file before owncloud.online writes it to storage, and decrypts it on read. | Marketplace |
| [Anti-Virus](apps/files_antivirus.en.md) | The `files_antivirus` app has a virus scanner check every uploaded file before owncloud.online stores it. | Marketplace |
| [BW-Tech File Firewall](apps/file_firewall.en.md) | The `file_firewall` app blocks file access according to rules you define: by IP range, group, device, time of day, file size, file type, tag, or type of request. | API key |
| [Audit Log](apps/audit_log.en.md) | The `audit_log` app records who did what and when: logins, file access, shares, changes to accounts and groups, tags, comments, enabling and disabling apps, and every `occ` command. | API key |

### Files and storage

| App | What it does | Availability |
| --- | --- | --- |
| [Windows Network Drive (Custom)](apps/wnd.en.md) | The app mounts shares from Windows and Samba file servers as external storage in owncloud.online. | API key |
| [External Storage: S3](apps/files_external_s3.en.md) | The app mounts a bucket in Amazon S3 or an S3-compatible object store as a folder in the Files app. | Marketplace |
| [S3 Primary Object Storage](apps/files_primary_s3.en.md) | With this app, owncloud.online stores the content of every file in an S3 bucket instead of the data directory. | Marketplace |
| [Auto Expire](apps/oco_expire.en.md) | Auto Expire cleans up old files automatically. | API key |
| [Workflow](apps/workflow.en.md) | Workflow assigns tags automatically on upload, based on rules, and deletes files with a certain tag after a set period. | API key |
| [Text Editor](apps/files_texteditor.en.md) | The Text Editor opens text files right in the browser and saves changes back to the file. | Marketplace |
| [PDF Viewer](apps/files_pdfviewer.en.md) | The PDF Viewer shows PDF files in the browser instead of downloading them. | Marketplace |
| [Media Viewer](apps/files_mediaviewer.en.md) | The Media Viewer shows pictures and videos in the browser, full screen, with paging, rotating, zooming, and video controls. | Marketplace |
| [Gallery](apps/gallery.en.md) | Gallery shows the pictures in a folder as an album in a grid. | Marketplace |

### Collaboration and office

| App | What it does | Availability |
| --- | --- | --- |
| [Office (Collabora Online)](apps/oco_office.en.md) | Office (Collabora Online) connects owncloud.online to a Collabora Online server. | API key |
| [ONLYOFFICE](apps/onlyoffice.en.md) | With ONLYOFFICE, users create and edit documents, spreadsheets, presentations, and PDF forms in the browser, alone or together with others at the same time. | Marketplace |
| [Collabora Online](apps/richdocuments.en.md) | With this app, users edit text documents, spreadsheets, and presentations in the browser. | Marketplace |
| [Calendar](apps/calendar.en.md) | Calendar is the web interface for the CalDAV server built into owncloud.online. | API key |
| [BWTalk](apps/bwtalk.en.md) | BWTalk adds chat as well as audio and video calls to owncloud.online: one-to-one and group conversations, public rooms with a guest link, screen sharing, and files from the instance right in the chat. | API key |
| [Guests](apps/guests.en.md) | With Guests, users share files and folders with people outside the organization without anyone creating an account first. | Marketplace |
| [Custom Groups](apps/customgroups.en.md) | With this app, users create their own groups, for example for a project or a team, and share files with all members at once. | Marketplace |
| [Schwarzes Brett](apps/schwarzes_brett.en.md) | Schwarzes Brett (German for "notice board") is a shared bulletin board for schools and teams. | API key |

### Administration and tools

| App | What it does | Availability |
| --- | --- | --- |
| [Market](apps/market.en.md) | With the Market app, admins install, update, and remove apps in the web interface. | Included in core |
| [Admin Config Report](apps/configreport.en.md) | The app collects the configuration of an instance in one JSON file: version, apps, database, PHP, external storage, and the contents of `config.php`. | Marketplace |
| [owncloud.online Theme](apps/theme-owncloudonline.en.md) | The theme defines the look of owncloud.online: colors, logo, login page, and email header. | API key |
| [First run wizard](apps/firstrunwizard.en.md) | The app shows every account a welcome window over the Files view after the first login. | Marketplace |
| [Announcement Center](apps/announcementcenter.en.md) | With this app, admins send messages to all accounts, for example about a maintenance window or a changed procedure. | Marketplace |
| [Activity](apps/activity.en.md) | The app records what happens to files and folders: new, changed, deleted, and restored files, shares, comments, tags, and downloads through public links. | Marketplace |
| [Notifications](apps/notifications.en.md) | The app receives messages from other apps, shows them under the bell in the top right corner, and passes them on to the desktop and mobile clients. | Marketplace |
| [AI Documents](apps/ai_documents.en.md) | AI Documents analyzes documents with an AI service that you choose. | Marketplace |
| [MCP Connector](apps/oco_mcp.en.md) | The MCP Connector turns an owncloud.online instance into a server for the Model Context Protocol (MCP). | Included in core |

## Plugins during a server update

During a server update, the risk is not the core but the plugins: an app that
no longer fits the new server version stops the update. That is why
`occ upgrade` first runs the step *Upgrade app code from the marketplace*. It
sorts all installed apps into three groups (compatible, incompatible, missing:
directory gone, database entry still there) and then tries to bring them to a
matching version through the Marketplace, in the order incompatible, missing,
compatible.

What happens next depends on the result:

* If an **incompatible or missing** app is left over, the update stops
  ("Upgrade is not possible"). Before that, the output lists every affected app
  with the matching `occ app:disable` command. Work through the list and run
  `occ upgrade` again.
* If a **compatible** app could not be updated, the update continues; the
  output says "App was not updated: …".

On a minor server update, the server does not move apps to a new major version
on its own. If you want that:

```bash
sudo -u www-data php8.4 occ upgrade --major
```

Two things that are easy to miss during an update:

* `apps-external/` is part of the code tree, not of the data directory. A backup
  of the data directory therefore does **not** contain the plugins you
  installed, see [Moving to another server](../administration/migration.en.md).
* If the directory of an app that is already loaded is replaced with a newer
  version while the server is running, `occ` itself can no longer start. Turn
  on maintenance mode **before** you swap the directory, see
  [Backups and updates](../administration/backups-updates.en.md).

## Settings in config.php

| Key | Default | Meaning |
| --- | --- | --- |
| `appstoreurl` | `https://marketplace.owncloud.online` | Address of the Marketplace. The value `local` or a `file://` address switches to a catalog that works without internet access |
| `apps_paths` | not set | List of app directories. The first entry with `'writable' => true` receives all new installations. Without the key, this is `apps/` |
| `has_internet_connection` | `true` | With `false`, the Market app refuses all access ("The Internet connection is disabled."), and a server update disables the Market app |
| `upgrade.automatic-app-update` | `true` | With `false`, `occ upgrade` does not contact the Marketplace. Apps stay as they are; you install updates by hand afterwards |
| `operation.mode` | `single-instance` | Any other value prevents installing apps through the web interface and through `occ` |
| `marketplace.key` | not set | API key for the Marketplace. If it is set here, it can no longer be changed in the Market app |

Set and check them like any other system key:

```bash
sudo -u www-data php8.4 occ config:system:set appstoreurl \
  --value https://market.example.com
sudo -u www-data php8.4 occ config:system:get appstoreurl
```

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| The Market app shows "Installing and updating Apps is not supported!" with the addition "This is a clustered setup or the web server has no permissions to write to the apps folder." | No app directory is writable, or `operation.mode` is not `single-instance` | Add an entry with `'writable' => true` to `apps_paths`; give the directory to the web server user; check `operation.mode` |
| `occ market:install` stops with "Installing apps is not supported because the app folder is not writable." | Same cause | Same fix |
| "Unknown app (%s)" | The ID is not in the catalog of the configured Marketplace, or the app requires an API key | Check the ID with `occ market:list`, check `appstoreurl` and the API key |
| "No compatible version for %s" | No version of the app fits the running server version | Check the server version with `occ status`. Installing the package by hand does not help: the app would stop the next `occ upgrade` |
| The Market app reports an update, `market:upgrade` does nothing | It is a major update; without `--major` it is skipped | `occ market:upgrade <app_id> --major` |
| A new version does not show up in the Market app | The catalog is cached for 30 minutes | In the Market app, choose *Settings → Clear cache* and reload the page |
| "The Internet connection is disabled." | `has_internet_connection` is `false` | Change the key, or switch to a local catalog with `appstoreurl` and install packages with `--local` |
| "Shipped apps cannot be uninstalled" | The app ships with the server | `occ app:disable <app_id>` instead of `market:uninstall` |
| "Market app can not uninstall itself." | The Market app is supposed to remove itself | Not possible; only `occ app:disable market` |
| `occ upgrade` stops with "Upgrade is not possible" | Incompatible or missing apps that the Marketplace could not fix | Run the `occ app:disable` commands from the output, then run `occ upgrade` again |
| During the update: "Market app is unavailable for updating of apps." | The Market app is disabled | `occ app:enable market`, then `occ market:upgrade --all` |
| After the update, an app is missing from the list | It was disabled as incompatible | `occ app:list --disabled` shows it with its version; enable it again after updating the app |
| No notification about new app versions | The check runs as a background job | Check cron, see [Background jobs (cron)](../administration/background-jobs.en.md) |
| Every `occ` call fails with `OC\NeedsUpdateException` after an app directory was replaced | The app is loaded at the start of every call | Turn on maintenance mode, see [Backups and updates](../administration/backups-updates.en.md) |

If the cause is still unclear, check the server log, see
[Server log and error messages](../administration/logging.en.md).

## Reporting a problem

Report problems with the server itself or with the Market app at
<https://github.com/BWTECH-github/owncloud.online/issues>. For all other
plugins, especially apps that require an API key, contact BW-Tech GmbH support
(<https://bw.tech>).

A useful report contains:

```bash
# server version
sudo -u www-data php8.4 occ status

# version and state of the affected app
sudo -u www-data php8.4 occ app:list

# system configuration; sensitive values are replaced with a placeholder
# (only --private would show them in plain text)
sudo -u www-data php8.4 occ config:list system
```

Add the server log entries from the time of the error
([Server log and error messages](../administration/logging.en.md)) and the
steps that reproduce the problem.
