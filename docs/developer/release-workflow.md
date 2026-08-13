# Ein Release bauen

Ein Release von owncloud.online besteht aus zwei Archiven des Serverkerns und
den Dateien, mit denen sich deren Inhalt und Herkunft nachprüfen lassen. Gebaut
wird es nicht von Hand: Ein Git-Tag stößt den Workflow
`.github/workflows/release-owncloud-online.yml` an. Er erzeugt die Archive,
legt Prüfsummen, eine Stückliste (SBOM) und ein Manifest daneben und
veröffentlicht alles als GitHub-Release. Fertige Releases stehen unter
<https://github.com/BWTECH-github/owncloud.online/releases>, die aktuelle
Fassung ist 11.0.13.

Diese Seite beschreibt den Weg vom Versionsstand im Repository bis zum
geprüften Archiv auf dem Server. Wer nichts baut, sondern nur ein
heruntergeladenes Archiv prüfen will, braucht nur den Abschnitt *Echtheit
prüfen*.

## Schritt 1 — Version festlegen

`version.php` im Wurzelverzeichnis ist die einzige Stelle, an der die Version
des Kerns steht. Alles andere leitet sich davon ab, auch der Name der
Release-Archive, wenn der Bau ohne Tag angestoßen wird.

| Feld | Bedeutung |
| --- | --- |
| `$OC_Version` | Vierstellige Fassung, etwa `[11, 0, 13, 0]`. Die vierte Stelle ist laut Kommentar in der Datei ausdrücklich **nicht** Teil der öffentlichen Versionsnummer, sondern löst Datenbank-Aktualisierungen zwischen Vorabfassungen aus |
| `$OC_VersionString` | Die lesbare Version, etwa `11.0.13`. Diesen Wert liest der Release-Workflow aus, wenn ihm keine Version übergeben wurde |
| `$OC_VersionCanBeUpgradedFrom` | Älteste Stände, von denen aus eine Aktualisierung erlaubt ist |
| `$OC_Channel` | Auslieferungskanal, im Repository `bwtech`. Der Bau überschreibt ihn mit dem Wert von `RELEASE_CHANNEL` |
| `$OC_Build` | Im Repository leer. Der Bau trägt hier Zeitstempel und Commit ein |
| `$vendor` | Herausgeber des Pakets |

Beim Erhöhen die dritte Stelle von `$OC_Version` und `$OC_VersionString`
gemeinsam anfassen — sie dürfen nicht auseinanderlaufen, sonst meldet die
Instanz nach dem Einspielen eine andere Version, als das Archiv im Namen trägt.

## Schritt 2 — Changelog schreiben

Jede Änderung bekommt eine eigene Datei im Verzeichnis `changelog/unreleased/`.
Aufbau und erlaubte Arten stehen in `changelog/TEMPLATE` und
`changelog/README.md`: eine Kopfzeile aus Art und Titel, danach ein
beschreibender Absatz, zuletzt die zugehörigen Verweise. Als Art sind `Bugfix`,
`Change`, `Enhancement` und `Security` vorgesehen.

`CHANGELOG.md` fasst diese Einträge je Version zusammen. Der oberste Abschnitt
ist `[Unreleased]` und sammelt, was in die nächste Version geht; beim Release
entsteht daraus ein Abschnitt mit Versionsnummer und Datum. Für die Einträge
selbst sieht `changelog/README.md` vor, sie aus `unreleased/` in einen Ordner
nach dem Schema `<version>_<jjjj-mm-tt>` zu übernehmen.

`CHANGELOG.md` und `README.md` liegen dem Release **nicht** bei: Der Bau
entfernt beide aus dem Archiv und vermerkt sie in `removed-release-files.txt`.
Die Änderungsliste steht stattdessen in den Release-Notizen auf GitHub.

## Schritt 3 — Vorbedingungen prüfen

Der Release-Bau enthält ein Tor, an dem er ohne Rücksicht abbricht: die
minifizierten Dateien. Ausgeliefert wird im Betrieb die `.min`-Fassung neben
den JavaScript- und CSS-Dateien aus `core/`, `settings/` und `apps/`; weicht
sie vom Quelltext ab, würde das Release alten Code ausliefern. Vor dem Tag
deshalb:

```bash
make minify-assets
git status --porcelain -- '*.min.js' '*.min.css'
```

Die zweite Zeile muss leer bleiben. Meldet sie etwas, gehören die erzeugten
Dateien in denselben Commit. Wichtig sind dabei die Werkzeugversionen: Workflow
und Prüflauf verwenden `terser@5.49.1` und `clean-css-cli@5.6.3`. Andere
Versionen erzeugen andere Bytes und damit einen Unterschied, den das Tor
anschlägt.

Die übrigen Prüfungen — Commit-Konventionen, PHP-Unit-Tests und der
Update-Test einer bestehenden Installation — laufen auf `main` und in
Pull Requests, nicht am Tag. Der Tag baut nur. Ein Stand, der auf `main` rot
ist, wird also trotzdem gebaut; prüfen Sie vorher den letzten Lauf.

## Schritt 4 — Tag setzen

