# Reverse proxy and TLS

Most production installations of owncloud.online run behind a reverse proxy
that terminates TLS. The application server then sees the proxy instead of the
client. Client address, protocol, and host name arrive only in HTTP
headers, and the server trusts these headers only after you configure it to.
Below are the required keys, examples for nginx and Apache, and the typical
misconfigurations.

## How the client address is determined

The relevant code is `OC\AppFramework\Http\Request::getRemoteAddress()` in
`lib/private/AppFramework/Http/Request.php`:

1. The starting value is `$_SERVER['REMOTE_ADDR']`, the address of the peer.
   Behind a proxy, this is the address of the proxy.
2. If this address is listed in `trusted_proxies`, the server reads the server
   variables named in `forwarded_for_headers`.
3. It goes through these variables in order and takes the first syntactically
   valid IP address. If it finds none, it keeps `REMOTE_ADDR`.

Three details of this logic decide whether it works:

- The match against `trusted_proxies` is an **exact string comparison**
  (`in_array`). CIDR notation such as `10.0.0.0/24` is not expanded and never
  matches. List every proxy address on its own, and write IPv6 addresses
  exactly as they arrive in `REMOTE_ADDR`.
- `forwarded_for_headers` contains names of `$_SERVER` variables, not header
  names: `X-Forwarded-For` becomes `HTTP_X_FORWARDED_FOR`. If the key is not
  set, only `HTTP_X_FORWARDED_FOR` is used.
- Within the header, the **leftmost** entry wins. If the proxy only appends its
  peer address to a value sent by the client, the client's value stays on the
  left and is used. The proxy must therefore **set** the header, not append to
  it (see the examples below).

## Why trusted_proxies matters for security

The address is more than a log value. Several protection mechanisms count by
it:

| Use | Location |
| --- | --- |
| Brute-force protection for logins, counted per (IP, account name) | `lib/private/User/Manager.php` → `Throttler::sleepDelay('login', …)` |
| Brute-force protection for public link passwords, counted per (IP, share token) | `lib/private/Share20/Manager.php` → `'share_password'` |
| Brute-force protection at the MCP endpoint | `apps/oco_mcp/lib/Controller/McpController.php` → `'oco_mcp'` |
| `remoteAddr` field in every log line | `lib/private/Log/Owncloud.php`, `lib/private/Log/Syslog.php` |
| "Trusted domain error" log warning for access through an unknown host name | `lib/base.php` |

The throttle in `lib/private/OCO/Security/Bruteforce/Throttler.php` counts in
three dimensions and applies the strictest: per (address, identifier), per
address across all identifiers, and per subnet (`/24` for IPv4, `/64` for
IPv6). The subnet count detects attacks that try passwords from many addresses
(password spraying). A wrong address source therefore affects all three
dimensions.

| Setup | Result |
| --- | --- |
| `trusted_proxies` set correctly | The real client address is counted. Protection works, and the log shows a useful address |
| `trusted_proxies` not set | All requests count against the proxy address. Users slow each other down, and every log line shows the same address |
| The proxy appends to `X-Forwarded-For` instead of setting it | The value sent by the client is on the left and is used. An attacker gets a new "address" with every request, and the throttle never applies |
| `trusted_proxies` contains an address that is not used exclusively by the proxy | Anyone sending requests from there sets their own address through the header, with the same result |
| Application server reachable directly from the network | Requests bypass the proxy. The client address stays real, because `REMOTE_ADDR` is then not in `trusted_proxies`. The server, however, evaluates `X-Forwarded-Proto` and `X-Forwarded-Host` without this check: the protocol of generated links can be set from outside, and the host name can be set to any name in `trusted_domains` |

This leads to two requirements when you set up the instance:

1. Set `trusted_proxies` to the **actual** addresses of the proxies. No ranges
   "just in case", no `0.0.0.0/0` (which would not be evaluated anyway). Every
   address listed there can claim any client address.
2. Never expose the application server (PHP-FPM or the backend HTTP server)
   directly: bind it to `127.0.0.1` or use a firewall rule that only lets the
   proxy through.

