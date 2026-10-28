# Virus scanning

Virus scanning is not a core feature. It comes from the `files_antivirus` app
(called "Anti-Virus" in the Market app, version 1.3.11). The app hooks into the
write path and passes every upload to an external scanner: ClamAV directly or a
product that speaks ICAP. In addition, a background job scans files that are
already stored.

## Install and enable the app

The app is publicly available in the Marketplace (no API key needed).
`market:install` downloads, installs, and enables it; if it is already present,
`app:enable` is enough:

```bash
sudo -u www-data php8.4 occ market:install files_antivirus
sudo -u www-data php8.4 occ app:enable files_antivirus
```

During installation, the app tries the `daemon` and `socket` modes in turn and
keeps the first one for which a test scan succeeds. If both fail, it stays on
`executable`. The app records that this probe has run in the `autoprobe` key.

The settings are under **Settings → Admin → Security**, section **Antivirus
Configuration**. **Advanced** holds the rules that the app uses to interpret
the scanner's answers.

<figure markdown="span">
  ![Admin settings, Security: Antivirus Configuration with mode ClamAV Executable](../assets/screenshots/en/admin-antivirus.png)
  <figcaption>Under "Antivirus Configuration" you choose the mode and what happens with infected files. The path to clamscan is set in config.php.</figcaption>
</figure>

## Modes

The mode is stored in the `av_mode` key. An unknown value leads to the error
`Please check the settings at the admin page. Invalid mode: "…"`.

| Value | Label in the user interface | How it works | Required keys |
| --- | --- | --- | --- |
| `executable` | ClamAV Executable | starts `clamscan` for each file, feeds the data to its standard input, and evaluates the exit code | `files_antivirus.av_path`, optionally `files_antivirus.av_cmd_options` |
| `daemon` | ClamAV Daemon (TCP Socket) | connects to `av_host:av_port`, first checks `PING` (expects `PONG`) and `VERSION`, then sends `nINSTREAM` | `av_host`, `av_port` |
| `socket` | ClamAV Daemon (Unix Socket) | like `daemon`, but through the Unix socket | `av_socket` |
| `icap` | ClamAV & Kaspersky (ICAP) | ICAP **REQMOD** to `av_host:av_port` | `av_host`, `av_port`, `av_request_service`, `av_response_header` |
| `fortinet` | Fortinet (ICAP) | ICAP **RESPMOD** with Fortinet-specific headers | same as `icap` |
| `mawgw` | McAfee Webgateway / Skyhigh Secure Web Gateway (ICAP) | ICAP **RESPMOD** with McAfee-specific headers | same as `icap` |

clamd must answer `VERSION` with a line that starts with `ClamAV`. ClamAV 1.x
and later can be configured to reject the command (`EnableVersionCommand no`)
and answer `COMMAND UNAVAILABLE`. Since version 1.3.5 the app accepts this
answer too, because `PING` has already confirmed the service.

In the ClamAV modes, the app interprets the scanner's answer with rules: in
`executable` mode by the exit code of the program, in `daemon` and `socket` mode
by matching the output of the service. The rules are under **Advanced** in the
user interface. The ICAP modes do not use rules; they use the response header of
the ICAP server.

The three ICAP modes require a valid license in the server's license manager.
Without a valid license, after a 24-hour grace period, the app logs "No valid
license found for icap scanner, resetting mode to executable" and returns
`executable` when the mode is read. The license key goes into
`config/config.php` under `'license-key'`.

## Settings and their keys

All values in the following table are app configuration of the
`files_antivirus` app.

| Key | Label | Default | Meaning |
| --- | --- | --- | --- |
| `av_mode` | Mode | `executable` | mode, see above |
| `av_socket` | Socket | `/var/run/clamav/clamd.ctl` | path to the clamd Unix socket |
| `av_host` | Host | `localhost` | target for `daemon` and the ICAP modes |
| `av_port` | Port | `3310` | matching port |
| `av_stream_max_length` | Stream Length | `26214400` | after this many bytes the app reconnects to the scanner; must not be larger than `StreamMaxLength` in the clamd configuration |
| `av_max_file_size` | File size limit, -1 means no limit | `-1` | limit in bytes, see the section below |
| `av_infected_action` | When infected files were found during a background scan | `only_log` | `only_log` = *Only log*, `delete` = *Delete file* |
| `av_scan_background` | Background scanning | `true` | turns the cron job on or off |
| `av_request_service` | "ICAP request service. Possible values: …" (in the Fortinet and McAfee modes: "ICAP request service. Fortinet uses …" / "McAfee Webgateway uses …") | `avscan` | `avscan` for ClamAV, `req` for Kaspersky ScanEngine, `respmod` for Fortinet and McAfee Webgateway |
| `av_response_header` | "ICAP response header holding the virus information. …" (in the Fortinet and McAfee modes: "ICAP response header. …") | `X-Infection-Found` | alternatives named in the user interface: `X-Virus-ID` (Fortinet), `X-Virus-Name` (McAfee Webgateway) |

On the command line:

