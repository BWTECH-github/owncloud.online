# occ – die Kommandozeile

`occ` ist das Verwaltungswerkzeug von owncloud.online auf der Kommandozeile. Es
liegt als Datei `occ` im Wurzelverzeichnis der Installation, startet dieselbe
Umgebung wie die Weboberfläche und lädt zusätzlich die Befehle der aktivierten
Apps. Der Befehlsvorrat ist deshalb nicht fest: Er hängt davon ab, ob die
Instanz installiert ist, ob sie im Wartungsmodus steht und welche Apps
aktiviert sind.

## Aufruf

```bash
# alle verfügbaren Befehle auflisten
sudo -u www-data php8.4 /var/www/owncloud.online/occ list

# Hilfe zu einem einzelnen Befehl mit allen Optionen
sudo -u www-data php8.4 /var/www/owncloud.online/occ user:add --help
```

Der Aufruf funktioniert aus jedem Arbeitsverzeichnis, solange der vollständige
Pfad angegeben wird — `console.php` wechselt selbst in das Installationsverzeichnis.
Auf dieser Seite ist der Pfad in den Beispielen der Kürze halber weggelassen.

Vorausgesetzt werden die PHP-Erweiterungen **posix** (harte Bedingung, ohne sie
bricht `occ` sofort ab) und **pcntl** (weiche Bedingung, ohne sie lassen sich
lang laufende Befehle nicht sauber unterbrechen; es erscheint eine Warnung).

## Rechte: immer als Webserver-Benutzer

`occ` prüft vor jeder Ausführung, ob der aufrufende Benutzer identisch mit dem
Eigentümer von `config/config.php` ist. Ist er es nicht, bricht der Aufruf ab:

```text
Console has to be executed with the user that owns the file config/config.php
```

In der Standardinstallation ist das `www-data`. Deshalb gilt ausnahmslos:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
```

Ein Aufruf als `root` ist auch dann falsch, wenn er durchläuft: Dateien und
Verzeichnisse, die dabei entstehen — Logdatei, Vorschaubilder, Dateien im
Datenverzeichnis — gehören danach `root` und sind für den Webserver nicht mehr
beschreibbar. Dasselbe gilt für den Cron-Eintrag, siehe
[Hintergrund-Jobs](background-jobs.md).

## Globale Optionen

| Option | Wirkung |
| --- | --- |
| `--help`, `-h` | Hilfe zum Befehl statt Ausführung |
| `-v`, `-vv`, `-vvv` | Ausführlichere Ausgabe |
| `--no-warnings` | Unterdrückt globale Hinweise, gibt nur die Befehlsausgabe aus |
| `--output=plain\|json\|json_pretty` | Maschinenlesbare Ausgabe — nur bei Befehlen, die auf `OC\Core\Command\Base` aufbauen, unter anderem `status`, `check`, `app:list`, `user:list`, `group:list`, `config:list` |

```bash
sudo -u www-data php8.4 occ status --output=json_pretty
```

## Wartungsmodus

Der Wartungsmodus wird über `occ` gesetzt und schreibt den Systemwert
`maintenance` nach `config/config.php`:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
sudo -u www-data php8.4 occ maintenance:mode --off

# ohne Option: aktuellen Zustand anzeigen
sudo -u www-data php8.4 occ maintenance:mode
```

Entscheidend für die Praxis: **Im Wartungsmodus lädt `occ` keine Apps.** Damit
verschwinden auch deren Befehle aus der Liste. `occ` weist beim Start darauf
hin:

```text
owncloud.online is in maintenance mode - no app have been loaded
```

