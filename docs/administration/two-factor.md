# Zwei-Faktor-Anmeldung

owncloud.online prüft den zweiten Faktor im Kern, liefert ihn aber nicht selbst:
Den Faktor bringt eine App mit, die sich in ihrer `info.xml` unter
`<two-factor-providers>` einträgt. Für zeitgesteuerte Einmalpasswörter (TOTP)
ist das die App `twofactor_totp`. Ohne eine solche aktivierte App fragt die
Anmeldung keinen zweiten Faktor ab — auch dann nicht, wenn der Zwang
eingeschaltet ist.

## Provider-App bereitstellen

Die App gehört nicht zum Lieferumfang des Kerns; sie wird wie andere Apps über
den Markt installiert (siehe [Apps und Marketplace](apps-market.md)) und danach
aktiviert:

```bash
sudo -u www-data php8.4 occ app:enable twofactor_totp
```

Die QR-Codes werden über imagick gerendert, und die App verlangt die
Erweiterung in ihrer `info.xml` (`<lib>imagick</lib>`). Ohne `php8.4-imagick`
lässt sich der Schlüssel weder in den Kontoeinstellungen noch auf der
Anmeldeseite als QR-Code anzeigen.

Welche Apps aktiv sind, zeigt:

```bash
sudo -u www-data php8.4 occ app:list
```

## Zweiten Faktor im Konto einrichten

Der Weg führt über **Einstellungen → Persönlich → Sicherheit**, Abschnitt
**TOTP-Zweifaktorauthentifizierung**:

1. Das Kästchen **TOTP (zeitgesteuertes Einmalpasswort) aktivieren** setzen.
   Der Server erzeugt daraufhin einen neuen Schlüssel und zeigt ihn zusammen
   mit einem QR-Code an.
2. Den QR-Code in der Authenticator-App scannen. Wer nicht scannen kann,
   überträgt den unter *Dies ist Ihr neuer TOTP-Schlüssel:* angezeigten Wert
   von Hand.
3. Den angezeigten sechsstelligen Code in das Feld **Authentifizierungscode**
   eintragen und **Überprüfen** drücken. Die Antwort lautet *Geprüft* oder
   *Nicht geprüft*. In der Sprachfassung *Deutsch (Persönlich)* heißen dieselben
   Beschriftungen *Bestätigen*, *Bestätigt* und *Nicht bestätigt*.

Schritt 3 ist der entscheidende: Nur ein **bestätigter** Schlüssel zählt. Wird
das Kästchen gesetzt, aber nie ein Code bestätigt, bleibt die Anmeldung
unverändert; beim nächsten Aufruf der Seite steht das Kästchen wieder leer, denn
sein Zustand hängt am bestätigten Schlüssel. Wird das Kästchen wieder entfernt,
löscht der Server den Schlüssel; eine erneute Aktivierung erzeugt einen neuen und
verlangt einen neuen Scan.

Zum Zeitverhalten: Der Code wechselt alle 30 Sekunden, und die Prüfung
akzeptiert drei Zeitschritte in beide Richtungen, also rund anderthalb Minuten
Abweichung zwischen Server- und Geräteuhr. Ein bereits angenommener Code wird
kein zweites Mal akzeptiert; der zuletzt geprüfte Code wird dafür gespeichert.

## Zweiten Faktor erzwingen

Der Zwang steht unter **Einstellungen → Administration → Sicherheit**, Abschnitt
**Zwei-Faktor-Authentifizierung**. Dort gibt es zwei Bedienelemente:

- das Kästchen **Erzwinge Zwei-Faktor-Authentifizierung für alle Nutzer.**
- das Feld **Die folgenden Gruppen sind von der erzwungenen
  Zwei-Faktor-Authentifizierung ausgenommen**

In der Sprachfassung *Deutsch (Förmlich: Sie)* heißt der Navigationspunkt
*Administrator* statt *Administration*; der Abschnitt selbst ist derselbe.

Beide Bedienelemente schreiben in die App-Konfiguration:

