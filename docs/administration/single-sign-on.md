# Anmeldung über OAuth2 und OpenID Connect

owncloud.online bringt zwei getrennte Apps für passwortlose Anmeldung mit, die
unterschiedliche Aufgaben haben: `oauth2` macht die Instanz selbst zum
Autorisierungsserver, damit Desktop-, Android- und iOS-Client sowie fremde
Anwendungen ohne Benutzerpasswort angebunden werden können. `openidconnect`
dreht die Richtung um und meldet Benutzer gegen einen fremden Anbieter an.
Beide Apps können gleichzeitig aktiv sein.

## Was welche App macht

| | OAuth2 (`oauth2`) | OpenID Connect (`openidconnect`) |
| --- | --- | --- |
| Rolle | owncloud.online gibt Token aus | owncloud.online prüft Token eines fremden Anbieters |
| Konten liegen | lokal in owncloud.online | beim Identity Provider |
| Konfiguration | Clients in der Datenbank, per `occ` oder Verwaltungsbereich | `config.php` oder App-Konfiguration |
| Einstellungsseite | Einstellungen → Administration → *Nutzer-Authentifizierung* | Einstellungen → Administration → *OpenID Connect* |

Beide Apps registrieren ein Authentifizierungsmodul für `Bearer`-Token. Kennt
`oauth2` einen vorgelegten Token nicht und ist `openidconnect` aktiviert, gibt
`oauth2` den Token weiter, statt die Anfrage abzuweisen
(`lib/AuthModule.php`). Ein Mischbetrieb aus lokalen OAuth2-Clients und
Anmeldung über den Anbieter ist damit möglich.

Beide Module kennen das Benutzerpasswort nicht. Nutzerschlüssel-Verschlüsselung
funktioniert mit solchen Sitzungen deshalb nicht, nur der Hauptschlüssel, siehe
[Verschlüsselung](encryption.md).

## OAuth2 einrichten

### App aktivieren

```bash
sudo -u www-data php8.4 occ app:enable oauth2
```

Die Datenbanktabellen legt die App über ihre Migrationen an. Dieselben
Migrationen tragen außerdem drei Clients mit fest eingebauten Kennungen ein —
*Desktop Client*, *Android* und *iOS*. Sie sind unmittelbar nach dem Aktivieren
vorhanden und müssen nicht von Hand angelegt werden; `occ oauth2:list-clients`
zeigt sie an.

### Client anlegen

```bash
sudo -u www-data php8.4 occ oauth2:add-client \
  "Fremdanwendung" \
  "<client-id>" \
  "<client-secret>" \
  "https://app.example.com/oauth/callback"
```

Der Name muss neu sein — die drei mitgelieferten Namen sind bereits vergeben.

| Argument | Pflicht | Bedeutung |
| --- | --- | --- |
| `name` | ja | Anzeigename auf der Zustimmungsseite, muss eindeutig sein |
| `client-id` | ja | Kennung des Clients, mindestens 32 Zeichen |
| `client-secret` | ja | Geheimnis des Clients, mindestens 32 Zeichen |
| `redirect-url` | ja | Rückleit-URL, muss eine gültige URL sein |
| `allow-sub-domains` | nein | `true` oder `false`, Standard `false` |
| `trusted` | nein | `true` überspringt die Zustimmungsseite, Standard `false` |
| `force-trust` | nein | `true` erlaubt `trusted` auch für `localhost` |

Weitere Befehle:

```bash
# alle Clients samt Geheimnis anzeigen
sudo -u www-data php8.4 occ oauth2:list-clients

# einzelnen Wert ändern
sudo -u www-data php8.4 occ oauth2:modify-client "Desktop Client" redirect-url "http://localhost:*"

# Client entfernen
sudo -u www-data php8.4 occ oauth2:remove-client "<client-id>"
```

`oauth2:modify-client` akzeptiert als Schlüssel `name`, `client-id`,
`client-secret`, `redirect-url`, `allow-sub-domains` und `trusted`.

