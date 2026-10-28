# Entwicklung

owncloud.online ist Dateiablage, Freigabe und Zusammenarbeit auf dem eigenen
Server, gebaut für PHP 8.4 und gepflegt von der BW-Tech GmbH.

Der Quelltext liegt unter
<https://github.com/BWTECH-github/owncloud.online>, fertige Pakete unter
<https://github.com/BWTECH-github/owncloud.online/releases>. Die aktuelle
Version ist 11.0.25 (`version.php`).

## Aufbau des Repositorys

| Verzeichnis | Inhalt |
| --- | --- |
| `core/` | Start der Anwendung, Templates, JavaScript und CSS des Kerns. Die `occ`-Befehle des Kerns stehen in `core/Command` |
| `lib/private/` | Implementierung, Namensraum `OC\`. Keine stabile Schnittstelle: Apps sollen sich nicht darauf stützen |
| `lib/public/` | die für Apps stabile Schnittstelle, Namensraum `OCP\`. Jede neue Methode braucht ein `@since`; `build/OCPSinceChecker.php` prüft das |
| `lib/private/OCO/` | Ergänzungen von owncloud.online, Namensraum `OCO\` (in `composer.json` registriert) |
| `settings/` | persönliche Einstellungen und Verwaltungsseiten |
| `apps/` | mitgelieferte Apps: `comments`, `dav`, `federatedfilesharing`, `federation`, `files`, `files_external`, `files_sharing`, `files_trashbin`, `files_versions`, `oco_mcp`, `provisioning_api`, `systemtags`, `updatenotification`. Eigene `occ`-Befehle einer App liegen unter `apps/<id>/lib/Command` |
| `apps-external/market/` | die Market-App, über die Apps aus dem Marktplatz installiert werden. Eine Kopie aus [BWTECH-github/market](https://github.com/BWTECH-github/market), die nicht hier bearbeitet wird (siehe unten) |
| `ocs/`, `ocs-provider/`, `ocm-provider/` | OCS-Schnittstelle und die Endpunkte für Verbund-Freigaben |
| `config/` | `config.sample.php` mit allen Schlüsseln samt Erklärung. Die eigene `config.php` entsteht bei der Installation und ist nicht versioniert |
| `core/l10n/`, `settings/l10n/` | Übersetzungen als `.json` und `.js`, direkt im Repository gepflegt. Unter `l10n/` liegen nur die Hilfsskripte dazu |
| `core/vendor/` | JavaScript-Bibliotheken der Oberfläche. Bewusst eingecheckt, damit eine Installation aus dem Git-Stand ohne Node-Werkzeuge eine funktionierende Oberfläche hat |
| `lib/composer/` | Zielverzeichnis von Composer (`vendor-dir` in `composer.json`), nicht versioniert |
| `vendor-bin/` | je Werkzeug eine eigene Composer-Installation: `owncloud-codestyle`, `php_codesniffer`, `phan`, `phpstan`, `behat` |
| `tests/` | PHPUnit unter `tests/lib`, `tests/Core`, `tests/Settings`; Karma-Konfiguration `tests/karma.config.js`; Behat unter `tests/acceptance`; Browser-Tests unter `tests/js` |
| `build/` | Bau- und Testskripte, darunter `build/autotest.sh` und `build/minify-assets.sh`; `build/package.json` listet die Node-Abhängigkeiten |
| `changelog/` | Changelog-Einträge; neue Einträge kommen nach `changelog/unreleased/` |
| `docs/` | diese Dokumentation (MkDocs, Konfiguration `mkdocs.yml`) |
| `.github/workflows/` | die CI |

## Voraussetzungen

| Werkzeug | Version | Wofür |
| --- | --- | --- |
| PHP | 8.4 | `composer.json` verlangt `>=8.4` und ist auf `platform.php` 8.4 festgelegt |
| Composer | v2 | Abhängigkeiten und Autoloader |
| Node.js | ab 14.17, die CI nutzt 20 | Minifizierer und JavaScript-Tests |
| Yarn | ab 1.0 | installiert die Node-Abhängigkeiten aus `build/package.json` |
| Git | beliebig | Auschecken und Einreichen |

Nötig sind die PHP-Erweiterungen aus dem `require`-Block der `composer.json`:
`apcu`, `ctype`, `curl`, `exif`, `fileinfo`, `gd`, `iconv`, `imagick`, `intl`,
`json`, `libxml`, `mbstring`, `memcached`, `pdo`, `posix`, `simplexml`, `zip`.
Dazu kommt der Treiber der Datenbank, die Sie benutzen; für den Anfang genügt
`pdo_sqlite`.

Für die JavaScript-Tests brauchen Sie zusätzlich Firefox:
`tests/karma.config.js` startet `FirefoxHeadless`.

## Abhängigkeiten holen

```bash
git clone https://github.com/BWTECH-github/owncloud.online.git
cd owncloud.online
make install-composer-deps
```

`make install-composer-deps` ruft `composer install` einschließlich der
Entwicklungsabhängigkeiten auf und legt alles unter `lib/composer/` ab. Dort
liegt danach auch PHPUnit (`lib/composer/phpunit/phpunit/phpunit`), das die
Testziele benutzen.

Die Prüfwerkzeuge kommen aus eigenen Installationen unter `vendor-bin/`:

```bash
make vendor-bin-deps
```

Damit liegen php-cs-fixer, PHP_CodeSniffer, Phan, PHPStan und Behat bereit.
Genau diese beiden Aufrufe macht auch der Codestil-Job
(`.github/workflows/lint-and-codestyle.yml`).

Für JavaScript-Tests und die Minifizierer:

```bash
make install-nodejs-deps
```

Das führt `yarn install` im Verzeichnis `build/` aus.

`make` ohne Ziel holt Composer- und Node-Abhängigkeiten in einem Schritt.
`make help` listet die gebräuchlichen Ziele auf; `vendor-bin-deps`,
`minify-assets`, `check-minified` und `test-js-browser` stehen nicht darin.
`make clean` entfernt Abhängigkeiten, Testergebnisse und Bauverzeichnis.

!!! warning "`make clean` löscht `core/vendor`"
    In `core/vendor` stecken eingepflegte Sicherheitskorrekturen an
    ausgelieferten Fremdbibliotheken, allen voran der jQuery-Backport für
    CVE-2020-11022/11023. Ein anschließendes `yarn install` bringt sie nicht
    zurück; sie müssen erneut angewandt werden. Den aktuellen Stand beschreibt
    die Seite [CVE-Status](../administration/upstream-cve-status.md#frontend-bibliotheken),
    Abschnitt „Frontend-Bibliotheken“.

## Eine Instanz zum Entwickeln

Installieren lässt sich der Checkout direkt über `occ`:

```bash
php8.4 occ maintenance:install \
  --database sqlite \
  --admin-user admin \
  --admin-pass 'BitteEigenesPasswortWaehlen' \
  --data-dir "$(pwd)/data"
