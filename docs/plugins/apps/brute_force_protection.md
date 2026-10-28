# Brute-Force-Schutz

Die App sperrt die Anmeldung vorübergehend, wenn von einer IP-Adresse zu viele
Fehlversuche für ein Konto kommen. Ein Angriff, der Passwörter durchprobiert,
läuft damit nach wenigen Versuchen ins Leere. Dasselbe gilt für Passwörter
öffentlicher Links.

!!! note "Verfügbarkeit"
    Öffentlich im Marketplace, ohne API-Schlüssel. App-ID
    `brute_force_protection`, Version 1.5.2. Name im Markt:
    „Brute-Force Protection“.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 |
| Cron | Ein täglicher Hintergrund-Job räumt alte Einträge auf. Ohne Cron wächst die Tabelle der Fehlversuche. |
| Reverse-Proxy | Steht die Instanz hinter einem Proxy, muss `trusted_proxies` in `config/config.php` stimmen, siehe [Reverse-Proxy und TLS](../../administration/reverse-proxy.md). |

## Installation

In der Weboberfläche: im App-Menü links oben **Markt** öffnen, nach
„Brute-Force Protection“ suchen und **Installieren** klicken.

Auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ market:install brute_force_protection
```

`market:install` lädt die App, installiert sie und schaltet sie ein. Ist die App
schon vorhanden und nur abgeschaltet, genügt:

```bash
sudo -u www-data php8.4 occ app:enable brute_force_protection
```

Für Server ohne Internetzugang siehe [Apps und Marketplace](../../administration/apps-market.md).

## Wie die Sperre wirkt

Die App zählt fehlgeschlagene Anmeldungen je Kombination aus Anmeldename und
IP-Adresse. Groß- und Kleinschreibung des Anmeldenamens spielen dabei keine
Rolle. Erreicht die Zahl der Fehlversuche innerhalb des Zeitfensters den
eingestellten Wert, lehnt owncloud.online weitere Anmeldungen dieser Kombination
ab. Die Anmeldeseite meldet die Sperre und nennt die verbleibende Wartezeit.

- Die Sperre endet nach der Sperrdauer, gerechnet ab dem letzten Fehlversuch.
  Versuche während der Sperre zählen nicht und verlängern sie nicht.
- Eine erfolgreiche Anmeldung setzt den Zähler für diese Kombination zurück.
- Andere Konten und andere IP-Adressen sind nicht betroffen.
- Bei öffentlichen Links zählt die App falsche Link-Passwörter je Link und
  IP-Adresse, mit denselben drei Werten.

Ist die App eingeschaltet, übernimmt sie die Bremse für die Anmeldung und für
Link-Passwörter vollständig. Die im Kern eingebaute Bremse zählt auf diesen
beiden Wegen nicht mehr mit. Wird die App abgeschaltet, greift wieder der Kern.

## Einrichtung

**Einstellungen → Administration → Sicherheit**, Abschnitt
**Brute-Force-Schutz**. Alle drei Werte müssen ganze Zahlen größer als 0 sein.
**Einstellungen speichern** übernimmt sie.

| Beschriftung | Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- | --- |
| „Wie viele Sekunden soll nach gescheiterten Versuchen gewartet werden bis sich User neu anmelden können?“ | `brute_force_protection_time_threshold` | 60 | Zeitfenster in Sekunden, in dem Fehlversuche gezählt werden |
| „Sperre nach wie vielen gescheiterten Versuchen?“ | `brute_force_protection_fail_tolerance` | 3 | Zahl der Fehlversuche im Zeitfenster, ab der gesperrt wird |
| „Für wie viele Sekunden sperren?“ | `brute_force_protection_ban_period` | 300 | Dauer der Sperre in Sekunden |

Die deutsche Beschriftung des ersten Felds führt in die Irre: Gemeint ist nicht
eine Wartezeit, sondern das Zeitfenster, über das die Fehlversuche gezählt
werden. Die englische Oberfläche sagt „Count failed attempts over how many
seconds?“.

<figure markdown="span">
  ![Administration, Sicherheit: Brute-Force-Schutz mit Zeitfenster, Zahl der Fehlversuche und Sperrdauer](../../assets/screenshots/de/admin-brute-force.png)
  <figcaption>Der Brute-Force-Schutz sperrt Anmeldungen nach mehreren Fehlversuchen. Im Bild: 3 Fehlversuche in 60 Sekunden führen zu 300 Sekunden Sperre.</figcaption>
</figure>

Dieselben Werte auf der Kommandozeile, hier 5 Fehlversuche in 120 Sekunden und
15 Minuten Sperre:

```bash
sudo -u www-data php8.4 occ config:app:set brute_force_protection brute_force_protection_fail_tolerance --value=5
sudo -u www-data php8.4 occ config:app:set brute_force_protection brute_force_protection_time_threshold --value=120
sudo -u www-data php8.4 occ config:app:set brute_force_protection brute_force_protection_ban_period --value=900
```

Eigene `occ`-Befehle hat die App nicht.

## Grenzen und Hinweise

- **Hinter einem Reverse-Proxy** sieht owncloud.online ohne korrektes
  `trusted_proxies` jede Anfrage mit der Adresse des Proxys. Dann sperrt die App
  alle Personen gemeinsam, sobald eine einzige sich mehrmals vertippt. Das ist
  die häufigste Fehlkonfiguration.
- **Gezählt wird nur je Konto und Adresse:** Wer von einer Adresse aus viele
  Konten mit je wenigen Passwörtern durchprobiert, bleibt unter der Grenze. Die
  Bremse im Kern zählt zusätzlich je Adresse über alle Konten und je Subnetz.
  Für Anmeldung und Link-Passwörter tritt sie zurück, solange die App
  eingeschaltet ist, siehe
  [Sicherheit und Setup-Warnungen](../../administration/security-hardening.md).
- **Keine vorzeitige Freigabe:** Es gibt keinen Befehl, der eine Sperre
  aufhebt. Sie endet nach der eingestellten Sperrdauer.
- **Link-Besucher:** Eine kleine Zahl erlaubter Fehlversuche trifft auch
  Besucher öffentlicher Links, die sich beim Link-Passwort vertippen.
- **Umzug von einer älteren Instanz:** Enthält die Datenbank noch die Werte der
  Vorgänger-App `security`, übernimmt die App sie beim ersten Einschalten. Eigene
  Werte überschreibt sie nie. Die Übernahme steht im Serverprotokoll.
- Die App ersetzt keine starken Passwörter. Sie ergänzt die
  [Passwortrichtlinie](password_policy.md) und die
  [Zwei-Faktor-Anmeldung](twofactor_totp.md). Falsche Codes der
  Zwei-Faktor-Anmeldung zählt sie nicht.
- Die App lässt sich nicht nur für einzelne Gruppen einschalten; sie wirkt immer
  für die ganze Instanz.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Alle Personen sind gleichzeitig gesperrt | `trusted_proxies` fehlt, alle Anfragen tragen die Adresse des Proxys. | `trusted_proxies` auf die Adresse des Proxys setzen |
| Ein Angriff wird nicht gesperrt, die Adresse wechselt bei jedem Versuch | Der Proxy hängt seine Adresse an eine vom Client mitgeschickte `X-Forwarded-For`-Kopfzeile an. owncloud.online übernimmt die erste Adresse der Liste, also den Wert des Angreifers. | Im Proxy die Kopfzeile setzen statt ergänzen (nginx: `$remote_addr` statt `$proxy_add_x_forwarded_for`), siehe [Reverse-Proxy und TLS](../../administration/reverse-proxy.md) |
| Tabellen `oc_bfp_failed_logins` und `oc_bfp_link_accesses` werden immer größer | Der tägliche Aufräum-Job läuft nicht. | Cron prüfen, siehe [Hintergrund-Jobs (Cron)](../../administration/background-jobs.md) |
| Speichern meldet „Eingaben müssen positive ganzzahlige Werte sein“ | Ein Feld ist leer oder enthält keine Zahl größer als 0. | ganze Zahlen größer als 0 eintragen |
