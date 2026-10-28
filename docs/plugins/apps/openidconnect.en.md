# OpenID Connect

The app logs people in through an external identity provider that speaks
OpenID Connect, for example Keycloak, AD FS, or Microsoft Entra ID. The provider
then manages passwords, multi-factor rules, and account locks; owncloud.online
only checks its tokens. Desktop and mobile clients can
log in through the provider as well.

!!! note "Availability"
    Public in the Marketplace, no API key required. App ID `openidconnect`,
    version 2.4.8. Name in the Market: "OpenID Connect".

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 |
| Memory cache | Redis, Memcached, or APCu, configured as `memcache.local` or `memcache.distributed`. As soon as a configuration exists, every web request fails without a cache. |
| Identity provider | Publishes a discovery document at `/.well-known/openid-configuration` and knows owncloud.online as a client with client ID and secret |

Set up the memory cache before you configure the app, see
[Security and setup warnings](../../administration/security-hardening.en.md).

## Installation

In the web interface: open **Market** from the app menu at the top left, search
for "OpenID Connect", and click **Install**.

On the command line:

```bash
sudo -u www-data php8.4 occ market:install openidconnect
```

`market:install` downloads, installs, and enables the app. If the app is already
present but disabled, this is enough:

```bash
sudo -u www-data php8.4 occ app:enable openidconnect
```

## Register owncloud.online with the identity provider

Create a client for owncloud.online at the provider and enter these addresses
(replace `cloud.example.com` with your own address):

| Purpose | Address |
| --- | --- |
| Redirect URI | `https://cloud.example.com/index.php/apps/openidconnect/redirect` |
| Logout initiated by the provider (front-channel logout) | `https://cloud.example.com/index.php/apps/openidconnect/logout` |

## Configuration

You can maintain the values in two places: in the form under
**Settings → Admin → OpenID Connect** or in `config/config.php`. Required
fields are marked with `*`.

Values saved in the form go into the app configuration and take precedence over
`config/config.php`. At the top of the form, **Configuration source** shows which
source applies: `appconfig` (form), `system` (`config.php`), or `empty`.
**Use system config** deletes the form values; after that, `config/config.php`
applies again.

<figure markdown="span">
  ![Admin settings, OpenID Connect with fields for Provider URL, Client ID, Client secret, and Scopes](../../assets/screenshots/en/admin-openidconnect.png)
  <figcaption>To log in through an identity provider, enter the provider URL, client ID, and client secret. Required fields are marked with *.</figcaption>
</figure>

### Main fields

| Field | Key in `config.php` | Meaning |
| --- | --- | --- |
| Provider URL | `provider-url` | Address of the provider (issuer) |
| Client ID | `client-id` | Identifier under which owncloud.online is registered with the provider |
| Client secret | `client-secret` | The matching secret |
| Scopes | `scopes` | Requested scopes, default `openid profile email` |
| Login button text | `loginButtonName` | Text of the button on the login page |
| Lookup mode | `mode` | **User ID** (`userid`, default): the claim is compared with the user ID. **Email** (`email`): the lookup uses the email address. |
| Search claim | `search-attribute` | Claim that identifies the account, for example `preferred_username` or `email`; default `email` |
| Allowed user backends | `allowed-user-backends` | Only accounts from these user backends may log in. Without an entry, all may log in; in `config.php`, leave the key out in that case, because an empty array blocks everyone. |
| Redirect login page automatically | `autoRedirectOnLoginPage` | The login page forwards to the provider right away. |
| Create missing users on first login | `auto-provision` → `enabled` | Unknown people get an account at their first login. |
| Groups | `auto-provision` → `groups` | Groups for newly created accounts |
| Update email and display name on login | `auto-provision` → `update` → `enabled` | Takes over the email address and display name at every login |
| Disable TLS verification | `insecure` | Turns off certificate checks, for tests only |

With the defaults, the app compares the `email` claim with the user ID. This
only works if the user IDs are email addresses. Otherwise, set **Lookup mode** to
**Email** or choose a different claim.

**Provisioning claim** and **Provisioning attribute** (`provisioning-claim`,
`provisioning-attribute`) work together as a filter: an account is only created
if the named claim contains the attribute.

### Example for config.php

