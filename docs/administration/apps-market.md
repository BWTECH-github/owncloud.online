# Apps und Marketplace

owncloud.online kann Apps klassisch per `occ`, per Paketinstaller oder über die eigene Market-App installieren.

## App-Liste prüfen

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:list
```

Lokaler Teststand:

```text
comments, dav, federatedfilesharing, federation, files, files_external,
files_primary_s3, files_sharing, files_trashbin, files_versions, oauth2,
provisioning_api, systemtags, updatenotification, wnd, workflow
```

## Plugin-Pakete bauen

Auf Windows:

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Build-PluginPackages.ps1
```

Ergebnis:

```text
C:\git\_plugin_packages\packages\<app_id>-<version>.tar.gz
C:\git\_plugin_packages\manifest.json
```

Wenn eine App eine `composer.json` enthält, führt das Paket-Script Composer im Staging aus. Der `vendor`-Ordner ist danach im Paket enthalten.

## Pakete lokal installieren

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Install-PluginPackages.ps1 -Maintenance
```

## Market-App konfigurieren

In `config/config.php`:

```php
'appstoreenabled' => true,
'appstoreurl' => 'https://market.example.com',
```

Für lokale Tests:

```php
'appstoreurl' => 'http://127.0.0.1:8090',
```

## Reihenfolge bei App-Updates

1. App-Paket bauen.
2. Paket im Market Backend hochladen.
3. Changelog eintragen.
4. owncloud.online Market-App öffnen.
5. Update prüfen und installieren.
6. `occ app:check-code <app_id>` ausführen.
7. Logs prüfen.