If the "Brute-Force Protection" app (`brute_force_protection`) is installed and
enabled, the built-in throttle leaves the rules for logins and link passwords
to the app (since 11.0.14). The built-in throttle still protects all other
paths. The app also counts by client address, so `trusted_proxies` matters just
as much.

## Setting the values

```bash
sudo -u www-data php8.4 occ config:system:set trusted_proxies 0 --value 10.0.0.5
sudo -u www-data php8.4 occ config:system:set overwriteprotocol --value https
sudo -u www-data php8.4 occ config:system:set overwrite.cli.url \
  --value https://cloud.example.com
```

The second positional argument (`0`) is the array index. `config:system:set`
accepts several name parts and builds nested values from them. A second proxy
gets index `1`.

The same settings directly in `config/config.php`:

```php
'trusted_proxies' => ['10.0.0.5'],
'forwarded_for_headers' => ['HTTP_X_FORWARDED_FOR'],
'overwriteprotocol' => 'https',
'overwrite.cli.url' => 'https://cloud.example.com',
```

## The overwrite keys

| Key | Effect | Example |
| --- | --- | --- |
| `overwriteprotocol` | Replaces the detected protocol when URLs are generated. Only `http` and `https` are valid | `'https'` |
| `overwritehost` | Replaces the host name, with a port if needed. The code treats it as trusted and does not check it against `trusted_domains` | `'cloud.example.com:8443'` |
| `overwritewebroot` | Subpath under which the instance is reachable from outside | `'/cloud'` |
| `overwritecondaddr` | Regular expression matched against `REMOTE_ADDR`; in the shipped code it only limits `overwriteprotocol` | `'^10\.0\.0\.5$'` |
| `overwrite.cli.url` | Base URL for calls without a request, that is cron and `occ` | `'https://cloud.example.com'` |

Protocol: if `overwriteprotocol` is empty, `getServerProtocol()` evaluates the
`X-Forwarded-Proto` header **without** checking `trusted_proxies`. The same
applies to `X-Forwarded-Host` in `getInsecureServerHost()`. The proxy must
therefore set both headers itself and overwrite any value sent by the client.

Host: without `overwritehost`, the server checks the name from
`X-Forwarded-Host` or `Host` against `trusted_domains`. If it does not match,
the server uses the first entry in `trusted_domains`. Links then point to a
different host name than the one the user requested.

Three things about `overwritecondaddr` that often cost time:

- The server checks `REMOTE_ADDR`, which is the address of the **proxy**, not
  the client. Put the proxy address there.
- The server puts the expression between two slashes without escaping it.
  Escape dots, use anchors, and do not use slashes in the pattern.
- `config/config.sample.php` says the condition applies to
  `overwritewebroot`, `overwriteprotocol`, and `overwritehost`. In the code,
  this is only true for `overwriteprotocol`: `isOverwriteCondition()` is called
  without a type for host, web root, and request URI, and the last condition,
  `$type !== 'protocol'`, then makes the result true regardless of the
  expression. You cannot limit `overwritehost` by address with this key.

## nginx as TLS terminator

