# OAuth2

The app turns owncloud.online into an OAuth 2.0 authorization server. Desktop,
Android, and iOS clients as well as other applications log in through the
browser and receive a token; they do not store the account password. Users see
in their settings which applications have access and can revoke access for each
one.

!!! note "Availability"
    Public in the Marketplace, no API key required. App ID `oauth2`,
    version 0.10.1. Name in the Market: "OAuth2".

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 with the extensions `gmp`, `intl`, and `mbstring` |
| Database | MySQL or MariaDB, PostgreSQL, Oracle, or SQLite |
| Web server | It passes the `Authorization` header on to PHP. This matters especially with PHP-FPM and reverse proxies. |
| Cron | A daily background job deletes expired codes and tokens. |

## Installation

In the web interface: open **Market** from the app menu at the top left, search
for "OAuth2", and click **Install**.

On the command line:

```bash
sudo -u www-data php8.4 occ market:install oauth2
```

`market:install` downloads, installs, and enables the app. If the app is already
present but disabled, this is enough:

```bash
sudo -u www-data php8.4 occ app:enable oauth2
```

When the app is enabled for the first time, it registers three clients with
fixed identifiers: "Desktop Client", "Android", and "iOS". Do not delete or
change these entries, otherwise the matching apps can no longer log in through
OAuth2.

## Configuration

**Settings → Admin → User Authentication**, section **OAuth 2.0**:

- The **Registered clients** table shows **Name**, **Redirection URI**,
  **Client Identifier**, **Secret**, **Subdomains allowed**, and
  **Trusted client**. The trash icon deletes a client.
- Under **Add client**, enter **Name** and **Redirection URI**, select
  **Allow subdomains** and **Trusted client** if needed, and click **Add**.
  owncloud.online generates the client identifier and secret.

| Switch | Effect |
| --- | --- |
| Allow subdomains | The redirect may also point to subdomains of the registered address. |
| Trusted client | The consent page is skipped; the client receives the code directly. |

<figure markdown="span">
  ![Admin settings, OAuth 2.0 with one registered client and the Add client form](../../assets/screenshots/en/admin-oauth2.png)
  <figcaption>Under "OAuth 2.0" you register clients with a name and redirection URI. owncloud.online generates the client ID and secret.</figcaption>
</figure>

owncloud.online checks the redirection URI exactly: scheme, host, port, path, and
query must match. Desktop programs are the exception: if `http://localhost:*` is
registered, the client may redirect to `localhost`, `127.0.0.1`, or `[::1]` with
any port (RFC 8252).

### Clients on the command line

```bash
# register a client with your own identifier; client ID and secret need at least 32 characters each
sudo -u www-data php8.4 occ oauth2:add-client "Intranet" \
  "$(openssl rand -hex 32)" "$(openssl rand -hex 32)" \
  "https://intranet.example.com/oauth/callback"

# list all clients including their secrets
sudo -u www-data php8.4 occ oauth2:list-clients

# change one value
sudo -u www-data php8.4 occ oauth2:modify-client "Intranet" redirect-url "https://intranet.example.com/callback"

# delete a client
sudo -u www-data php8.4 occ oauth2:remove-client <client-id>
```

After the redirect URL, `oauth2:add-client` accepts three more values in this
order, each `true` or `false`: `allow-sub-domains`, `trusted`, and
`force-trust`. You only need `force-trust` to register `localhost` as trusted,
which is meant for test setups. `oauth2:modify-client` changes `name`,
`client-id`, `client-secret`, `redirect-url`, `allow-sub-domains`, or `trusted`.

### Token lifetime

| Key (app `oauth2`) | Default | Allowed | Meaning |
| --- | --- | --- | --- |
| `access_token_lifetime` | 3600 | 10 to 86400 | Validity of an access token in seconds |
| `refresh_token_grace_period` | 180 | 0 to 3600 | For this many seconds, a refresh token that was already redeemed returns the same new token pair again, in case the response got lost on the way. 0 turns this off. |

```bash
sudo -u www-data php8.4 occ config:app:set oauth2 access_token_lifetime --value=3600
```

An authorization code is valid for 10 minutes. Every refresh replaces the
refresh token with a new one.

### Endpoints for your own applications

| Purpose | Address |
| --- | --- |
| Authorization | `/index.php/apps/oauth2/authorize` |
| Token | `/index.php/apps/oauth2/api/v1/token` |
| User data (OpenID Connect UserInfo) | `/index.php/apps/oauth2/api/v1/userinfo` |

The app implements the authorization code flow according to RFC 6749, with PKCE
(`S256` or `plain`). For more on endpoints and flows, see
[Login with OAuth2 and OpenID Connect](../../administration/single-sign-on.en.md).

## Usage

When connecting, the client opens the browser. After the login, including the
code if [two-factor authentication](twofactor_totp.en.md) is set up,
owncloud.online shows a consent page with the application's name. **Authorize**
grants access; you can close the browser window afterwards.

To revoke access: **Settings → Personal → Security**, section **OAuth 2.0**,
list **Authorized Applications**. The delete icon (**Revoke authorization**)
next to an application removes its codes and tokens for your own account.

## Limitations and notes

- **Deleting a client:** In the web interface, deleting a client also removes
  all codes and tokens of all accounts. `occ oauth2:remove-client` only deletes
  the entry; access tokens already issued stay valid until they expire.
- **Encryption:** OAuth2 never sees the account password. With
  [encryption](../../administration/encryption.en.md), only the master key works,
  not per-user keys.
- **Tokens only for clients:** With `'token_auth_enforced' => true` in
  `config/config.php`, clients can no longer log in with the account password,
  only with a token or app password.
- **Together with OpenID Connect:** If the [OpenID Connect](openidconnect.en.md)
  app is enabled as well, OAuth2 passes unknown tokens on to it. Both apps can
  run at the same time.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `invalid_client` at the token endpoint | Client ID or secret is wrong, or the web server or proxy does not pass the `Authorization` header on | Compare the values with `occ oauth2:list-clients`; pass the header on |
| `invalid_grant: auth grant redirect uri invalid` | The redirection URI does not match the registration. | Correct the URI with `occ oauth2:modify-client` |
| `invalid_grant: auth grant redirect uri mismatch` | The token request and the authorization use different redirection URIs. | Send the same URI in both requests |
| `invalid_grant: code verifier invalid` | The PKCE verifier does not match the challenge. | Check the encoding in the client (`base64url(sha256(verifier))` without padding) |
| Page "Request not valid" instead of the consent page | Unknown client ID or a different redirection URI | Register the client or align the URI |
| `Cannot set localhost as trusted.` | `localhost` is supposed to be trusted. | Set `force-trust` to `true`, for tests only |
| Expired tokens stay in the database | The daily cleanup job is not running. | Check cron; the tokens are rejected anyway |
