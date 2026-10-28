# Two-factor authentication

owncloud.online checks the second factor in the core, but it does not provide
one itself. The factor comes from an app that registers itself under
`<two-factor-providers>` in its `info.xml`. For time-based one-time passwords
(TOTP) that app is `twofactor_totp`. Without such an app enabled, login does not
ask for a second factor, even when enforcement is switched on.

## Install the provider app

The app does not ship with the core. It is publicly available in the
Marketplace (no API key needed) and is installed like any other app through the
Market app, see [Apps and Marketplace](apps-market.en.md). On the command line,
one command installs and enables it:

```bash
sudo -u www-data php8.4 occ market:install twofactor_totp
```

If the app is already present and only disabled, this is enough:

```bash
sudo -u www-data php8.4 occ app:enable twofactor_totp
```

The app creates the QR codes with imagick and requires the extension in its
`info.xml` (`<lib>imagick</lib>`). If `php8.4-imagick` is missing,
`occ app:enable` refuses to enable the app and names the missing library ("The
library imagick is not available."). `market:install` does not check the
library and enables the app anyway. Without the extension, however, the secret
cannot be shown as a QR code, neither in the account settings nor on the login
page. So install `php8.4-imagick` before the app.

To see which apps are enabled:

```bash
sudo -u www-data php8.4 occ app:list
```

## Set up the second factor in an account

Go to **Settings → Personal → Security**, section **TOTP Second-factor Auth**:

1. Check **Activate TOTP**. The server then creates a new secret and shows it
   together with a QR code.
2. Scan the QR code with the authenticator app. If you cannot scan, type in the
   value shown after *This is your new TOTP secret:* by hand.
3. Enter the six-digit code from the app in the **Authentication code** field
   and click **Verify**. The answer is *Verified* or *Not verified*.

<figure markdown="span">
  ![Setting up two-factor authentication with TOTP: QR code and field for the verification code](../assets/screenshots/en/personal-2fa.png)
  <figcaption>After you activate TOTP, scan the QR code with an authenticator app and verify a code. The second factor is active only after that.</figcaption>
</figure>

Step 3 is the one that matters: only a **verified** secret counts. If the box is
checked but no code is ever verified, login stays as it was. The next time the
page loads, the box is empty again, because its state follows the verified
secret. Unchecking the box deletes the secret on the server. Activating it again
creates a new secret and requires a new scan.

Timing: the code changes every 30 seconds, and the check accepts three time
steps in both directions. That allows a difference of about one and a half
minutes between the server clock and the device clock. A code that was already
accepted is not accepted a second time; the server stores the last verified code
for this purpose.

## Enforce the second factor

Enforcement is under **Settings → Admin → Security**, section **Two-factor
Authentication**. It has two controls:

- the checkbox **Enforce two-factor authentication to all the users**
- the field **Exclude the following groups from enforcing two-factor
  authentication**

<figure markdown="span">
  ![Admin settings, Security: enforce two-factor authentication, with exceptions for groups](../assets/screenshots/en/admin-2fa.png)
  <figcaption>Here you enforce two-factor authentication for all users. Groups in the field below are exempt.</figcaption>
</figure>

Both controls write to the app configuration:

| Key | App | Meaning |
| --- | --- | --- |
| `enforce_2fa` | `core` | `yes` turns enforcement on, any other value leaves it off |
| `enforce_2fa_excluded_groups` | `core` | JSON list of exempt groups, default `[]` |

The same settings on the command line:

```bash
sudo -u www-data php8.4 occ config:app:set core enforce_2fa --value yes
sudo -u www-data php8.4 occ config:app:set core enforce_2fa_excluded_groups --value '["Engineering","Board"]'
sudo -u www-data php8.4 occ config:app:get core enforce_2fa
sudo -u www-data php8.4 occ config:app:get core enforce_2fa_excluded_groups
```

To turn it off, use `--value no` or delete the key:

```bash
sudo -u www-data php8.4 occ config:app:delete core enforce_2fa
```

### What enforcement per group actually does

The model only knows exceptions, not an allow list: enforcement applies to
everyone, and the listed groups are exempt. To enforce the factor for a single
group only, every other group must be on the exception list. Accounts that
belong to no group cannot be exempted this way, because the check only compares
group memberships. Members of exempt groups can still set up the factor
voluntarily; the user interface says so, too.

### Behavior when the second factor is enforced

- Accounts without a configured factor are not locked out. On the factor page
  the app creates a secret and shows the QR code, so setup happens right at
  login. The code entered there also counts as verification of the new secret.
- Enforcement overrides the per-account switch-off (see below).
- Without an enabled provider app, enforcement has no effect, because there is
  no factor to ask for.

The flow after a correct password: the **Two-step verification** page appears
with the list of available factors. With exactly one factor, the page skips the
list and asks for the code right away. Until the code is correct, the server
does not let you reach any other page; only **Cancel login** leads out.

## App passwords and client sync

When an account uses a second factor, the server rejects every client login
that sends the account password. Requests with an app password (device token)
keep working.

| Access path | Behavior with an active second factor |
| --- | --- |
| Login in the browser | unchanged, the factor is requested |
| Client with the account password (WebDAV, OCS) | rejected; the log shows `Login failed: '<account>' (Remote IP: '<ip>')` |
| Client with an app password | keeps working |
| Token generation via `index.php/token/generate` | rejected with HTTP 401 |
| Browser session that is already logged in | not asked for the factor afterwards; the prompt happens at login |

Existing app passwords stay valid. Turning on the second factor does not revoke
any tokens. That is convenient for clients that are already set up, and it is
also the weak spot of the measure: a leaked app password still bypasses the
second factor. If you introduce the factor to shut out compromised credentials,
go through the tokens and revoke them.

Create and revoke app passwords under **Settings → Personal → Security**,
section **App passwords / tokens**: enter a name and click **Create new app
passcode**. *Username* and *Password / Token* are then shown only once. On the
same page, the *Sessions* section lists the web, desktop, and mobile clients
that are currently logged in.

<figure markdown="span">
  ![Personal settings, Security: two sessions and two app passwords](../assets/screenshots/en/personal-security.png)
  <figcaption>Under "Security" you see logged-in browsers and devices. Create app passwords for desktop and mobile apps here.</figcaption>
</figure>

Independent of the second factor, you can block login with the account password
for all clients in `config/config.php`:

```php
'token_auth_enforced' => true,
```

Login in the browser is not affected.

## When an account has lost its second factor

The second factor cannot be recovered, only reset. After that, the account sets
up a new device.

In the admin settings, use **Settings → Admin → User Authentication**, section
**TOTP Admin Reset**: find the account with *Search users*, select it in the
list, and click *Reset selected*. *Reset all TOTP setups* resets every setup on
the instance.

The same on the command line:

```bash
sudo -u www-data php8.4 occ twofactor_totp:delete-secret alice
sudo -u www-data php8.4 occ twofactor_totp:delete-secret alice bob
sudo -u www-data php8.4 occ twofactor_totp:delete-secret --all
```

Without an account and without `--all`, the command stops with a message. The
output shows the number of deleted secrets per account.

What happens next depends on enforcement. If it applies to the account, the
account sets the factor up again on the factor page at the next login. If it
does not apply, the second factor is off for that account until it is switched
on again in the account settings.

Two more options that keep the secret:

```bash
# Switch the factor off for one account without deleting the secret
sudo -u www-data php8.4 occ user:setting alice core two_factor_auth_disabled --value 1

# Undo the switch-off
sudo -u www-data php8.4 occ user:setting alice core two_factor_auth_disabled --delete

# Mark the secret as "not verified"; the factor is then off unless enforcement applies
sudo -u www-data php8.4 occ twofactor_totp:set-secret-verification-status false --uid alice
```

Both options only work while enforcement does not apply to the account, because
enforcement is checked first. With an enforced factor, a secret marked as "not
verified" only leads back to the factor page, where the same secret appears
again as a QR code. The switch-off via `two_factor_auth_disabled` is also
available in the Provisioning API: send a PUT to `ocs/v1.php/cloud/users/<uid>`
with the key `two_factor_auth_enabled` (value `true` or `false`). When you read
the account data, the API returns the same field.

Clean up secrets of accounts that were already deleted:

```bash
sudo -u www-data php8.4 occ twofactor_totp:delete-redundant-secret
```

!!! warning "Do not disable the app for testing"
    Disabling the `twofactor_totp` app deletes the secrets of **all**
    accounts. If you disable it "just for a quick test", every account on the
    instance has to set up TOTP again. Deleting an account also removes its
    secret.

## Interaction with brute-force protection

Brute-force protection counts failed **password** checks per combination of IP
address and login name. The first attempts carry no delay; after that the delay
grows, and the login form shows the remaining time. Behind a reverse proxy, the
count is only correct if `trusted_proxies` is set correctly, see
[Security and setup warnings](security-hardening.en.md).

For the second factor, the order matters:

1. Wrong password: the attempt is counted, and the factor page is never
   reached.
2. Correct password: the counter for this pair of IP address and login name is
   cleared, then the factor page follows.
3. Wrong code on the factor page: the attempt is **not** counted. The core only
   writes a warning to the log:
   `Two factor verify failed: '<account>' (Remote IP: '<ip>')`.

So brute-force protection guards the password, not the second factor. Any
number of wrong codes causes neither a delay nor a lockout. Guessing the code is
only hindered by its six digits, the narrow time window, and the block on
reusing a code. If that is not enough for you, monitor the log line above, see
[Server log and error messages](logging.en.md).

When the `brute_force_protection` app is enabled, it takes over the protection
of login and of public link passwords. The built-in protection stays out of
exactly these two paths, so that two mechanisms do not count side by side and
report two different delays. It still covers all other actions.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Enforcement is on, but no code is requested | No app with `<two-factor-providers>` is enabled; without a factor, enforcement has nothing to ask for | `occ market:install twofactor_totp` or `occ app:enable twofactor_totp`, then check `occ app:list` |
| Box checked in the account, but login does not change | The secret was never verified with a code; only verified secrets count | Enter a code and click **Verify** until *Verified* appears |
| Codes are rejected although the app shows them | Server or device clock is off by more than about one and a half minutes | Check time synchronization on the server and on the device |
| The same code is not accepted a second time | The last verified code is stored and blocked for reuse | Wait for the next code |
| `occ app:enable` refuses the app, the message names `imagick` | `php8.4-imagick` is missing; the app requires the extension | Install the extension, reload PHP-FPM |
| No QR code, setup fails | `php8.4-imagick` is missing; `market:install` enabled the app without this check, or the extension was removed later | Install the extension, reload PHP-FPM |
| Desktop or mobile client reports a login error, the browser works | The client sends the account password, which is blocked with an active second factor (log: `Login failed: …`) | Create an app password and enter it in the client instead of the account password |
| An old client still reaches the data although the factor is active | An app password created before the change is still valid | Revoke the token under **Personal → Security → App passwords / tokens** |
| An exempt group is still forced to use the factor | `enforce_2fa_excluded_groups` does not contain a valid JSON list, or a group name is misspelled | Check `occ config:app:get core enforce_2fa_excluded_groups` and set it again |
| `two_factor_auth_disabled` has no effect for an account | Enforcement applies to this account and is checked first | Exempt the account's group or turn enforcement off |
| After disabling `twofactor_totp`, all setups are gone | Disabling deletes every secret | Have the accounts set up the factor again; do not disable the app for testing |
| Several wrong codes have no consequence | Brute-force protection only counts password attempts, not factor attempts | Monitor the log for `Two factor verify failed` |