Im Verwaltungsbereich unter *Nutzer-Authentifizierung* gibt es denselben
Client-Bestand unter *OAuth 2.0* → *Angelegte Clients*. Das Formular
*Client hinzufügen* kennt nur *Name*, *URI weiterleiten*, *Subdomains erlauben*
und *Vertrauenswürdiger Client*; Kennung und Geheimnis werden dabei zufällig
erzeugt. Ein Client mit einer vorgegebenen Kennung lässt sich deshalb nur über
`occ oauth2:add-client` anlegen oder nachträglich mit
`occ oauth2:modify-client` auf die gewünschten Werte setzen.

### Rückleit-URLs der Clients

Die Werte sind im jeweiligen Client fest eingebaut. Die Migration registriert
sie serverseitig bereits mit; die Tabelle dient dem Abgleich.

| Client | Registrierte Rückleit-URL | Client-Kennung |
| --- | --- | --- |
| Desktop Client | `http://localhost:*` — der Client wählt den Port frei | `xdXOt13JKxym1B1QcEncf2XDkLAexMBFwiT9j6EfhhHFJhs2KM9jbjTmf8JBXE69` |
| Android | `oc://android.owncloud.com` | `e4rAsNUSIUs0lF4nbv9FmCeUkTlV9GdgTLDH1b5uie7syb90SzEVrbN7HIpmWJeD` |
| iOS | `oc://ios.owncloud.com` | `mxd5OQDk6es5LzOzRvidJNfXLUZS2oN3oUFeXPP8LpPrhx3UroJFduGEYIBOxkY1` |

Bei iOS weichen Server und Client auseinander: Serverseitig steht
`oc://ios.owncloud.com`, das Branding des iOS-Clients
(`ownCloud/Resources/Theming/Branding.plist`) trägt dagegen
`oc://ios.owncloud.online` und bei Kennung und Geheimnis noch den Platzhalter
`TODO_REGISTER_ON_OWNCLOUD_ONLINE`. Vor dem Ausrollen eines eigenen
iOS-Clients müssen beide Seiten angeglichen werden.

Die zugehörigen Geheimnisse stehen im Quellcode der Clients
(`src/libsync/theme.cpp` beim Desktop-Client,
`owncloudApp/src/main/res/values/setup.xml` bei Android). Sie sind kein
Schutzmechanismus, sondern nur der zweite Teil der vereinbarten Kennung; der
eigentliche Schutz liegt in der Rückleit-URL und in PKCE.

Beim Einlösen prüft die App Protokoll, Host, Pfad und Query der Rückleit-URL auf
exakte Gleichheit. Der Port wird nur dann nicht geprüft, wenn die registrierte
URL mit `http://localhost:*` beginnt. Andere Hosts unterhalb der registrierten
Domain sind nur erlaubt, wenn `allow-sub-domains` gesetzt ist
(`lib/Utilities.php`).

### Zustimmungsseite

Ohne `trusted` sieht der Benutzer im Browser eine Seite mit dem Clientnamen und
dem Knopf *Autorisieren*; darunter führt *Benutzer wechseln, um fortzufahren* zu
einer Neuanmeldung. Mit `trusted` entfällt diese Seite und der Client bekommt
den Autorisierungscode direkt. `localhost` und `127.0.0.1` lassen sich nur mit
`force-trust` als vertrauenswürdig eintragen — das ist für Testaufbauten
gedacht, nicht für Kundeninstanzen.

### Endpunkte und Laufzeiten

| Zweck | Adresse |
| --- | --- |
| Autorisierung | `/index.php/apps/oauth2/authorize` |
| Token | `/index.php/apps/oauth2/api/v1/token` |
| UserInfo | `/index.php/apps/oauth2/api/v1/userinfo` |