| Schlüssel | App | Bedeutung |
| --- | --- | --- |
| `enforce_2fa` | `core` | `yes` schaltet den Zwang ein, jeder andere Wert lässt ihn aus |
| `enforce_2fa_excluded_groups` | `core` | JSON-Liste der ausgenommenen Gruppen, Vorgabe `[]` |

Dieselbe Einstellung auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ config:app:set core enforce_2fa --value yes
sudo -u www-data php8.4 occ config:app:set core enforce_2fa_excluded_groups --value '["Technik","Vorstand"]'
sudo -u www-data php8.4 occ config:app:get core enforce_2fa
sudo -u www-data php8.4 occ config:app:get core enforce_2fa_excluded_groups
```

Ausschalten geht über `--value no` oder durch Löschen des Schlüssels:

```bash
sudo -u www-data php8.4 occ config:app:delete core enforce_2fa
```

### Was der Zwang je Gruppe tatsächlich leistet

Das Modell kennt nur Ausnahmen, keine Positivliste: Der Zwang gilt für alle,
und die eingetragenen Gruppen sind davon ausgenommen. Soll er nur für eine
einzige Gruppe gelten, müssen alle übrigen Gruppen in der Ausnahmeliste stehen
— und Konten, die in keiner Gruppe sind, lassen sich auf diesem Weg nicht
ausnehmen, weil die Prüfung nur Gruppenmitgliedschaften vergleicht. Mitglieder
ausgenommener Gruppen können den Faktor weiterhin freiwillig einrichten; darauf
weist auch die Oberfläche hin.

### Verhalten bei erzwungenem Faktor

- Konten ohne eingerichteten Faktor werden nicht ausgesperrt: Auf der
  Faktor-Seite erzeugt die App einen Schlüssel und zeigt den QR-Code an, die
  Einrichtung findet also unmittelbar bei der Anmeldung statt. Der eingegebene
  Code gilt zugleich als Bestätigung des neuen Schlüssels.
- Der Zwang überstimmt die kontobezogene Abschaltung (siehe unten).
- Ohne aktive Provider-App bleibt der Zwang wirkungslos, weil es keinen Faktor
  gibt, der abgefragt werden könnte.

Der Ablauf nach richtigem Passwort: Es erscheint die Seite **Bestätigung in
zwei Schritten** mit der Liste der verfügbaren Faktoren, bei genau einem Faktor
geht es direkt zur Code-Eingabe. Bis der Code stimmt, gibt die Middleware keine
andere Seite frei; nur **Anmelden abbrechen** führt hinaus.

## App-Passwörter und Client-Abgleich

Gilt für ein Konto ein zweiter Faktor, weist der Server jede Client-Anmeldung
ab, die das Kontopasswort mitsendet. Anfragen mit einem App-Passwort
(Gerätetoken) laufen unverändert weiter.

| Zugangsweg | Verhalten bei aktivem zweitem Faktor |
| --- | --- |
| Anmeldung im Browser | unverändert, der Faktor wird abgefragt |
| Client mit Kontopasswort (WebDAV, OCS) | abgewiesen; im Protokoll steht `Login failed: '<konto>' (Remote IP: '<ip>')` |
| Client mit App-Passwort | läuft weiter |
| Token-Ausgabe über `index.php/token/generate` | abgewiesen mit HTTP 401 |
| Bereits angemeldete Browser-Sitzung | wird nicht nachträglich zum Faktor aufgefordert; die Abfrage entsteht beim Anmelden |

Bestehende App-Passwörter bleiben gültig. Das Einschalten des zweiten Faktors
entzieht keine Token — bequem für laufende Clients, zugleich aber der wunde
Punkt der Maßnahme: Ein abgeflossenes App-Passwort umgeht den zweiten Faktor
weiterhin. Wer den Faktor einführt, um kompromittierte Zugangsdaten
auszuschließen, muss die Token deshalb durchgehen und entziehen.

Token verwalten und neu erstellen lässt sich unter **Einstellungen → Persönlich
→ Sicherheit**, Abschnitt **App-Passwörter / Token**: Namen eintragen,
**Neuen App-Passcode erstellen** drücken, dann werden *Benutzername* und
*Passwort / Token* einmalig angezeigt. Auf derselben Seite listet der Abschnitt
*Sitzungen* die aktuell angemeldeten Web-, Desktop- und Mobil-Clients.

Unabhängig vom zweiten Faktor lässt sich die Anmeldung mit dem Kontopasswort
für alle Clients sperren, in `config/config.php`:

```php
'token_auth_enforced' => true,
```

Die Browser-Anmeldung ist davon nicht betroffen.

## Wenn ein Konto seinen zweiten Faktor verloren hat

Der zweite Faktor lässt sich nicht wiederherstellen, nur zurücksetzen. Danach
richtet das Konto ein neues Gerät ein.

In der Verwaltungsoberfläche steht dafür **Einstellungen → Administration →
Nutzer-Authentifizierung**, Abschnitt **TOTP Admin Reset**. Die Beschriftungen
dieses Abschnitts sind nicht übersetzt und erscheinen auch in einer deutschen
Oberfläche englisch: über *Search users* das Konto suchen, in der Liste
markieren und *Reset selected* drücken. *Reset all TOTP setups* setzt sämtliche
Einrichtungen der Instanz zurück.

Derselbe Vorgang auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ twofactor_totp:delete-secret alice
sudo -u www-data php8.4 occ twofactor_totp:delete-secret alice bob
sudo -u www-data php8.4 occ twofactor_totp:delete-secret --all
```