```bash
sudo -u www-data php8.4 occ config:app:set files_antivirus av_mode --value daemon
sudo -u www-data php8.4 occ config:app:get files_antivirus av_mode
```

Two values do **not** work through the user interface or `config:app:set`. The
app reads them only from the system configuration and silently drops attempts
to write them to the app configuration. A value written with `config:app:set`
does end up in the database, but the app never reads it. The user interface
only displays both values, with the note "You can change this value in the
system configuration (config.php)." They belong in `config/config.php`:

```php
'files_antivirus.av_path' => '/usr/bin/clamscan',
'files_antivirus.av_cmd_options' => '',
```

`av_cmd_options` is a comma-separated list; each entry is escaped separately and
appended to the command.

Very old installations (app version 0.16 and earlier) kept both values in the
database. Since version 1.3.10, the update no longer copies them to
`config.php`; it logs and deletes them instead. If you need custom values, add
them to `config.php` yourself. Without an entry, `/usr/bin/clamscan` and no
extra options apply.

## What happens when a virus is found

What happens depends on how the file arrives.

| Path | Reaction |
| --- | --- |
| Upload by a logged-in account | log entry (warning) "Infected file deleted." with the finding, account, and path; activity entry `virus_detected`; the written file is deleted; the upload stops with "Virus %s is detected in the file. Upload cannot be completed." |
| Upload through a public link | log entry (warning) "Infected file deleted after uploading to the public folder."; no activity entry; the request is rejected with the same message |
| Background scan, `av_infected_action = delete` | activity entry; the file is deleted; log entry "Infected file deleted." |
| Background scan, `av_infected_action = only_log` | activity entry; log entry "File is infected."; the file stays |

In the user interface, the activity entry reads "**Infected file** has been
**found**" or "File %s is infected with %s". It is only visible if the
`activity` app is installed.

The third possible outcome is important: the scan does not produce a clear
result.
That happens when the scanner fails or restarts in the middle of a scan, when
the data stream breaks off, or when the answer matches no rule. Since version
1.3.6 the app rejects such an upload instead of storing the file unscanned. The
log shows "File could not be checked, upload refused." at error level, and the
client gets "The file could not be checked for viruses. Upload cannot be
completed, please try again." The upload can be repeated. The background scan
logs unscanned files as "Not Checked." and leaves them in place.

If the scanner cannot be reached at all, the upload is **rejected** as well:
opening the connection already fails, and the upload ends with "Either the
antivirus app is misconfigured or the external antivirus service is not
accessible. Please reach out to your system administrator!". In ICAP mode, the
same applies to an answer the app cannot interpret. So a scanner outage blocks
uploads.

This also applies to writes that the server triggers itself, such as saving in
the text editor or newly generated previews. As long as the scanner does not
answer, no new previews are created.

!!! warning "S3 as primary storage"
    An object store used as primary storage (app `files_primary_s3`) has no
    part files; an upload writes directly to the target path. If an existing
    file is overwritten and the scan does not return a clean result, the app
    therefore removes the target file itself, including its entry in the file
    list and without the trash bin. The file is missing until a new upload
    succeeds. On local storage the app only removes the part file, and the
    existing file stays unchanged.

## File size limit and larger files

`av_max_file_size` is a value in bytes; `-1` (default) means no limit. The limit
works differently in three places:

- **On upload**, the app compares the upload size with the limit. If the file is
  larger, the scan is **skipped completely** and the file is stored unscanned
  (debug log: "Scanning is skipped."). The app does not scan the beginning of
  the file in this case.
- **In the background scan**, the selection query only picks files smaller than
  the limit. Files with a size of 0 are never scanned.
- **When the data stream is read** (background scan, upload through a public
  link), the scanner stops after `av_max_file_size` bytes and only evaluates
  that beginning.

Writes by the server itself (text editor, previews) are always scanned, without
a size limit.

If the upload size cannot be determined, there is no scan either (debug log:
"No upload in progress or chunk is being uploaded."). This mainly affects
chunked uploads: single chunks below `uploads/` are skipped, and only the
assembled file is scanned at the final `MOVE`. The app determines its size on
the server from the sum of the stored chunks, not from a header sent by the
client, which could otherwise be used to bypass the limit.

So if you set a limit, decide what should happen to larger files: they remain
unscanned in owncloud.online.

## Background scan via cron

The app registers the job `OCA\Files_Antivirus\Cron\Task`. It only runs when the
app is enabled and `av_scan_background` is `true`.

| Property | Value |
| --- | --- |
| Interval | 15 minutes |
| Files per run | 10 |
| Selection | files below `files/`, no directories, size not 0, never scanned **or** with a changed ETag |
| Bookkeeping | table `oc_files_antivirus` with `fileid`, `check_time`, `etag` |

This results in an upper limit of about 960 files per day and instance. A large
existing set of files is not scanned within days; the background scan catches
up afterwards and does not replace the scan on upload. Changed files come up
again automatically through the ETag comparison. The run records clean files in
the table; findings and unscanned files are not recorded.

The job only runs if background jobs run at all, see
[Background jobs (cron)](background-jobs.en.md). To turn it off:

```bash
sudo -u www-data php8.4 occ config:app:set files_antivirus av_scan_background --value false
```

