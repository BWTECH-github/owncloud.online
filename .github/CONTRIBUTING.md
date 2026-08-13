# Beitragen zu owncloud.online

Danke, dass Sie sich das ansehen. Dieses Dokument beschreibt, wie Fehler
gemeldet und Änderungen eingebracht werden.

## Fehler melden

Fehler gehören in die
[Issues](https://github.com/BWTECH-github/owncloud.online/issues) dieses
Repositorys. Betrifft der Fehler ein Plugin, nutzen Sie bitte dessen eigenes
Repository unter [BWTECH-github](https://github.com/BWTECH-github) — dort landet
die Meldung bei denen, die den Code pflegen.

Prüfen Sie vorher kurz, ob es die Meldung schon gibt. Doppelte Meldungen kosten
alle Beteiligten Zeit.

Hilfreich ist eine Meldung, wenn sie folgendes enthält:

* **Was Sie getan haben** — die Schritte, mit denen sich das Verhalten erzeugen
  lässt, in der Reihenfolge, in der Sie sie gegangen sind.
* **Was Sie erwartet haben** und **was stattdessen passiert ist**.
* **Version** von owncloud.online, PHP-Version, Datenbank, Betriebssystem.
* **Auszug aus dem Protokoll** (`data/owncloud.log`) rund um den Zeitpunkt.
  Bitte prüfen Sie den Auszug vorher auf Benutzernamen, Pfade und Token.

Bei Fehlern in der Weboberfläche helfen zusätzlich die Meldungen aus der
Browser-Konsole und ein Bildschirmfoto.

## Sicherheitslücken

**Nicht** als Issue melden. Sicherheitsrelevante Funde bitte vertraulich an
[BW.Tech](https://bw.tech) — siehe [SECURITY.md](SECURITY.md). Wir brauchen die
Gelegenheit, eine Lücke zu schließen, bevor sie öffentlich bekannt wird.

## Änderungen einbringen

1. Zweig von `main` abzweigen.
2. Änderung möglichst klein halten — ein Thema pro Pull Request lässt sich
   prüfen, ein Sammelpaket nicht.
3. Tests ergänzen, wo Verhalten geändert wird.
4. Commit-Nachrichten im Format
   [Conventional Commits](https://www.conventionalcommits.org/) — ein CI-Job
   prüft das, siehe [README](../README.md#commit-nachrichten).
5. Pull Request öffnen und beschreiben, **warum** die Änderung nötig ist. Das
   *was* steht im Diff.

### Was die CI prüft

Ein Pull Request muss durch: PHP-Syntax, Codestil (`php-cs-fixer` mit dem
ownCloud-Standard), statische Analyse, Unit-Tests gegen MySQL, MariaDB,
PostgreSQL und SQLite, ein Upgrade-Test über die letzten zwei Releases sowie die
Prüfung, dass die minifizierten Geschwister der geänderten JS- und CSS-Dateien
aktuell sind.

Letzteres wird gern übersehen: Wer `core/js/*.js` oder `core/css/*.css` ändert,
muss `make minify-assets` laufen lassen und das Ergebnis mit committen. Sonst
liefert der Server weiter den alten Stand aus — `preferMinified` bevorzugt die
`.min`-Datei.

## Übersetzungen

Übersetzungen liegen als JSON- und JS-Dateien unter `core/l10n/` und
`settings/l10n/` und werden direkt im Repository gepflegt. Die Plural-Regel
einer Sprache steht als `pluralForm` in der jeweiligen Datei.

## Herkunft

owncloud.online ist ein Fork von
[ownCloud Core](https://github.com/owncloud/core). Beiträge, die auch das
Ursprungsprojekt betreffen, sind dort ebenfalls willkommen — die beiden
Projekte entwickeln sich unabhängig weiter.