Ohne Kontoangabe und ohne `--all` bricht der Befehl mit einem Hinweis ab. Die
Ausgabe nennt je Konto die Zahl der gelöschten Schlüssel.

Was danach passiert, hängt am Zwang: Ist er für das Konto wirksam, richtet sich
das Konto bei der nächsten Anmeldung auf der Faktor-Seite neu ein. Ist er es
nicht, ist der zweite Faktor für dieses Konto schlicht aus, bis es ihn in den
Kontoeinstellungen wieder einschaltet.

Zwei weitere Wege, die den Schlüssel erhalten:

```bash
# Faktor für ein Konto abschalten, ohne den Schluessel zu loeschen
sudo -u www-data php8.4 occ user:setting alice core two_factor_auth_disabled --value 1

# Abschaltung wieder zuruecknehmen
sudo -u www-data php8.4 occ user:setting alice core two_factor_auth_disabled --delete

# Schluessel als "nicht geprueft" markieren; der Faktor ist damit aus, sofern kein Zwang gilt
sudo -u www-data php8.4 occ twofactor_totp:set-secret-verification-status false --uid alice
```

Beide Wege greifen nur, solange der Zwang für dieses Konto nicht gilt — der
Zwang wird zuerst geprüft. Bei erzwungenem Faktor führt ein als „nicht geprüft"
markierter Schlüssel lediglich wieder auf die Faktor-Seite, auf der derselbe
Schlüssel erneut als QR-Code erscheint. Die Abschaltung über
`two_factor_auth_disabled` erlaubt auch die Provisioning-API mit einem PUT auf
`ocs/v1.php/cloud/users/<uid>` und dem Schlüssel `two_factor_auth_enabled`
(Wert `true` oder `false`); dasselbe Feld erscheint lesend in den Kontodaten.

Aufräumen von Schlüsseln bereits gelöschter Konten:

```bash
sudo -u www-data php8.4 occ twofactor_totp:delete-redundant-secret
```

> **Achtung:** Wird die App `twofactor_totp` deaktiviert, löscht sie über einen
> Hook die Schlüssel **aller** Konten. Ein Deaktivieren „nur kurz zum Testen"
> kostet damit die Einrichtung der gesamten Instanz. Auch das Löschen eines
> Kontos entfernt dessen Schlüssel.

## Zusammenspiel mit der Anmeldebremse

Die Bremse gegen Passwort-Raten zählt fehlgeschlagene **Passwort**-Prüfungen je
Kombination aus IP-Adresse und Anmeldename. Die ersten Versuche sind frei,
danach wächst eine Wartezeit, die das Anmeldeformular als Restzeit anzeigt.
Voraussetzung für die richtige Zählung hinter einem Reverse-Proxy ist ein
korrektes `trusted_proxies`, siehe
[Sicherheit und Setup-Warnungen](security-hardening.md).

