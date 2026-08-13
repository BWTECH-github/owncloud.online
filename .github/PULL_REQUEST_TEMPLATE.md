<!--
Danke für den Beitrag zu owncloud.online.

Sicherheitsrelevante Korrekturen bitte NICHT hier öffentlich einreichen —
vertraulich melden, siehe .github/SECURITY.md.

Änderungen gehen gegen den Zweig `main`.

Commit-Nachrichten im Format Conventional Commits, sonst wird die CI rot.
Gültige Typen: feat, fix, docs, style, refactor, test, build, perf, ci, chore,
revert.
-->

## Was ändert sich

<!-- Kurz und konkret. Das Detail steht im Diff. -->

## Warum

<!-- Welches Problem löst das? Wenn es ein Issue dazu gibt, hier verlinken. -->

- Behebt #

## Wie wurde es geprüft

<!-- Testumgebung und die Fälle, die Sie tatsächlich durchgespielt haben.
     "Sollte funktionieren" ist keine Prüfung. -->

- Umgebung (PHP-Version, Datenbank, Browser):
- Fall 1:
- Fall 2:

## Bildschirmfotos

<!-- Bei sichtbaren Änderungen: vorher und nachher. -->

## Art der Änderung

- [ ] Fehlerkorrektur
- [ ] Neue Funktion
- [ ] Änderung am Datenbankschema (erzwingt Minor- statt Patch-Release)
- [ ] Bricht bestehendes Verhalten
- [ ] Aufräumen / technische Schuld
- [ ] Nur Tests

## Checkliste

- [ ] Tests ergänzt oder angepasst
- [ ] Bei Änderungen an `*.js` / `*.css`: `make minify-assets` gelaufen und das
      Ergebnis mitcommittet — sonst liefert der Server weiter den alten Stand aus
- [ ] Barrierefreiheit geprüft (Tastaturbedienung, sichtbarer Fokus, Kontrast)
- [ ] Dokumentation unter `docs/` angepasst, falls nötig
- [ ] Changelog-Eintrag ergänzt, siehe [TEMPLATE](../changelog/TEMPLATE)