Ein Autorisierungscode ist 600 Sekunden gültig, ein Zugriffstoken 3600
Sekunden; danach holt der Client mit dem Refresh-Token ein neues. Abgelaufene
Codes und Token räumt ein täglicher Hintergrund-Job weg, und zwar erst eine
Woche nach Ablauf. Läuft kein Cron, bleiben die Zeilen in der Datenbank stehen
— abgelehnt werden sie trotzdem. Siehe
[Hintergrund-Jobs (Cron)](background-jobs.md).

## OpenID Connect einrichten

### Voraussetzungen

```bash
sudo -u www-data php8.4 occ app:enable openidconnect
```

Sobald eine Konfiguration hinterlegt ist, verlangt die App bei jedem
Web-Request einen verteilten Cache und bricht sonst mit
`A real distributed mem cache setup is required` ab. `memcache.distributed`
fällt auf `memcache.local` zurück; ist keines von beiden gesetzt, ist die
Instanz nach dem Konfigurieren nicht mehr bedienbar. Redis oder APCu also
**vorher** einrichten, siehe
[Sicherheit und Setup-Warnungen](security-hardening.md).

### Konfiguration in config.php

```php
'openid-connect' => [
    'provider-url'     => 'https://idp.example.com',
    'client-id'        => 'owncloud-online',
    'client-secret'    => '…',
    'scopes'           => ['openid', 'profile', 'email'],
    'mode'             => 'userid',
    'search-attribute' => 'preferred_username',
    'loginButtonName'  => 'Anmeldung über den Firmenzugang',
    'autoRedirectOnLoginPage' => false,
],
```

`mode` entscheidet, wie der Benutzer gesucht wird: `userid` vergleicht den in
`search-attribute` genannten Claim mit der Benutzerkennung, `email` sucht über
die Mailadresse. `allowed-user-backends` schränkt die Anmeldung auf bestimmte
Benutzer-Backends ein. Fehlt die Angabe, sind alle erlaubt.

Neue Konten kann die App selbst anlegen:

```php
'auto-provision' => [
    'enabled'            => true,
    'groups'             => ['oidc-users'],
    'email-claim'        => 'email',
    'display-name-claim' => 'name',
    'picture-claim'      => 'picture',
    'update'             => ['enabled' => true],
],
```

Im selben `auto-provision`-Block wirken `provisioning-claim` und
`provisioning-attribute` zusammen als Filter: Das Konto entsteht nur, wenn der
genannte Claim eine Liste ist und das geforderte Attribut enthält. Mit
`mode => 'email'` bekommen neu angelegte Konten eine erzeugte Kennung der Form
`oidc-user-<zufall>`, weil die Mailadresse dann der Suchschlüssel ist und nicht
die Kennung.

### Konfiguration im Verwaltungsbereich

Einstellungen → Administration → *OpenID Connect* pflegt dieselben Werte über
ein Formular. Die Feldbeschriftungen dieser Seite sind nicht übersetzt und
erscheinen auf Deutsch eingestellter Oberfläche in englischer Sprache
(*Provider URL*, *Client ID*, *Client secret*, *Lookup mode*, *Search claim*
und so weiter).

Wichtig ist die Rangfolge: Gespeicherte Formularwerte landen als
App-Konfiguration unter der App `openidconnect` im Schlüssel `openid-connect`
und haben **Vorrang** vor `config.php`. Solange sie existieren, bleibt jede
Änderung an `config.php` wirkungslos. Die Seite zeigt oben unter
*Configuration source* an, welche Quelle gerade greift. Der Knopf
*Use system config* löscht die App-Konfiguration wieder; auf der Kommandozeile:

```bash
# gespeicherte App-Konfiguration ansehen
sudo -u www-data php8.4 occ config:app:get openidconnect openid-connect

# App-Konfiguration verwerfen, danach gilt wieder config.php
sudo -u www-data php8.4 occ config:app:delete openidconnect openid-connect

# den Block aus config.php prüfen
sudo -u www-data php8.4 occ config:list system
```

### Was beim Anbieter eingetragen wird

