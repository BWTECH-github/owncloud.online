# Sicherheit

## Unterstützte Versionen

Dieses Repository ist owncloud.online, gepflegt von der BW-Tech GmbH.
Unterstützt wird ausschließlich die eigene Release-Linie:

| Version              | Unterstützt        |
| -------------------- | ------------------ |
| owncloud.online 11.x | :white_check_mark: |
| älter                | :x:                |

## Eine Lücke melden

Sicherheitsrelevante Funde bitte **vertraulich** melden, nicht als öffentliches
Issue. Zwei Wege:

1. **Bevorzugt:** die private Meldefunktion von GitHub in diesem Repository —
   Reiter *Security* → *Report a vulnerability*.
2. E-Mail an **security@bw.tech** mit Beschreibung, betroffener Version und,
   wenn möglich, den Schritten zum Nachstellen.

Wir bestätigen den Eingang innerhalb von drei Werktagen und nennen innerhalb von
30 Tagen eine Korrektur oder einen Plan zur Entschärfung.

## Geerbte Schwachstellen

owncloud.online ist ein Fork und erbt damit einen Teil seiner Codebasis. Bekannte
CVEs aus dieser Herkunft werden nachverfolgt und zurückportiert; der Stand steht
in [`docs/administration/upstream-cve-status.md`](../docs/administration/upstream-cve-status.md).
