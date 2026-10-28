# SAML/SSO Integration

!!! info "API key required"
    The Marketplace shows this app only with a valid API key. You can request
    the key from BW-Tech GmbH. To enter it: open **Market** from the app menu,
    click **Add API Key** under **Settings** in the left column, paste the key
    into the **Your personal API Key** field, and click **Save**. The Market
    then reloads the catalog. With a wrong key, it reports "The API-Key is
    invalid!". For details, see [Enter an API key](market.en.md#api-schluessel).
    App ID `user_shibboleth`, version 4.0.0.

With this app, people log in with single sign-on through a SAML 2.0 identity
provider (IdP), for example a university's or a company's. The Shibboleth
service provider in Apache in front of owncloud.online handles the login itself.
The app reads the data the service provider passes on and assigns the person to
an account: either an account it creates at the first login, or an account from
another user backend such as LDAP.

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4, as PHP-FPM or `mod_php` |
| Web server | Apache 2.4 with Shibboleth service provider 3 (package `libapache2-mod-shib`, service `shibd`) |
| Identity provider | SAML 2.0, delivers a unique identifier; recommended: `eduPersonPrincipalName` (variable `eppn`) or `subject-id` |
| Marketplace | a valid API key, see the box above |

## Installation

With the API key in place: search for "SAML/SSO Integration" in the **Market**
and click **Install**. On the command line:

```bash
# store the API key without the web interface
sudo -u www-data php8.4 occ config:app:set market key --value='<API-KEY>'

# install and enable the app
sudo -u www-data php8.4 occ market:install user_shibboleth
```

Between the two commands, select **Clear cache** in the Market. The Market keeps
the catalog cached for up to 30 minutes; until then, `occ market:install` may
not know the app.

Alternatively, put the key into `config/config.php` as
`'marketplace.key' => '<API-KEY>'`. This entry takes precedence; the Market can
then no longer change the key.

After enabling, the mode is **Not active**. Until you choose another mode, the
app does not change the login.

## Web server and service provider

In the following setup, the same instance is reachable twice: at `/` without
SSO (local login, clients, public links) and at `/oc-shib` with the service
provider. On the SSO path, the service provider requires an IdP session only on
the login page. The example assumes PHP-FPM; with `mod_php`, leave out the
`FilesMatch` block.

```apache
<VirtualHost *:443>
    ServerName cloud.example.org
    DocumentRoot /var/www/owncloud.online

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/cloud.example.org.crt
    SSLCertificateKeyFile /etc/ssl/private/cloud.example.org.key

    # Without AllowOverride All, the .htaccess of owncloud.online is ignored
    <Directory /var/www/owncloud.online>
        Require all granted
        AllowOverride All
    </Directory>
    # Under PHP-FPM, the Authorization header only reaches PHP with CGIPassAuth On
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
        CGIPassAuth On
    </FilesMatch>

    Alias /oc-shib /var/www/owncloud.online
    # On the whole SSO path: attributes once an IdP session exists, but no enforcement
    <Location /oc-shib>
        AuthType shibboleth
        ShibRequestSetting requireSession false
        Require shibboleth
    </Location>
    # Only the login page requires an IdP session
    <LocationMatch "^/oc-shib/(index\.php/)?login">
        AuthType shibboleth
        ShibRequestSetting requireSession true
        Require shib-session
    </LocationMatch>

    <Location /Shibboleth.sso>
        AuthType None
        Require all granted
        SetHandler shib
    </Location>
</VirtualHost>
```

Without `AllowOverride All` or `CGIPassAuth On`, WebDAV, OCS, and client logins
return 401. In the app's form, enter `/oc-shib/index.php/login` as the
**Single sign-on entry point**.

If you run only one path, put `requireSession false` on `/` and
`requireSession true` on the login page. A local login is then only possible
through a separate exception in the web server.

The service provider must pass the attributes as environment variables, not as
headers. It sets `REMOTE_USER` from the attributes listed in
`/etc/shibboleth/shibboleth2.xml`:

```xml
<ApplicationDefaults entityID="https://cloud.example.org/shibboleth"
                     REMOTE_USER="eppn subject-id persistent-id">
```

In the default `/etc/shibboleth/attribute-map.xml`, `mail` and `displayName` are
commented out, and there is no attribute for the quota. Enable or add the lines
you need:

```xml
<Attribute name="urn:oid:0.9.2342.19200300.100.1.3" id="mail"/>
<Attribute name="urn:oid:2.16.840.1.113730.3.1.241" id="displayName"/>
<Attribute name="urn:oid:1.3.6.1.4.1.5923.1.1.1.6" id="eppn"/>
<!-- custom attribute for the quota; the OID is an example -->
<Attribute name="urn:oid:1.3.6.1.4.1.99999.1.1" id="quota"/>
```

Then restart `shibd` and reload the identity provider's metadata.

## Configuration