```

SQLite reicht für die tägliche Arbeit. Für MySQL, MariaDB oder PostgreSQL
kommen `--database-host`, `--database-name`, `--database-user` und
`--database-pass` hinzu; so richtet es auch die CI ein
(`.github/workflows/php-unit.yml`).

!!! note "Warum hier kein `sudo -u www-data`"
    In den übrigen Kapiteln steht vor `occ`-Aufrufen meist
    `sudo -u www-data`, weil dort ein Webserver die Instanz betreibt. Ein
    Entwicklungs-Checkout gehört Ihrem eigenen Konto: Ein Aufruf als
    `www-data` würde Dateien mit fremdem Eigentümer im Arbeitsbaum anlegen.
    Betreiben Sie die Instanz über Apache oder nginx, gilt wieder die Form mit
    `sudo -u www-data`.

Die mitgelieferten Apps schalten Sie einzeln ein. Dieselbe Auswahl benutzt die
CI:

```bash
php8.4 occ app:enable files_sharing
php8.4 occ app:enable files_trashbin
php8.4 occ app:enable files_versions
php8.4 occ app:enable provisioning_api
php8.4 occ app:enable federation
php8.4 occ app:enable federatedfilesharing
```

Zum Ausprobieren im Browser genügt der eingebaute Server von PHP:

```bash
php8.4 -S localhost:8080 -t .
```

Das ist nur ein Entwicklungsserver. Der Upgrade-Job der CI prüft
eine Instanz auf demselben Weg (`.github/workflows/upgrade-test.yml`):
`status.php` und `/index.php/login` müssen mit 200 antworten, und in der
Ausgabe des PHP-Servers darf kein fataler PHP-Fehler (`PHP Fatal`, `Uncaught`)
stehen.

Zwei Schlüssel in `config/config.php` helfen beim Entwickeln:

```php
'debug' => true,
'loglevel' => 0,
```

`debug` zeigt Ausnahmen samt Aufrufliste in der Weboberfläche.
`config.sample.php` warnt davor, das auf einem Produktivsystem zu setzen: Eine
Aufrufliste kann Passwörter im Klartext enthalten. `loglevel` 0 schreibt auch
Debug-Meldungen ins Protokoll, siehe
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).

Zustand prüfen:

```bash
php8.4 occ status
php8.4 occ app:list
```

## Apps aus dem Marktplatz ergänzen

![Market-App, Ansicht Entdecken mit App-Kacheln und Kategorien](../assets/screenshots/de/market.png)

Alles, was nicht im Repository liegt, kommt aus dem Marktplatz
(marketplace.owncloud.online): in der Weboberfläche über das App-Menü →
**Markt** oder auf der Kommandozeile.

```bash
php8.4 occ market:install <app_id>
```

Ein Paket, das Ihnen als `.tar.gz` vorliegt, etwa ein selbst gebautes Plugin,
spielen Sie ohne den Umweg über den Marktplatz ein:

```bash
php8.4 occ market:install --local /pfad/zu/app.tar.gz
```

Auf einer Serverinstallation lautet derselbe Aufruf:

```bash
sudo -u www-data php8.4 occ market:install <app_id>
```

Manche Apps zeigt der Marktplatz nur mit API-Schlüssel. Den Schlüssel erhalten
Sie von der BW-Tech GmbH. Näheres zur Verwaltung von Apps und zum Schlüssel
steht unter [Apps und Marketplace](../administration/apps-market.md).

## Tests

| Befehl | Was er ausführt | In der CI |
| --- | --- | --- |
| `make test-php-unit` | PHPUnit über `tests/`, gesteuert von `build/autotest.sh`; Datenbank über `TEST_DATABASE` (Vorgabe `sqlite`) | ja |
| `make test-php-style` | php-cs-fixer im Trockenlauf, PHP_CodeSniffer für `tests/acceptance` und `tests/TestHelpers`, dazu `build/OCPSinceChecker.php` | ja |
| `make test-php-phpstan` | PHPStan über `apps core settings lib/private lib/public ocs ocs-provider` | ja |
| `make test-php-phan` | Phan mit `.phan/config.php` | ja |
| `make minify-assets` | erzeugt die `.min`-Geschwisterdateien neu | ja, als Vergleich |
| `make test-js` | Karma und Jasmine nach `tests/karma.config.js` | ja, Job *JS Unit* |
| `make test-js-browser` | prüft im echten Chromium, dass die Korrekturen für select2 (CVE-2016-10744) und jQuery UI (CVE-2021-41182 bis -41184) wirken; braucht Playwright (`PLAYWRIGHT_MODULE` oder `node_modules/playwright`), ruft vorher `make check-minified` auf | nein |
| `make test-acceptance-api`, `-cli`, `-webui` | Behat über `tests/acceptance/run.sh` | nein |

Eine einzelne Testdatei, mit der in `make help` genannten Form:

```bash
make test-php-unit TEST_DATABASE=mysql TEST_PHP_SUITE=path/to/testfile.php
```

Die CI führt die Unit-Tests gegen SQLite, MySQL 8.0, MariaDB 10.6 und 10.11
sowie PostgreSQL 16 aus. Die Läufe außer SQLite beschränken sich auf die Gruppe
`DB`; alles andere prüft der SQLite-Lauf.

!!! warning "`make test-php-unit` fasst Ihre Konfiguration an"
    `build/autotest.sh` verschiebt eine vorhandene `config/config.php` nach
    `config/config-autotest-backup.php` und stellt sie beim Beenden zurück.
    Bricht der Lauf hart ab, liegt Ihre Konfiguration unter diesem Namen und
    muss von Hand zurückbenannt werden.

Die Behat-Ziele brauchen eine laufende Instanz mit der App `testing`, die
dieses Repository nicht mitliefert. Kein CI-Job führt sie aus. Ohne
`TEST_SERVER_URL` startet `tests/acceptance/run.sh` einen eigenen
PHP-Entwicklungsserver.

Neben den Testzielen laufen in der CI noch zwei Prüfungen, die leicht übersehen
werden (`.github/workflows/lint-and-codestyle.yml`):

* `composer audit --locked` für das Repository selbst sowie für
  `apps/oco_mcp` und `apps-external/market`. Eine neue oder angehobene
  Abhängigkeit mit bekanntem Sicherheitshinweis (Advisory) bringt die CI zu
  Fall.
* die eigenständigen Sicherheitstests der App `oco_mcp`
  (`apps/oco_mcp/tests/basic_auth_test.php` und `security_test.php`). Sie laufen
  ohne Instanz und lassen sich lokal genauso aufrufen.

## Änderungen an der Market-App

`apps-external/market/` wird nicht in diesem Repository bearbeitet. Die
Market-App hat ihr eigenes Repository,
[BWTECH-github/market](https://github.com/BWTECH-github/market); hier liegt eine
Kopie davon, weil sie ins Release-Paket gehört. Welcher Stand das ist, hält
`build/market.ref` fest. Eine Änderung an der Market-App läuft deshalb so:

1. im Market-Repository ändern, testen, committen und pushen
2. hier `build/sync-market.sh <commit>` ausführen; das übernimmt genau diesen
   Stand nach `apps-external/market/` und schreibt `build/market.ref` neu
3. beides hier committen

Das Skript übernimmt alle versionierten Dateien des Market-Repositorys außer
`.github/`, `.gitignore` und `tests/`. Der Job `market-copy` in
`.github/workflows/lint-and-codestyle.yml` vergleicht bei jedem Push auf `main`
und in jedem Pull Request die Kopie mit dem festgehaltenen Stand
(`build/sync-market.sh --check`) und wird rot, wenn jemand die Kopie direkt
bearbeitet hat.

`build/market.ref` übernimmt das Skript dabei nicht blind: `repo=` muss
`https://github.com/BWTECH-github/market.git` sein, `commit=` eine volle
Commit-ID (40 Hex-Zeichen) und ein `<ref>`-Argument ein Commit, Tag oder Zweig
ohne führendes `-`. Sonst endet es mit dem Rückgabewert 2, ohne etwas abzurufen
oder zu ändern. `--check` verlangt außerdem, dass der Commit im
Market-Repository von `main` oder einem Tag aus erreichbar ist. Liegt der Stand
nur in einem Pull Request oder auf einem anderen Zweig, endet das Skript
ebenfalls mit 2. Führen Sie die Änderung also erst im Market-Repository
zusammen und übernehmen Sie sie dann.