Der Workflow reagiert auf jeden Tag, der mit `v` beginnt:

```bash
git tag -a v11.0.13 -m "owncloud.online 11.0.13"
git push origin v11.0.13
```

Damit ist der Bau angestoßen. Die Version ermittelt der Workflow aus dem
Tag-Namen ohne führendes `v`; ist kein Tag im Spiel oder lautet der Name `main`
bzw. `master`, liest er `$OC_VersionString` aus `version.php`. Ohne beides
bricht er mit „Could not resolve ownCloud version" ab.

Ein Bau lässt sich auch von Hand starten (*workflow_dispatch*). Dann sind zwei
Eingaben möglich: eine abweichende Version und die Option, aus dem Ergebnis
auch ein GitHub-Release anzulegen. Ohne diese Option bleiben die Dateien am
Workflow-Lauf hängen und werden nicht veröffentlicht.

Gebaut wird unter Ubuntu mit PHP 8.4 und Node.js 20. Der eigentliche Bau ist
ein Aufruf von `make dist-dir RELEASE_CHANNEL=bwtech`; anschließend entfernt
der Workflow Entwicklungs- und Werkzeugdateien aus dem Ergebnis und packt es.

## Was im Archiv liegt

Beide Archive enthalten ein einziges Verzeichnis `owncloud/`, das nach dem
Auspacken vollständig einsatzbereit ist: der Kern samt PHP-Abhängigkeiten, die
mitgelieferten Kern-Apps unter `apps/` und die Markt-App unter
`apps-external/market/`. Weitere Apps sind nicht enthalten — das Manifest hält
das ausdrücklich fest.

Nicht enthalten sind Test- und Entwicklungsbestandteile, und zwar aus zwei
Schritten: `make dist-dir` kopiert `tests/` und `l10n/` gar nicht erst mit und
räumt in `apps/`, `core/vendor/` und `lib/composer/` Ordner wie `test`, `doc`
oder `examples` weg; danach entfernt der Workflow `node_modules/`, `.github/`,
Editor- und Werkzeugordner sowie einzelne Dateien wie `phpunit.xml`,
`phpstan.neon`, `phpcs.xml`, `package-lock.json` oder `yarn.lock`.

Mitgeschrieben wird nur der zweite Schritt: `removed-release-files.txt` listet
ausschließlich dessen Pfade — in 11.0.13 sind das 24 Einträge, angeführt von
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
| `owncloud-online-<version>.tar.gz` | Der Server als tar-Archiv, in 11.0.13 rund 57 MB |
| `owncloud-online-<version>.zip` | Derselbe Stand als ZIP, rund 64 MB, für Umgebungen ohne tar |
| `SHA256SUMS.txt` | SHA256-Summen der beiden Archive und der JSON-Dateien |
| `sbom-owncloud-online-<version>.cdx.json` | Stückliste aller ausgelieferten Bestandteile im Format CycloneDX |
| `release-manifest.json` | Produktname, Version, Commit, PHP-Version des Baus und der Hinweis, dass keine zusätzlichen Apps beiliegen |
| `removed-release-files.txt` | Liste der Pfade, die der Workflow nach `make dist-dir` aus dem Archiv entfernt hat |

## Echtheit prüfen

Zuerst die Prüfsummen. Alle Dateien im selben Verzeichnis, dann:

```bash
sha256sum -c SHA256SUMS.txt
```

Haben Sie nur eine Datei heruntergeladen, melden die übrigen Zeilen sonst
„No such file or directory":

```bash
sha256sum --ignore-missing -c SHA256SUMS.txt
```

`SHA256SUMS.txt` deckt vier Dateien ab: die beiden Archive,
`release-manifest.json` und die SBOM. Weder `removed-release-files.txt` noch
die Prüfsummendatei selbst stehen darin. Und die Prüfsummen belegen nur, dass
der Download unverfälscht angekommen ist, nicht, wer ihn gebaut hat.

Für die Herkunft gibt es die Bau-Bescheinigung (Sigstore, schlüssellos über
OIDC), die der Workflow für die Archive und die SBOM ausstellt:

```bash
gh attestation verify owncloud-online-11.0.13.tar.gz \
  --repo BWTECH-github/owncloud.online
```

Sie bindet die Datei an den Workflow-Lauf, der sie erzeugt hat. Beachten Sie:
Sowohl die SBOM als auch die Bescheinigung sind im Workflow als „darf
fehlschlagen" markiert. Fehlt eine der beiden in einem Release, ist das kein
Hinweis auf Manipulation, sondern auf einen fehlgeschlagenen Schritt — dann
bleiben die Prüfsummen.

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
Release-Bau legt entsprechend keine Signatur bei. Der Aufruf steigt deshalb
aus, bevor er überhaupt nach Signaturdaten sucht: keine Ausgabe, Rückgabewert
0. Ein stiller Lauf belegt hier also nichts. Die Prüfung des Downloads leisten
die Prüfsummen im Abschnitt oben.