| Zweck | Adresse |
| --- | --- |
| Rückleit-URL (Redirect URI) | `https://cloud.example.com/index.php/apps/openidconnect/redirect` |
| Abmeldung durch den Anbieter | `https://cloud.example.com/index.php/apps/openidconnect/logout` |
| Discovery-Weiterreichung | `https://cloud.example.com/index.php/apps/openidconnect/config` |

Die Rückleit-URL lässt sich mit `redirect-url` überschreiben, etwa wenn ein
Proxy davor eine andere Adresse ausliefert. Die Abmeldeadresse erwartet die
Parameter `iss` und `sid`. `iss` muss dieselbe Domäne wie die konfigurierte
`provider-url` tragen — Protokoll, Host und Port werden verglichen, der Pfad
nicht. Passt es nicht oder fehlt einer der beiden Parameter, wird die
Sitzungskennung nicht aus dem Cache entfernt; eine im selben Aufruf noch aktive
Browser-Sitzung wird davon unabhängig abgemeldet.

Auf der Anmeldeseite erscheint unter *Alternative Logins* ein Knopf mit dem
Text aus `loginButtonName`. Mit `autoRedirectOnLoginPage => true` wird
stattdessen jeder Aufruf der Anmeldeseite sofort zum Anbieter weitergeleitet —
die lokale Anmeldemaske ist dann nicht mehr erreichbar.

## .well-known-Adressen umschreiben

Der Desktop-Client fragt beim Einrichten eines Kontos zuerst
`/.well-known/openid-configuration` ab und nimmt Autorisierungs- und
Token-Endpunkt aus der Antwort. Genau diese Adresse liefert eine
Standardinstallation aber nicht aus: Die mitgelieferte `.htaccess` bildet nur
`host-meta` (auch `host-meta.json`), `carddav` und `caldav` ab und beantwortet
alles Übrige, das mit einem Punkt beginnt, mit 404 — ausgenommen
`acme-challenge` und `pki-validation`.

Die Folge ist kein sichtbarer Fehler, sondern ein stiller Rückfall: Unser
Desktop-Client wechselt bei fehlgeschlagener Abfrage auf die alten
OAuth2-Routen `/index.php/apps/oauth2/authorize` und
`/index.php/apps/oauth2/api/v1/token`. Der Client meldet sich dann weiterhin
lokal an, obwohl OpenID Connect eingerichtet ist.

Damit die Abfrage funktioniert, muss der Webserver umschreiben:

| Angefragte Adresse | Ziel |
| --- | --- |
| `/.well-known/openid-configuration` | `/index.php/apps/openidconnect/config` |

Apache, in der vhost-Konfiguration:

```apache
RewriteEngine on
RewriteRule ^/\.well-known/openid-configuration$ /index.php/apps/openidconnect/config [PT,L]
```

nginx:

```nginx
location = /.well-known/openid-configuration {
    return 301 /index.php/apps/openidconnect/config;
}
```

Zwei Punkte dazu:

- Die Regel gehört in die vhost-Konfiguration, nicht in die `.htaccess`. Die
  `.htaccess` ist Teil des Auslieferungspakets und wird bei einer
  Aktualisierung durch die Paketfassung ersetzt. Hinter nginx wird sie ohnehin
  nicht gelesen.
- Sie muss vor der Regel stehen, die Pfade mit führendem Punkt abweist.

Die Ziel-Route reicht das Discovery-Dokument des Anbieters durch. Sie antwortet
mit einer leeren JSON-Liste (`[]`), solange keine OpenID-Connect-Konfiguration
hinterlegt ist — dann greift wieder der Rückfall auf die OAuth2-Routen.

## Sitzungen zurückziehen

**Benutzer:** Einstellungen → Persönlich → *Sicherheit* → *OAuth 2.0* →
*Autorisierte Anwendungen*. Das Löschsymbol in der Zeile (*Revoke
authorization*) entfernt Autorisierungscodes, Zugriffs- und Refresh-Token
dieses Clients für dieses Konto. Andere Benutzer desselben Clients sind nicht
betroffen.

