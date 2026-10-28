# Password Policy

The app defines what passwords must look like: for user accounts and for
passwords on public links. It adds a password history, expiration periods with
reminders, and a maximum lifetime for public links. It is meant for instances
that have to enforce minimum password requirements.

!!! note "Availability"
    Public in the Marketplace, no API key required. App ID `password_policy`,
    version 2.3.3. Name in the Market: "Password Policy".

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 |
| Cron | for expiration reminders; the background job runs every 12 hours |
| Notifications app | Reminders arrive as notifications, so the `notifications` app must be enabled. |

## Installation

In the web interface: open **Market** from the app menu at the top left, search
for "Password Policy", and click **Install**.

On the command line:

```bash
sudo -u www-data php8.4 occ market:install password_policy
```

`market:install` downloads, installs, and enables the app. If the app is already
present but disabled, this is enough:

```bash
sudo -u www-data php8.4 occ app:enable password_policy
```

For servers without internet access, see [Apps and Marketplace](../../administration/apps-market.en.md).

## Configuration

The rules are under **Settings → Admin → Security**, section
**Password and public link expiration policies**. Each rule has a checkbox, and
most also have a value. Without the check mark, the value has no effect. After
installation, no rule is turned on; the Default column shows the preset value.
**Save** applies the settings.

<figure markdown="span">
  ![Admin settings, Security: password and public link expiration policies with minimum requirements and expiration rules](../../assets/screenshots/en/admin-password-policy.png)
  <figcaption>The password policy sets minimum length, character types, and expiration, including passwords for public links.</figcaption>
</figure>

### Minimum requirements for accounts and public links

| Label | Keys | Default |
| --- | --- | --- |
| minimum characters | `spv_min_chars_checked`, `spv_min_chars_value` | 8 |
| lowercase letters | `spv_lowercase_checked`, `spv_lowercase_value` | 1 |
| uppercase letters | `spv_uppercase_checked`, `spv_uppercase_value` | 1 |
| numbers | `spv_numbers_checked`, `spv_numbers_value` | 1 |
| special characters | `spv_special_chars_checked`, `spv_special_chars_value` | 1 |
| Restrict to these special characters: | `spv_def_special_chars_checked`, `spv_def_special_chars_value` | `#!` |

For lowercase and uppercase letters, numbers, and special characters, the value
is a minimum count. The minimum length counts characters, not bytes.

### User password policies

| Label | Keys | Default |
| --- | --- | --- |
| last passwords should not be used | `spv_password_history_checked`, `spv_password_history_value` | 3 |
| days until user password expires | `spv_user_password_expiration_checked`, `spv_user_password_expiration_value` | 90 |
| days before password expires, users will receive a reminder notification | `spv_user_password_expiration_notification_checked`, `spv_user_password_expiration_notification_value` | 30 |
| Force users to change their password on first login | `spv_user_password_force_change_on_first_login_checked` | off |

The reminder must come before the expiration: if the reminder period is as long
as or longer than the expiration period, the background job sends nothing and
writes a warning to the log.

An expired password does not disable the account. At the next login in the
browser, owncloud.online asks for a new password before anything else.

The app only knows a password's age if the password was set while the app was
enabled. Passwords from before that time therefore only expire after the
account has changed its password once. To require a change right away, use
`occ user:expire-password` (see below).

### Public link expiration policies

| Label | Keys | Default |
| --- | --- | --- |
| days maximum until link expires if password is set | `spv_expiration_password_checked`, `spv_expiration_password_value` | 7 |
| days maximum until link expires if password is not set | `spv_expiration_nopassword_checked`, `spv_expiration_nopassword_value` | 7 |

When one of these rules applies, the public link needs an expiration date: the
first rule covers links with a password, the second links without one.
owncloud.online rejects a date later than allowed. With separate periods, links
with a password may stay valid longer than links without one. These rules apply in
addition to the core sharing settings; the stricter rule wins, see
[Sharing policies](../../administration/sharing-policies.en.md).

### Set values on the command line

A check mark is stored as `on`. When you set a switch with `occ`, always use
`on`; to turn a rule off, delete the key:

```bash
sudo -u www-data php8.4 occ config:app:set password_policy spv_min_chars_checked --value=on
sudo -u www-data php8.4 occ config:app:set password_policy spv_min_chars_value --value=12
sudo -u www-data php8.4 occ config:app:delete password_policy spv_min_chars_checked
```

Exception: `spv_user_password_expiration_notification_value` stores the reminder
period in seconds, not days. 30 days are `2592000`.

### Expire an account's password right away

```bash
# alice's password expires now
sudo -u www-data php8.4 occ user:expire-password -u alice

# at a fixed point in time
sudo -u www-data php8.4 occ user:expire-password "2026-12-31 12:00:00 CET" -u alice

# all members of a group
sudo -u www-data php8.4 occ user:expire-password -g sales
```

The command works only when the rule **days until user password expires** is
turned on. `--all` covers all accounts that can change their password in
owncloud.online.

## Usage

Users notice the policy in three places:

- When they change their password or set a link password, a message explains
  which rule is not met, for example "The password is too short. At least 12
  characters are required."
- Before the password expires, if the reminder is turned on, and after it has
  expired, a notification with a **Change Password** button appears. With the
  default setting of the Notifications app, it also goes out by email to the
  account's address.
- After expiration, or at the first login, the login leads to a page where the
  new password is set.

## Limitations and notes

- The rules apply to local accounts, meaning accounts created by administrators,
  and to guest accounts. Accounts from LDAP or other user backends are excluded;
  the directory service's rules apply there.
- New requirements for how a password is built apply from the next password
  change on. Existing passwords stay valid.
- When the app is enabled for the first time on a database that still contains
  the predecessor app `security`, it takes over its rules (minimum length,
  uppercase and lowercase letters, digits, special characters). It never
  overwrites its own values. The imported rules then also apply to passwords on
  public links. The import is recorded in the server log.
- Very strict rules often lead to passwords written on paper. NIST SP 800-63B
  recommends length over character classes and forced changes only when there
  is reason to suspect a compromise.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| A rule has no effect | The rule's check mark is missing, or `occ` did not set it to `on`. | Set the check mark or use `--value=on` |
| No expiration reminders | Cron is not running, the Notifications app is off, or the reminder period is not shorter than the expiration period. | Check cron, enable `notifications`, correct the periods |
| `user:expire-password` reports "Cannot use this command because no expiration rule was configured" | The expiration rule for user accounts is off. | Turn on **days until user password expires** |
| LDAP accounts are not affected | By design | Set rules in the directory service |
| A link cannot be saved without an expiration date ("An expiration date is required.") | An expiration rule for public links is active. | Set an expiration date or turn the rule off |
