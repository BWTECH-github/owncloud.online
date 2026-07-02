# Security Policy

## Supported Versions

This repository is the BW-Tech GmbH fork **owncloud.online** (based on ownCloud 10.x).
Only the fork's own release line is supported here:

| Version            | Supported          |
| ------------------ | ------------------ |
| owncloud.online 11.x | :white_check_mark: |
| anything older     | :x:                |

For vulnerabilities in upstream ownCloud itself, please follow the upstream
process at [https://owncloud.com/security/](https://owncloud.com/security/).

## Reporting a Vulnerability

When you've encountered a security vulnerability in owncloud.online,
please disclose it responsibly and privately:

1. **Preferred:** use GitHub's private vulnerability reporting on this
   repository ("Report a vulnerability" under the Security tab), or
2. e-mail **security@bw.tech** with a description, affected version and,
   if possible, steps to reproduce.

Please do not open public issues for security problems.

We aim to acknowledge reports within 3 business days and to provide a fix
or mitigation plan within 30 days for confirmed issues. Upstream-inherited
CVEs are tracked and back-ported as documented in
`docs/administration/upstream-cve-status.md`.
