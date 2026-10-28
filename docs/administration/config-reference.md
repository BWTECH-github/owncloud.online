# Konfiguration (config.php)

Die zentrale Konfiguration liegt in `/var/www/owncloud.online/config/config.php`.
Die Datei ist ein PHP-Array, gehört `www-data` und hat die Rechte `0640`, denn
sie enthält die Zugangsdaten der Datenbank und den Instanz-Schlüssel.

Alle verfügbaren Schlüssel sind in `config/config.sample.php` beschrieben. Die
Tabellen unten nennen die Schlüssel, die im Betrieb gebraucht werden.

## Werte lesen und setzen

Ändern Sie die Konfiguration im laufenden Betrieb mit `occ` statt im Editor.
`occ` prüft den Typ des Werts und schreibt die Datei unter einer exklusiven
Dateisperre:

```bash
# alle Werte anzeigen (Passwörter und Schlüssel ausgeblendet, --private zeigt sie)
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:list system

# einzelnen Wert setzen
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:set loglevel --value 2 --type integer

# Wert löschen
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:system:delete maintenance
```

Vergessen Sie `--type` nicht (`integer`, `double`, `boolean`, `json`). Ohne die
Angabe landet der Wert als Zeichenkette in der Datei und wird falsch
ausgewertet.

## Grundeinstellungen

| Schlüssel | Bedeutung |
| --- | --- |
| `instanceid` | Instanz-Kennung, **nie ändern** |
| `passwordsalt`, `secret` | Kryptografische Werte, **nie ändern**, immer mitsichern |
| `datadirectory` | Ablageort der Benutzerdaten |
| `dbtype`, `dbhost`, `dbname`, `dbuser`, `dbpassword` | Datenbankzugang, siehe [Datenbank](database.md) |
| `trusted_domains` | Erlaubte Hostnamen. Fehlt der Name, wird der Zugriff verweigert |
| `overwrite.cli.url` | Basis-URL für Cron und Aufrufe über die Kommandozeile |
| `maintenance` | Wartungsmodus (besser über `occ maintenance:mode`) |

## Betrieb hinter einem Reverse-Proxy

```php
'trusted_proxies' => ['10.0.0.5'],
'overwriteprotocol' => 'https',
```

`trusted_proxies` ist **sicherheitsrelevant**: Nur für diese Adressen wertet der
Server `X-Forwarded-For` aus. Ist der Wert falsch oder der PHP-FPM-Server direkt
aus dem Netz erreichbar, kann ein Angreifer seine Client-IP frei wählen und
damit IP-basierte Schutzmechanismen aushebeln, etwa die Bremse gegen
Passwort-Raten. Siehe
[Sicherheit und Setup-Warnungen](security-hardening.md).

## Protokollierung

| Schlüssel | Empfehlung |
| --- | --- |
| `loglevel` | `2` (Warnungen) im Betrieb, `0` nur kurzzeitig zur Fehlersuche |
| `logfile` | Abweichender Pfad, sonst `<datadirectory>/owncloud.log` |
| `log_rotate_size` | Rotationsgröße in Bytes, Standard `104857600` (100 MB); `0` oder `false` schaltet die Rotation ab |

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
| `skeletondirectory` | Vorlagenordner für neue Konten; eine leere Zeichenkette (`''`) lässt neue Konten ohne Vorlage starten |
| `user.home_base_dirs` | Zusätzlich erlaubte Basisverzeichnisse für Benutzer-Homes aus dem Backend (z. B. LDAP) |

## Nach jeder Änderung

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:list system
```

Ein Syntaxfehler in `config.php` legt die ganze Instanz lahm. Legen Sie vor dem
Bearbeiten eine Kopie an und prüfen Sie die Datei danach mit
`php8.4 -l config/config.php`.
