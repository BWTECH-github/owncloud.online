# Troubleshooting

Bei „Interner Serverfehler" oder einem unklaren Symptom zuerst das Protokoll
lesen: [Serverprotokoll und Fehlermeldungen](logging.md) erklärt, wo es liegt,
wie sich über die **Anfragekennung** vom Fehlerbildschirm der passende Eintrag
finden lässt und was in einen Fehlerbericht gehört.

## Login leitet immer wieder auf Login zurück

Prüfen:

```bash
sudo -u www-data php8.4 occ config:system:get trusted_domains
sudo -u www-data php8.4 occ config:system:get overwrite.cli.url
```

Häufige Ursachen:

- falsche Domain in `trusted_domains`
- HTTP/HTTPS-Mix hinter Reverse Proxy
- Session-Cookie wird vom Browser nicht akzeptiert
- `overwriteprotocol` fehlt bei Proxy-Setup
- PHP-Session-Pfad nicht beschreibbar

Proxy-Beispiel:

```php
'overwrite.cli.url' => 'https://cloud.example.com',
'overwriteprotocol' => 'https',
'trusted_proxies' => ['127.0.0.1'],
```

## JavaScript-Fehler: jQuery ist nicht definiert

Ursache ist meist ein unvollständiger Build. Bei owncloud.online müssen Composer und `make` im Release-Prozess laufen.

```bash
cd /var/www/owncloud.online
composer install --no-dev --optimize-autoloader
make
```

## Plugin hat keinen vendor-Ordner

Plugin neu paketieren:

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Build-PluginPackages.ps1
```

Das Script führt Composer für Plugins mit `composer.json` aus.

## Integritätswarnung nach Branding

Branding-Änderungen verändern Core-Dateien. Für Release-Builds muss entschieden werden, ob die Integritätsprüfung angepasst oder die geänderten Dateien signiert werden. Nicht dokumentierte Core-Änderungen dürfen nicht in Kundenpakete.

## Desktop Client OAuth schlägt fehl

Prüfen:

- OAuth2-App ist aktiviert.
- Desktop Client ist als OAuth2-Client registriert.
- Redirect-URL passt zu `http://localhost:*`.
- Server liefert eine gültige OIDC-Discovery unter `/.well-known/openid-configuration` oder der Client nutzt einen kompatiblen Fallback.
