# Release Workflow

## Core Release

1. Branch oder Tag vorbereiten.
2. Composer-Abhängigkeiten installieren.
3. `make` ausführen.
4. Status- und Login-Test durchführen.
5. Release-Tarball erstellen.
6. Frische Installation aus dem Tarball testen.
7. GitHub Release veröffentlichen.

## Plugin Release

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Build-PluginPackages.ps1
```

Danach im Market Backend hochladen:

1. Admin UI öffnen.
2. App oder Update auswählen.
3. `.tar.gz` hochladen.
4. Changelog eintragen.
5. App im Katalog prüfen.
6. Installation über ownCloud.online Market-App testen.

## Release-Artefakte

| Artefakt | Zweck |
| --- | --- |
| Core `.tar.gz` | GitHub Release, Serverinstallation |
| Server Bundle | leerer Linux-Server |
| Webhosting ZIP | Webhosting ohne Composer |
| Plugin `.tar.gz` | Marketplace und Plugin-Updates |

## Nach dem Release

```bash
php8.4 occ upgrade
php8.4 occ maintenance:repair
php8.4 occ app:list
php8.4 occ status
```