`MARKET_REPO=<URL oder Pfad>` holt den Stand beim Übernehmen aus einer anderen
Quelle, etwa einem lokalen Klon. Zusammen mit `--check` bricht das Skript mit 2
ab; geprüft wird immer gegen GitHub. `bash tests/build/sync_market_test.sh`
prüft diese Regeln ohne Netz und läuft im selben Job vor dem Vergleich.
Zusätzlich baut `.github/workflows/market-bundle.yml` bei Änderungen unter
`apps-external/market/` die Datei `js/market.bundle.js` aus `src/` nach und
lässt den Job scheitern, wenn das eingecheckte Bündel davon abweicht.

## Codestil

Maßgeblich ist php-cs-fixer mit dem Regelsatz aus
`vendor-bin/owncloud-codestyle`, konfiguriert in `.php-cs-fixer.dist.php`.
Geprüft werden der Kern und die mitgelieferten Apps außer `oco_mcp`;
`apps-external`, `build`, `data` und `lib/composer` sind ausgenommen.

```bash
make test-php-style      # prüft, ändert nichts
make test-php-style-fix  # korrigiert, was sich automatisch korrigieren lässt
```

Beide Ziele setzen `--allow-risky yes`. Rufen Sie php-cs-fixer von Hand ohne
diese Option auf, weicht das Ergebnis von dem der CI ab.

