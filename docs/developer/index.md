# Entwicklerhandbuch

Dieses Kapitel beschreibt lokale Entwicklung, Tests, Release-Builds und Kompatibilitätsregeln.

## Repositories

| Repository | Pfad |
| --- | --- |
| Core | `C:\git\owncloud.online` |
| Release Tools | `C:\git\owncloud-online-release-tools` |
| Market Backend | `C:\git\market-backend` |
| Desktop Client | `C:\git\client` |
| Plugins | `C:\git\<app_id>` |

## Lokale Server

```powershell
wsl --cd /mnt/c/git/owncloud.online php8.4 -S 127.0.0.1:8088 -t .
wsl --cd /mnt/c/git/market-backend php8.4 -S 127.0.0.1:8090 -t public
```

## Pflichtchecks vor Release

```bash
php8.4 occ status
php8.4 occ app:list
php8.4 occ integrity:check-core
php8.4 occ maintenance:repair
```

Für Plugins:

```bash
php8.4 occ app:check-code <app_id>
```
