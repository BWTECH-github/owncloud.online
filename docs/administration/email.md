# E-Mail-Versand

owncloud.online verschickt Mails an mehreren Stellen: Freigabe-Einladungen,
Passwort-Zurücksetzen, Kontoanlage und Kalendereinladungen. Ist kein Versand
eingerichtet, fallen diese Funktionen aus — meist ohne sichtbare Meldung, der
Fehler landet nur im Serverprotokoll. Diese Seite beschreibt die Einrichtung
über die Oberfläche und über `config/config.php`, den Testversand sowie die
Fehlersuche.

## Was ohne Mailversand ausfällt

| Funktion | Zuständiger Code | Verhalten ohne Versand |
| --- | --- | --- |
| Freigabe-Einladung an interne Benutzer | `lib/private/Share/MailNotifications.php` | Die Freigabe entsteht trotzdem; der Fehler wird als „Can't send mail to inform the user about an internal share" im Log unter `sharing` vermerkt |
| Freigabe-Einladung an eine Link-Adresse | dito | Zusätzlich muss die App-Einstellung `shareapi_allow_public_notification` auf `yes` stehen, sonst wird der Versand mit „Public link mail notification is not allowed" (HTTP 403) abgelehnt |
| Passwort vergessen | `core/Controller/LostController.php` | Der Benutzer erhält „Die E-Mail zum Zurücksetzen konnte nicht versendet werden. Bitte kontaktiere deinen Administrator." |
| Bestätigung nach Passwortänderung | `core/Controller/LostController.php`, `settings/ChangePassword/Controller.php` | Passwort ist geändert, es kommt keine Benachrichtigung |
| Neues Konto: Link zum Setzen des Passworts | `settings/Controller/UsersController.php` | Das Konto existiert, der Benutzer bekommt keinen Zugang |
| Bestätigung und Änderung der eigenen Adresse | `settings/Controller/UsersController.php` | Adresswechsel bleibt unbestätigt |
| Kalendereinladungen (iMIP) | `apps/dav/lib/CalDAV/Schedule/IMipPlugin.php` | Eingeladene erhalten nichts |
| Aktivitäts-Zusammenfassungen | Plugin `activity`, `lib/MailQueueHandler.php` | Die Warteschlange in der Tabelle `activity_mq` wird nicht geleert |

Zwei Einschränkungen, die häufig anders erwartet werden:

* Eine eigene **Warnmail vor dem Ablauf** einer Freigabe verschickt der Kern
  nicht. Das Ablaufdatum wird in die Einladungsmail eingesetzt
  (`createMailBody()` in `lib/private/Share/MailNotifications.php`), danach
  erfolgt keine weitere Erinnerung.
* Aktivitäts-Zusammenfassungen entstehen erst im Hintergrund-Job der App
  `activity`. Ohne laufenden Cron werden sie nie verschickt, siehe
  [Hintergrund-Jobs (Cron)](background-jobs.md). Ein Nachholen von Hand:

```bash
sudo -u www-data php8.4 occ activity:send-emails
```

## Einrichtung über die Oberfläche

![Verwaltungseinstellungen](../assets/screenshots/owncloud-online-admin-settings.png)

Der Abschnitt **E-Mail-Server** liegt unter *Einstellungen → Administration →
Allgemein* (`settings/Panels/Admin/Mail.php`, Abschnitt `general`).

| Feld | Schlüssel | Anmerkung |
| --- | --- | --- |
| Sendemodus | `mail_smtpmode` | Auswahl `php` und `smtp`; `sendmail` erscheint nur, wenn das Binary gefunden wird; `qmail` nur, wenn es bereits gesetzt ist |
| Verschlüsselung | `mail_smtpsecure` | Nichts / SSL/TLS / STARTTLS, nur im Modus `smtp` sichtbar |
| Absenderadresse | `mail_from_address` und `mail_domain` | zwei Felder, getrennt durch das `@` |
| Authentication method | `mail_smtpauthtype` | in der deutschen Oberfläche unübersetzt; Auswahl None / Login / Plain / NT LAN Manager |
| Authentifizierung benötigt | `mail_smtpauth` | blendet die Zugangsdaten ein |
| Serveradresse | `mail_smtphost` und `mail_smtpport` | zwei Felder, getrennt durch den Doppelpunkt |
| Zugangsdaten | `mail_smtpname`, `mail_smtppassword` | Speichern nur über den Knopf *Anmeldeinformationen speichern* |
| Test Empfänger E-Mail Adresse | — | wird nicht gespeichert, gilt nur für den Testversand |