Zwei Regeln, die der Formatierer nicht durchsetzt:

* Neue Methoden in `lib/public/` brauchen eine `@since`-Angabe.
  `build/OCPSinceChecker.php` läuft in `make test-php-style` und zusätzlich am
  Anfang jedes `build/autotest.sh`-Laufs.
* Code und Bezeichner auf Englisch; für PHP 8.4 gelten die Punkte unter
  [Kompatibilität mit PHP 8.4](php84-compat.md).

## Minifizierte Geschwisterdateien

`TemplateLayout::preferMinified()` liefert eine `foo.min.js` aus, sobald sie
neben `foo.js` liegt, begrenzt auf Pfade unterhalb des Serververzeichnisses
(`OC::$SERVERROOT`). Wer eine `.js`- oder `.css`-Datei ändert und die
`.min`-Datei daneben stehen lässt, ändert damit nichts an dem, was der Server
ausliefert.

Führen Sie deshalb **nach jeder Änderung an JavaScript oder CSS** diesen Befehl
aus und checken Sie das Ergebnis mit ein:

```bash
make minify-assets
```

Das Ziel ruft `build/minify-assets.sh` auf und erzeugt die Geschwisterdateien
für `core/js`, `settings/js`, `apps/*/js` sowie die entsprechenden
CSS-Verzeichnisse; `tests/` und `vendor/` bleiben außen vor.

