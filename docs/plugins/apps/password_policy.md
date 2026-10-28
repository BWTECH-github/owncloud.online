# Passwortrichtlinie

Die App legt fest, wie Passwörter aussehen müssen: für Benutzerkonten und für
Passwörter öffentlicher Links. Dazu kommen ein Passwortverlauf, Ablauffristen
mit Erinnerung und eine Höchstlaufzeit für öffentliche Links. Sie ist für
Instanzen gedacht, die Mindestanforderungen an Passwörter durchsetzen müssen.

!!! note "Verfügbarkeit"
    Öffentlich im Marketplace, ohne API-Schlüssel. App-ID `password_policy`,
    Version 2.3.3. Name im Markt: „Password Policy“.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 |
| Cron | für die Ablauf-Erinnerungen; der Hintergrund-Job läuft alle 12 Stunden |
| App Benachrichtigungen | Die Erinnerungen kommen als Benachrichtigung; dafür muss die App `notifications` eingeschaltet sein. |

## Installation

In der Weboberfläche: im App-Menü links oben **Markt** öffnen, nach
„Password Policy“ suchen und **Installieren** klicken.

Auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ market:install password_policy
```

`market:install` lädt die App, installiert sie und schaltet sie ein. Ist die App
schon vorhanden und nur abgeschaltet, genügt:

```bash
sudo -u www-data php8.4 occ app:enable password_policy
```

Für Server ohne Internetzugang siehe [Apps und Marketplace](../../administration/apps-market.md).

## Einrichtung

Die Regeln stehen unter **Einstellungen → Administration → Sicherheit**,
Abschnitt **Richtlinien für Passwörter und öffentliche Links**. Jede Regel hat
ein Ankreuzfeld, die meisten zusätzlich einen Wert. Ohne Haken wirkt der Wert
nicht. Nach der Installation ist keine Regel eingeschaltet; die Spalte „Vorgabe“
nennt den vorbelegten Wert. **Speichern** übernimmt die Einstellungen.

<figure markdown="span">
  ![Administration, Sicherheit: Richtlinien für Passwörter und öffentliche Links mit Mindestanforderungen und Ablaufregeln](../../assets/screenshots/de/admin-password-policy.png)
  <figcaption>Die Passwortrichtlinie legt Mindestlänge, Zeichenarten und Ablauf fest, auch für Passwörter öffentlicher Links.</figcaption>
</figure>

### Mindestanforderungen für Konten und öffentliche Links

| Beschriftung | Schlüssel | Vorgabe |
| --- | --- | --- |
| Mindestlänge | `spv_min_chars_checked`, `spv_min_chars_value` | 8 |
| Kleinbuchstaben | `spv_lowercase_checked`, `spv_lowercase_value` | 1 |
| Großbuchstaben | `spv_uppercase_checked`, `spv_uppercase_value` | 1 |
| Zahlen | `spv_numbers_checked`, `spv_numbers_value` | 1 |
| Sonderzeichen | `spv_special_chars_checked`, `spv_special_chars_value` | 1 |
| Auf diese Sonderzeichen beschränken: | `spv_def_special_chars_checked`, `spv_def_special_chars_value` | `#!` |

Bei Klein- und Großbuchstaben, Zahlen und Sonderzeichen ist der Wert eine
Mindestanzahl. Die Mindestlänge zählt Zeichen, nicht Bytes.

### Richtlinien für Benutzerkonten

| Beschriftung | Schlüssel | Vorgabe |
| --- | --- | --- |
| Zuletzt benutzte Passwörter dürfen nicht benutzt werden | `spv_password_history_checked`, `spv_password_history_value` | 3 |
| Tage bis Benutzer-Passwörter ablaufen | `spv_user_password_expiration_checked`, `spv_user_password_expiration_value` | 90 |
| Tage bevor Passwörter ablaufen, erhalten Benutzer eine Erinnerung | `spv_user_password_expiration_notification_checked`, `spv_user_password_expiration_notification_value` | 30 |
| Änderung des Passworts beim ersten Login erzwingen | `spv_user_password_force_change_on_first_login_checked` | aus |

Die Erinnerung muss vor dem Ablauf liegen: Ist die Erinnerungsfrist gleich lang
oder länger als die Ablauffrist, verschickt der Hintergrund-Job nichts und
schreibt eine Warnung ins Protokoll.

Ein abgelaufenes Passwort deaktiviert das Konto nicht. Bei der nächsten
Anmeldung im Browser verlangt owncloud.online ein neues Passwort, erst danach
geht es weiter.

Das Alter eines Passworts kennt die App erst, wenn es bei eingeschalteter App
gesetzt wurde. Passwörter aus der Zeit davor laufen deshalb erst ab, nachdem das
Konto sein Passwort einmal geändert hat. Wer sofort einen Wechsel verlangen will,
verwendet `occ user:expire-password` (siehe unten).

### Richtlinien für das Ablaufdatum von öffentlichen Links