Das obere Formular speichert **bei jeder Änderung sofort**
(`settings/js/panels/mail.js` sendet bei `change` an
`/settings/admin/mailsettings`). Benutzername und Passwort gehen einen eigenen
Weg und werden erst mit dem Knopf übernommen. Wird die Authentifizierung
abgeschaltet, löscht `MailSettingsController::setMailSettings()` Benutzername
und Passwort aus der Konfiguration.

Ist `config_is_read_only` auf `true` gesetzt, zeigt der Abschnitt nur den
Hinweis „Die Konfigurationsdatei ist schreibgeschützt" und kein Formular — dann
führt nur der Weg über `config/config.php`.

## Einrichtung über config.php

Typische SMTP-Anbindung mit STARTTLS auf Port 587:

```bash
sudo -u www-data php8.4 occ config:system:set mail_smtpmode --value smtp
sudo -u www-data php8.4 occ config:system:set mail_smtphost --value smtp.example.com
sudo -u www-data php8.4 occ config:system:set mail_smtpport --value 587 --type integer
sudo -u www-data php8.4 occ config:system:set mail_smtpsecure --value tls
sudo -u www-data php8.4 occ config:system:set mail_smtpauth --value true --type boolean
sudo -u www-data php8.4 occ config:system:set mail_smtpauthtype --value LOGIN
sudo -u www-data php8.4 occ config:system:set mail_smtpname --value noreply@example.com
sudo -u www-data php8.4 occ config:system:set mail_smtppassword --value 'CHANGE_ME'
sudo -u www-data php8.4 occ config:system:set mail_from_address --value noreply
sudo -u www-data php8.4 occ config:system:set mail_domain --value example.com
```

`--type` ist bei `mail_smtpport` und `mail_smtpauth` nötig, sonst landen die
Werte als Zeichenkette in der Datei. Gleichwertig ist der direkte Eintrag in
`config/config.php`:

```php
'mail_smtpmode' => 'smtp',
'mail_smtphost' => 'smtp.example.com',
'mail_smtpport' => 587,
'mail_smtpsecure' => 'tls',
'mail_smtpauth' => true,
'mail_smtpauthtype' => 'LOGIN',
'mail_smtpname' => 'noreply@example.com',
'mail_smtppassword' => 'CHANGE_ME',
'mail_from_address' => 'noreply',
'mail_domain' => 'example.com',
```

Kontrolle der gesetzten Werte:

```bash
sudo -u www-data php8.4 occ config:system:get mail_smtphost
```

`occ config:list system` blendet `mail_smtphost`, `mail_smtpname`,
`mail_smtppassword`, `mail_from_address` und `mail_domain` aus — sie stehen in
`lib/private/SystemConfig.php` als schutzwürdig. `config:system:get` gibt den
Klartext aus.

## Alle mail_-Schlüssel

| Schlüssel | Vorgabe im Code | Bedeutung |
| --- | --- | --- |
| `mail_smtpmode` | `php` | Versandweg. Nur `smtp` spricht SMTP; alles außer `smtp` und `qmail` ruft `/usr/sbin/sendmail -bs` auf |
| `mail_smtphost` | `127.0.0.1` | Adresse des SMTP-Servers, nur im Modus `smtp` |
| `mail_smtpport` | `25` | Port des SMTP-Servers. Der Wert `465` schaltet zusätzlich implizites TLS ein |
| `mail_smtpsecure` | leer | Nicht leer bedeutet: Verbindung **muss** verschlüsselt sein. Die Werte `ssl` und `tls` wirken dabei gleich |
| `mail_smtpauth` | `false` | Anmeldung am SMTP-Server durchführen |
| `mail_smtpauthtype` | `LOGIN` | Verfahren. Der Code kennt `LOGIN`, `PLAIN` und `CRAM-MD5` |
| `mail_smtpname` | leer | Benutzername für die SMTP-Anmeldung |
| `mail_smtppassword` | leer | Passwort für die SMTP-Anmeldung |
| `mail_smtpdebug` | `false` | Übergibt den Server-Logger an den Transport, der SMTP-Dialog wird auf Debug-Ebene protokolliert |
| `mail_smtptimeout` | `10` | **Ohne Wirkung** — die Zeile, die den Wert setzen würde, ist in `lib/private/Mail/Mailer.php` auskommentiert |
| `mail_from_address` | nicht gesetzt | Lokalteil der Absenderadresse |
| `mail_domain` | nicht gesetzt | Domain der Absenderadresse; ohne Wert der Hostname des Servers |

Zum Sendemodus im Detail (`Mailer::getInstance()` und
`Mailer::getSendMailInstance()`):

* `smtp` — Verbindung zu `mail_smtphost:mail_smtpport`. Nach 100 Nachrichten
  wird die Verbindung neu aufgebaut.
