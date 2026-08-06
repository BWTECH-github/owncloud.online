# Konfiguration (config.php)

Die zentrale Konfiguration liegt in `/var/www/owncloud.online/config/config.php`.
Die Datei ist ein PHP-Array und gehört `www-data` (Rechte `0640`) — sie enthält
Datenbank-Zugangsdaten und den Instanz-Schlüssel.

Alle verfügbaren Schlüssel sind in `config/config.sample.php` dokumentiert.
Diese Seite beschreibt die, die im Betrieb tatsächlich gebraucht werden.

## Werte lesen und setzen

Konfiguration nie im laufenden Betrieb von Hand editieren, wenn `occ` es kann —
`occ` validiert Typen und schreibt die Datei atomar:

```bash
# alle Werte anzeigen (Passwörter werden ausgeblendet)
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:list system

# einzelnen Wert setzen
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:set loglevel --value 2 --type integer

# Wert löschen
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:delete maintenance
```

`--type` nicht vergessen (`integer`, `boolean`, `json`) — sonst landet der Wert
als Zeichenkette und wird falsch ausgewertet.

## Grundeinstellungen

| Schlüssel | Bedeutung |
| --- | --- |
| `instanceid` | Instanz-Kennung — **nie ändern** |
| `passwordsalt`, `secret` | Kryptografische Werte — **nie ändern**, immer mitsichern |
| `datadirectory` | Ablageort der Benutzerdaten |
| `dbtype`, `dbhost`, `dbname`, `dbuser`, `dbpassword` | Datenbankzugang, siehe [Datenbank](database.md) |
| `trusted_domains` | Erlaubte Hostnamen. Fehlt der Name, verweigert ownCloud den Zugriff |
| `overwrite.cli.url` | Basis-URL für Cron und CLI-Aufrufe |
| `maintenance` | Wartungsmodus (besser über `occ maintenance:mode`) |

## Betrieb hinter einem Reverse-Proxy

```php
'trusted_proxies' => ['10.0.0.5'],
'overwriteprotocol' => 'https',
```

`trusted_proxies` ist **sicherheitsrelevant**: Nur für diese Adressen wertet
ownCloud `X-Forwarded-For` aus. Ist der Wert falsch — oder der PHP-FPM-Server
direkt aus dem Netz erreichbar — kann ein Angreifer seine Client-IP frei
wählen und damit IP-basierte Schutzmechanismen aushebeln, etwa die
Anmelde-Bremse gegen Passwort-Raten. Siehe
[Sicherheit und Setup-Warnungen](security-hardening.md).

## Protokollierung

| Schlüssel | Empfehlung |
| --- | --- |
| `loglevel` | `2` (Warnungen) im Betrieb, `0` nur kurzzeitig zur Fehlersuche |
| `logfile` | Abweichender Pfad, sonst `<datadirectory>/owncloud.log` |
| `log_rotate_size` | Rotationsgröße in Bytes, z. B. `104857600` (100 MB) |

Details unter [Serverprotokoll und Fehlermeldungen](logging.md).

## Leistung

| Schlüssel | Empfehlung |
| --- | --- |
| `memcache.local` | `\OC\Memcache\APCu` |
| `memcache.locking` | `\OC\Memcache\Redis` (mit `redis`-Block) |
| `filelocking.enabled` | `true` |
| `enable_previews` | `true`; `preview_max_x`/`preview_max_y` begrenzen die Größe |

Siehe [Performance](performance.md).

## Wartung und Updates

| Schlüssel | Bedeutung |
| --- | --- |
| `updatechecker` | Update-Prüfung; auf `false`, wenn Updates zentral gesteuert werden |
| `upgrade.disable-web` | Upgrades nur über `occ` erlauben (empfohlen) |
| `skeletondirectory` | Vorlagenordner für neue Konten; leer lassen für keine Vorlage |
| `user.home_base_dirs` | Zusätzlich erlaubte Basisverzeichnisse für Benutzer-Homes aus dem Backend (z. B. LDAP) |

## Nach jeder Änderung

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:list system
```

Ein Syntaxfehler in `config.php` legt die Instanz komplett lahm — vor dem
Editieren eine Kopie anlegen und danach `php8.4 -l config/config.php` prüfen.
