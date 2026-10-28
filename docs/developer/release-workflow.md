# Ein Release bauen

Ein Release von owncloud.online besteht aus zwei Archiven des Servers und den
Dateien, mit denen sich deren Inhalt und Herkunft nachprüfen lassen. Gebaut
wird es nicht von Hand: Ein Git-Tag stößt den Workflow
`.github/workflows/release-owncloud-online.yml` an. Er erzeugt die Archive,
legt Prüfsummen, eine Stückliste (SBOM) und ein Manifest daneben und
veröffentlicht alles als GitHub-Release. Fertige Releases stehen unter
<https://github.com/BWTECH-github/owncloud.online/releases>; die aktuelle
Version ist 11.0.25.

Wer nichts baut, sondern nur ein heruntergeladenes Archiv prüfen will, braucht
nur den Abschnitt [Echtheit prüfen](#echtheit-pruefen).

## Schritt 1: Version festlegen

`version.php` im Wurzelverzeichnis ist die einzige Stelle, an der die Version
des Kerns steht. Alles andere leitet sich davon ab, auch der Name der
Release-Archive, wenn ein Lauf auf `main` ohne Tag und ohne eingetragene
Version startet.

| Feld | Bedeutung |
| --- | --- |
| `$OC_Version` | Versionsnummer mit vier Stellen, etwa `[11, 0, 25, 0]`. Die vierte Stelle ist laut Kommentar in der Datei **nicht** Teil der öffentlichen Versionsnummer, sondern löst Datenbank-Aktualisierungen zwischen Vorabversionen und der fertigen Version aus |
| `$OC_VersionString` | Die lesbare Version, etwa `11.0.25`. Diesen Wert liest der Release-Workflow, wenn er auf `main` ohne Tag und ohne eingetragene Version läuft |
| `$OC_VersionCanBeUpgradedFrom` | Älteste Stände, von denen aus eine Aktualisierung erlaubt ist |
| `$OC_Channel` | Auslieferungskanal, im Repository `bwtech`. Der Bau überschreibt ihn mit dem Wert von `RELEASE_CHANNEL` |
| `$OC_Build` | Im Repository leer. Der Bau trägt hier Zeitstempel und Commit ein |
| `$vendor` | Herausgeber des Pakets |

Ändern Sie beim Erhöhen die dritte Stelle von `$OC_Version` und
`$OC_VersionString` gemeinsam. Laufen sie auseinander, meldet die Instanz nach dem
Einspielen eine andere Version, als das Archiv im Namen trägt.

## Schritt 2: Changelog schreiben

Jede Änderung bekommt eine eigene Datei im Verzeichnis `changelog/unreleased/`.
Aufbau und erlaubte Typen stehen in `changelog/TEMPLATE` und
`changelog/README.md`: eine Kopfzeile aus Typ und Titel, danach ein
beschreibender Absatz, zuletzt die zugehörigen Verweise. Als Typ sind `Bugfix`,
`Change`, `Enhancement` und `Security` vorgesehen.

`CHANGELOG.md` fasst die Einträge je Version zusammen. Die Datei beginnt mit
einem Inhaltsverzeichnis; darunter folgt je Version ein Abschnitt, die neueste
zuerst, etwa „Changelog for owncloud.online [11.0.25] (2026-10-28)“ mit den
Teilen *Summary* und *Details*. Beim Release kommt der Abschnitt der neuen
Version oben hinzu. `changelog/README.md` beschreibt außerdem, die Einträge aus
`unreleased/` in einen Ordner nach dem Schema `<version>_<jjjj-mm-tt>` zu
verschieben; für die Versionen 11.0.x gibt es solche Ordner nicht.

`CHANGELOG.md` und `README.md` liegen dem Release **nicht** bei: Der Bau
entfernt beide aus dem Archiv und vermerkt sie in `removed-release-files.txt`.
Die Release-Notizen auf GitHub fassen die Änderungen zusammen und verweisen
für die Einzelheiten auf `CHANGELOG.md` im Repository.

## Schritt 3: Vorbedingungen prüfen

Der Release-Bau enthält ein Tor, an dem er in jedem Fall abbricht: die
minifizierten Dateien. Ausgeliefert wird im Betrieb die `.min`-Fassung neben
den JavaScript- und CSS-Dateien aus `core/`, `settings/` und `apps/`. Weicht
sie vom Quelltext ab, würde das Release alten Code ausliefern. Prüfen Sie
deshalb vor dem Tag:

```bash
make minify-assets
git status --porcelain -- '*.min.js' '*.min.css'
```

Der zweite Befehl darf nichts ausgeben. Gibt er Dateien aus, gehören sie in
denselben Commit wie die Änderung am Quelltext. `make check-minified` erledigt
beides in einem Schritt und bricht bei einer Abweichung ab. Wichtig sind die
Werkzeugversionen: Workflow und Prüflauf verwenden `terser@5.49.1` und
`clean-css-cli@5.6.3`. Andere Versionen erzeugen andere Bytes und damit einen
Unterschied, an dem das Tor anschlägt.

Die übrigen Prüfungen der CI (Commit-Format, Codestil, Unit- und
JavaScript-Tests, der Upgrade-Test einer bestehenden Installation) laufen bei
Pushes auf `main` und in Pull Requests, nicht am Tag. Der Tag baut nur. Ein
Stand, der auf `main` rot ist, wird also trotzdem gebaut; prüfen Sie vorher
den letzten Lauf.

## Schritt 4: Tag setzen

Der Workflow reagiert auf jeden Tag, der mit `v` beginnt:

```bash
git tag -a v11.0.25 -m "owncloud.online 11.0.25"
git push origin v11.0.25
```

Damit ist der Bau angestoßen. Die Version ermittelt der Workflow aus dem
Tag-Namen ohne führendes `v`.

Ein Bau lässt sich auch von Hand starten (*Run workflow*). Dann sind zwei
Eingaben möglich: eine Version und die Option *Create a GitHub Release with the
generated files*. Bleibt die Version leer, nimmt der Workflow den Namen des
Zweigs, auf dem der Lauf startet. Lautet er `main` oder `master`, liest der
Workflow stattdessen `$OC_VersionString` aus `version.php` (ersatzweise
`$OC_Version`). Gibt auch das nichts her, bricht der Schritt *Resolve release
version* ab. Ohne die Option werden die Dateien nicht veröffentlicht; sie
hängen sieben Tage lang am Workflow-Lauf.

Gebaut wird unter Ubuntu mit PHP 8.4 und Node.js 20. Der Workflow holt zuerst
die PHP-Abhängigkeiten ohne Entwicklungspakete (`composer install --no-dev`)
und ruft dann `make -o build/node_modules dist-dir RELEASE_CHANNEL=bwtech`
auf. `-o build/node_modules` überspringt `yarn install`: `core/vendor` ist
eingecheckt und wird nur kopiert. Anschließend entfernt der Workflow
Entwicklungs- und Werkzeugdateien aus dem Ergebnis und packt es.

## Was im Archiv liegt

Beide Archive enthalten ein einziges Verzeichnis `owncloud/` mit allem, was
die Installation braucht: dem Kern samt PHP-Abhängigkeiten, den mitgelieferten
Kern-Apps unter `apps/` und der Market-App unter `apps-external/market/`.
Weitere Apps sind nicht enthalten; das Manifest vermerkt das.

Test- und Entwicklungsbestandteile fallen in zwei Schritten weg:
`make dist-dir` übernimmt `tests/` und `l10n/` nicht und räumt in
`apps/`, `apps-external/`, `core/vendor/` und `lib/composer/` Ordner wie
`test`, `doc` oder `examples` weg. Danach entfernt der Workflow
`node_modules/`, `.github/`, Editor- und Werkzeugordner sowie einzelne Dateien
wie `phpunit.xml`, `phpstan.neon`, `phpcs.xml`, `package-lock.json` oder
`yarn.lock`.

Mitgeschrieben wird nur der zweite Schritt: `removed-release-files.txt` listet
ausschließlich dessen Pfade. In 11.0.25 sind das 22 Einträge, angeführt von
`README.md` und `CHANGELOG.md`. Was schon `make dist-dir` weglässt, taucht dort
nicht auf; die Datei beantwortet also nicht jede Frage nach einer fehlenden
Datei.

`version.php` im Archiv weicht an einer Stelle vom Repository ab: `$OC_Build`
trägt Bauzeitpunkt und Commit, im Repository ist das Feld leer. `$OC_Channel`
schreibt der Bau zwar ebenfalls neu, mit `RELEASE_CHANNEL=bwtech` steht dort
aber derselbe Wert wie im Repository.

## Die Artefakte eines Release

| Datei | Inhalt |
| --- | --- |
| `owncloud-online-<version>.tar.gz` | Der Server als tar-Archiv, in 11.0.25 rund 57 MiB |
| `owncloud-online-<version>.zip` | Derselbe Stand als ZIP, rund 64 MiB, für Umgebungen ohne tar |
| `SHA256SUMS.txt` | SHA256-Summen der beiden Archive und der JSON-Dateien |
| `sbom-owncloud-online-<version>.cdx.json` | Stückliste aller ausgelieferten Bestandteile im Format CycloneDX |
| `release-manifest.json` | Produktname, Version, Commit, PHP-Version des Baus und der Hinweis, dass keine zusätzlichen Apps beiliegen |
| `removed-release-files.txt` | Liste der Pfade, die der Workflow nach `make dist-dir` aus dem Archiv entfernt hat |

## Echtheit prüfen {#echtheit-pruefen}

Prüfen Sie zuerst die Prüfsummen. Liegen alle Release-Dateien im selben
Verzeichnis, genügt dieser Aufruf:

```bash
sha256sum -c SHA256SUMS.txt
```

Haben Sie nur einen Teil der Dateien heruntergeladen, meldet `sha256sum` für
die übrigen „No such file or directory“. Dann prüfen Sie so:

```bash
sha256sum --ignore-missing -c SHA256SUMS.txt
```

`SHA256SUMS.txt` deckt vier Dateien ab: die beiden Archive,
`release-manifest.json` und die SBOM. Weder `removed-release-files.txt` noch
die Prüfsummendatei selbst stehen darin. Die Prüfsummen belegen nur, dass der
Download unverfälscht angekommen ist, nicht, wer ihn gebaut hat.

Für die Herkunft gibt es die Bau-Bescheinigung (Sigstore, schlüssellos über
OIDC), die der Workflow für die Archive und die SBOM ausstellt. Prüfen lässt
sie sich mit der GitHub CLI:

```bash
gh attestation verify owncloud-online-11.0.25.tar.gz \
  --repo BWTECH-github/owncloud.online
```

Sie bindet die Datei an den Workflow-Lauf, der sie erzeugt hat. Beachten Sie:
Sowohl die SBOM als auch die Bescheinigung sind im Workflow als „darf
fehlschlagen“ markiert. Fehlt eine der beiden in einem Release, ist meist der
zugehörige Schritt gescheitert. Ohne Bescheinigung bleiben nur die Prüfsummen,
und die belegen die Herkunft nicht.

Welchen Stand ein ausgepacktes Archiv enthält, zeigt:

```bash
grep -E 'OC_VersionString|OC_Build' owncloud/version.php
```

Der Commit aus `$OC_Build` muss zu dem in `release-manifest.json` passen.

## Nach dem Einspielen prüfen

Auf einer neuen Installation:

```bash
sudo -u www-data php8.4 occ status
sudo -u www-data php8.4 occ app:list
```

`occ status` muss dieselbe Version melden, die im Archivnamen steht. Die
Signaturprüfung des Codes (`occ integrity:check-core`) ist hier **kein**
sinnvoller Test: Der Auslieferungskanal `bwtech` gehört zu den Kanälen, für die
`lib/private/IntegrityCheck/Checker.php` die Prüfung nicht erzwingt, und der
Release-Bau legt entsprechend keine Signatur bei. Der Aufruf endet deshalb,
bevor er überhaupt nach Signaturdaten sucht: keine Ausgabe, Rückgabewert 0. Ein
stiller Lauf belegt hier also nichts. Die Prüfung des Downloads leisten die
Prüfsummen im Abschnitt oben.

Schalten Sie beim Aktualisieren einer bestehenden Installation den
Wartungsmodus ein, bevor Sie das Verzeichnis austauschen. Sonst trifft
womöglich jemand auf eine Instanz, deren Code schon neu und deren Datenbank
noch alt ist. `occ upgrade` schaltet den Wartungsmodus zwar selbst ein, aber
erst beim Lauf, also nach dem Austausch. Ein bereits eingeschalteter
Wartungsmodus bleibt danach an (`Maintenance mode is kept active`) und muss am
Ende von Hand ausgeschaltet werden:

```bash
sudo -u www-data php8.4 occ maintenance:mode --on
# jetzt erst das entpackte Verzeichnis einspielen
sudo -u www-data php8.4 occ upgrade
sudo -u www-data php8.4 occ maintenance:repair
sudo -u www-data php8.4 occ maintenance:mode --off
```

Sicherung, Reihenfolge und Rückweg stehen unter
[Backups und Updates](../administration/backups-updates.md).

## Apps kommen nicht aus dem Release

![Market-App, Ansicht Entdecken mit App-Kacheln und Kategorien](../assets/screenshots/de/market.png)

Da das Archiv nur Kern-Apps und die Market-App enthält, spielen Sie alle
übrigen Apps danach ein: in der Weboberfläche über das App-Menü → **Markt**
(der Eintrag erscheint nur für Administratoren) oder auf der Kommandozeile.

```bash
# verfügbare Apps auflisten
sudo -u www-data php8.4 occ market:list

# App aus dem Marktplatz installieren
sudo -u www-data php8.4 occ market:install <app_id>

# vorliegendes Paket von Hand einspielen
sudo -u www-data php8.4 occ market:install --local /pfad/zur/app.tar.gz

# Aktualisierungen einspielen
sudo -u www-data php8.4 occ market:upgrade --all
```

Manche Apps zeigt der Marktplatz nur mit API-Schlüssel. Den Schlüssel erhalten
Sie von der BW-Tech GmbH; eingetragen wird er in der Market-App unter *API
Schlüssel hinzufügen*.

Prüfen Sie nach einem Server-Update die Apps: `occ app:list` zeigt aktivierte
und deaktivierte, `occ market:upgrade --list` die verfügbaren Aktualisierungen. Näheres unter
[Apps und Marketplace](../administration/apps-market.md).

## Lokal nachbauen

Der Bauschritt selbst braucht keine CI. Nötig sind PHP 8.4, Composer v2 und
Node.js mit den Minifizierern aus Schritt 3. So bauen Sie wie der Workflow:

```bash
rm -rf lib/composer
composer install --no-dev
make minify-assets
make -o build/node_modules dist-dir RELEASE_CHANNEL=bwtech
```

`rm -rf lib/composer` ist Absicht und entspricht dem Workflow: So landen im
Paket nur die Pakete aus `composer install --no-dev`, keine
Entwicklungsabhängigkeiten aus `make install-composer-deps` und keine Reste
früherer Läufe. Zum Weiterentwickeln holen Sie die Entwicklungsabhängigkeiten
danach mit `make install-composer-deps` zurück.

Das Ergebnis liegt in `build/dist/owncloud`. Es entspricht dem Stand vor dem
zweiten Aufräumschritt und dem Packen; die Löschliste und die Archive erzeugt
erst der Workflow. Byte-gleich zum veröffentlichten Archiv wird ein lokaler Bau
ohnehin nicht: `$OC_Build` enthält den Zeitpunkt des Baus.

Ohne `RELEASE_CHANNEL=bwtech` trägt der Bau den Vorgabewert `git` in
`version.php` ein.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Bau bricht ab mit „Stale or uncommitted .min siblings detected“ | Eine JavaScript- oder CSS-Datei wurde geändert, ohne die `.min`-Fassung daneben neu zu erzeugen | `make minify-assets` ausführen und die erzeugten Dateien committen, mit `terser@5.49.1` und `clean-css-cli@5.6.3`; andere Versionen erzeugen abweichende Bytes |
| Schritt *Resolve release version* bricht ab | Weder Tag noch Eingabe liefern eine Version, und `version.php` gibt keine her | Version beim Start angeben oder `$OC_VersionString` in `version.php` setzen |
| Archivname enthält einen Zweignamen statt einer Version | Lauf von Hand auf einem anderen Zweig als `main` oder `master`, ohne eingetragene Version | Lauf mit eingetragener Version wiederholen |
| Lauf war erfolgreich, aber es gibt kein Release | Der Lauf wurde von Hand ohne die Option zum Anlegen eines Releases gestartet; veröffentlicht wird nur bei einem Tag oder mit dieser Option | Tag setzen oder den Lauf mit gesetzter Option wiederholen; die Dateien des alten Laufs hängen sieben Tage lang am Lauf selbst |
| Archivname nennt eine andere Version als `version.php` | Der Tag hat Vorrang, `version.php` ist nur der Ersatzweg | `version.php` vor dem Tag anpassen, Tag neu setzen |
| `sha256sum -c` meldet „No such file or directory“ | Es wurde nur ein Teil der Release-Dateien heruntergeladen | Mit `--ignore-missing` prüfen |
| `sha256sum -c` meldet „FAILED“ | Unvollständiger oder veränderter Download | Datei erneut laden; bleibt es dabei, die Datei nicht verwenden |
| SBOM oder Bau-Bescheinigung fehlen im Release | Beide Schritte dürfen fehlschlagen, ohne den Bau abzubrechen | Prüfsummen verwenden; für die fehlenden Dateien den Bau erneut anstoßen |
| Nach dem Austausch meldet `occ`: „owncloud.online or one of the apps require upgrade - only a limited number of commands are available“ | Der Code ist neu, die Datenbank noch alt | `occ upgrade` ausführen; der Befehl steht in dieser Lage zur Verfügung, siehe [Backups und Updates](../administration/backups-updates.md) |
| `occ integrity:check-core` gibt nichts aus | Releases dieses Workflows sind nicht signiert; für den Kanal `bwtech` erzwingt der Kern die Prüfung deshalb nicht und überspringt sie | Erwartetes Verhalten, kein Beleg für das Archiv. Echtheit über `SHA256SUMS.txt` und die Bau-Bescheinigung prüfen |
| Nach dem Update fehlen Apps | Das Release liefert nur Kern-Apps und die Market-App aus | Apps über den Marktplatz nachinstallieren, siehe oben |
| `occ market:install` meldet „Installing apps is not supported because the app folder is not writable.“ | Das App-Verzeichnis ist für den Webserver-Benutzer nicht beschreibbar | Rechte des App-Verzeichnisses korrigieren |