| Vorgang | Im Wartungsmodus |
| --- | --- |
| Core-Befehle (`user:*`, `group:*`, `config:*`, `db:*`, `migrations:*`, `maintenance:*`, `upgrade`, `log:*`) | verfügbar |
| App-Befehle (`files:*`, `trashbin:*`, `versions:*`, `dav:*`, `files_external:*`, `market:*`) | **nicht** verfügbar — die Apps werden nicht geladen |
| `occ system:cron` — und damit auch `cron.php` auf der Kommandozeile, das nur `occ system:cron` aufruft | bricht mit „We are in maintenance mode, skipping cron" ab |
| Weboberfläche (`index.php`) | HTTP 503 mit `Retry-After: 120` und Wartungsseite |
| Sync-Clients (`remote.php`, WebDAV) | HTTP 503 „System in maintenance mode." — ohne `Retry-After` |

Zwei weitere Zustände schränken den Befehlsvorrat ebenso ein:

* **Nicht installiert:** Es steht nur `maintenance:install` zur Verfügung, dazu
  die wenigen Befehle, die keine Installation brauchen (`status`, `check`,
  `integrity:*`, `app:check-code`, `l10n:createjs`).
* **Update ausstehend:** Meldet die Instanz „owncloud.online or one of the apps
  require upgrade", werden die Apps nicht geladen — der Befehlsvorrat entspricht
  dem im Wartungsmodus, die Core-Befehle bleiben also vorhanden. Der Weg heraus
  ist `occ upgrade`.

Der Einzelbenutzermodus (`maintenance:singleuser`, Systemwert `singleuser`) ist
davon zu unterscheiden: Er lässt die Apps geladen. `occ system:cron` bricht darin
mit „We are in admin only mode, skipping cron" ab, die Weboberfläche antwortet
Konten außerhalb der Gruppe `admin` mit HTTP 503, und der WebDAV-Endpunkt
verweigert jede Anfrage mit „System in single user mode." — auch die von
Administratoren.

## Wartung und Update

| Befehl | Zweck |
| --- | --- |
| `status` | Version, Edition und Installationszustand ausgeben |
| `check` | Abhängigkeiten der Server-Umgebung prüfen |
| `upgrade` | Update-Routinen nach dem Einspielen einer neuen Version ausführen |
| `maintenance:mode` | Wartungsmodus ein- und ausschalten oder abfragen |
| `maintenance:singleuser` | Einzelbenutzermodus ein- und ausschalten oder abfragen |
| `maintenance:repair` | Reparaturschritte ausführen (`--list`, `--single`, `--include-expensive`) |
| `maintenance:file-locks` | Transaktionale Dateisperren anzeigen und aufräumen |
| `maintenance:data-fingerprint` | Datenfingerabdruck nach dem Zurückspielen eines Backups erneuern |
| `maintenance:update:htaccess` | Die `.htaccess` neu schreiben |
| `maintenance:mimetype:update-db` | MIME-Typen in Datenbank und Dateicache aktualisieren |
| `maintenance:mimetype:update-js` | `mimetypelist.js` neu erzeugen |
| `maintenance:install` | Instanz einrichten (nur vor der Installation vorhanden) |
| `previews:cleanup` | Vorschaubilder ohne zugehörige Datei entfernen |
| `integrity:check-core` | Signatur des Core-Codes prüfen |
| `integrity:check-app` | Signatur einer App prüfen |

Ein Update spielt zuerst den Code ein und lässt danach `occ upgrade` laufen:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:mode --off
```

`integrity:check-core` liefert auf dem ausgelieferten Kanal keine belastbare
Aussage — die Einzelheiten stehen unter
[Sicherheit und Setup-Warnungen](security-hardening.md).

Steckengebliebene Dateisperren nach einem Absturz löst dieser Befehl:

```bash
# gefahrlos im laufenden Betrieb: nur abgelaufene Sperren
sudo -u www-data php8.4 occ maintenance:file-locks --cleanup-expired

