# LDAP Integration

The app connects owncloud.online to an LDAP directory or Active Directory.
Accounts, groups, and attributes such as email address and storage quota come
from the directory, and the directory service checks the passwords.
owncloud.online does not store directory passwords. Several directories can be
connected side by side.

!!! note "Availability"
    Public in the Marketplace, no API key required. App ID `user_ldap`,
    version 0.21.0. Name in the Market: "LDAP Integration".

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 with the `ldap` extension (package `php8.4-ldap`). Without it, the app cannot be enabled. |
| Directory server | LDAP or Active Directory, reachable from the server. For `ldaps://` or StartTLS, the server must trust the directory server's certificate. |
| Bind account | An account with read access to the required subtree, or anonymous read access |
| Cron | for regular account synchronization |

## Installation

In the web interface: open **Market** from the app menu at the top left, search
for "LDAP Integration", and click **Install**.

On the command line:

```bash
sudo -u www-data php8.4 occ market:install user_ldap
```

`market:install` downloads, installs, and enables the app. If the app is already
present but disabled, this is enough:

```bash
sudo -u www-data php8.4 occ app:enable user_ldap
```

`php8.4 -m | grep ldap` shows whether the PHP extension is loaded.

## Configuration

The settings are under **Settings → Admin → User Authentication**, section
**LDAP**. A wizard guides you through four tabs, followed by **Advanced** and
**Expert**:

1. **Server:** **Host** and **Port** of the directory server, plus **User DN**
   and **Password** of the bind account, for example
   `uid=agent,dc=example,dc=com`. For anonymous access, leave both fields empty.
   The field **One Base DN per line** holds the subtree to search;
   **Detect Base DN** and **Test Base DN** help with that. For LDAP over SSL, the
   host starts with `ldaps://`; **Use StartTLS support** is something different
   and stays off in that case.
2. **Users:** defines which entries count as accounts, through
   **Only these object classes:** and **Only from these groups:** or directly
   through **Edit LDAP Query**. **Verify settings and count users** shows the
   result.
3. **Login Attributes:** defines what people log in with:
   **LDAP / AD Username:**, **LDAP / AD Email Address:**, or
   **Other Attributes:**. **Test Loginname** checks a name.
4. **Groups:** defines which entries count as groups.
   **Verify settings and count groups** shows the result.
5. **Advanced → Connection Settings:** select **Configuration Active**. Without
   this check mark, owncloud.online skips the configuration.

<figure markdown="span">
  ![Admin settings, LDAP wizard on the Server tab with empty fields for host, port, and user DN](../../assets/screenshots/en/admin-ldap.png)
  <figcaption>The LDAP wizard guides you through tabs for server, users, login attributes, and groups.</figcaption>
</figure>

For large directories, select **Manually enter LDAP filters (recommended for
large directories)** on the **Server** tab. The wizard then stops querying all
object classes and groups on every tab.

Each connection gets its own configuration ID (`s01`, `s02`, and so on). All
values are stored in the app configuration of `user_ldap`.

[Directory service (LDAP)](../../administration/ldap.en.md) describes the
individual fields, filters, attributes, and keys in detail.

## Command line

The app comes with eight commands, each prefixed with `sudo -u www-data php8.4`:

| Command | Purpose |
| --- | --- |
| `occ ldap:create-empty-config` | create a new, empty configuration; without an ID, the command assigns the next free one |
| `occ ldap:show-config [s01]` | show a configuration, all of them without an ID; `--show-password` reveals the bind password |
| `occ ldap:set-config s01 ldap_host ldaps://dc01.example.org` | set one value |
| `occ ldap:test-config s01` | validate the configuration and test the connection including the bind |
| `occ ldap:delete-config s01` | delete a configuration |
| `occ ldap:search "miller"` | search accounts; `--group` searches groups, `--limit` limits the hits (default 15) |
| `occ ldap:check-user jmiller` | check whether an account still exists in the directory |
| `occ ldap:invalidate-cache` | clear the LDAP cache, for example after changes in the directory |

`ldap:set-config` silently discards unknown keys. Check every change with
`ldap:show-config`.

## Synchronization with cron

Without synchronization, an account only appears at its first login, and an
entry deleted in the directory stays in owncloud.online. The app has no
background job of its own; the core command `user:sync` does the
synchronization:

```bash
# synchronize accounts; accounts missing in the directory are disabled
sudo -u www-data php8.4 occ user:sync ldap --missing-account-action=disable
```

In cron, `--missing-account-action` must be set. Without the option, the command
asks what to do with missing accounts, and in cron nobody answers that question.
`disable` locks missing accounts and keeps their data; `remove`
deletes them including their files. Entry in the web server user's crontab
(`sudo crontab -u www-data -e`), here hourly:

```
0 * * * * /usr/bin/php8.4 /var/www/owncloud.online/occ user:sync ldap --missing-account-action=disable
```

## Limitations and notes

- **Do not change the internal username later.** It is the name of the home
  folder and part of all addresses, including WebDAV. Changes under **Expert**
  only affect newly mapped accounts.
- **Do not clear the mapping.** **Clear Username-LDAP User Mapping** and
  **Clear Groupname-LDAP Group Mapping** clear the mapping for all LDAP
  configurations. Do not use these buttons on a production instance.
- **Bind password:** It is stored base64-encoded in the database, which is not
  encryption. Give the bind account read access only.
- **Certificate check:** **Turn off SSL certificate validation.** is only for
  narrowing down errors. It is better to make the directory server's certificate
  trusted on the server.
- The [Password Policy](password_policy.en.md) does not apply to LDAP accounts.
- The `user_webdavauth` app must not be active at the same time.
  owncloud.online does not offer it.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Warning "The PHP LDAP module is not installed, the backend will not work." | The PHP extension `ldap` is not loaded | Install `php8.4-ldap` and reload PHP-FPM |
| `ldap:test-config` reports "The configuration is invalid." | A required value is missing, or the login filter lacks `%uid`. | Check the values with `ldap:show-config` |
| `ldap:test-config` reports that the bind failed | User DN or password is wrong, or only one of them is set | Fill in both fields or leave both empty |
| Nobody can log in although the test passes | **Configuration Active** is not set. | `occ ldap:set-config s01 ldap_configuration_active 1` |
| A changed email address or quota does not arrive | The values are cached (default 600 seconds). | `occ ldap:invalidate-cache` |
| `user:sync` does not complete in cron | `--missing-account-action` is missing, so the command asks a question. | Always pass the option |
| The wizard tabs load very slowly | The wizard queries the whole directory. | Select **Manually enter LDAP filters** |

The app's messages are in the server log under `user_ldap`, see
[Server log and error messages](../../administration/logging.en.md).