**Settings → Admin → User Authentication**, section **SAML/SSO Integration**.
At the top, the **This request** box checks the current request: if you open the
page through the SSO path, it shows the session, the user ID, and the account the
app would use. Below it, the page shows the server environment of the request,
with cookies, credentials, keys, and tokens hidden.

<figure markdown="span">
  ![Admin settings, SAML/SSO Integration with mode and server variables](../../assets/screenshots/en/admin-saml.png)
  <figcaption>For single sign-on through SAML, choose the mode and enter the web server's variables.</figcaption>
</figure>

### Mode

| Option | Value for `occ` | Effect |
| --- | --- | --- |
| Not active | `notactive` | Single sign-on is off. Default. |
| Create users automatically | `autoprovision` | Whoever logs in with single sign-on and has no account yet gets one. Existing accounts, including those from LDAP, are recognized and used. |
| Single sign-on only (accounts from another backend, e.g. LDAP) | `ssoonly` | Only people with an existing account, for example from LDAP, can log in. No new accounts are created. |

In every mode, password login stays available for local accounts and LDAP
accounts.

### Server variables

| Field | Default | Meaning |
| --- | --- | --- |
| Session | `Shib-Session-ID` | Variable that shows an active SSO session. Required. |
| User ID | `eppn` | Variable with the account's identifier. Required. |
| Email | `eppn` | Variable with the email address; with several addresses, the first valid one is used |
| Display name | `eppn` | Variable with the display name |
| Quota | `quota` | Variable with the storage quota, for example `5 GB`, `none`, or `default` |

Email, display name, and quota may stay empty. The app then does not take the
value from the IdP, and the account keeps its stored value. The fields also
accept variables that are missing on this page right now; suggestions appear
while you type.

### User ID mapper

| Option | Effect |
| --- | --- |
| Unchanged (default) | The identifier stays as the IdP delivers it. |
| AD FS: first value, lower case | Part before the first `;`, trimmed and in lower case; for AD FS, which often delivers the identifier twice |
| GUID: convert binary form into text form | Converts a GUID of 32 hex characters into the usual text form with hyphens |

### Login page and logout

| Field | Meaning |
| --- | --- |
| Single sign-on entry point | Address of the login page behind single sign-on, for example `/oc-shib/index.php/login`. When set, the login page shows a button that keeps the requested page. |
| Button label | Text of the button, at most 100 characters. Empty means "Log in with single sign-on". |
| Skip the login page | Whoever opens the login page without SSO goes straight to the single sign-on entry point. The local login stays reachable at `/index.php/login?direct=1`. |
| Logout address of the service provider | For example `/Shibboleth.sso/Logout`. When set, **Log out** ends the session in owncloud.online and then at the service provider. When empty, owncloud.online asks people logged in with SSO to close the browser. |

Turn on **Skip the login page** only if everyone logs in with single sign-on.
Otherwise guests and local accounts end up at the identity provider, where they
have no account. The logout address only helps if the identity provider
supports single logout; otherwise it logs the person in again right away.

If `config/config.php` still has its own entry for the SSO path under
`login.alternatives`, remove it once the entry point is set. Otherwise the login
page shows two identical buttons.

### Command line

```bash
# show or set the mode: notactive, autoprovision, ssoonly
sudo -u www-data php8.4 occ shibboleth:mode autoprovision

# show or set the server variables and the mapper
sudo -u www-data php8.4 occ shibboleth:mapping --uid eppn --email mail --display-name displayName --quota quota

# check the setup; exit code 0 = fine, 1 = problems
sudo -u www-data php8.4 occ shibboleth:check
```

`shibboleth:mapping` also accepts `--shib-session` and `--uid-mapper` with the
class name of the mapper, for example `OCA\User_Shibboleth\Mapper\ADFSMapper`.
`shibboleth:check` checks the mode, mapping, mapper, entry point,
`login.alternatives`, `overwrite.cli.url`, and the WebDAV address, and points out
unsafe mappings.

## How the app finds the account

1. If an account with exactly this identifier exists, the app uses it with its
   own backend.
2. Otherwise it searches the other backends, such as LDAP. Only exact matches
   count; upper and lower case do not matter. For local accounts, only the
   identifier counts, never the email address. If several accounts match, the
   app rejects the login.
3. Without a match, the mode **Create users automatically** creates a new
   account; **Single sign-on only** rejects the login.

Single sign-on never logs anyone in to a local account with administrator rights,
even if the IdP delivers the same identifier. These accounts log in with their
password at `/index.php/login?direct=1`. If someone needs administrator rights
with SSO, grant them to their SSO or LDAP account.

If a directory such as LDAP is unreachable, the login stops instead of creating
a duplicate account.

### Switch from "Create users automatically" to "Single sign-on only"

Accounts that the app created stay in its own backend. They keep working as
long as their identifier matches the SSO identifier exactly. If the lookup
finds an LDAP account whose identifier already belongs to an account of the
app, however, the app rejects the login. To move such an account to LDAP:

