# LDAP-Integration

Die App bindet owncloud.online an ein LDAP-Verzeichnis oder ein Active Directory
an. Konten, Gruppen und Attribute wie E-Mail-Adresse und Speicherkontingent
kommen aus dem Verzeichnis, die Passwortprüfung übernimmt der Verzeichnisdienst.
owncloud.online speichert die Verzeichnis-Passwörter nicht. Mehrere
Verzeichnisse lassen sich nebeneinander anbinden.

!!! note "Verfügbarkeit"
    Öffentlich im Marketplace, ohne API-Schlüssel. App-ID `user_ldap`,
    Version 0.21.0. Name im Markt: „LDAP Integration“.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 mit der Erweiterung `ldap` (Paket `php8.4-ldap`). Ohne sie lässt sich die App nicht einschalten. |
| Verzeichnisserver | LDAP oder Active Directory, vom Server aus erreichbar. Für `ldaps://` oder StartTLS muss das Zertifikat des Verzeichnisservers auf dem Server vertrauenswürdig sein. |
| Bind-Konto | ein Konto mit Leserecht auf den benötigten Teilbaum, oder anonymer Lesezugriff |
| Cron | für den regelmäßigen Abgleich der Konten |

## Installation

In der Weboberfläche: im App-Menü links oben **Markt** öffnen, nach
„LDAP Integration“ suchen und **Installieren** klicken.

Auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ market:install user_ldap
```

`market:install` lädt die App, installiert sie und schaltet sie ein. Ist die App
schon vorhanden und nur abgeschaltet, genügt:

```bash
sudo -u www-data php8.4 occ app:enable user_ldap
```

Ob die PHP-Erweiterung geladen ist, zeigt `php8.4 -m | grep ldap`.

## Einrichtung

Die Einstellungen stehen unter **Einstellungen → Administration →
Nutzer-Authentifizierung**, Abschnitt **LDAP**. Ein Assistent führt durch vier
Reiter, dazu kommen **Fortgeschritten** und **Experte**:

1. **Server:** **Host** und **Port** des Verzeichnisservers, dazu **Benutzer-DN**
   und **Passwort** des Bind-Kontos, etwa `uid=agent,dc=example,dc=com`. Für
   anonymen Zugriff bleiben beide Felder leer. Im Feld **Einen Basis-DN pro
   Zeile** steht der Teilbaum, in dem gesucht wird; **Base DN ermitteln** und
   **Base DN testen** helfen dabei. Für LDAP über SSL beginnt der Host mit
   `ldaps://`; **Benutze StartTLS support** ist etwas anderes und bleibt dann
   aus.
2. **Benutzer:** legt fest, welche Einträge als Konten gelten, über
   **Nur diese Objektklassen:** und **Nur aus diesen Gruppen:** oder direkt über
   **LDAP-Abfrage bearbeiten**. **Einstellungen überprüfen und Benutzer zählen**
   zeigt das Ergebnis.
3. **Loginattribute:** bestimmt, womit sich Personen anmelden:
   **LDAP-/AD-Benutzername:**, **LDAP-/AD-E-Mail-Adresse:** oder
   **Andere Attribute:**. Mit **Loginnamen testen** prüfen Sie einen Namen. In der
   Sprachfassung „Deutsch (Förmlich: Sie)“ heißt der Reiter **Anmeldeattribute**.
4. **Gruppen:** legt fest, welche Einträge als Gruppen gelten.
   **Einstellungen überprüfen und Gruppen zählen** zeigt das Ergebnis.
5. **Fortgeschritten → Verbindungseinstellungen:** **Konfiguration aktiv**
   ankreuzen. Ohne diesen Haken überspringt owncloud.online die Konfiguration.

<figure markdown="span">
  ![Administration, LDAP-Assistent auf dem Reiter Server mit leeren Feldern für Host, Port und Benutzer-DN](../../assets/screenshots/de/admin-ldap.png)
  <figcaption>Der LDAP-Assistent führt in Reitern durch Server, Benutzer, Loginattribute und Gruppen.</figcaption>
</figure>

Bei großen Verzeichnissen setzen Sie auf dem Reiter **Server** das Kästchen
**LDAP-Filter manuell eingeben (empfohlen für große Verzeichnisse)**. Dann fragt
der Assistent nicht bei jedem Reiter alle Objektklassen und Gruppen ab.

Jede Verbindung bekommt eine eigene Konfigurations-ID (`s01`, `s02` und so
weiter). Alle Werte liegen in der App-Konfiguration von `user_ldap`.

Die einzelnen Felder, Filter, Attribute und Schlüssel beschreibt
[Verzeichnisdienst (LDAP)](../../administration/ldap.md) ausführlich.

## Kommandozeile

Die App bringt acht Befehle mit, jeweils mit `sudo -u www-data php8.4` davor:

| Befehl | Zweck |
| --- | --- |
| `occ ldap:create-empty-config` | neue, leere Konfiguration anlegen; ohne Angabe vergibt der Befehl die nächste freie ID |
| `occ ldap:show-config [s01]` | Konfiguration anzeigen, ohne ID alle; `--show-password` zeigt das Bind-Passwort |
| `occ ldap:set-config s01 ldap_host ldaps://dc01.example.org` | einen Wert setzen |
| `occ ldap:test-config s01` | Konfiguration prüfen und Verbindung samt Bind testen |
| `occ ldap:delete-config s01` | Konfiguration löschen |
| `occ ldap:search "meier"` | Konten suchen; `--group` sucht Gruppen, `--limit` begrenzt die Treffer (Vorgabe 15) |
| `occ ldap:check-user jmeier` | prüfen, ob ein Konto im Verzeichnis noch existiert |
| `occ ldap:invalidate-cache` | den LDAP-Cache leeren, etwa nach Änderungen im Verzeichnis |

`ldap:set-config` verwirft unbekannte Schlüssel ohne Fehlermeldung. Prüfen Sie
jede Änderung mit `ldap:show-config`.

## Abgleich per Cron

Ohne Abgleich entsteht ein Konto erst bei der ersten Anmeldung, und ein im
Verzeichnis gelöschter Eintrag bleibt in owncloud.online bestehen. Die App hat
keinen eigenen Hintergrund-Job; den Abgleich macht der Kernbefehl `user:sync`:

```bash
# Konten abgleichen; im Verzeichnis fehlende Konten werden deaktiviert
sudo -u www-data php8.4 occ user:sync ldap --missing-account-action=disable
```

Im Cron muss `--missing-account-action` gesetzt sein. Ohne die Option fragt der
Befehl, was mit fehlenden Konten geschehen soll, und im Cron beantwortet niemand
diese Frage. `disable` sperrt fehlende Konten und behält ihre Daten;
`remove` löscht sie samt Dateien. Eintrag in der Crontab des
Webserver-Benutzers (`sudo crontab -u www-data -e`), hier stündlich:

```
0 * * * * /usr/bin/php8.4 /var/www/owncloud.online/occ user:sync ldap --missing-account-action=disable
```

## Grenzen und Hinweise

- **Den internen Benutzernamen nicht nachträglich ändern.** Er ist der Name des
  Home-Verzeichnisses und Teil aller Adressen, auch für WebDAV. Änderungen unter
  **Experte** wirken nur auf neu zugeordnete Konten.
- **Zuordnung nicht löschen.** **LDAP-Benutzernamenzuordnung löschen** und
  **LDAP-Gruppennamenzuordnung löschen** leeren die Zuordnung für alle
  LDAP-Konfigurationen. Verwenden Sie diese Knöpfe nicht in einer produktiven
  Instanz.
- **Bind-Passwort:** Es liegt nur base64-kodiert in der Datenbank, also nicht
  verschlüsselt. Geben Sie dem Bind-Konto nur Leserechte.
- **Zertifikatsprüfung:** **Schalte die SSL-Zertifikatsprüfung aus.** dient nur
  zum Eingrenzen von Fehlern. Besser ist es, das Zertifikat des
  Verzeichnisservers auf dem Server zu hinterlegen.
- Die [Passwortrichtlinie](password_policy.md) gilt nicht für LDAP-Konten.
- Die App `user_webdavauth` darf nicht gleichzeitig aktiv sein. owncloud.online
  bietet sie nicht an.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Warnung „The PHP LDAP module is not installed, the backend will not work.“ (nicht übersetzt) | PHP-Erweiterung `ldap` nicht geladen | `php8.4-ldap` installieren, PHP-FPM neu laden |
| `ldap:test-config` meldet „The configuration is invalid.“ | Eine Pflichtangabe fehlt, oder der Anmeldefilter enthält kein `%uid`. | Werte mit `ldap:show-config` prüfen |
| `ldap:test-config` meldet, der Bind sei fehlgeschlagen | Benutzer-DN oder Passwort falsch, oder nur eines von beiden gesetzt | beide Felder füllen oder beide leeren |
| Niemand kann sich anmelden, der Test ist grün | **Konfiguration aktiv** ist nicht gesetzt. | `occ ldap:set-config s01 ldap_configuration_active 1` |
| Geänderte E-Mail-Adresse oder geändertes Kontingent kommt nicht an | Die Werte liegen im Cache (Vorgabe 600 Sekunden). | `occ ldap:invalidate-cache` |
| `user:sync` läuft im Cron nicht durch | `--missing-account-action` fehlt, der Befehl stellt eine Rückfrage. | Option immer mitgeben |
| Die Reiter des Assistenten laden sehr langsam | Der Assistent fragt das ganze Verzeichnis ab. | **LDAP-Filter manuell eingeben** setzen |

Meldungen der App stehen im Serverprotokoll unter `user_ldap`, siehe
[Serverprotokoll und Fehlermeldungen](../../administration/logging.md).