```php
'openid-connect' => [
    'provider-url' => 'https://idp.example.com',
    'client-id' => 'owncloud-online',
    'client-secret' => '…',
    'loginButtonName' => 'Log in with your company account',
    'mode' => 'userid',
    'search-attribute' => 'preferred_username',
    'auto-provision' => [
        'enabled' => true,
        'groups' => ['oidc-users'],
        'email-claim' => 'email',
        'display-name-claim' => 'name',
        'update' => ['enabled' => true],
    ],
],
```

### Command line

The app has no `occ` commands of its own. The core commands are enough:

```bash
# show the form values
sudo -u www-data php8.4 occ config:app:get openidconnect openid-connect

# discard the form values; config.php applies again afterwards
sudo -u www-data php8.4 occ config:app:delete openidconnect openid-connect

# check the block in config.php
sudo -u www-data php8.4 occ config:list system
```

### Desktop client

When the desktop client sets up an account, it requests
`/.well-known/openid-configuration` from the server. If that fails, it silently
logs in through the [OAuth2](oauth2.en.md) app instead of the provider. The web
server therefore has to redirect this address to
`/index.php/apps/openidconnect/config`. The rules for Apache and nginx are in
[Login with OAuth2 and OpenID Connect](../../administration/single-sign-on.en.md).

## Usage

The login page shows a button with the configured text below the login form.
Clicking it leads to the provider; after logging in there, the person is logged
in to owncloud.online. With **Redirect login page automatically**, the browser
goes straight to the provider without a button. Logging out of owncloud.online
also ends the session at the provider if the provider offers a logout endpoint
(`end_session_endpoint`).

## Limitations and notes

- **Local login with automatic redirect:** With **Redirect login page
  automatically**, the local login form is no longer reachable. Make sure first
  that an administrator can log in through the provider. To undo it on the
  command line, `occ config:app:delete openidconnect openid-connect` discards
  the form values. If the redirect is set in `config/config.php`, remove
  `autoRedirectOnLoginPage` there.
- **Encryption:** Logging in through the provider does not supply an account
  password. With [encryption](../../administration/encryption.en.md), only the
  master key works.
- **Tokens of other clients:** Access tokens must be issued for this instance
  (check `token-aud-check`, on by default). owncloud.online rejects tokens that
  the same provider issued for a different client.
- **Several server nodes:** For a logout initiated by the provider to work
  everywhere, all nodes must share the same distributed cache.
- **Together with OAuth2:** Both apps can be active at the same time. Local
  OAuth2 clients and login through the provider then work side by side.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Every page reports "A real distributed mem cache setup is required" | No memory cache is set up | Set `memcache.local` or `memcache.distributed` |
| "Configuration issue in openidconnect app" during login | The configuration is missing or invalid | Check `occ config:app:get openidconnect openid-connect` and `occ config:list system` |
| Page "Sign-in with the identity provider failed", log entry "OpenID Connect provider request failed: …" | The provider is unreachable, returns an error status, or has no usable discovery document. Requests to the provider time out after 60 seconds. | Check DNS, firewall, proxy, and TLS from the server, for example `curl https://idp.example.com/.well-known/openid-configuration` |
| "Token cannot be verified" | Signature check failed: clock skew, wrong issuer, or the provider's keys are unreachable | Check time synchronization and that the provider's `jwks_uri` is reachable |
| "User anna is not known." or "User with anna@example.com is not known." | No account matches the claim, and **Create missing users on first login** is off. | Check **Lookup mode** and **Search claim**, or let the app create accounts |
| "anna@example.com is not unique." | `mode` is `email`, and several accounts share the address. | Switch to `userid` or clean up the accounts |
| "User is from wrong user backend" | `allowed-user-backends` excludes the account's backend. | Add the backend or remove the restriction |
| Changes to `config.php` have no effect | Form values take precedence. | **Use system config** or `occ config:app:delete openidconnect openid-connect` |
| Endless loop on the login page | Automatic redirect, and the provider sends the browser back without a session | Turn the redirect off while testing |
| The desktop client logs in locally | `/.well-known/openid-configuration` returns 404. | Set up the redirect in the web server |

Detailed messages of the app are in the server log, see
[Server log and error messages](../../administration/logging.en.md).
