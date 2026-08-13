# owncloud.online Dokumentation

owncloud.online ist Dateiablage, Freigabe und Zusammenarbeit auf dem eigenen
Server, auf PHP 8.4. Diese Dokumentation beschreibt Installation, Betrieb,
Updates, die eigenen Plugins, das Market-Backend und die Clients.

![Anmeldeseite von owncloud.online](assets/screenshots/owncloud-online-login.png)

## Stand dieser Dokumentation

| Bereich | Stand |
| --- | --- |
| Server-Version | `11.0.13` |
| PHP-Zielversion | PHP 8.4 |
| Repository | <https://github.com/BWTECH-github/owncloud.online> |
| Stand | 13. August 2026 |

## Dokumentationsbereiche

- **Installation** — leerer Linux-Server, Webhosting ohne Root-Rechte, lokale
  Installation unter WSL.
- **Administration** — Sicherheit, Cron, Zwischenspeicher, Apps, Sicherungen,
  Updates und Fehlersuche.
- **Benutzer** — Dateien, Freigaben, Weboberfläche und Client.
- **Plugins** — geprüfte Apps, Paketbau, Markt und Update-Ablauf.
- **Entwickler** — Release-Ablauf, PHP-8.4-Regeln und Tests.

## Architektur

![Aufbau von owncloud.online](assets/images/architecture-overview.svg)

## Abgrenzung

Diese Dokumentation beschreibt owncloud.online: PHP 8.4, die BW-Tech
Release-Tools und die eigenen Plugin-Repositories. Sie ist eigenständig — was
hier steht, gilt für diesen Server und wurde daran geprüft.

- owncloud.online Repository: <https://github.com/BWTECH-github/owncloud.online>
- Release Tools lokal: `C:\git\owncloud-online-release-tools`
- Market Backend lokal: `C:\git\market-backend`

## Lokale Testdaten

Beim Einrichten legen Sie das Administratorkonto selbst an:

```text
Benutzer: admin
Passwort: <eigenes Passwort wählen>
```

Wählen Sie auch für eine reine Testinstanz ein zufälliges Passwort und
verwenden Sie es nirgends erneut. Testinstanzen sind erfahrungsgemäß schneller
aus dem lokalen Netz erreichbar als geplant, und ein Passwort aus einer
Anleitung steht in jeder Wörterbuchliste.
