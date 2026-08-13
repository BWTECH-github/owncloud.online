# Entwicklung

owncloud.online ist ein Fork von ownCloud Core, der auf PHP 8.4 gehoben und
eigenständig weitergepflegt wird. Diese Seite ist der Einstieg für alle, die am
Quelltext mitarbeiten wollen: Aufbau des Repositorys, Voraussetzungen,
Abhängigkeiten, eine Instanz zum Entwickeln, die Prüfungen, die auch die CI
ausführt, und der Weg, auf dem eine Änderung eingereicht wird.

Der Quelltext liegt unter
<https://github.com/BWTECH-github/owncloud.online>, fertige Pakete unter
<https://github.com/BWTECH-github/owncloud.online/releases>. Aktuelle Fassung
ist 11.0.13 (`version.php`).

## Aufbau des Repositorys

| Verzeichnis | Inhalt |
| --- | --- |
| `core/` | Start der Anwendung, Templates, JavaScript und CSS des Kerns. Die `occ`-Befehle des Kerns stehen in `core/Command` |
| `lib/private/` | Implementierung, Namensraum `OC\`. Kein stabiler Vertrag — Apps sollen sich nicht darauf stützen |
| `lib/public/` | die für Apps stabile Schnittstelle, Namensraum `OCP\`. Jede neue Methode braucht ein `@since`; `build/OCPSinceChecker.php` prüft das |
| `lib/private/OCO/` | eigene Ergänzungen dieses Forks, Namensraum `OCO\` (in `composer.json` registriert) |
| `settings/` | persönliche Einstellungen und Verwaltungsseiten |
| `apps/` | mitgelieferte Apps: `comments`, `dav`, `federatedfilesharing`, `federation`, `files`, `files_external`, `files_sharing`, `files_trashbin`, `files_versions`, `oco_mcp`, `provisioning_api`, `systemtags`, `updatenotification`. Eigene `occ`-Befehle einer App liegen unter `apps/<id>/lib/Command` |
| `apps-external/market/` | die Market-App, über die Apps aus dem eigenen Markt installiert werden |
| `ocs/`, `ocs-provider/`, `ocm-provider/` | OCS-Schnittstelle und die Kennungen für Verbund-Freigaben |
| `config/` | `config.sample.php` mit allen Schlüsseln samt Erklärung. Die eigene `config.php` entsteht bei der Installation und ist nicht versioniert |
| `core/l10n/`, `settings/l10n/` | Übersetzungen als `.json` und `.js`, direkt im Repository gepflegt. Unter `l10n/` liegen nur die Hilfsskripte dazu |
| `core/vendor/` | JavaScript-Bibliotheken der Oberfläche. Bewusst eingecheckt, damit eine Installation aus dem Git-Stand ohne Node-Werkzeuge eine funktionierende Oberfläche hat |
| `lib/composer/` | Zielverzeichnis von Composer (`vendor-dir` in `composer.json`), nicht versioniert |
| `vendor-bin/` | je Werkzeug eine eigene Composer-Installation: `owncloud-codestyle`, `php_codesniffer`, `phan`, `phpstan`, `behat` |
| `tests/` | PHPUnit unter `tests/lib`, `tests/Core`, `tests/Settings`; Karma-Konfiguration `tests/karma.config.js`; Behat unter `tests/acceptance` |
| `build/` | Bau- und Testskripte, darunter `build/autotest.sh` und `build/minify-assets.sh`; `build/package.json` hält die Node-Abhängigkeiten |
| `changelog/` | Changelog-Schnipsel; neue Einträge kommen nach `changelog/unreleased/` |
| `docs/` | diese Dokumentation (MkDocs, Konfiguration `mkdocs.yml`) |
| `.github/workflows/` | die CI |

## Voraussetzungen

| Werkzeug | Fassung | Wofür |
| --- | --- | --- |
| PHP | 8.4 | `composer.json` verlangt `>=8.4` und ist auf `platform.php` 8.4 festgelegt |
| Composer | v2 | Abhängigkeiten und Autoloader |
| Node.js | ab 14.17, die CI nutzt 20 | Minifizierer und JavaScript-Tests |
| Yarn | ab 1.0 | installiert die Node-Abhängigkeiten aus `build/package.json` |
| Git | — | Auschecken und Einreichen |

Die PHP-Erweiterungen aus dem `require`-Block der `composer.json`: `apcu`,
`ctype`, `curl`, `exif`, `fileinfo`, `gd`, `iconv`, `imagick`, `intl`, `json`,
`libxml`, `mbstring`, `memcached`, `pdo`, `posix`, `simplexml`, `zip`. Dazu der
Treiber der Datenbank, die Sie benutzen; für den Anfang genügt `pdo_sqlite`.

Für die JavaScript-Tests wird zusätzlich Firefox gebraucht —
`tests/karma.config.js` startet `FirefoxHeadless`.

## Abhängigkeiten holen

```bash
git clone https://github.com/BWTECH-github/owncloud.online.git
cd owncloud.online
make install-composer-deps
```

`make install-composer-deps` ruft `composer install` einschließlich der
Dev-Abhängigkeiten auf und legt alles unter `lib/composer/` ab. Dort liegt
danach auch PHPUnit (`lib/composer/phpunit/phpunit/phpunit`), das die Testziele
benutzen.

Die Prüfwerkzeuge kommen aus eigenen Installationen unter `vendor-bin/`:

```bash
make vendor-bin-deps
```

Damit liegen php-cs-fixer, PHP_CodeSniffer, Phan, PHPStan und Behat bereit —
genau diese beiden Aufrufe macht auch der Codestil-Job
(`.github/workflows/lint-and-codestyle.yml`).

Für JavaScript-Tests und die Minifizierer:

```bash
make install-nodejs-deps
```

Das führt `yarn install` im Verzeichnis `build/` aus.

`make` ohne Ziel holt Composer- und Node-Abhängigkeiten in einem Schritt.
`make help` listet die gebräuchlichen Ziele auf — `vendor-bin-deps` und
`minify-assets` stehen nicht darin. `make clean` räumt Abhängigkeiten,
Testergebnisse und Bauverzeichnis wieder weg.

!!! warning "`make clean` löscht `core/vendor`"
    In `core/vendor` stecken eingepflegte Sicherheitskorrekturen an
    ausgelieferten Fremdbibliotheken — allen voran der jQuery-Backport für
    CVE-2020-11022/11023. Ein anschließendes `yarn install` bringt sie nicht
    zurück; sie müssen erneut angewandt werden. Stand siehe
    [Upstream-CVE-Status](../administration/upstream-cve-status.md), Abschnitt
    „Frontend-Bibliotheken".

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
    Entwicklungs-Checkout gehört Ihrem eigenen Konto: ein Aufruf als
    `www-data` würde Dateien mit fremdem Eigentümer im Arbeitsbaum anlegen.
    Betreiben Sie die Instanz über Apache oder nginx, gilt wieder die Form mit
    `sudo -u www-data`.

Die mitgelieferten Apps schaltet man einzeln frei, dieselbe Auswahl benutzt die
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

Das ist ausdrücklich nur ein Entwicklungsserver. Der Upgrade-Job der CI prüft
eine Instanz auf demselben Weg (`.github/workflows/upgrade-test.yml`):
`status.php` und `/index.php/login` müssen mit 200 antworten und im
Server-Protokoll darf kein fataler PHP-Fehler stehen.

Zwei Schlüssel in `config/config.php` helfen beim Entwickeln:

```php
'debug' => true,
'loglevel' => 0,
```

`debug` zeigt Ausnahmen samt Aufrufliste in der Weboberfläche —
`config.sample.php` warnt ausdrücklich davor, das auf einem Produktivsystem zu
setzen, weil in solchen Auflistungen Passwörter im Klartext stehen können.
`loglevel 0` schreibt auch Debug-Meldungen ins Protokoll, siehe
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).

Zustand prüfen:

```bash
php8.4 occ status
php8.4 occ app:list
```

## Apps aus dem Markt ergänzen

![Markt mit den installierten Apps](../assets/screenshots/owncloud-online-apps.png)

Alles, was nicht im Repository liegt, kommt aus dem eigenen Markt: in der
Weboberfläche über das App-Menü → **Markt**, oder auf der Kommandozeile.

```bash
php8.4 occ market:install <app_id>
```

Ein Paket, das Ihnen als `.tar.gz` vorliegt — etwa ein selbst gebautes Plugin —
spielen Sie ohne den Umweg über den Markt ein:

```bash
php8.4 occ market:install --local /pfad/zu/app.tar.gz
```

Auf einer Serverinstallation lautet derselbe Aufruf:

```bash
sudo -u www-data php8.4 occ market:install <app_id>
```

Näheres zur Verwaltung von Apps steht unter
[Apps und Marketplace](../administration/apps-market.md).

## Tests

| Befehl | Was er ausführt | In der CI |
| --- | --- | --- |
| `make test-php-unit` | PHPUnit über `tests/`, gesteuert von `build/autotest.sh`; Datenbank über `TEST_DATABASE` (Vorgabe `sqlite`) | ja |
| `make test-php-style` | php-cs-fixer im Trockenlauf, PHP_CodeSniffer für `tests/acceptance` und `tests/TestHelpers`, dazu `build/OCPSinceChecker.php` | ja |
| `make test-php-phpstan` | PHPStan über `apps core settings lib/private lib/public ocs ocs-provider` | ja |
| `make test-php-phan` | Phan mit `.phan/config.php` | ja |
| `make minify-assets` | erzeugt die `.min`-Geschwisterdateien neu | ja, als Vergleich |
| `make test-js` | Karma und Jasmine nach `tests/karma.config.js` | nein |
| `make test-acceptance-api`, `-cli`, `-webui` | Behat über `tests/acceptance/run.sh` | nein |

Eine einzelne Testdatei, mit der im `make help` genannten Form:

```bash
make test-php-unit TEST_DATABASE=mysql TEST_PHP_SUITE=path/to/testfile.php
```

Die CI fährt die Unit-Tests gegen SQLite, MySQL 8.0, MariaDB 10.6 und 10.11
sowie PostgreSQL 16. Die Läufe außer SQLite beschränken sich auf die Gruppe
`DB`; alles andere prüft der SQLite-Lauf.

!!! warning "`make test-php-unit` fasst Ihre Konfiguration an"
    `build/autotest.sh` verschiebt eine vorhandene `config/config.php` nach
    `config/config-autotest-backup.php` und stellt sie beim Beenden zurück.
    Bricht der Lauf hart ab, liegt Ihre Konfiguration unter diesem Namen und
    muss von Hand zurückbenannt werden.

Zu den Behat-Zielen gehört eine laufende Instanz mit der App `testing`, die
dieses Repository nicht mitliefert. Kein CI-Job führt sie aus; ohne
`TEST_SERVER_URL` startet `tests/acceptance/run.sh` einen eigenen
PHP-Entwicklungsserver.

Neben den Testzielen laufen in der CI noch zwei Prüfungen, die man leicht
übersieht (`.github/workflows/lint-and-codestyle.yml`):

* `composer audit --locked` für das Repository selbst sowie für
  `apps/oco_mcp` und `apps-external/market`. Eine neue oder angehobene
  Abhängigkeit mit bekanntem Advisory bringt die CI zu Fall.
* die eigenständigen Sicherheitstests der App `oco_mcp`
  (`apps/oco_mcp/tests/basic_auth_test.php` und `security_test.php`), die ohne
  Instanz laufen und sich genauso lokal aufrufen lassen.

Wer an `apps-external/market/` arbeitet: `.github/workflows/market-bundle.yml`
baut `js/market.bundle.js` aus `src/` nach und lässt den Job scheitern, wenn
das eingecheckte Bündel davon abweicht.

## Codestil

Maßgeblich ist php-cs-fixer mit dem ownCloud-Standard aus
`vendor-bin/owncloud-codestyle`, konfiguriert in `.php-cs-fixer.dist.php`.
Geprüft werden der Kern und die mitgelieferten Apps; `apps-external`, `build`,
`data` und `lib/composer` sind ausgenommen.

```bash
make test-php-style      # prüft, ändert nichts
make test-php-style-fix  # korrigiert, was sich automatisch korrigieren lässt
```

Beide Ziele setzen `--allow-risky yes`. Rufen Sie php-cs-fixer von Hand ohne
diese Option auf, weicht das Ergebnis von dem der CI ab.

Zwei Regeln, die nicht der Formatierer durchsetzt:

* Neue Methoden in `lib/public/` brauchen eine `@since`-Angabe.
  `build/OCPSinceChecker.php` läuft in `make test-php-style` und zusätzlich am
  Anfang jedes `build/autotest.sh`-Laufs.
* Code und Bezeichner auf Englisch; für PHP 8.4 gelten die Punkte unter
  [PHP 8.4 Kompatibilität](php84-compat.md).

## Minifizierte Geschwisterdateien

`TemplateLayout::preferMinified()` liefert eine `foo.min.js` aus, sobald sie
neben `foo.js` liegt — begrenzt auf Pfade unterhalb des Serververzeichnisses
(`OC::$SERVERROOT`). Wer eine `.js`- oder `.css`-Datei ändert und die
`.min`-Datei daneben stehen lässt, ändert damit nichts an dem, was der Server
ausliefert.

Deshalb gilt: **nach jeder Änderung an JavaScript oder CSS**

```bash
make minify-assets
```

und das Ergebnis mit einchecken. Das Ziel ruft `build/minify-assets.sh` auf und
erzeugt die Geschwisterdateien für `core/js`, `settings/js`, `apps/*/js` sowie
die entsprechenden CSS-Verzeichnisse; `tests/` und `vendor/` bleiben außen vor.

Die CI hat dafür ein eigenes Tor, den Job *Minified assets up to date*: Er ruft
`make minify-assets` auf und lässt den Lauf scheitern, sobald danach

```bash
git status --porcelain -- '*.min.js' '*.min.css'
```

irgendetwas ausgibt. Denselben Vergleich macht der Release-Bau.

Benutzen Sie dieselben Minifizierer wie die CI, sonst entstehen andere Bytes:

```bash
npm install -g terser@5.49.1 clean-css-cli@5.6.3
```

`make install-nodejs-deps` bringt beide in denselben Fassungen mit
(`build/package.json`); das Skript bevorzugt die Installation unter
`build/node_modules` und greift erst danach auf global installierte zurück.
Findet es gar keinen Minifizierer, kopiert es die Originaldatei auf den
`.min`-Namen — der Checkout bleibt funktionsfähig, aber das Ergebnis weicht von
dem der CI ab und das Tor wird rot.

## Dokumentation

Die Seiten unter `docs/` sind die Quelle für die veröffentlichte
Dokumentation; die Navigation steht im `nav`-Block der `mkdocs.yml`. Eine neue
Seite ohne Eintrag dort erscheint in der Navigation nicht.

```bash
python3 -m pip install -r requirements-docs.txt
python3 -m mkdocs build --strict
```

Genau diesen Aufruf macht `.github/workflows/docs.yml`. `--strict` lässt schon
eine Warnung fehlschlagen, etwa einen Verweis auf eine Seite, die es nicht
gibt.

Änderungen, die nur `docs/`, `mkdocs.yml`, `changelog/` oder Markdown-Dateien
betreffen, lösen die übrigen CI-Läufe nicht aus — die Wege sind in `ci.yml` und
`lint-and-codestyle.yml` unter `paths-ignore` ausgenommen.

## Einen Beitrag einreichen

Der ausführliche Text steht in `.github/CONTRIBUTING.md`, hier das Wesentliche:

1. Zweig von `main` abzweigen und ein Thema pro Pull Request.
2. Tests ergänzen, wo sich Verhalten ändert.
3. Bei Änderungen an `.js` oder `.css`: `make minify-assets` laufen lassen und
   das Ergebnis mitcommitten.
4. Einen Changelog-Eintrag nach `changelog/unreleased/` legen, aufgebaut wie
   `changelog/TEMPLATE`. Die erste Zeile beginnt mit einer der Kategorien
   `Bugfix`, `Change`, `Enhancement` oder `Security`.
5. Commit-Nachrichten im Format Conventional Commits. Ein eigener CI-Job prüft
   das. Gültige Typen: `feat`, `fix`, `docs`, `style`, `refactor`, `test`,
   `build`, `perf`, `ci`, `chore`, `revert` — andere lehnt die Prüfung ab.
6. Pull Request öffnen und begründen, **warum** die Änderung nötig ist. Die
   Vorlage `.github/PULL_REQUEST_TEMPLATE.md` fragt Umgebung und tatsächlich
   durchgespielte Fälle ab; „sollte funktionieren" ist keine Prüfung.

Fehler ohne eigenen Beitrag melden Sie über die
[Issues](https://github.com/BWTECH-github/owncloud.online/issues) des
Repositorys. **Sicherheitslücken gehören nicht in ein Issue** — dafür gilt der
vertrauliche Weg aus `.github/SECURITY.md`.

Grün werden muss ein Pull Request bei: Commit-Format, PHP-Codestil, PHPStan,
Phan, Composer-Audit, den `oco_mcp`-Sicherheitstests, dem Tor für die
minifizierten Dateien, den Unit-Tests über alle vier Datenbanken und dem
Upgrade-Test, der eine bestehende Installation der letzten beiden Releases auf
den neuen Stand hebt.

## Was ein Release enthält

Ein Tag der Form `v<version>` stößt
`.github/workflows/release-owncloud-online.yml` an. Vor dem Packen prüft der
Lauf dasselbe Tor wie die CI: sind die `.min`-Geschwisterdateien nicht aktuell,
bricht er ab. Danach entstehen diese Dateien, zu finden unter
<https://github.com/BWTECH-github/owncloud.online/releases>:

| Datei | Inhalt |
| --- | --- |
| `owncloud-online-<version>.tar.gz` | die Distribution, rund 57 MB |
| `owncloud-online-<version>.zip` | dieselbe Distribution als ZIP, rund 64 MB |
| `SHA256SUMS.txt` | Prüfsummen der Artefakte |
| `sbom-owncloud-online-<version>.cdx.json` | Stückliste im CycloneDX-Format |
| `release-manifest.json` | Fassung, Commit und benutzte PHP-Fassung des Baus |
| `removed-release-files.txt` | Liste der beim Bau entfernten Entwicklungsdateien |

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| `make` bricht ab mit „Could not open input file: install" | Das Makefile sucht Composer über `command -v composer`. Fehlt es im Pfad, bleibt die Variable leer und der Aufruf lautet `php install` | Composer v2 installieren und in den Pfad legen |
| „yarn is not available on your system, please install yarn" | Die Regel für die Node-Abhängigkeiten prüft Yarn ausdrücklich | Yarn installieren (`npm install -g yarn`) oder nur `make install-composer-deps` benutzen, solange Sie keine JS-Tests brauchen |
| `make test-php-unit` findet PHPUnit nicht | PHPUnit kommt aus den Dev-Abhängigkeiten unter `lib/composer/phpunit/phpunit/phpunit` | `make install-composer-deps` ausführen |
| Nach einem abgebrochenen Testlauf fehlt `config/config.php` | `build/autotest.sh` hat sie nach `config/config-autotest-backup.php` verschoben und konnte sie nicht zurückstellen | Datei von Hand zurückbenennen |
| Nach einem Zweigwechsel liefert jede Seite 500, `occ` meldet fehlende Klassen | `lib/composer/` ist nicht versioniert; die alte Classmap zeigt auf gelöschte Klassen | `make install-composer-deps` erneut ausführen |
| Änderungen an JS oder CSS wirken im Browser nicht | Der Server bevorzugt die `.min`-Geschwisterdatei, die noch den alten Stand hat | `make minify-assets` |
| CI-Job *Minified assets up to date* rot | `.min`-Dateien sind veraltet oder neu entstanden und nicht eingecheckt | `make minify-assets`, Ergebnis committen |
| `.min`-Dateien unterscheiden sich von denen der CI | Andere Minifizierer-Fassung, oder gar keine — dann kopiert das Skript das Original | terser 5.49.1 und clean-css-cli 5.6.3 benutzen |
| CI-Job *Commits* rot | Commit-Nachricht nicht im Conventional-Commits-Format oder unbekannter Typ | Nachricht anpassen, nur die oben genannten Typen benutzen |
| `make test-js` startet keinen Browser | `tests/karma.config.js` startet `FirefoxHeadless` | Firefox installieren |
| Behat-Ziele brechen sofort ab | `vendor-bin/behat/vendor` fehlt, oder die App `testing` ist auf der Instanz nicht vorhanden | `make vendor-bin-deps`; die App `testing` gehört nicht zu diesem Repository |
| `mkdocs build --strict` scheitert ohne sichtbaren Fehler in der geänderten Datei | `--strict` wertet jede Warnung als Fehler, auch einen Verweis auf eine Seite, die es nicht gibt | Ausgabe nach `WARNING` durchsuchen und den Verweis richtigstellen |

Weitere Seiten für Entwickler: [PHP 8.4 Kompatibilität](php84-compat.md) und
[Release Workflow](release-workflow.md).