# alle Sperren — verlangt aktiven Wartungsmodus
sudo -u www-data php8.4 occ maintenance:file-locks --all
```

Mit `--dry-run` zeigt der Befehl nur an, was er löschen würde.

## Konten und Gruppen

| Befehl | Zweck |
| --- | --- |
| `user:add` | Konto anlegen |
| `user:delete` | Konto löschen |
| `user:modify` | Angaben eines Kontos ändern |
| `user:disable` | Konto sperren |
| `user:enable` | Gesperrtes Konto wieder freigeben |
| `user:list` | Konten mit ihren Attributen auflisten |
| `user:list-groups` | Gruppen eines Kontos auflisten |
| `user:lastseen` | Letzte Anmeldung eines Kontos anzeigen |
| `user:inactive` | Konten melden, die sich seit N Tagen nicht angemeldet haben |
| `user:report` | Anzahl der Konten mit Zugang ausgeben |
| `user:resetpassword` | Passwort eines Kontos zurücksetzen |
| `user:setting` | Einstellungen eines Kontos lesen und ändern |
| `user:sync` | Konten aus einem Backend in die Kontentabelle übernehmen |
| `user:move-home` | Heimatverzeichnis eines Kontos verschieben |
| `user:home:list-dirs` | Alle benutzten Wurzelverzeichnisse für Heimatverzeichnisse auflisten |
| `user:home:list-users` | Alle Konten auflisten, deren Heimat unter einem Pfad liegt |
| `group:add` | Gruppe anlegen |
| `group:delete` | Gruppe löschen |
| `group:list` | Gruppen auflisten |
| `group:add-member` | Mitglieder zu einer Gruppe hinzufügen |
| `group:remove-member` | Mitglieder aus einer Gruppe entfernen |
| `group:list-members` | Mitglieder einer Gruppe auflisten |
| `twofactorauth:enable` | Zwei-Faktor-Anmeldung für ein Konto einschalten |
| `twofactorauth:disable` | Zwei-Faktor-Anmeldung für ein Konto ausschalten |

`user:sync` ist der Befehl für angebundene Verzeichnisdienste. Er kennt die
Kurzformen `ldap`, `samba` und `shibboleth` anstelle des vollen Klassennamens
und listet mit `--list` die aktiven Backends auf:

```bash
sudo -u www-data php8.4 occ user:sync --list
sudo -u www-data php8.4 occ user:sync ldap --missing-account-action=disable
```

`--missing-account-action` nimmt `disable` oder `remove`. **`remove` löscht mit
dem Konto auch dessen Daten und Dateien** — im Regelbetrieb gehört dort
`disable` hin.

## Dateien und Dateisystem

| Befehl | Zweck |
| --- | --- |
| `files:scan` | Dateisystem auf Änderungen prüfen und den Dateicache nachziehen |
| `files:cleanup` | Verwaiste Einträge aus dem Dateicache entfernen |
| `files:check-cache` | Prüfen, ob eine Datei im primären Speicher tatsächlich vorhanden ist |
| `files:remove-storage` | Einen Speicher samt zugehöriger Cache-Einträge aus der Datenbank entfernen |
| `files:checksums:verify` | Gespeicherte Prüfsummen gegen neu berechnete vergleichen |
| `files:transfer-ownership` | Alle Dateien und Ordner eines Kontos auf ein anderes übertragen, Freigaben eingeschlossen |
| `files:troubleshoot-transfer-ownership` | Nach Problemen aus einer vorangegangenen Übertragung suchen |
| `trashbin:cleanup` | Gelöschte Dateien endgültig entfernen |
| `trashbin:expire` | Papierkorb nach den eingestellten Regeln ablaufen lassen |
| `versions:cleanup` | Dateiversionen entfernen |
| `versions:expire` | Dateiversionen nach den eingestellten Regeln ablaufen lassen |
| `files_external:list` | Eingerichtete externe Speicher auflisten |
| `files_external:create` | Externen Speicher anlegen |
| `files_external:delete` | Externen Speicher löschen |
| `files_external:config` | Backend-Konfiguration eines Speichers verwalten |
| `files_external:option` | Einbindungsoptionen eines Speichers verwalten |
| `files_external:applicable` | Zuständige Konten und Gruppen eines Speichers verwalten |
| `files_external:backends` | Verfügbare Speicher- und Anmelde-Backends anzeigen |
| `files_external:verify` | Konfiguration eines Speichers gegen das Ziel prüfen |
| `files_external:import` | Speicherkonfigurationen einlesen |
| `files_external:export` | Speicherkonfigurationen ausgeben |

`files:scan` ist der Befehl, den man nach jedem Eingriff direkt im
Datenverzeichnis braucht — der Dateicache kennt sonst die neuen Dateien nicht:

```bash
# ein Konto
sudo -u www-data php8.4 occ files:scan alice

