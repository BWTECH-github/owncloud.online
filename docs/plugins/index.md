# Plugins

owncloud.online nutzt Core-Apps aus dem Server-Repository und externe Apps aus lokalen Plugin-Repositories. Externe Apps werden als `.tar.gz` gebaut und können über das eigene Market Backend verteilt werden.

## Regeln für eigene Plugins

- PHP 8.4 kompatibel.
- Geänderte Dateien enthalten die Kennung `modified by BW-Tech GmbH`, wenn diese Regel im Plugin bereits gilt.
- `appinfo/info.xml` nutzt korrekten Namen und korrekten Author.
- Wenn das Original von ownCloud Contributors stammt, bleibt der Ursprung sichtbar, z. B. `ownCloud contributors, modified by BW-Tech GmbH`.
- Kein Dummy-Code und keine stillen Fehler.
- Vor Release: `occ app:check-code <app_id>`, Logs und UI prüfen.

## Paketbau

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Build-PluginPackages.ps1
```

## Paketinstallation

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Install-PluginPackages.ps1 -Maintenance
```

## Market Backend

![Market Backend](../assets/screenshots/market-backend-dashboard.png)