**Administration:** Wird ein Client unter *Nutzer-Authentifizierung* gelöscht,
verschwinden zusätzlich alle Codes und Token dieses Clients für sämtliche
Benutzer.

`occ oauth2:remove-client` verhält sich anders: Der Befehl löscht nur den
Client-Eintrag. Bereits ausgegebene Zugriffstoken bleiben deshalb bis zu ihrem
Ablauf gültig — höchstens eine Stunde —, während ein Refresh danach mit
`invalid_client` scheitert. Wer den Zugang sofort schließen will, löscht den
Client im Verwaltungsbereich.

Beim Löschen eines Benutzers werden dessen Codes und Token automatisch mit
entfernt.

**OpenID Connect:** Die Abmeldung in der Weboberfläche ruft beim Anbieter
`revokeToken` und `signOut` auf; `post_logout_redirect_uri` bestimmt, wohin der
Browser danach geht. Umgekehrt kann der Anbieter eine Sitzung über die
Abmeldeadresse mit `iss` und `sid` beenden: Die Sitzungskennung wird dann aus
dem verteilten Cache entfernt, und beim nächsten Request wird der Benutzer
abgemeldet. Läuft die Instanz auf mehreren Knoten, muss dieser Cache von allen
Knoten gemeinsam genutzt werden, sonst wirkt die Abmeldung nur auf einem.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| `invalid_client` am Token-Endpunkt | Kennung oder Geheimnis stimmen nicht, oder der Reverse Proxy reicht den `Authorization`-Header nicht durch | Werte mit `occ oauth2:list-clients` vergleichen; Header im Proxy durchreichen |
| `invalid_grant`, `auth grant redirect uri invalid` | Die vom Client geschickte Rückleit-URL weicht ab; Protokoll, Host, Pfad und Query müssen exakt passen | Registrierte URL mit `occ oauth2:modify-client <name> redirect-url <url>` korrigieren |
| Seite „Anfrage nicht gültig" statt Zustimmungsseite | Unbekannte `client_id` oder unpassende Rückleit-URL | Client anlegen beziehungsweise Rückleit-URL angleichen |
| Desktop-Client meldet sich lokal an, obwohl OpenID Connect eingerichtet ist | `/.well-known/openid-configuration` liefert 404, der Client fällt auf die OAuth2-Routen zurück | Umschreibung im Webserver einrichten |
| Jede Seite bricht mit „A real distributed mem cache setup is required" ab | OpenID Connect ist konfiguriert, aber kein verteilter Cache vorhanden | `memcache.local` bzw. `memcache.distributed` setzen |
| „Configuration issue in openidconnect app" beim Anmelden | Konfiguration fehlt oder ist kein gültiges JSON | `occ config:app:get openidconnect openid-connect` und `occ config:list system` prüfen |
| Änderungen an `config.php` bleiben wirkungslos | Eine App-Konfiguration überdeckt sie | `occ config:app:delete openidconnect openid-connect` oder im Formular *Use system config* |
| „… is not unique." bei der Anmeldung | `mode` steht auf `email` und mehrere Konten teilen sich die Adresse | Auf `mode` `userid` wechseln oder die Konten bereinigen |
| „User … is not known." | Konto fehlt lokal und Auto-Provisionierung ist aus | `search-attribute` prüfen oder `auto-provision` einschalten |
| „User is from wrong user backend" | `allowed-user-backends` schließt das Backend des Kontos aus | Backend ergänzen oder die Einschränkung entfernen |
| Verschlüsselung schlägt bei Token-Anmeldung fehl | Die Auth-Module kennen kein Passwort | Nur Hauptschlüssel verwenden, siehe [Verschlüsselung](encryption.md) |
| Abgelaufene Token bleiben in der Datenbank | Der tägliche Aufräum-Job läuft nicht | Cron prüfen, siehe [Hintergrund-Jobs (Cron)](background-jobs.md) |

Ausführliche Meldungen beider Apps stehen im Protokoll, siehe
[Serverprotokoll und Fehlermeldungen](logging.md).