Die CI hat dafür ein eigenes Tor, den Job *Minified assets up to date*: Er ruft
`make minify-assets` auf und lässt den Lauf scheitern, sobald danach

```bash
git status --porcelain -- '*.min.js' '*.min.css'
```

irgendetwas ausgibt. Denselben Vergleich macht der Release-Bau. Lokal erledigt
`make check-minified` beides in einem Schritt und bricht bei einer Abweichung
ab; die neu erzeugten Dateien bleiben im Arbeitsverzeichnis liegen.

Benutzen Sie dieselben Minifizierer wie die CI, sonst entstehen andere Bytes:

```bash
npm install -g terser@5.49.1 clean-css-cli@5.6.3
```

`make install-nodejs-deps` bringt beide in denselben Versionen mit
(`build/package.json`). Das Skript bevorzugt die Installation unter
`build/node_modules` und greift erst danach auf global installierte zurück.
Findet es gar keinen Minifizierer, kopiert es die Originaldatei auf den
`.min`-Namen. Der Checkout bleibt dann funktionsfähig, aber das Ergebnis weicht
von dem der CI ab, und das Tor wird rot.

## Dokumentation

Die Seiten unter `docs/` sind die Quelle der veröffentlichten Dokumentation auf
<https://docs.owncloud.online>. Die Navigation steht im `nav`-Block der
`mkdocs.yml`; eine neue Seite ohne Eintrag dort erscheint in der Navigation
nicht. Ausnahme sind die Plugin-Seiten unter `docs/plugins/apps/`, die ohne
Eintrag im Bereich Plugins erscheinen.

