# Troubleshooting

Bei „Interner Serverfehler“ oder einem unklaren Symptom lesen Sie zuerst das
Protokoll: [Serverprotokoll und Fehlermeldungen](logging.md) erklärt, wo es
liegt, wie Sie über die **Anfragekennung** vom Fehlerbildschirm den passenden
Eintrag finden und was in einen Fehlerbericht gehört.

## Anmeldung führt immer wieder zur Anmeldeseite zurück

Prüfen:

```bash
sudo -u www-data php8.4 occ config:system:get trusted_domains
sudo -u www-data php8.4 occ config:system:get overwrite.cli.url
```

Häufige Ursachen:

- falsche Domain in `trusted_domains`
- HTTP und HTTPS gemischt hinter einem Reverse-Proxy
- der Browser nimmt das Sitzungs-Cookie nicht an
- `overwriteprotocol` fehlt bei einem Proxy-Setup
- der Sitzungspfad von PHP ist nicht beschreibbar

Proxy-Beispiel:

```php
'overwrite.cli.url' => 'https://cloud.example.com',
'overwriteprotocol' => 'https',
'trusted_proxies' => ['127.0.0.1'],
```

Einzelheiten unter [Reverse-Proxy und TLS](reverse-proxy.md).

## WebDAV antwortet unter Last mit 503

Antwortet der Server bei vielen gleichzeitigen Anfragen, etwa nach einem großen
Sync oder Bulk-Upload, mit HTTP 503 und der Meldung „Internal Server Error“,
ist die Anfrage schon beim Hochfahren an einem Dienst gescheitert, bevor WebDAV
sie bearbeiten konnte. Die eigentliche Ausnahme steht im Protokoll (App
`remote`). Häufige Ursachen:

- **Redis gestört oder überlastet** (`RedisException`, etwa „read error on
  connection“): Als Zwischenspeicher (`memcache.local`, `memcache.distributed`)
  bricht ein Redis-Ausfall keine Anfrage ab. Er wirkt wie ein leerer Cache und
  steht je Anfrage einmal im Protokoll („Memory cache unavailable …“). Für
  Dateisperren (`memcache.locking`) bleibt Redis Pflicht. Setzen Sie einen
  Verbindungs-Timeout, sonst wartet jede Anfrage bei einem nicht erreichbaren
  Redis bis zum TCP-Timeout des Betriebssystems:

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
  Datenbankknotens muss über der Zahl der PHP-Worker liegen, die ihn erreichen,
  bei mehreren Webservern oder Mandanten auf einem Cluster über deren Summe.
- **Galera-Cluster mit Lastverteilung auf alle Knoten** („Deadlock found when
  trying to get lock“ bei gleichzeitigen Änderungen): Schreibzugriffe auf einen
  Knoten lenken, etwa in HAProxy die übrigen Knoten als `backup` eintragen.