Für den zweiten Faktor ist die Reihenfolge entscheidend:

1. Falsches Passwort — der Versuch wird gezählt, die Faktor-Seite wird nie
   erreicht.
2. Richtiges Passwort — der Zähler für dieses Paar aus IP und Anmeldename wird
   geleert, danach folgt die Faktor-Seite.
3. Falscher Code auf der Faktor-Seite — der Versuch wird **nicht** gezählt. Der
   Kern schreibt lediglich eine Warnung ins Protokoll:
   `Two factor verify failed: '<konto>' (Remote IP: '<ip>')`.

Die Bremse schützt also das Passwort, nicht den zweiten Faktor. Nach beliebig
vielen falschen Codes gibt es weder Wartezeit noch Sperre; gegen das Raten des
Codes stehen allein seine sechs Stellen, das enge Zeitfenster und die Sperre
gegen die Wiederverwendung eines Codes. Wer das für zu wenig hält, überwacht
die genannte Protokollzeile, siehe
[Serverprotokoll und Fehlermeldungen](logging.md).

Ist die App `brute_force_protection` aktiviert, stellt sie die Richtlinie für
die Anmeldung und für Freigabe-Passwörter. Die eingebaute Bremse tritt auf
genau diesen beiden Wegen zurück, damit nicht zwei Mechanismen nebeneinander
zählen und zwei verschiedene Wartezeiten nennen. Für alle übrigen Aktionen
bleibt sie zuständig.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Zwang ist eingeschaltet, es wird trotzdem kein Code verlangt | Keine App mit `<two-factor-providers>` aktiv — ohne Faktor greift der Zwang ins Leere | `occ app:enable twofactor_totp`, danach `occ app:list` prüfen |
| Kästchen im Konto gesetzt, beim Anmelden ändert sich nichts | Der Schlüssel wurde nie mit einem Code bestätigt; nur bestätigte Schlüssel zählen | Code eingeben und **Überprüfen** drücken, bis *Geprüft* erscheint |
| Codes werden abgelehnt, obwohl die App sie anzeigt | Uhr von Server oder Endgerät weicht um mehr als rund anderthalb Minuten ab | Zeitsynchronisierung auf dem Server und dem Endgerät prüfen |
| Derselbe Code wird ein zweites Mal nicht angenommen | Der zuletzt geprüfte Code ist gespeichert und für die Wiederverwendung gesperrt | Den nächsten Code abwarten |
| Kein QR-Code, Einrichtung schlägt fehl | `php8.4-imagick` fehlt, die App verlangt die Erweiterung | Erweiterung installieren, PHP-FPM neu laden |
| Desktop- oder Mobil-Client meldet Anmeldefehler, der Browser funktioniert | Der Client sendet das Kontopasswort; das ist bei aktivem zweitem Faktor gesperrt (Protokoll: `Login failed: …`) | App-Passwort erstellen und im Client statt des Kontopassworts eintragen |
| Alter Client gelangt trotz aktivem Faktor an die Daten | Ein vor der Umstellung erzeugtes App-Passwort gilt weiter | Token unter **Persönlich → Sicherheit → App-Passwörter / Token** entziehen |
| Ausgenommene Gruppe wird trotzdem zum Faktor gezwungen | `enforce_2fa_excluded_groups` enthält keine gültige JSON-Liste oder einen falsch geschriebenen Gruppennamen | `occ config:app:get core enforce_2fa_excluded_groups` prüfen und neu setzen |
| `two_factor_auth_disabled` für ein Konto wirkt nicht | Der Zwang gilt für dieses Konto und wird zuerst geprüft | Gruppe des Kontos ausnehmen oder den Zwang abschalten |
| Nach dem Deaktivieren von `twofactor_totp` sind alle Einrichtungen weg | Das Deaktivieren löscht über einen Hook sämtliche Schlüssel | Konten neu einrichten lassen; die App nicht zu Testzwecken deaktivieren |
| Mehrere falsche Codes bleiben folgenlos | Die Anmeldebremse zählt nur Passwortversuche, keine Faktor-Versuche | Protokoll auf `Two factor verify failed` überwachen |