| Was | Wo und wie |
| --- | --- |
| Deutsche Seite | `docs/<bereich>/<seite>.md`, Standardsprache |
| Englische Fassung | `<seite>.en.md` neben der deutschen Seite. Links darin zeigen auf die `.en.md`-Gegenstücke |
| Englische Navigationstitel | `mkdocs.yml`, Block `extra.i18n.nav_en` (deutscher Titel → englischer Titel). MkDocs ignoriert `extra` |
| Feste Anker | Abschnitte, auf die verlinkt wird, bekommen einen Anker, der in beiden Sprachen gleich ist: `## Datei hochladen {#datei-hochladen}` in der deutschen, `## Upload a file {#datei-hochladen}` in der englischen Fassung |
| Bildschirmfotos | `docs/assets/screenshots/de/<name>.png` und `docs/assets/screenshots/en/<name>.png`, gleicher Name, Oberfläche in der jeweiligen Sprache |
| Plugin-Seiten | `docs/plugins/apps/<app-id>.md` und `.en.md`. Apps, die der Marktplatz nur mit API-Schlüssel zeigt, beginnen mit `!!! info "Nur mit API-Schlüssel"` bzw. `!!! info "API key required"` |

Bauen und prüfen:

```bash
python3 -m pip install -r requirements-docs.txt
python3 -m mkdocs build --strict
```

Genau diesen Aufruf macht `.github/workflows/docs.yml`. Mit `--strict` scheitert
der Bau schon an einer einzigen Warnung, etwa an einem Verweis auf eine Seite,
die es nicht gibt.

Änderungen, die nur `docs/`, `mkdocs.yml`, `requirements-docs.txt`,
`changelog/` oder Markdown-Dateien betreffen, lösen die übrigen CI-Läufe nicht
aus: `ci.yml` und `lint-and-codestyle.yml` nehmen sie unter `paths-ignore` aus.

## Einen Beitrag einreichen

Der ausführliche Text steht in `.github/CONTRIBUTING.md`, hier das Wesentliche:

1. Einen eigenen Zweig von `main` anlegen, ein Thema pro Pull Request.
2. Tests ergänzen, wo sich Verhalten ändert.
3. Bei Änderungen an `.js` oder `.css`: `make minify-assets` laufen lassen und
   das Ergebnis mitcommitten.
4. Einen Changelog-Eintrag nach `changelog/unreleased/` legen, aufgebaut wie
   `changelog/TEMPLATE`. Die erste Zeile beginnt mit einem der Typen
   `Bugfix`, `Change`, `Enhancement` oder `Security`.
5. Commit-Nachrichten im Format Conventional Commits. Ein eigener CI-Job prüft
   das. Gültige Typen: `feat`, `fix`, `docs`, `style`, `refactor`, `test`,
   `build`, `perf`, `ci`, `chore`, `revert`; andere lehnt die Prüfung ab.
6. Den Pull Request öffnen und begründen, **warum** die Änderung nötig ist. Die
   Vorlage `.github/PULL_REQUEST_TEMPLATE.md` fragt Umgebung und tatsächlich
   durchgespielte Fälle ab; „sollte funktionieren“ ist keine Prüfung.

