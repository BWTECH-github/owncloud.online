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

## WebDAV antwortet unter Last mit 503

Antwortet der Server bei vielen gleichzeitigen Anfragen – etwa nach einem
großen Sync oder Bulk-Upload – mit `503 Internal Server Error`, ist die Anfrage
schon beim Hochfahren an einem Dienst gescheitert, bevor WebDAV sie bearbeiten
konnte. Die eigentliche Ausnahme steht im Protokoll (App `remote`). Häufige
Ursachen:

- **Redis gestört oder überlastet** (`RedisException`, etwa „read error on
  connection“): Als Zwischenspeicher (`memcache.local`, `memcache.distributed`)
  bricht ein Redis-Ausfall keine Anfrage ab, er wirkt wie ein leerer Cache und
  steht je Anfrage einmal im Protokoll („Memory cache unavailable …“). Für
  Dateisperren (`memcache.locking`) bleibt Redis Pflicht. Ein
  Verbindungs-Timeout setzen, sonst wartet jede Anfrage bei einem nicht
  erreichbaren Redis bis zum TCP-Timeout des Betriebssystems:

    ```php
    'redis' => [
      'host' => 'redis.example.com',
      'port' => 6379,
      'timeout' => 1.5,
    ],
    ```

    Auf dem Redis-Server müssen `maxclients` und `tcp-backlog` über der Summe
    der PHP-Worker aller Webserver liegen, die ihn nutzen.
- **Datenbankverbindungen erschöpft** (`Failed to connect to the database`,
  „Too many connections“; die Antwort ist dann oft `599`, die Ausnahme steht in
  `crash-JJJJ-MM-TT.log` im Datenverzeichnis): `max_connections` jedes
  Datenbankknotens muss über der Zahl der PHP-Worker liegen, die ihn erreichen –
  bei mehreren Webservern oder Mandanten auf einem Cluster über deren Summe.
- **Galera-Cluster mit Lastverteilung auf alle Knoten** („Deadlock found when
  trying to get lock“ bei gleichzeitigen Änderungen): Schreibzugriffe auf einen
  Knoten lenken, etwa in HAProxy die übrigen Knoten als `backup` eintragen.
- **Verbindungsabbrüche bei langen Anfragen** („MySQL server has gone away“):
  Leerlauf-Timeouts eines Datenbank-Proxys (HAProxy `timeout client` und
  `timeout server`) müssen länger sein als die längste Anfrage, etwa der
  Zusammenbau großer Uploads.
- **Sitzungssperre** (Sitzungen in Redis mit `redis.session.locking_enabled`):
  Anfragen derselben Sitzung warten aufeinander, höchstens
  `redis.session.lock_retries` × `redis.session.lock_wait_time` (Vorgabe
  100 × 20 ms = 2 s). Sync-Clients geben die Sperre gleich nach dem Lesen der
  Sitzung wieder frei. Wird sie trotzdem nicht rechtzeitig frei, läuft die
  Anfrage weiter – mit phpredis ab 6.3 nur lesend, sonst ohne Sitzung – und
  das Protokoll meldet „Session lock not acquired in time“ (App `core`).
  Häufen sich diese Meldungen, hält eine Anfrage ihre Sitzung zu lange, meist
  wegen einer langsamen Datenbank oder eines überlasteten Redis.

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

Die Token-Anfrage (`POST /index.php/apps/oauth2/api/v1/token`) weist mit
HTTP-Basic den Client aus, nicht den Benutzer. Sie zählt deshalb nicht als
Anmeldeversuch: kein „Login failed“ im Protokoll, keine Bremse gegen
Passwort-Raten, und eine mitgeschickte Sitzung bleibt bestehen.
