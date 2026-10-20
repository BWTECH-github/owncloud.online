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
  Anfrage weiter, und das Protokoll meldet „Session lock not acquired in time“
  (App `core`). Ein angemeldeter Browser behält seine Sitzung dann nur lesend
  („continuing read-only“, Änderungen daran werden nicht gespeichert). Das geht
  mit phpredis 5.3 und ab 6.3 über `redis.session.lock_failure_readonly`, das
  owncloud.online selbst einschaltet, solange kein `php_admin_value` es auf 0
  setzt. Clients mit Authorization-Kopf (Sync-Clients, API), leere Sitzungen
  und Sitzungen, die sich ohne Sperre gar nicht lesen lassen (phpredis 6.0 bis
  6.2), bekommen eine neue Sitzung samt neuem Cookie („continuing with a new
  session“); ein Browser ist dann abgemeldet. Häufen sich diese Meldungen über
  viele Sitzungen, hält eine Anfrage ihre Sitzung zu lange, meist wegen einer
  langsamen Datenbank oder eines überlasteten Redis.
- **Verwaiste Sitzungssperre**: Endet ein PHP-Prozess, ohne die Sperre
  freizugeben (Neustart von Apache oder PHP-FPM, OOM-Killer), bleibt sie bis
  `redis.session.lock_expire` stehen; ohne eigenen Wert gilt
  `max_execution_time`, bei owncloud.online meist 3600 s. Sync-Clients wechseln
  dann von selbst zu einer neuen Sitzung. Ein angemeldeter Browser wartet bei
  jeder Anfrage die volle Zeit, bis er sich ab- und wieder anmeldet. Jede Sperre
  ist ein eigener Schlüssel `<prefix><Sitzungskennung>_LOCK` (Datenbank und
  Präfix aus `session.save_path`, die Sitzungskennung steht im Cookie, das wie
  die `instanceid` heißt). Sein Wert nennt Rechner und PID des PHP-Prozesses,
  der die Sperre hält. Läuft dieser Prozess nicht mehr, die Sperre löschen:

    ```bash
    redis-cli -h <redis-host> -n 1 --scan --pattern '<prefix>*_LOCK'
    redis-cli -h <redis-host> -n 1 GET '<prefix><Sitzungskennung>_LOCK'
    redis-cli -h <redis-host> -n 1 DEL '<prefix><Sitzungskennung>_LOCK'
    ```

## Client nach Widerruf abgemeldet

Sync-Clients schicken mit jeder Anfrage ihr Sitzungs-Cookie mit. Die Sitzung
gilt nur, solange die Anmeldung gilt, mit der sie begann; der Server prüft das
vor jeder Anfrage:

- **App-Passwort entzogen** (Persönlich → Sicherheit → App-Passwörter / Token):
  Die nächste Anfrage des Geräts bekommt 401, auch wenn es nur das Cookie
  schickt. Der Client muss mit einem neuen App-Passwort eingerichtet werden.
- **Kennwort geändert**: Sitzungen, die mit dem Kennwort begannen, enden
  spätestens `last_check_timeout` Minuten (App-Wert `core`, Vorgabe 5) nach der
  Änderung, auch wenn das Gerät nur das Cookie schickt. App-Passwörter, die vor
  der Änderung entstanden, werden mit ihrer nächsten Prüfung ungültig (wie
  bisher bei einer Anmeldung damit), ihre Sitzungen enden; der Client braucht
  ein neues App-Passwort. Ausgenommen sind Konten aus LDAP: Deren App-Passwörter
  prüft der Server nicht gegen das Kennwort, sie müssen entzogen werden. Jede
  dieser Prüfungen mit dem alten Kennwort steht als „Login failed“ im Protokoll
  und zählt wie ein Fehlversuch für die Bremse gegen Passwort-Raten.
- **Sitzung unter „Sitzungen“ entfernt**: Die Sitzung endet mit der nächsten
  Anfrage. Hat das Gerät noch gültige Zugangsdaten (App-Passwort, OAuth2-Token,
  Kennwort), meldet es sich damit sofort neu an. Um ein Gerät auszusperren,
  dessen Zugang entziehen oder das Kennwort ändern, nicht nur die Sitzung
  entfernen.
- **Desktop-Freigabe (OAuth2) widerrufen**: WebDAV- und OCS-Anfragen mit dem
  Token werden sofort abgewiesen. Die Sitzung lässt sich danach nicht mehr
  nutzen: Sie gilt nur zusammen mit einem Token, das die OAuth2-App für ihr
  Konto bestätigt, und endet mit der ersten Anfrage ohne Token (etwa nur mit
  Cookie), spätestens mit ihrem Ablauf (`session_lifetime`).

Ein abgelaufenes Access-Token beendet die Sitzung bei WebDAV nicht: Die Anfrage
damit bekommt 401, der Client erneuert das Token und arbeitet mit derselben
Sitzung weiter. Kommt es zuerst über OCS oder die Weboberfläche, endet die
Sitzung dort wie bisher, und der Client meldet sich mit dem neuen Token neu an.
Token-Erneuerung (`POST /index.php/apps/oauth2/api/v1/token`) und
OpenID-Connect-Discovery (`/index.php/apps/openidconnect/config`, Ziel von
`/.well-known/openid-configuration`) ruft der Desktop-Client mit dem Cookie, aber
ohne Token auf; sie lassen die Sitzung unberührt.

Eine so beendete Sitzung steht mit Stufe info im Protokoll:
„Client session of `<konto>` ended: `<Grund>`“. Anfragen, die zu einer per
OAuth2 begonnenen Sitzung nur das Cookie ohne Token schicken, werden nicht mehr
angenommen; Desktop- und Mobil-Clients senden das Token immer mit. Sitzungen,
die vor dem Update begannen und danach ohne App-Passwort nur das Cookie
schicken, müssen sich einmal neu anmelden.

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
Passwort-Raten, und eine mitgeschickte Sitzung bleibt bestehen – auch wenn der
Client seine Daten im Formular statt über HTTP-Basic schickt.