Beim Aktualisieren einer bestehenden Installation gehört der Wartungsmodus vor
den Austausch des Verzeichnisses, damit niemand auf eine Instanz trifft, deren
Code schon neu und deren Datenbank noch alt ist. `occ upgrade` schaltet ihn
sonst zwar selbst ein, aber erst beim Lauf, also nach dem Austausch. Ein
bereits eingeschalteter Wartungsmodus bleibt danach an (`Maintenance mode is
kept active`) und muss am Ende von Hand aus:

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

![Der Markt mit den installierten Apps](../assets/screenshots/owncloud-online-apps.png)

Da das Archiv nur Kern-Apps und die Markt-App enthält, werden alle übrigen Apps
danach eingespielt: in der Weboberfläche über das App-Menü → **Markt** (der
Eintrag erscheint nur für Administratoren), oder auf der Kommandozeile.

```bash
# verfügbare Apps auflisten
sudo -u www-data php8.4 occ market:list

# App aus dem Markt installieren
sudo -u www-data php8.4 occ market:install <app_id>

# vorliegendes Paket von Hand einspielen
sudo -u www-data php8.4 occ market:install --local /pfad/zur/app.tar.gz

# Aktualisierungen einspielen
sudo -u www-data php8.4 occ market:upgrade --all
```

Nach einem Server-Update lohnt der Blick auf die Apps: `occ app:list` zeigt
aktivierte und deaktivierte, `occ market:upgrade --list` die verfügbaren
Aktualisierungen. Näheres unter
[Apps und Marketplace](../administration/apps-market.md).

## Lokal nachbauen

Der Bauschritt selbst braucht keine CI. Nötig sind `composer`, `node` und
`yarn` (siehe Kopf des `Makefile`) sowie PHP 8.4:

```bash
make minify-assets
make dist-dir RELEASE_CHANNEL=bwtech
```

Das Ergebnis liegt in `build/dist/owncloud`. Es entspricht dem Stand vor dem
zweiten Aufräumschritt und dem Packen — die Löschliste und die Archive erzeugt
erst der Workflow. Byte-gleich zum veröffentlichten Archiv wird ein lokaler Bau ohnehin
nicht: `$OC_Build` enthält den Zeitpunkt des Baus.

Ohne `RELEASE_CHANNEL=bwtech` trägt der Bau den Vorgabewert `git` in
`version.php` ein.

## Was intern bleibt

Das Einstellen der App-Pakete in den eigenen Markt erfolgt intern und lässt
sich von außen nicht nachvollziehen.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Bau bricht ab mit „Stale or uncommitted .min siblings detected" | Eine JavaScript- oder CSS-Datei wurde geändert, ohne die `.min`-Fassung daneben neu zu erzeugen | `make minify-assets` ausführen und die erzeugten Dateien committen — mit `terser@5.49.1` und `clean-css-cli@5.6.3`, andere Versionen erzeugen abweichende Bytes |
| Bau bricht ab mit „Could not resolve ownCloud version" | Weder Tag noch Eingabe liefern eine Version, und `version.php` gibt keine her | Version im Aufruf angeben oder `$OC_VersionString` in `version.php` setzen |
| Lauf war erfolgreich, aber es gibt kein Release | Der Lauf wurde von Hand ohne die Option zum Anlegen eines Releases gestartet; veröffentlicht wird nur bei einem Tag oder mit dieser Option | Tag setzen oder den Lauf mit gesetzter Option wiederholen; die Dateien des alten Laufs hängen weiterhin am Lauf selbst |
| Archivname nennt eine andere Version als `version.php` | Der Tag hat Vorrang, `version.php` ist nur der Ersatzweg | `version.php` vor dem Tag anpassen, Tag neu setzen |
| `sha256sum -c` meldet „No such file or directory" | Es wurde nur ein Teil der Release-Dateien heruntergeladen | Mit `--ignore-missing` prüfen |
| `sha256sum -c` meldet „FAILED" | Unvollständiger oder veränderter Download | Datei erneut laden; bleibt es dabei, die Datei nicht verwenden |
| SBOM oder Bau-Bescheinigung fehlen im Release | Beide Schritte dürfen fehlschlagen, ohne den Bau abzubrechen | Prüfsummen verwenden; für die fehlenden Dateien den Bau erneut anstoßen |
| Nach dem Austausch meldet `occ`: „require upgrade - only a limited number of commands are available" | Der Code ist neu, die Datenbank noch alt | `occ upgrade` ausführen — der Befehl steht in dieser Lage zur Verfügung, siehe [Backups und Updates](../administration/backups-updates.md) |
| `occ integrity:check-core` gibt nichts aus | Releases dieses Workflows sind nicht signiert; für den Kanal `bwtech` erzwingt der Kern die Prüfung deshalb nicht und überspringt sie | Erwartetes Verhalten, kein Beleg für das Archiv — Echtheit über `SHA256SUMS.txt` und die Bau-Bescheinigung prüfen |
| Nach dem Update fehlen Apps | Das Release liefert nur Kern-Apps und die Markt-App aus | Apps über den Markt nachinstallieren, siehe oben |
| `occ market:install` meldet, Installieren sei nicht unterstützt | Das App-Verzeichnis ist für den Webserver-Benutzer nicht beschreibbar | Rechte des App-Verzeichnisses richten |