* `qmail` — Aufruf von `/var/qmail/bin/sendmail -bs`.
* `php`, `sendmail` und jeder andere Wert — Aufruf von `/usr/sbin/sendmail
  -bs`. Der Modus `php` benutzt also **nicht** die Funktion `mail()` und
  **nicht** den in der `php.ini` eingetragenen `sendmail_path`, auch wenn der
  Beschreibungstext in `config/config.sample.php` das nahelegt. Ohne lokal
  installierten MTA schlägt dieser Weg fehl.

Ergänzend gehört zu diesem Themenblock, ohne `mail_`-Präfix:

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `remove_sender_display_name` | `false` | Lässt den Anzeigenamen des Freigebenden aus dem Absender der Freigabemail weg. Hilft gegen Spamfilter, die „Name via owncloud.online" als Identitätsmissbrauch werten |
| `allow_user_to_change_mail_address` | `true` | Auf `false` dürfen Benutzer ihre Adresse nicht mehr selbst ändern |

## Absenderadresse und Antwortadresse

Die Absenderadresse baut `\OCP\Util::getDefaultEmailAddress()` zusammen. Jede
Stelle im Code gibt einen Standard-Lokalteil vor, den `mail_from_address`
überschreibt:

| Mailart | Standard-Lokalteil | Registriert in |
| --- | --- | --- |
| Passwort zurücksetzen, Passwortänderung | `lostpassword-noreply` | `core/Application.php` |
| Freigabe-Einladungen | `sharing-noreply` | `lib/private/Share/MailNotifications.php` |
| Kontoanlage, Adressbestätigung, Test-Mail | `no-reply` | `settings/Application.php` |
| Aktivitäts-Zusammenfassungen | `no-reply` | Plugin `activity`, `lib/MailQueueHandler.php` |

Den Domain-Teil liefert `mail_domain`, ersatzweise der Hostname des Servers.
Ergibt sich daraus keine gültige Adresse, fällt der Code auf
`<lokalteil>@localhost.localdomain` zurück — solche Mails werden von den
meisten Empfängerservern abgelehnt. Setzen Sie deshalb auf Produktivsystemen
`mail_from_address` und `mail_domain` immer explizit, und zwar auf eine Domain,
für die Ihr SMTP-Server versenden darf.

Die **Antwortadresse** wird nur bei Freigabemails gesetzt
(`MailNotifications::getReplyTo()`): Es ist die Adresse des Benutzers, der
freigegeben hat. Hat dieser keine Adresse hinterlegt, wird auf
`sharing-noreply@<mail_domain>` zurückgefallen. Kalendereinladungen sind ein
Sonderfall: Dort setzt `IMipPlugin` den Absender der iTIP-Nachricht — je nach
Methode der Organisator oder ein antwortender Teilnehmer — sowohl als Absender-
als auch als Antwortadresse; `mail_from_address` greift dort nicht. Alle
übrigen Mails haben keine eigene Antwortadresse.

## Testversand

Im Abschnitt **E-Mail-Server** steht unten das Feld *Test Empfänger E-Mail
Adresse*, vorbelegt mit der Adresse des angemeldeten Kontos, und der Knopf
*E-Mail senden*. Ergebnis:

* Erfolg: „E-Mail wurde verschickt".
* Fehler: „Beim Senden der E-Mail ist ein Problem aufgetreten. Bitte überprüfe
  deine Einstellungen. (Fehler: …)" — in der Klammer steht die Meldung des
  Transports, siehe [Fehlersuche](#fehlersuche).
* Ist weder ein Empfänger eingetragen noch beim eigenen Konto eine Adresse
  hinterlegt, erscheint der Hinweis, dass zuerst die eigene Adresse gesetzt
  werden muss.

Die Testmail geht als `no-reply@<mail_domain>` mit dem Betreff
„E-Mail-Einstellungen testen" heraus. Sie prüft damit nur den Weg vom Server
zum Mailserver, nicht die spätere Zustellbarkeit der Freigabe- und
Passwortmails.

Adressen von Benutzern setzen oder nachtragen:

```bash
# Adresse eines vorhandenen Kontos setzen
sudo -u www-data php8.4 occ user:modify benutzername email person@example.com

# Konto samt Adresse anlegen
sudo -u www-data php8.4 occ user:add --email person@example.com benutzername
```

Benutzer selbst pflegen ihre Adresse unter *Einstellungen → Persönlich →
Allgemein* im Abschnitt *E-Mail*.

## SMTP-Dialog protokollieren

Für die Fehlersuche lässt sich der gesamte SMTP-Dialog mitschreiben. Das
protokolliert auch den Benutzernamen, deshalb nur kurzzeitig einschalten:

```bash
sudo -u www-data php8.4 occ config:system:set mail_smtpdebug --value true --type boolean
sudo -u www-data php8.4 occ config:system:set loglevel --value 0 --type integer
```

`loglevel 0` ist nötig, weil der Transport auf Debug-Ebene schreibt. Danach
beides zurückstellen (`loglevel` auf `2`, `mail_smtpdebug` auf `false`). Zum
Lesen des Protokolls siehe
[Serverprotokoll und Fehlermeldungen](logging.md).

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Unknown authenticator type" beim Senden | `mail_smtpauth` ist an, aber `mail_smtpauthtype` steht auf einem Wert, den der Code nicht kennt — das trifft die Auswahl **NT LAN Manager** und den leeren Wert **None** aus der Oberfläche | `mail_smtpauthtype` auf `LOGIN`, `PLAIN` oder `CRAM-MD5` setzen; wird keine Anmeldung gebraucht, `mail_smtpauth` auf `false` |
| „TLS required but neither TLS or STARTTLS are in use." | `mail_smtpsecure` ist gesetzt, der Server bietet aber kein STARTTLS an (oder OpenSSL fehlt in PHP) | Port prüfen (Klartext-Port statt Submission-Port), sonst `openssl`-Erweiterung nachinstallieren |
| „Unable to connect with STARTTLS." | STARTTLS wird angeboten, der TLS-Aufbau scheitert — meist wegen eines nicht vertrauenswürdigen oder auf einen anderen Namen ausgestellten Zertifikats | Zertifikatskette des Mailservers prüfen, `mail_smtphost` exakt auf den im Zertifikat geführten Namen setzen |
| Verbindung auf Port 465 kommt nicht zustande | Implizites TLS wird ausschließlich über den Port `465` aktiviert; die Auswahl *SSL/TLS* in der Oberfläche erzwingt nur Verschlüsselung, sie schaltet kein implizites TLS ein | Für SMTPS `mail_smtpport` auf `465` setzen, für Submission mit STARTTLS auf `587` |
| Verschlüsselung angeblich aus, Verbindung ist trotzdem TLS | Ist `mail_smtpsecure` leer, wird STARTTLS trotzdem benutzt, sobald der Server es anbietet — der leere Wert erzwingt es nur nicht | Kein Handlungsbedarf; erzwingen lässt sich TLS mit `mail_smtpsecure` |
| „Connection could not be established with host …" | Falscher Host oder Port, oder eine Firewall blockt ausgehendes SMTP | Erreichbarkeit vom Server aus prüfen; **nicht** Host und Port zusammen in `mail_smtphost` schreiben — der Code hängt `mail_smtpport` immer an und erzeugt sonst `host:24:25` |
| Versand hängt lange, bevor er abbricht | `mail_smtptimeout` wirkt nicht; maßgeblich ist `default_socket_timeout` aus der `php.ini` (Vorgabe 60 Sekunden) | `default_socket_timeout` in der `php.ini` von FPM **und** CLI anpassen |
| Nur der Weg über die Oberfläche funktioniert, Aktivitäts- und Benachrichtigungsmails bleiben aus | Der Versand hängt am Hintergrund-Job | Cron prüfen, siehe [Hintergrund-Jobs (Cron)](background-jobs.md) |
| Kein Zurücksetzen des Passworts möglich | Das Konto hat keine Adresse. Im Log steht „Could not send reset email because there is no email address for this username." | Adresse mit `occ user:modify … email …` nachtragen |
| Zweite Reset-Mail kommt nicht an | Innerhalb von fünf Minuten wird keine weitere verschickt („The email is not sent because a password reset email was sent recently.") | Warten, nicht mehrfach anfordern |
| Benutzer können Links nicht per Mail verschicken | App-Einstellung `shareapi_allow_public_notification` steht auf `no` | *Einstellungen → Administration → Teilen*, Haken bei „Benutzern erlauben, E-Mail-Benachrichtigungen für freigegebene Dateien zu senden" |
| Mails landen im Spam oder werden abgewiesen | Absenderadresse passt nicht zur versendenden Domain, oder der Anzeigename im Absender stört den Filter | `mail_from_address` und `mail_domain` auf die eigene Domain setzen, SPF/DKIM dort pflegen, notfalls `remove_sender_display_name` auf `true` |
| Änderungen in der Oberfläche werden nicht übernommen | `config_is_read_only` ist gesetzt | Werte direkt in `config/config.php` pflegen, im Cluster auf allen Knoten |

Bleibt die Ursache unklar, den SMTP-Dialog wie oben beschrieben mitschreiben —
darin steht die Antwort des Mailservers im Klartext.
