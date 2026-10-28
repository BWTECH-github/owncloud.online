# Brute-Force Protection

The app temporarily blocks logins when too many failed attempts for one account
come from the same IP address. An attack that tries one password after another
gets nowhere after a few attempts. The same applies to passwords on public
links.

!!! note "Availability"
    Public in the Marketplace, no API key required. App ID
    `brute_force_protection`, version 1.5.2. Name in the Market:
    "Brute-Force Protection".

## Requirements

| Area | Requirement |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 |
| Cron | A daily background job removes old entries. Without cron, the table of failed attempts keeps growing. |
| Reverse proxy | If the instance runs behind a proxy, `trusted_proxies` in `config/config.php` must be correct, see [Reverse proxy and TLS](../../administration/reverse-proxy.en.md). |

## Installation

In the web interface: open **Market** from the app menu at the top left, search
for "Brute-Force Protection", and click **Install**.

On the command line:

```bash
sudo -u www-data php8.4 occ market:install brute_force_protection
```

`market:install` downloads, installs, and enables the app. If the app is already
present but disabled, this is enough:

```bash
sudo -u www-data php8.4 occ app:enable brute_force_protection
```

For servers without internet access, see [Apps and Marketplace](../../administration/apps-market.en.md).

## How the block works

The app counts failed logins per combination of login name and IP address. Upper
and lower case in the login name do not matter. Once the number of failed
attempts within the time window reaches the configured value, owncloud.online
rejects further logins for this combination. The login page reports the block
and shows the remaining waiting time.

- The block ends after the ban period, counted from the last failed attempt.
  Attempts during the block are not counted and do not extend it.
- A successful login resets the counter for that combination.
- Other accounts and other IP addresses are not affected.
- For public links, the app counts wrong link passwords per link and IP
  address, with the same three values.

While the app is enabled, it takes over brute-force protection for logins and
link passwords completely. The protection built into the core no longer counts
on these two paths. If you disable the app, the core takes over again.

## Configuration

**Settings → Admin → Security**, section **Brute Force Protection**. All three
values must be whole numbers greater than 0. **Save settings** applies them.

| Label | Key | Default | Meaning |
| --- | --- | --- | --- |
| "Count failed attempts over how many seconds?" | `brute_force_protection_time_threshold` | 60 | Time window in seconds in which failed attempts are counted |
| "Ban after how many failed attempts?" | `brute_force_protection_fail_tolerance` | 3 | Number of failed attempts within the window that triggers the block |
| "Ban for how many seconds?" | `brute_force_protection_ban_period` | 300 | Duration of the block in seconds |

<figure markdown="span">
  ![Admin settings, Security: Brute Force Protection with time window, number of failed attempts, and ban duration](../../assets/screenshots/en/admin-brute-force.png)
  <figcaption>Brute-force protection blocks logins after several failed attempts. Shown here: 3 failed attempts within 60 seconds lead to a 300-second ban.</figcaption>
</figure>

The same values on the command line, here 5 failed attempts within 120 seconds
and a 15-minute block:

```bash
sudo -u www-data php8.4 occ config:app:set brute_force_protection brute_force_protection_fail_tolerance --value=5
sudo -u www-data php8.4 occ config:app:set brute_force_protection brute_force_protection_time_threshold --value=120
sudo -u www-data php8.4 occ config:app:set brute_force_protection brute_force_protection_ban_period --value=900
```

The app has no `occ` commands of its own.

## Limitations and notes

- **Behind a reverse proxy** without a correct `trusted_proxies` setting,
  owncloud.online sees every request with the proxy's address. The app then
  blocks everyone at once as soon as one person mistypes a few times. This is
  the most common misconfiguration.
- **Counting is per account and address only:** An attacker who tries a few
  passwords each against many accounts from one address stays below the limit.
  The protection built into the core also counts per address across all
  accounts and per subnet. For logins and link passwords, it steps back while
  the app is enabled, see
  [Security and setup warnings](../../administration/security-hardening.en.md).
- **No early release:** There is no command that lifts a block. It ends after
  the configured ban period.
- **Link visitors:** A small number of allowed failed attempts also affects
  visitors of public links who mistype the link password.
- **Moving from an older instance:** If the database still contains the values
  of the predecessor app `security`, the app takes them over when it is enabled
  for the first time. It never overwrites its own values. The import is recorded
  in the server log.
- The app does not replace strong passwords. It complements the
  [Password Policy](password_policy.en.md) and
  [two-factor authentication](twofactor_totp.en.md). It does not count wrong
  two-factor codes.
- The app cannot be enabled for specific groups only; it always applies to the
  whole instance.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Everyone is blocked at the same time | `trusted_proxies` is missing, so all requests carry the proxy's address. | Set `trusted_proxies` to the proxy's address |
| An attack is not blocked, and the address changes with every attempt | The proxy appends its address to an `X-Forwarded-For` header sent by the client. owncloud.online takes the first address in the list, which is the attacker's value. | Make the proxy set the header instead of appending to it (nginx: `$remote_addr` instead of `$proxy_add_x_forwarded_for`), see [Reverse proxy and TLS](../../administration/reverse-proxy.en.md) |
| Tables `oc_bfp_failed_logins` and `oc_bfp_link_accesses` keep growing | The daily cleanup job is not running. | Check cron, see [Background jobs (cron)](../../administration/background-jobs.en.md) |
| Saving reports "Inputs must be positive integers" | A field is empty or does not contain a number greater than 0. | Enter whole numbers greater than 0 |