| Beschriftung | Schlüssel | Vorgabe |
| --- | --- | --- |
| Maximale Anzahl Tage bis zum Ablaufen von Links, falls ein Passwort gesetzt ist | `spv_expiration_password_checked`, `spv_expiration_password_value` | 7 |
| Maximale Anzahl Tage bis zum Ablaufen von Links, falls kein Passwort gesetzt ist | `spv_expiration_nopassword_checked`, `spv_expiration_nopassword_value` | 7 |

Greift eine dieser Regeln, braucht der öffentliche Link ein Ablaufdatum: die
erste Regel bei Links mit Passwort, die zweite bei Links ohne. Ein späteres
Datum als erlaubt lehnt owncloud.online ab. Mit getrennten Fristen dürfen Links
mit Passwort länger gelten als Links ohne. Diese Regeln wirken
zusätzlich zu den Freigabe-Einstellungen des Kerns; es gilt die strengere Regel,
siehe [Freigaben steuern](../../administration/sharing-policies.md).

### Werte auf der Kommandozeile setzen

Ein Haken wird als `on` gespeichert. Setzen Sie Schalter per `occ` deshalb
immer auf `on`; zum Abschalten löschen Sie den Schlüssel:

```bash
sudo -u www-data php8.4 occ config:app:set password_policy spv_min_chars_checked --value=on
sudo -u www-data php8.4 occ config:app:set password_policy spv_min_chars_value --value=12
sudo -u www-data php8.4 occ config:app:delete password_policy spv_min_chars_checked
```

Ausnahme: `spv_user_password_expiration_notification_value` speichert die
Erinnerungsfrist in Sekunden, nicht in Tagen. 30 Tage sind `2592000`.

### Passwort eines Kontos sofort ablaufen lassen

```bash
# Passwort von alice läuft sofort ab
sudo -u www-data php8.4 occ user:expire-password -u alice

# zu einem festen Zeitpunkt
sudo -u www-data php8.4 occ user:expire-password "2026-12-31 12:00:00 CET" -u alice

# alle Mitglieder einer Gruppe
sudo -u www-data php8.4 occ user:expire-password -g vertrieb
```

Der Befehl funktioniert nur, wenn die Regel **Tage bis Benutzer-Passwörter
ablaufen** eingeschaltet ist. `--all` erfasst alle Konten, die ihr Passwort in
owncloud.online ändern können.

## Nutzung

Benutzer bemerken die Richtlinie an drei Stellen:

- Beim Ändern des Passworts und beim Setzen eines Link-Passworts erklärt eine
  Meldung, welche Regel verletzt ist, zum Beispiel „Das Passwort ist zu kurz. Es
  muss aus mindestens 12 Zeichen bestehen.“
- Vor dem Ablauf, sofern die Erinnerung eingeschaltet ist, und nach dem Ablauf
  erscheint eine Benachrichtigung mit dem Knopf **Passwort ändern**. Mit der
  Standardeinstellung der App Benachrichtigungen geht sie zusätzlich per E-Mail
  an die Adresse des Kontos.
- Nach dem Ablauf oder beim ersten Login führt die Anmeldung auf eine Seite, auf
  der das neue Passwort gesetzt wird.

## Grenzen und Hinweise

- Die Regeln gelten für lokale Konten, also Konten, die Administratoren anlegen,
  und Gastkonten. Konten aus LDAP oder anderen Benutzer-Backends sind
  ausgenommen; dort gelten die Regeln des Verzeichnisdienstes.
- Neue Anforderungen an den Aufbau eines Passworts gelten ab der nächsten
  Passwortänderung. Bestehende Passwörter bleiben gültig.
- Wird die App auf einer Datenbank mit der Vorgänger-App `security` zum ersten
  Mal eingeschaltet, übernimmt sie deren Regeln (Mindestlänge, Groß- und
  Kleinbuchstaben, Ziffern, Sonderzeichen). Eigene Werte überschreibt sie nie.
  Die übernommenen Regeln gelten danach auch für Passwörter öffentlicher Links.
  Die Übernahme steht im Serverprotokoll.
- Sehr strenge Regeln führen oft zu notierten Passwörtern. Die Richtlinie
  NIST SP 800-63B empfiehlt Länge statt Zeichenklassen und einen erzwungenen
  Wechsel nur bei begründetem Verdacht.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Eine Regel wirkt nicht | Der Haken der Regel fehlt oder steht per `occ` nicht auf `on`. | Haken setzen oder `--value=on` verwenden |
| Keine Ablauf-Erinnerungen | Cron läuft nicht, die App Benachrichtigungen ist aus, oder die Erinnerungsfrist ist nicht kürzer als die Ablauffrist. | Cron prüfen, `notifications` einschalten, Fristen korrigieren |
| `user:expire-password` meldet „Cannot use this command because no expiration rule was configured“ | Die Ablaufregel für Benutzerkonten ist aus. | **Tage bis Benutzer-Passwörter ablaufen** einschalten |
| LDAP-Konten sind nicht betroffen | so vorgesehen | Regeln im Verzeichnisdienst festlegen |
| Link lässt sich ohne Ablaufdatum nicht speichern („Ein Ablaufdatum ist erforderlich.“) | Eine Ablaufregel für öffentliche Links ist aktiv. | Ablaufdatum setzen oder die Regel abschalten |