Here the headers are set with `proxy_set_header`, which overwrites whatever
the client sent. `X-Forwarded-For` deliberately uses `$remote_addr` instead of
`$proxy_add_x_forwarded_for`: the latter appends to a value set by the client,
and the leftmost entry is the one evaluated.

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name cloud.example.com;

    ssl_certificate     /etc/letsencrypt/live/cloud.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cloud.example.com/privkey.pem;

    # nginx does not read .htaccess, so HSTS belongs here
    add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;

    # WebDAV redirects, see the ".well-known" section
    location ^~ /.well-known/carddav { return 301 /remote.php/dav/; }
    location ^~ /.well-known/caldav  { return 301 /remote.php/dav/; }

    # Limits for large uploads
    client_max_body_size 0;
    client_body_timeout  300s;

    location / {
        proxy_pass http://127.0.0.1:8080;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Host  $host;

        proxy_http_version 1.1;
        proxy_request_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

If nginx serves the instance itself through PHP-FPM, the same points apply to
the FastCGI block. There, the `Authorization` header must also reach PHP:
`lib/base.php` rebuilds `PHP_AUTH_USER` and `PHP_AUTH_PW` from
`HTTP_AUTHORIZATION` or `REDIRECT_HTTP_AUTHORIZATION`. Without this header,
every basic auth login from WebDAV clients fails.

```nginx
location ~ \.php(?:$|/) {
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    include fastcgi_params;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_read_timeout 3600s;
}
```

## Apache as TLS terminator

`RequestHeader unset X-Forwarded-For` first removes any value sent by the
client. mod_proxy then adds the actual peer address as the only entry.

```apache
<VirtualHost *:443>
    ServerName cloud.example.com

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/cloud.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cloud.example.com/privkey.pem

    Header always set Strict-Transport-Security "max-age=15552000; includeSubDomains"

    RequestHeader unset X-Forwarded-For
    RequestHeader set   X-Forwarded-Proto "https"
    RequestHeader set   X-Forwarded-Host  "cloud.example.com"
    ProxyPreserveHost On

    RedirectMatch 301 ^/\.well-known/carddav /remote.php/dav/
    RedirectMatch 301 ^/\.well-known/caldav  /remote.php/dav/

    TimeOut      3600
    ProxyTimeout 3600
    LimitRequestBody 0

    ProxyPass        / http://127.0.0.1:8080/
    ProxyPassReverse / http://127.0.0.1:8080/
</VirtualHost>
```

If Apache terminates TLS and also runs owncloud.online itself, no reverse proxy
is involved. `mod_ssl` then sets the `HTTPS` environment
variable, and the shipped `.htaccess` sets the HSTS header itself
(`env=HTTPS`). In that case, do not also set HSTS in the VirtualHost, or the
header may appear twice in the response, depending on the configuration.
`AllowOverride All` for the directory is still required, otherwise neither the
redirects nor the security headers from `.htaccess` take effect.

## Timeouts for large uploads

Clients upload large files in chunks to `/remote.php/dav/uploads/…` and finish
with a `MOVE`. Only this `MOVE` assembles the chunks on the server
(`apps/dav/lib/Upload/AssemblyStream.php`) and writes the file to its target
location. The final step is therefore a single long request with no traffic,
which is exactly what proxies cut off after their default timeout. The client
then reports an error even though all chunks have been transferred.

Since 11.0.20, the web interface finishes large uploads asynchronously: the
server answers right away with `202` and assembles the file afterwards. Sync
clients use this path only if `dav.enable.async` is enabled (default:
`false`). For them, the final `MOVE` remains a long request, and the timeouts
in the following table must allow for it.

| Layer | Setting |
| --- | --- |
| nginx (proxy) | `client_max_body_size`, `client_body_timeout`, `proxy_read_timeout`, `proxy_send_timeout`, `proxy_request_buffering off` |
| nginx (FastCGI) | `fastcgi_read_timeout` |
| Apache | `TimeOut`, `ProxyTimeout`, `LimitRequestBody` |
| PHP-FPM | `request_terminate_timeout` in the pool |
| PHP | `upload_max_filesize`, `post_max_size`, `memory_limit`, `max_execution_time`, `max_input_time` |

A common trap: the shipped `.htaccess` does contain
`php_value upload_max_filesize 513M` and `php_value post_max_size 513M`, but
only inside `<IfModule mod_php5.c>` and `<IfModule mod_php7.c>`. Under
PHP-FPM, the recommended mode for owncloud.online, these lines never apply.
There, the shipped `.user.ini` sets 513 MB. Higher values belong in `php.ini`
or the pool configuration, because `.user.ini` is replaced together with the
program directory on every update.

## .well-known redirects

Calendar and contacts clients look for the DAV endpoint at
`/.well-known/caldav` and `/.well-known/carddav`. The shipped `.htaccess`
redirects both permanently to the DAV endpoint:

```apache
RewriteRule ^\.well-known/carddav /remote.php/dav/ [R=301,L]
RewriteRule ^\.well-known/caldav  /remote.php/dav/ [R=301,L]
```

`/remote.php/dav` is hard-wired in `remote.php`, as are `webdav`, `caldav`,
`carddav`, and `files`. The redirects therefore point to an endpoint that does
not depend on any app registration.

Two points:

- nginx does not read `.htaccess`. Without the two `location` blocks from the
  nginx example, the redirects are missing.
- `.htaccess` blocks paths that start with a dot with a 404. Explicit
  exceptions are `/.well-known/acme-challenge/` and
  `/.well-known/pki-validation/`. If you issue certificates through a
  different path, you have to allow it yourself.

The web interface checks both redirects: **Settings → Admin → General** sends
a `PROPFIND` to `/.well-known/caldav/` and `/.well-known/carddav/` and expects
status 207. Otherwise it shows the notice "Your web server is not set up
properly to resolve …". If you deliberately do not provide the redirect, you
can turn the check off:

```bash
sudo -u www-data php8.4 occ config:system:set \
  check_for_working_wellknown_setup --type boolean --value false
```

## Checking the setup

```bash
sudo -u www-data php8.4 occ config:system:get trusted_proxies
sudo -u www-data php8.4 occ config:system:get overwriteprotocol
sudo -u www-data php8.4 occ config:list system
sudo -u www-data php8.4 occ status
```

A single request shows whether the redirects work:

```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' \
  https://cloud.example.com/.well-known/caldav/
```

The expected result is `301` with the target `/remote.php/dav/`.

The log shows which address actually arrives. After a failed login, it is in
the `remoteAddr` field and in the message itself:
`Login failed: '<account>' (Remote IP: '<address>')`, see
[Server log and error messages](logging.en.md). If the log shows the proxy
address instead of the client address, `trusted_proxies` is wrong.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| All log lines show the same address in `remoteAddr` | `trusted_proxies` is missing or does not contain the address the proxy uses to reach the server | Find the address in `REMOTE_ADDR` and enter it exactly |
| `trusted_proxies` is set but has no effect | Entry in CIDR notation; the match is an exact string comparison | List every proxy address on its own |
| Setup warning "The reverse proxy headers configuration is incorrect, or you are accessing the server from a trusted proxy." | The detected client address is itself in `trusted_proxies`: the proxy does not set `X-Forwarded-For`, `forwarded_for_headers` names a variable that never arrives, or you are opening the page from the proxy itself | Set the header in the proxy, check `forwarded_for_headers` |
| Regular users slow each other down | The throttle counts the proxy address instead of the client | Fix `trusted_proxies` |
| Generated links and redirects start with `http://`, the browser blocks content | `overwriteprotocol` is missing, the proxy does not set `X-Forwarded-Proto` | Set `overwriteprotocol` to `https` |
| "You are accessing the server through an untrusted domain." | The proxy does not pass on `Host` or `X-Forwarded-Host`, or the name is missing from `trusted_domains` | Set the header and add the name to `trusted_domains` |
| Links point to a different host name than the one requested | Host not in `trusted_domains`; the server falls back to the first entry | Add the host name or set `overwritehost` |
| Parts of the interface are missing when installed in a subpath | `overwritewebroot` is not set | Set `overwritewebroot` to the external path |
| `overwritecondaddr` does not limit `overwritehost` | The code evaluates the condition only for `overwriteprotocol` | Do without the limit or make the distinction in the proxy |
| Setup warning about `/.well-known/caldav/` | nginx does not read `.htaccess`, the redirects are missing | Add the `location` blocks |
| WebDAV or desktop client gets 401 despite correct credentials | The `Authorization` header does not reach PHP | Pass the header on; with FastCGI, set `HTTP_AUTHORIZATION` |
| Large upload fails with 504 shortly before the end | The final `MOVE` assembles the chunks and exceeds the proxy timeout | Raise `proxy_read_timeout` or `ProxyTimeout` and `request_terminate_timeout` |
| Upload fails at a fixed size | Limit in the proxy or in PHP; the `php_value` lines in `.htaccess` only apply under mod_php 5 and 7 | Check `client_max_body_size` or `LimitRequestBody` and `php.ini` |
| Cron generates links with the wrong host | `overwrite.cli.url` is missing; without a request there are no headers | Set `overwrite.cli.url` to the external base URL |

See also: [Security and setup warnings](security-hardening.en.md),
[Configuration (config.php)](config-reference.en.md), and
[Server log and error messages](logging.en.md).
