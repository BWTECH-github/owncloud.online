# ownCloud.online Dokumentation

ownCloud.online ist ein Fork von ownCloud Server mit Zielplattform PHP 8.4. Diese Dokumentation beschreibt Installation, Betrieb, Updates, eigene Plugins, Market-Backend und Desktop-Client-Kompatibilität.

![ownCloud.online Login](assets/screenshots/owncloud-online-login.png)

## Stand dieser Dokumentation

| Bereich | Stand |
| --- | --- |
| Server-Version | `11.0.0.0`, Versionstring `11.0.0` |
| PHP-Zielversion | PHP 8.4 |
| Repository | `https://github.com/BWTECH-github/owncloud.online` |
| Lokaler Testserver | `http://127.0.0.1:8088` |
| Market Backend lokal | `http://127.0.0.1:8090` |
| Desktop Client Testbuild | `owncloud.online 7.2.0-git` |

## Dokumentationsbereiche

- **Installation**: leerer Linux-Server, Webhosting ohne Root-Rechte und lokale WSL-Installation.
- **Administration**: Sicherheit, Cron, Cache, Apps, Backups, Updates und Troubleshooting.
- **Benutzer**: Dateien, Freigaben, WebUI und Desktop Client.
- **Plugins**: geprüfte Apps, Paketbau, Marketplace und Update-Ablauf.
- **Entwickler**: Release-Prozess, PHP-8.4-Regeln und Tests.

## Architektur

![ownCloud.online Architektur](assets/images/architecture-overview.svg)

## Quellen und Abgrenzung

Die Struktur orientiert sich an der offiziellen ownCloud-Server-Dokumentation, ist aber auf ownCloud.online, PHP 8.4, die BW-Tech Release-Tools und die lokalen Plugin-Repositories zugeschnitten.

- Offizielle ownCloud Server Dokumentation: <https://doc.owncloud.com/server/latest/index.html>
- ownCloud.online Repository: <https://github.com/BWTECH-github/owncloud.online>
- Release Tools lokal: `C:\git\owncloud-online-release-tools`
- Market Backend lokal: `C:\git\market-backend`

## Lokale Testdaten

Für lokale Tests wurde verwendet:

```text
Benutzer: admin
Passwort: AdminWorkflow2026Local
```

Diese Zugangsdaten gehören nicht auf Produktivsysteme.