# nur ein Unterpfad
sudo -u www-data php8.4 occ files:scan --path="/alice/files/Musik"

# alle Konten
sudo -u www-data php8.4 occ files:scan --all
```

Zusätzlich gibt es `--group`, `--unscanned` (nur unvollständig erfasste
Dateien) und `--repair` für abgehängte Cache-Einträge. `--repair` ist deutlich
langsamer und gehört in ein Wartungsfenster.

## Freigaben

| Befehl | Zweck |
| --- | --- |
| `sharing:cleanup-remote-storages` | `shared::`-Speicher entfernen, zu denen kein Eintrag in `shares_external` mehr existiert |
| `incoming-shares:poll` | Eingehende föderierte Freigaben von Hand auf Änderungen abfragen |
| `federation:trusted-servers:add` | Vertrauenswürdigen Server eintragen |
| `federation:trusted-servers:list` | Vertrauenswürdige Server auflisten |
| `federation:trusted-servers:remove` | Vertrauenswürdigen Server entfernen |

Beim Übertragen eines Kontos wandern die Freigaben mit — dafür ist
`files:transfer-ownership` zuständig, siehe voriger Abschnitt.

## Konfiguration

| Befehl | Zweck |
| --- | --- |
| `config:list` | Alle Werte ausgeben (`system`, ein App-Name oder `all`) |
| `config:system:get` | Einen Wert aus `config.php` lesen |
| `config:system:set` | Einen Wert in `config.php` setzen |
| `config:system:delete` | Einen Wert aus `config.php` entfernen |
| `config:app:get` | Einen App-Wert aus der Datenbank lesen |
| `config:app:set` | Einen App-Wert in der Datenbank setzen |
| `config:app:delete` | Einen App-Wert aus der Datenbank entfernen |
| `config:import` | Eine Liste von Werten einlesen |

```bash
sudo -u www-data php8.4 occ config:list system
sudo -u www-data php8.4 occ config:system:set loglevel --value 2 --type integer
```

`config:list` ersetzt einen fest hinterlegten Satz vertraulicher **Systemwerte**
durch `***REMOVED SENSITIVE VALUE***` — darunter `dbpassword`, `passwordsalt`,
`secret`, `mail_smtppassword`, `ldap_agent_password`, `license-key` und die
Zugangsdaten unter `redis` und `objectstore`. Erst `--private` gibt sie im
Klartext aus. **Die App-Werte aus der Datenbank filtert der Befehl nicht** — sie
stehen bei `config:list all` und `config:list <app>` auch ohne `--private`
unverändert in der Ausgabe. Die Ausgabe gehört deshalb in keinem Fall ungeprüft
in ein Ticket oder ein Repository. Die einzelnen Schlüssel beschreibt
[Konfiguration (config.php)](config-reference.md).

## Hintergrundaufträge

| Befehl | Zweck |
| --- | --- |
| `background:cron` | Ausführungsart auf System-Cron stellen |
| `background:ajax` | Ausführungsart auf AJAX stellen |
| `background:webcron` | Ausführungsart auf Webcron stellen |
| `background:queue:status` | Zustand der Warteschlange anzeigen |
| `background:queue:execute` | Einen einzelnen Auftrag aus der Warteschlange ausführen |
| `background:queue:delete` | Einen Auftrag aus der Warteschlange löschen |
| `system:cron` | Hintergrundaufträge einmal wie ein Cron-Lauf abarbeiten |

Die drei `background:`-Befehle schreiben denselben App-Wert
`core` / `backgroundjobs_mode`; abfragen lässt er sich mit
`config:app:get core backgroundjobs_mode`. Empfohlen ist `cron`, Einrichtung
und Prüfung stehen unter [Hintergrund-Jobs](background-jobs.md).

`system:cron` bricht ab, wenn ein Update aussteht, der Wartungsmodus oder der
Einzelbenutzermodus aktiv ist oder die Ausführungsart auf `none` steht. Mit
`--progress` zeigt es einen Fortschrittsbalken — diese Option gehört
ausdrücklich nicht in einen Crontab-Eintrag.

## Datenbank

| Befehl | Zweck |
| --- | --- |
| `db:convert-mysql-charset` | Zeichensatz einer MySQL-/MariaDB-Datenbank auf `utf8mb4` umstellen |
| `db:restore-default-row-format` | Standard-Zeilenformat der MySQL-/MariaDB-Tabellen wiederherstellen |
| `migrations:status` | Zustand der Migrationen anzeigen |
| `migrations:migrate` | Migrationen bis zu einer Version oder bis zur neuesten ausführen |
| `migrations:execute` | Eine einzelne Migration gezielt ausführen |

Vor jedem dieser Eingriffe gehört ein Datenbank-Backup angelegt, siehe
[Backups und Updates](backups-updates.md). Im Regelbetrieb ruft man
`migrations:*` nicht direkt auf — `occ upgrade` erledigt das.

## Protokoll

| Befehl | Zweck |
| --- | --- |
| `log:manage` | Backend, Stufe und Zeitzone der Protokollierung setzen und anzeigen |
| `log:owncloud` | Das dateibasierte Backend einschalten, Pfad und Rotationsgröße setzen |

```bash
# Stufe auf Warnungen, Ausgabe in eine Datei
sudo -u www-data php8.4 occ log:manage --level warning
sudo -u www-data php8.4 occ log:owncloud --enable --rotate-size 100M
```

`log:manage` schreibt die Systemwerte `log_type` (`owncloud`, `syslog` oder
`errorlog`), `loglevel` (`debug`, `info`, `warning`, `error`, `fatal`) und
`logtimezone`. `log:owncloud` schreibt `logfile` und `log_rotate_size`
(`0` schaltet die Rotation ab). Zum Lesen und Auswerten siehe
[Serverprotokoll und Fehlermeldungen](logging.md).

## Apps

| Befehl | Zweck |
| --- | --- |
| `app:list` | Alle vorhandenen Apps mit Zustand auflisten |
| `app:enable` | App aktivieren |
| `app:disable` | App deaktivieren |
| `app:getpath` | Absoluten Pfad zum Verzeichnis einer App ausgeben |
| `app:check-code` | Code einer App auf Regelkonformität prüfen |
| `market:list` | Im Katalog verfügbare Apps auflisten |
| `market:install` | App aus dem Katalog installieren, vorhandene bei Bedarf aktualisieren |
| `market:upgrade` | Neue App-Versionen aus dem Katalog einspielen |
| `market:uninstall` | App entfernen |

Die vier `market:`-Befehle stammen aus der Markt-App und stehen nur zur
Verfügung, wenn diese aktiviert ist; Katalog und Konfiguration beschreibt
[Apps und Marketplace](apps-market.md).

Das gilt allgemein: Eine App bringt ihre Befehle nur mit, solange sie
aktiviert ist. Nach `app:disable files_external` verschwinden alle
`files_external:*`-Befehle aus `occ list`.

## Weitere registrierte Befehlsgruppen

| Befehl | Zweck |
| --- | --- |
| `encryption:status` | Zustand der serverseitigen Verschlüsselung anzeigen |
| `encryption:enable` | Verschlüsselung einschalten |
| `encryption:disable` | Verschlüsselung ausschalten |
| `encryption:list-modules` | Verfügbare Verschlüsselungsmodule auflisten |
| `encryption:set-default-module` | Standardmodul festlegen |
| `encryption:encrypt-all` | Alle Dateien aller Konten verschlüsseln |
| `encryption:decrypt-all` | Verschlüsselung abschalten und alle Dateien entschlüsseln |
| `encryption:show-key-storage-root` | Aktuelles Wurzelverzeichnis der Schlüsselablage anzeigen |
| `encryption:change-key-storage-root` | Wurzelverzeichnis der Schlüsselablage wechseln |
| `security:certificates` | Vertrauenswürdige Zertifikate auflisten |
| `security:certificates:import` | Vertrauenswürdiges Zertifikat einlesen |
| `security:certificates:remove` | Vertrauenswürdiges Zertifikat entfernen |
| `security:routes` | Verwendete Routen auflisten |
| `security:sign-key:create` | Signaturschlüssel eines Kontos für signierte URLs anlegen oder erneuern |
| `dav:cleanup-chunks` | Liegengebliebene Upload-Fragmente entfernen |
| `dav:create-addressbook` | Adressbuch anlegen |
| `dav:create-calendar` | Kalender anlegen |
| `dav:sync-birthday-calendar` | Geburtstagskalender abgleichen |
| `dav:sync-system-addressbook` | Konten in das System-Adressbuch übernehmen |
| `federation:sync-addressbooks` | Adressbücher aller föderierten Instanzen abgleichen |
| `integrity:sign-core` | Core mit einem privaten Schlüssel signieren |
| `integrity:sign-app` | App mit einem privaten Schlüssel signieren |
| `l10n:createjs` | JavaScript-Übersetzungsdateien einer App erzeugen |
| `migrations:generate` | Gerüst für eine neue Migration erzeugen (Entwicklung) |

Die Einzelheiten zur Verschlüsselung stehen unter
[Verschlüsselung](encryption.md).

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Console has to be executed with the user that owns the file config/config.php" | Aufruf als falscher Benutzer, meist als `root` oder als angemeldeter Administrator | Aufruf mit `sudo -u www-data` wiederholen |
| „The posix extensions are required" | PHP-Erweiterung `posix` fehlt in der CLI-Konfiguration | `posix` in der CLI-`php.ini` bereitstellen |
| Hinweis auf fehlendes PCNTL | PHP-Erweiterung `pcntl` fehlt | Erweiterung nachrüsten oder den Hinweis mit `--no-warnings` unterdrücken |
| App-Befehle wie `files:scan` fehlen in `occ list` | Wartungsmodus aktiv, oder die zugehörige App ist deaktiviert | `occ maintenance:mode` prüfen, sonst `occ app:list` |
| „owncloud.online or one of the apps require upgrade" | Der Code ist neuer als der Datenbankstand | `occ upgrade` ausführen |
| „owncloud.online is not installed" | Es gibt noch keine Installation | `occ maintenance:install` verwenden |
| „Environment not properly prepared." | `occ` hat beim Start Umgebungsfehler gefunden und bricht ab | `occ check` aufrufen — dieser Befehl umgeht die Abbruchprüfung und nennt die Fehler einzeln |
| „Refusing to delete all file locks while maintenance mode is disabled." | `maintenance:file-locks --all` ohne Wartungsmodus | erst `occ maintenance:mode --on`, oder stattdessen `--cleanup-expired` verwenden |
| „We are in maintenance mode, skipping cron" | `system:cron` bei aktivem Wartungsmodus | Wartungsmodus beenden |
| „We are in admin only mode, skipping cron" | Einzelbenutzermodus aktiv | `occ maintenance:singleuser --off` |
| „Background Jobs are disabled!" | `backgroundjobs_mode` steht auf `none` | `occ background:cron` setzen |
| Nach `occ` gehören neue Dateien `root` | Ein früherer Aufruf lief als `root` | Eigentümer zurücksetzen (`chown -R www-data:www-data`) und künftig `sudo -u www-data` verwenden |
| Neue Dateien im Datenverzeichnis erscheinen nicht in der Weboberfläche | Der Dateicache kennt sie nicht | `occ files:scan` für das betroffene Konto oder den Pfad |