## Effect on upload speed

The scan runs synchronously in the write path: an upload is only complete when
the scanner has answered. Every file passes through the scanner in addition to
the normal write, and upload time grows accordingly. The modes differ a lot:

- `executable` starts a separate `clamscan` process for **every** file, which
  loads the signature database again. This is by far the most expensive option
  and not suitable for production systems. Use `daemon` or `socket` there,
  because the service keeps the signatures in memory once.
- With `daemon` and `socket`, the connection is closed and reopened after every
  `av_stream_max_length` bytes. A value that is too small causes many
  reconnects per file. Each of these segments needs a clear result; if one
  fails, the whole upload is rejected. For large files the rejection only comes
  after the complete upload, for chunked uploads at the final `MOVE`.
- The ICAP modes collect the file **completely in memory** and send it at the
  end. With `av_max_file_size = -1`, the largest uploaded file therefore
  determines the PHP memory needed for the request. On instances with large
  files a limit is mandatory here, with the side effect described above that
  nothing above it is scanned.
- If the scanner runs on the same machine, it competes with PHP-FPM for CPU and
  memory. A separate scan host takes load off the application server but adds
  network latency per file.

For very large files, the whole scan time falls into the last request of the
upload. The time limits of the reverse proxy, PHP-FPM, and the client must allow
for that, otherwise the upload fails even without a finding.

The background scan also causes read access to the stored files. On storage
systems that bill per access, this is a cost item of its own.

## Check the connection

Saving the **Antivirus Configuration** triggers a real test scan with the EICAR
test string. If the scanner does not detect it as infected, "Test scan was
unsuccessful. Please recheck antivirus settings." appears. The test therefore
tells you more than a plain reachability check: it also fails when the rules or
the signature database do not work.

For troubleshooting, set the log level to `debug`. At this level the app writes
file sizes, the reasons for skipped scans, and the scanner's answers:

```bash
sudo -u www-data php8.4 occ log:manage --level debug
```

Set it back afterwards (`--level warning`), see
[Server log and error messages](logging.en.md).

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| After saving: "Test scan was unsuccessful. Please recheck antivirus settings." | The EICAR test scan did not go through: scanner not reachable, wrong mode, or the rules do not work | Read the log; for connection and configuration errors the cause is there as a warning; for rules that match but are wrong, the test only reports the failure |
| Log: "Unexpected response to ping" or "Unexpected response to version" | No clamd answers on `av_host:av_port` or at the socket | Correct the target; for `VERSION` the app only accepts an answer that starts with `ClamAV`, or `COMMAND UNAVAILABLE` |
| Log: "Could not connect to host … on port …" / "Could not connect to socket …" | The service is not running, the path is wrong, or the web server user may not open the socket | Start the service, check `av_socket`, check the permissions on the socket |
| Log: "The antivirus executable could not be found at path …" | `av_path` points nowhere | Set `'files_antivirus.av_path'` in `config/config.php`; the user interface intentionally cannot do this |
| Uploads fail with "Either the antivirus app is misconfigured or the external antivirus service is not accessible. …" | The scanner could not be initialized; in that case the upload is rejected | Fix the scanner or disable the app temporarily with `occ app:disable files_antivirus` |
| Uploads fail with "The file could not be checked for viruses. Upload cannot be completed, please try again." | The scan did not produce a clear result: scanner overloaded or restarted, clamd time limit exceeded, no matching rule | Check the log for "File could not be checked, upload refused.", stabilize the scanner, repeat the upload |
| Log: "Failed to write a chunk. Check if Stream Length matches StreamMaxLength in ClamAV daemon settings", uploads above a certain size fail | `av_stream_max_length` is larger than `StreamMaxLength` in clamd; clamd aborts every segment that is too long | Make both values match |
| Log: "No matching rules. Please check antivirus rules." or "No matching rule for exit code …" | The rule table is empty or misconfigured; the scan has no result, and the upload is rejected | In the user interface, **Advanced** → **Reset to defaults** |
| No new previews, the text editor cannot save | The scanner does not answer; these writes are scanned too | Fix the scanner |
| The mode falls back to "executable" on its own, log: "No valid license found for icap scanner" | The ICAP modes require a license, and the grace period has expired | Add a license in `config/config.php` under `'license-key'` or use a ClamAV mode |
| Files are not scanned, debug log: "Scanning is skipped." | The file is larger than `av_max_file_size` | Raise the limit or set it to `-1` |
| Files are not scanned, debug log: "No upload in progress or chunk is being uploaded." | The upload size cannot be determined at this point (typical for single chunks) | Normal for chunked uploads: the assembled file is scanned at the `MOVE`; if it stays this way, keep the background scan enabled |
| In ICAP mode the log fills with "ICAP request:" and "ICAP resp:" at *error* level | The app logs all ICAP communication at error level, although these are not errors | Not an error state; take it into account when sizing log rotation and storage |
| Log: "ICAP response unusable" and the upload is rejected | The ICAP server answers with a status the app cannot interpret | Check `av_request_service` and `av_response_header` against the scanner's documentation |