1. `occ shibboleth:check` shows how many accounts still belong to the app's
   backend.
2. Use `occ ldap:search <name>` and `occ user:list -a backend -a email <id>` to
   check under which identifier LDAP lists the person.
3. Back up the database. If LDAP uses the same identifier, switch the account's
   backend, for example on MariaDB or MySQL (backslashes are doubled there, but
   not on PostgreSQL and SQLite):

    ```sql
    UPDATE oc_accounts SET backend = 'OCA\\User_LDAP\\User_Proxy'
     WHERE user_id = 'carol' AND backend = 'OCA\\User_Shibboleth\\UserBackend';
    ```

    Then run `sudo -u www-data php8.4 occ user:sync "OCA\User_LDAP\User_Proxy" -u carol`.

4. If LDAP uses a different identifier, transfer files and shares to the LDAP
   account before you delete the old account.

Switching back to **Create users automatically** needs no conversion.

## Security notes

- **No headers as a source:** Variables the browser can send itself (`HTTP_*`,
  `PHP_AUTH_USER`, `PHP_AUTH_PW`) do not belong in the mapping. Use the service
  provider's environment variables, and `REMOTE_USER` or a scoped attribute as
  the identifier. The panel and `shibboleth:check` warn about unsafe mappings.
- **Unique identifier:** Map a scoped identifier such as `eppn` or `subject-id`,
  not a plain name like `uid` that an IdP account could share with the name of a
  local account.
- **New accounts** only get identifiers without slash, backslash, and control
  characters, at most 64 bytes long and with exactly one value. If the IdP
  delivers several values (`a;b`), the app creates no account. Fix: map a
  single-valued attribute or choose the AD FS mapper.
- On errors, the log contains only variable names and the mapping, never values
  from the server environment.

## Limitations and notes

- **Clients:** Desktop and mobile clients log in with an app password or through
  [OAuth2](oauth2.en.md), not through the IdP. `remote.php/nonshib-webdav` and
  `remote.php/dav` are available for this.
- **Encryption:** With single sign-on, there is no password from which per-user
  keys could be derived. [Encryption](../../administration/encryption.en.md)
  only works with the master key. If the encryption app is enabled without
  actually encrypting, it shows SSO accounts a misleading notice about the
  private key. Disable it in that case.
- **Windows network drives (WND):** The authentication method that uses the
  session's login credentials needs a password and does not work with SSO
  accounts. Stored credentials work.
- **No `user:sync`:** The backend cannot list accounts, so `occ user:sync` is not
  possible for it.
- **Links from emails** lead to the linked file or folder after the SSO login,
  even if the email points to the address without SSO.

## Upgrade from version 3.x

Settings and accounts of an earlier 3.x version of this app (same app ID) keep
working. To upgrade:

1. Remove the old `user_shibboleth` folder from the app directory or move it
   outside the instance. Do not unpack the new package over it, otherwise old
   files remain.
2. Unpack the new package and make the web server user its owner
   (`chown -R www-data:www-data user_shibboleth`).
3. Run `sudo -u www-data php8.4 occ upgrade`. `occ app:enable` is not enough
   here.
4. Run `sudo -u www-data php8.4 occ shibboleth:check`.

Deliberately different from 3.x: SSO no longer logs anyone in to local
administrator accounts, the email address of local accounts no longer leads to a match, and an
identifier with several values no longer creates a new account.
`shibboleth:check` lists affected accounts.

If `shibboleth:check` reports that the WebDAV address for clients is not
registered, this command restores it:

```bash
sudo -u www-data php8.4 occ config:app:set core remote_nonshib-webdav --value=user_shibboleth/appinfo/remote.php
```

## Troubleshooting

| Message or symptom | Cause | Fix |
| --- | --- | --- |
| "No single sign-on session in this request" in the **This request** box | The page was opened without the SSO path. | Open the page through the SSO path, for example `/oc-shib/index.php/settings/admin?sectionid=authentication` |
| "Login not possible: the identity provider did not send a user ID, or your account has not been enabled here." | The identifier variable is missing, or the identifier matches a local administrator account. | Check the mapping and `attribute-map.xml`; the log names the reason |
| "Login not possible: your sign-in matches more than one account." | Several accounts match the identifier. | Clean up the accounts or map a more unique identifier |
| "Login not possible: your account is managed by another user directory." | After switching to **Single sign-on only**, the identifier already belongs to an account of the app. | Move the account to the LDAP backend or transfer its data |
| "Login is temporarily not possible because the user directory cannot be reached." | LDAP is unreachable. | Check the connection to the directory |
| WebDAV and clients get 401 | `AllowOverride All` or `CGIPassAuth On` is missing. | Complete the web server configuration |
| New accounts have no email address | `mail` does not arrive. | Extend `attribute-map.xml` and restart `shibd` |