- **Verbindungsabbrüche bei langen Anfragen** („MySQL server has gone away“):
  Die Leerlauf-Timeouts eines Datenbank-Proxys (HAProxy `timeout client` und
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
  der die Sperre hält. Läuft dieser Prozess nicht mehr, löschen Sie die Sperre:

    ```bash
    redis-cli -h <redis-host> -n <db-nummer> --scan --pattern '<prefix>*_LOCK'
    redis-cli -h <redis-host> -n <db-nummer> GET '<prefix><Sitzungskennung>_LOCK'
    redis-cli -h <redis-host> -n <db-nummer> DEL '<prefix><Sitzungskennung>_LOCK'
    ```

## Client nach Widerruf abgemeldet

Sync-Clients schicken mit jeder Anfrage ihr Sitzungs-Cookie mit. Die Sitzung
gilt nur, solange die Anmeldung gilt, mit der sie begann. Der Server prüft das
vor jeder Anfrage:

- **App-Passwort entzogen** (Persönlich → Sicherheit → „App-Passwörter /
  Token“): Die nächste Anfrage des Geräts bekommt 401, auch wenn es nur das
  Cookie schickt. Der Client muss mit einem neuen App-Passwort eingerichtet
  werden.
- **Passwort geändert**: Ein Client, der sich mit dem Passwort anmeldet,
  verliert seine Sitzung spätestens `last_check_timeout` Minuten (App-Wert
  `core`, Vorgabe 5) nach der Änderung. Schickt er das alte Passwort mit, weist
  der Server die Anmeldung mit HTTP-Basic ab; schickt er nur das Cookie, prüft
  der Server das in der Sitzung hinterlegte Passwort. Ist in der Sitzung kein
  Passwort hinterlegt, beendet ein Passwortwechsel sie nicht. App-Passwörter, in
  denen das alte Passwort hinterlegt ist, werden mit ihrer nächsten Prüfung
  ungültig (wie schon bei einer Anmeldung mit ihnen), ihre Sitzungen enden, und
  der Client braucht ein neues App-Passwort. Gültig bleiben App-Passwörter ohne
  hinterlegtes Passwort, etwa solche, die in einer Sitzung ohne hinterlegtes
  Passwort angelegt wurden, und die App-Passwörter von Konten aus LDAP, die der
  Server nicht gegen das Passwort prüft. Sie müssen entzogen werden. Jede
  dieser Prüfungen mit dem alten Passwort steht als „Login failed“ im Protokoll
  und zählt wie ein Fehlversuch für die Bremse gegen Passwort-Raten. Ein
  Passwortwechsel allein sperrt deshalb niemanden verlässlich aus, der eine
  Sitzung hält (siehe
  [Gerät verloren oder Zugangsdaten abgegriffen](#geraet-verloren)).
- **Sitzung unter „Sitzungen“ entfernt**: Die Sitzung endet mit der nächsten
  Anfrage. Hat das Gerät noch gültige Zugangsdaten (App-Passwort, OAuth2-Token,
  Passwort), meldet es sich damit sofort neu an. Um ein Gerät auszusperren,
  entziehen Sie deshalb zusätzlich dessen Zugang, statt nur die Sitzung zu
  entfernen.
- **Autorisierung des Desktop-Clients (OAuth2) widerrufen** (Persönlich →
  Sicherheit → „OAuth 2.0“ → „Autorisierte Anwendungen“): WebDAV- und
  OCS-Anfragen mit dem Token werden sofort abgewiesen. Die Sitzung lässt sich danach nicht mehr
  nutzen: Sie gilt nur zusammen mit einem Token, das die OAuth2-App für ihr
  Konto bestätigt, und endet mit der ersten Anfrage ohne Token (etwa nur mit
  Cookie), spätestens mit ihrem Ablauf (`session_lifetime`).

<figure markdown="span">

![Persönliche Einstellungen, Sicherheit: zwei Sitzungen und zwei App-Passwörter](../assets/screenshots/de/personal-security.png)

<figcaption>Unter „Sicherheit“ sehen Sie angemeldete Browser und Geräte. Für Desktop- und Mobil-Apps erstellen Sie hier App-Passwörter.</figcaption>
</figure>

Ein abgelaufenes Access-Token beendet die Sitzung bei WebDAV nicht: Die Anfrage
damit bekommt 401, der Client erneuert das Token und arbeitet mit derselben
Sitzung weiter. Kommt die Anfrage zuerst über OCS oder die Weboberfläche, endet
die Sitzung dort wie bisher, und der Client meldet sich mit dem neuen Token neu
an. Token-Erneuerung (`POST /index.php/apps/oauth2/api/v1/token`) und
OpenID-Connect-Discovery (`/index.php/apps/openidconnect/config`, Ziel von
`/.well-known/openid-configuration`) ruft der Desktop-Client mit dem Cookie, aber
ohne Token auf. Sie lassen die Sitzung unberührt.

Eine so beendete Sitzung steht mit der Stufe Info im Protokoll:
„Client session of `<konto>` ended: `<Grund>`“. Anfragen, die zu einer per
OAuth2 begonnenen Sitzung nur das Cookie ohne Token schicken, werden nicht mehr
angenommen; Desktop- und Mobil-Clients senden das Token immer mit. Sitzungen,
die vor dem Update auf 11.0.25 begannen und danach ohne App-Passwort nur das
Cookie schicken, müssen sich einmal neu anmelden.

### Gerät verloren oder Zugangsdaten abgegriffen {#geraet-verloren}

Ein Passwortwechsel beendet die Sitzungen von Clients, die sich regulär mit dem
Passwort anmelden, spätestens nach `last_check_timeout` Minuten. Wer eine
Sitzung hält, also ihr Cookie hat, ist damit aber nicht verlässlich
ausgesperrt. Wirksam sind dagegen das Entfernen der Sitzung unter „Sitzungen“
und der Widerruf des App-Passworts, mit dem sie begann (die Sitzung endet mit
ihrer nächsten Anfrage), der Widerruf der OAuth2-Autorisierung (die Sitzung
lässt sich danach nicht mehr nutzen) und, solange es deaktiviert ist, ein
deaktiviertes Konto. Bei Verdacht deshalb alles zusammen:

1. Das Passwort ändern.
2. Unter Persönlich → Sicherheit → „Sitzungen“ alle übrigen Sitzungen
   entfernen. Dort steht eine Sitzung erst, sobald sie ein Sitzungstoken hat;
   manche Clients bekommen es erst mit ihrer zweiten Anfrage. Prüfen Sie die
   Liste deshalb später noch einmal.
3. Die App-Passwörter prüfen und jedes entziehen, das nicht sicher zu einem
   eigenen, weiter vertrauenswürdigen Gerät gehört, ebenso die Autorisierungen
   von Desktop-Clients (OAuth2) widerrufen. Betroffene Geräte neu einrichten.
4. Lässt sich das nicht sofort erledigen, das Konto bis dahin deaktivieren
   (Benutzerverwaltung oder `occ user:disable <konto>`): Solange es deaktiviert
   ist, lässt sich keine seiner Sitzungen nutzen. Das Deaktivieren entfernt
   aber weder Sitzungen noch App-Passwörter. Eine Sitzung, die währenddessen
   keine Anfrage schickt, kann nach dem Wiedereinschalten weiter gelten. Holen
   Sie die Schritte 2 und 3 deshalb in jedem Fall nach.

Öffentliche Links und Freigaben, die in dieser Zeit angelegt wurden, beendet
keiner dieser Schritte. Prüfen Sie sie gesondert.

## JavaScript-Fehler: jQuery ist nicht definiert

Die Bibliothek unter `core/vendor/jquery/` wird nicht geladen. Die
Entwicklerwerkzeuge des Browsers (Reiter „Netzwerk“) zeigen, welche Datei mit
404 oder 403 antwortet.

- **Installation aus dem Release-Paket:** Das Paket enthält `core/vendor`
  vollständig. Fehlt die Datei, ist das Archiv unvollständig ausgepackt.
  Prüfsumme vergleichen und das Programmverzeichnis neu auspacken, siehe
  [Backups und Updates](backups-updates.md).
- **Installation aus dem Git-Repository:** `core/vendor` ist eingecheckt und
  enthält Sicherheitskorrekturen an den Bibliotheken. Fehlt das Verzeichnis
  (etwa nach `make clean`), stellen Sie es aus dem Repository wieder her
  (`git checkout -- core/vendor`). Ein Neuaufbau mit `make` holt die
  Bibliotheken ohne diese Korrekturen, siehe
  [CVE-Status und Sicherheitskorrekturen](upstream-cve-status.md#frontend-bibliotheken). Die
  PHP-Abhängigkeiten installieren Sie mit:

    ```bash
    cd /var/www/owncloud.online
    sudo -u www-data composer install --no-dev --optimize-autoloader
    ```

- **Unterpfad oder Proxy:** Antworten alle Skripte mit 404, stimmt meist
  `overwritewebroot` nicht, siehe [Reverse-Proxy und TLS](reverse-proxy.md).

## Plugin hat keinen vendor-Ordner

Eine App lässt sich nicht aktivieren oder bricht mit einer Meldung über eine
fehlende Klasse ab, und in ihrem Verzeichnis fehlt `vendor/`. Ursache ist fast
immer eine App, die direkt aus ihrem Git-Repository statt aus einem Paket
installiert wurde. Die Pakete aus dem Marktplatz enthalten ihre Bibliotheken.

- Die Bibliotheken im App-Verzeichnis nachinstallieren, wenn die App eine
  `composer.json` hat:

    ```bash
    cd /var/www/owncloud.online/apps-external/<app-id>
    sudo -u www-data composer install --no-dev --optimize-autoloader
    ```

- Oder die App als Paket aus dem Marktplatz installieren. Für eine App, die
  schon unter `apps-external/` liegt, spielt `market:install` nur eine neuere
  Version ein. Verschieben Sie das vorhandene Verzeichnis deshalb vorher aus
  `apps-external/` heraus.

    ```bash
    sudo -u www-data php8.4 occ market:install <app-id>
    ```

## Integritätswarnung nach Branding

Wer für ein Branding Dateien des Kerns ändert, bekommt seit 11.0.13 auf
owncloud.online-Paketen keine Meldung „Manche Dateien haben die
Integritätsprüfung nicht bestanden …“ mehr: Für den Auslieferungskanal `bwtech`
erzwingt der Kern die Prüfung nicht, auch wenn in der Datenbank noch ein anderer
Kanal aus einer früheren Installation steht. Beim `occ upgrade` entfernt ein
Reparaturschritt einen solchen geerbten Kanal.

Auf älteren Versionen kommt die Meldung von diesem geerbten Kanal. Dann hilft:

```bash
sudo -u www-data php8.4 occ config:app:delete core OC_Channel
```

Danach die Prüfung über „Erneut scannen…“ in der Meldung neu starten. Was die
Integritätsprüfung leistet und was nicht, steht unter
[Sicherheit und Setup-Warnungen](security-hardening.md). Eigene Änderungen am
Kern halten Sie schriftlich fest, weil jedes Update sie überschreibt.

## OAuth-Anmeldung des Desktop-Clients schlägt fehl

Prüfen:

- Die App OAuth2 ist aktiviert.
- Der Desktop-Client ist als OAuth2-Client registriert: Unter **Einstellungen
  → Administration → Nutzer-Authentifizierung** steht im Abschnitt „OAuth 2.0“
  der Eintrag „Desktop Client“, und seine Weiterleitungs-URI (Spalte
  „URI weiterleiten“) lautet `http://localhost:*`.
- Der Server liefert eine gültige OIDC-Discovery unter
  `/.well-known/openid-configuration`, oder der Client weicht auf einen
  kompatiblen Ersatzweg aus.

Die Token-Anfrage (`POST /index.php/apps/oauth2/api/v1/token`) weist mit
HTTP-Basic den Client aus, nicht den Benutzer. Sie zählt deshalb nicht als
Anmeldeversuch: kein „Login failed“ im Protokoll, keine Bremse gegen
Passwort-Raten, und eine mitgeschickte Sitzung bleibt bestehen. Das gilt auch,
wenn der Client seine Daten im Formular statt über HTTP-Basic schickt.