Fehler, die Sie nicht selbst beheben, melden Sie über die
[Issues](https://github.com/BWTECH-github/owncloud.online/issues) des
Repositorys. **Sicherheitslücken gehören nicht in ein Issue**; dafür gilt der
vertrauliche Weg aus `.github/SECURITY.md`.

Ein Pull Request muss diese Prüfungen bestehen: Commit-Format, PHP-Codestil,
PHPStan, Phan, Composer-Audit, die `oco_mcp`-Sicherheitstests, das Tor für die
minifizierten Dateien, den Abgleich der Market-Kopie, die JavaScript-Tests, die
Unit-Tests auf allen vier Datenbanksystemen und den Upgrade-Test, der eine
bestehende Installation der letzten beiden Releases auf den neuen Stand hebt.
Ändert der Pull Request etwas unter `apps-external/market/`, prüft
`market-bundle.yml` zusätzlich das JavaScript-Bündel der Market-App.

## Was ein Release enthält

Ein Tag der Form `v<version>` stößt
`.github/workflows/release-owncloud-online.yml` an. Vor dem Packen prüft der
Lauf dasselbe Tor wie die CI: Sind die `.min`-Geschwisterdateien nicht aktuell,
bricht er ab. Danach entstehen diese Dateien, zu finden unter
<https://github.com/BWTECH-github/owncloud.online/releases>:

| Datei | Inhalt |
| --- | --- |
| `owncloud-online-<version>.tar.gz` | die Distribution, rund 57 MiB |
| `owncloud-online-<version>.zip` | dieselbe Distribution als ZIP, rund 64 MiB |
| `SHA256SUMS.txt` | Prüfsummen der Archive und der JSON-Dateien |
| `sbom-owncloud-online-<version>.cdx.json` | Stückliste im CycloneDX-Format |
| `release-manifest.json` | Version, Commit und PHP-Version des Baus |
| `removed-release-files.txt` | Liste der beim Bau entfernten Entwicklungsdateien |

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| `make` bricht ab mit „Could not open input file: install“ | Das Makefile sucht Composer über `command -v composer`. Fehlt es im Suchpfad, bleibt die Variable leer, und der Aufruf lautet `php install` | Composer v2 installieren und in den Suchpfad (`PATH`) aufnehmen |
| „yarn is not available on your system, please install yarn“ | Die Regel für die Node-Abhängigkeiten prüft vorab, ob Yarn vorhanden ist | Yarn installieren (`npm install -g yarn`) oder nur `make install-composer-deps` benutzen, solange Sie keine JavaScript-Tests brauchen |
| `make test-php-unit` findet PHPUnit nicht | PHPUnit kommt aus den Entwicklungsabhängigkeiten unter `lib/composer/phpunit/phpunit/phpunit` | `make install-composer-deps` ausführen |
| Nach einem abgebrochenen Testlauf fehlt `config/config.php` | `build/autotest.sh` hat sie nach `config/config-autotest-backup.php` verschoben und konnte sie nicht zurückstellen | Datei von Hand zurückbenennen |
| Nach einem Zweigwechsel liefert jede Seite 500, `occ` meldet fehlende Klassen | `lib/composer/` ist nicht versioniert; die alte Classmap zeigt auf gelöschte Klassen | `composer dump-autoload` ausführen. `make install-composer-deps` ruft Composer nur auf, wenn sich `composer.json` oder `composer.lock` geändert haben |
| Änderungen an JavaScript oder CSS wirken im Browser nicht | Der Server bevorzugt die `.min`-Geschwisterdatei, die noch den alten Stand hat | `make minify-assets` |
| CI-Job *Minified assets up to date* rot | `.min`-Dateien sind veraltet oder neu entstanden und nicht eingecheckt | `make minify-assets`, Ergebnis committen |
| `.min`-Dateien unterscheiden sich von denen der CI | Andere Minifizierer-Version oder gar keine; dann kopiert das Skript das Original | terser 5.49.1 und clean-css-cli 5.6.3 benutzen |
| CI-Job *Commits* rot | Commit-Nachricht nicht im Conventional-Commits-Format oder unbekannter Typ | Nachricht anpassen, nur die oben genannten Typen benutzen |
| CI-Job *Market-App gegen ihr Repository prüfen* rot | Die Kopie unter `apps-external/market/` wurde direkt bearbeitet, oder `build/market.ref` nennt einen Stand, der im Market-Repository nicht von `main` oder einem Tag aus erreichbar ist | Änderung im Market-Repository vornehmen und zusammenführen, dann `build/sync-market.sh <commit>` |
| `make test-js` startet keinen Browser | `tests/karma.config.js` startet `FirefoxHeadless` | Firefox installieren |
| Behat-Ziele brechen sofort ab | `vendor-bin/behat/vendor` fehlt, oder die App `testing` ist auf der Instanz nicht vorhanden | `make vendor-bin-deps`; die App `testing` gehört nicht zu diesem Repository |
| `mkdocs build --strict` scheitert ohne sichtbaren Fehler in der geänderten Datei | `--strict` wertet jede Warnung als Fehler, auch einen Verweis auf eine Seite, die es nicht gibt | Ausgabe nach `WARNING` durchsuchen und den Verweis richtigstellen |

Weitere Seiten für Entwickler: [Kompatibilität mit PHP 8.4](php84-compat.md) und
[Ein Release bauen](release-workflow.md).
