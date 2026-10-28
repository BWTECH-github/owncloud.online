# Standard-Verschlüsselungs-Modul

Die App `encryption` verschlüsselt den Inhalt jeder Datei, bevor owncloud.online
sie auf den Speicher schreibt, und entschlüsselt ihn beim Lesen. Sie ist für
Instanzen gedacht, deren Speicher nicht unter eigener Kontrolle steht: Ein
Hoster, ein S3-Bucket als externer Speicher, eine gestohlene Platte oder ein
Backup zeigt dann nur AES-256-Chiffrat. Gegen einen Angreifer auf dem laufenden
Server hilft sie nicht, denn dort liegt der Schlüssel.

!!! note "Verfügbarkeit"
    Öffentlich im Marketplace, ohne API-Schlüssel. App-ID `encryption`,
    Version 2.0.10. Name im Markt: „Default encryption module“.

!!! warning "Vor dem Einschalten lesen"
    Das Einschalten lässt sich nur zurücknehmen, wenn Sie dabei alle
    Dateiversionen löschen und alle Papierkörbe leeren (siehe
    „Verschlüsselung wieder abschalten“). Sichern Sie vorher Datenverzeichnis,
    Datenbank und
    `config/config.php`. Ohne die Schlüsselablage und den Wert `secret` sind
    verschlüsselte Dateien endgültig verloren.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 oder neuer, Erweiterung `openssl` |
| `config/config.php` | `secret` ist gesetzt und wird nie geändert |
| Speicher | kein S3 als Primärspeicher (siehe „Grenzen und Hinweise“) |

Im Hauptschlüsselbetrieb ist `secret` das Passwort, mit dem der private
Hauptschlüssel verschlüsselt ist. Sichern Sie `config/config.php` deshalb immer
zusammen mit dem Datenverzeichnis.

## Installation

In der Weboberfläche: im App-Menü links oben **Markt** öffnen, die App „Default
encryption module“ aufrufen und **Installieren** klicken.

Auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ market:install encryption
```

`market:install` lädt die App, installiert sie und schaltet sie ein. Ist die App
schon vorhanden und nur abgeschaltet, genügt
`sudo -u www-data php8.4 occ app:enable encryption`.

Die eingeschaltete App verschlüsselt noch nichts. Dazu schalten Sie die
Verschlüsselung wie im nächsten Abschnitt beschrieben ein.

## Einrichtung

### Verschlüsselung einschalten

1. **Einstellungen → Administration → Verschlüsselung** öffnen.
2. Im Abschnitt „Standard-Verschlüsselungs-Modul“ in der Auswahl „Bitte eine
   Verschlüsselungsoption wählen“ den Eintrag **Hauptschlüssel** wählen und
   **Diesen Modus immer verwenden** klicken. Die Seite lädt neu.
3. „Serverseitige Verschlüsselung aktivieren“ anhaken. Es erscheinen
   Warnhinweise. Mit **Verschlüsselung aktivieren** bestätigen.
4. Abmelden und wieder anmelden. Bis dahin meldet die Seite „Die
   Verschlüsselung-App ist aktiviert, aber Deine Schlüssel sind nicht
   initialisiert. Bitte melde Dich nochmals ab und wieder an.“ Danach steht dort
   „Verschlüsselungstyp: Hauptschlüssel“.

Lassen Sie Schritt 2 aus, stellt die App den Hauptschlüssel beim ersten
Seitenaufruf nach dem Einschalten selbst ein.

<figure markdown="span">
  ![Administration, Verschlüsselung mit der Option Serverseitige Verschlüsselung aktivieren](../../assets/screenshots/de/admin-encryption.png)
  <figcaption>Serverseitige Verschlüsselung schalten Sie hier ein. Lesen Sie vorher die Hinweise zu Schlüsseln und Sicherung.</figcaption>
</figure>

Dasselbe auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ config:app:set encryption useMasterKey --value 1
sudo -u www-data php8.4 occ encryption:enable
sudo -u www-data php8.4 occ encryption:status
```

### Vorhandene Dateien verschlüsseln

Ab dem Einschalten verschlüsselt die App jede Datei, die neu geschrieben wird.
Den vorhandenen Bestand verschlüsselt erst dieser Befehl:

```bash
sudo -u www-data php8.4 occ encryption:encrypt-all
```

Während des Laufs schaltet der Befehl die Instanz in den Einzelbenutzermodus
(nur Administratoren können sich anmelden) und den Papierkorb ab. Je nach
Datenmenge dauert das lange. Starten Sie ihn deshalb in `screen` oder `tmux`.
`-y` überspringt die Rückfrage.

Bricht der Lauf hart ab, etwa weil die Verbindung abreißt, bleiben beide
Einstellungen stehen. So nehmen Sie sie zurück:

```bash
sudo -u www-data php8.4 occ config:system:set singleuser --value false --type boolean
sudo -u www-data php8.4 occ app:enable files_trashbin
```

### Optionen

App-Werte der App `encryption`, zu lesen und zu setzen mit
`occ config:app:get encryption <schlüssel>` und
`occ config:app:set encryption <schlüssel> --value <wert>`. Die meisten übrigen
Werte setzt die App selbst. Ändern Sie diese nicht.

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `useMasterKey` | `1` nach der Einrichtung | Hauptschlüsselbetrieb |
| `encryptHomeStorage` | `1` | `0` verschlüsselt nur externe Speicher, nicht den Hauptspeicher |
| `hsm.url` | leer | Adresse eines externen HSM-Dienstes; der Dienst gehört nicht zur App. Ist der Wert gesetzt, stellt die App `crypto.engine` auf `hsm`. Löschen Sie `hsm.url` später, löschen Sie auch `crypto.engine` (`occ config:app:delete encryption crypto.engine`). |
| `hsm.jwt.secret` | `secret` | gemeinsames Geheimnis mit dem HSM-Dienst; im HSM-Betrieb einen eigenen Wert setzen |

Werte in `config/config.php`, die die App auswertet:

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `secret` | bei der Installation erzeugt | Passwort des privaten Hauptschlüssels |
| `cipher` | `AES-256-CTR` | erlaubt sind auch `AES-128-CTR`, `AES-256-CFB` und `AES-128-CFB`; bei anderen Werten schreibt die App eine Warnung ins Protokoll und nimmt `AES-256-CTR` |
| `openssl` → `private_key_bits` | `4096` | Schlüssellänge neuer Schlüsselpaare |

### Kommandozeile

Befehle der App:

| Befehl | Zweck |
| --- | --- |
| `encryption:recreate-master-key [-y]` | entschlüsselt alle Dateien, erzeugt einen neuen Hauptschlüssel und verschlüsselt wieder; danach müssen sich alle Konten neu anmelden |
| `encryption:fix-encrypted-version <konto> [-p <pfad>] [-i <anzahl>]` | repariert Dateien, die sich wegen „Bad Signature“ nicht mehr lesen lassen; freigegebene Dateien reparieren Sie beim Eigentümer |
| `encryption:migrate [<konto> …]` | stellt die Schlüsselablage der Vorgänger-App `files_encryption` auf das heutige Layout um; ohne Angabe für alle Konten |
| `encryption:hsmdaemon`, `encryption:hsmdaemon:decrypt` | nur mit einem eingetragenen HSM-Dienst (`hsm.url`) |

Aus dem Kern stammen `encryption:enable`, `encryption:disable`,
`encryption:status`, `encryption:list-modules`,
`encryption:set-default-module`, `encryption:encrypt-all`,
`encryption:decrypt-all`, `encryption:show-key-storage-root` und
`encryption:change-key-storage-root`.

## Nutzung

Im Hauptschlüsselbetrieb müssen Anwender nichts tun. Unter **Einstellungen →
Persönlich → Verschlüsselung** steht im Abschnitt
„owncloud.online-Basisverschlüsselungsmodul“: „Die serverseitige
Verschlüsselung ist aktiv und Deine Dateien werden transparent verschlüsselt. Es
sind keine persönlichen Einstellungen erforderlich.“ Freigaben, öffentliche
Links, Versionen, Papierkorb und die Clients arbeiten wie ohne Verschlüsselung.

## Verschlüsselung wieder abschalten

```bash
sudo -u www-data php8.4 occ encryption:decrypt-all
sudo -u www-data php8.4 occ versions:cleanup
sudo -u www-data php8.4 occ trashbin:cleanup
sudo -u www-data php8.4 occ encryption:disable
sudo -u www-data php8.4 occ app:disable encryption
```

`encryption:decrypt-all` entschlüsselt nur die aktuellen Dateien. Versionen und
Papierkorb bleiben verschlüsselt, und `encryption:disable` bricht ab, solange es
verschlüsselte Dateien gibt: „The system still has encrypted files. Please
decrypt them all before disabling encryption.“ Der Rückweg kostet deshalb alle
Dateiversionen und den Inhalt aller Papierkörbe. Wie `encrypt-all` schaltet auch
`decrypt-all` während des Laufs den Einzelbenutzermodus ein und den Papierkorb
ab; nach einem harten Abbruch nehmen Sie beides wie oben beschrieben zurück.

Schalten Sie die App erst ganz am Ende ab. Ohne sie lassen sich verschlüsselte
Reste nicht mehr lesen.

## Grenzen und Hinweise

- **Nur der Inhalt.** Dateinamen, Ordnerstruktur, Freigaben und alles in der
  Datenbank bleiben unverschlüsselt. Verschlüsselte Dateien belegen etwas mehr
  Platz.
- **Schlüsselablage sichern.** Die Schlüssel liegen unter
  `<datenverzeichnis>/files_encryption/` und
  `<datenverzeichnis>/<konto>/files_encryption/`. Wurde der Ablageort mit
  `encryption:change-key-storage-root` verlegt, liegen sie dort;
  `occ encryption:show-key-storage-root` zeigt ihn. Eine Sicherung ohne diese
  Verzeichnisse und ohne `config/config.php` ist wertlos.
- **S3 als Primärspeicher.** Mit der App `files_primary_s3` lässt sich die
  Verschlüsselung nicht einschalten. `occ encryption:enable` meldet dann
  „Storage encryption is not compatible with S3 Object Storage.“ Nutzen Sie dort
  die serverseitige Verschlüsselung des Buckets (`serversideencryption`).
- **Anmeldung ohne Passwort.** OAuth2, OpenID Connect und SAML/SSO liefern kein
  Anmeldepasswort. Mit ihnen funktioniert nur der Hauptschlüsselbetrieb.
- **Office.** ONLYOFFICE und Collabora Online öffnen verschlüsselte Dateien nur
  im Hauptschlüsselbetrieb.
- **Schlüssel je Konto.** Ältere Installationen, die noch Schlüssel je Konto
  verwenden, laufen weiter. Unterstützt wird dieser Betrieb nur noch für
  bestehende Installationen; die Einstellungsseite bietet nur den
  Hauptschlüssel an. Den Wiederherstellungsschlüssel gibt es nur in diesem
  alten Betrieb.
- **Umzug auf einen anderen Server.** Die App liest den verschlüsselten Bestand
  einer umgezogenen Instanz weiter, wenn `config/config.php` (`secret`,
  `instanceid`) und `files_encryption/` unverändert mitkommen. Bekommt ein
  Speicher dabei eine neue Speicher-ID, scheitern Dateien mit „Bad Signature“.
  Abhilfe: `occ encryption:fix-encrypted-version <konto> -p <pfad>`. Was nach
  dem Umzug geschrieben wird, kann eine ältere Instanz nicht mehr lesen.

Mehr zu Sicherung und Betrieb: [Verschlüsselung](../../administration/encryption.md)
und [Backups und Updates](../../administration/backups-updates.md).

## Fehlersuche

| Meldung | Ursache | Abhilfe |
| --- | --- | --- |
| „Die Verschlüsselung-App ist aktiviert, aber Deine Schlüssel sind nicht initialisiert. …“ | Die Schlüssel dieser Sitzung sind nicht geladen. | Ab- und wieder anmelden |
| Download bricht ab, im Protokoll „Bad Signature“ (Oberfläche: „ungültige Signatur“) | Die gespeicherte Versionsangabe passt nicht zum Inhalt, etwa nach einer Rücksicherung oder einem Umzug. | `occ encryption:fix-encrypted-version <konto> -p <pfad>` |
| Protokoll: „Can not get secret …“ | `secret` fehlt in `config/config.php`. | Wert aus der Sicherung wiederherstellen |
| `encryption:recreate-master-key` meldet „Master key is not enabled.“ | `useMasterKey` steht nicht auf `1`. | Hauptschlüssel wählen (siehe Einrichtung) |
| HTTP 403 oder 503 mit „Encryption not ready: Private Key missing …“ bei OAuth2 oder OpenID Connect | Der Betrieb mit Schlüsseln je Konto braucht das Anmeldepasswort. | Hauptschlüsselbetrieb verwenden |
| Protokoll: „Unsupported cipher (…) defined in config.php …“ | `cipher` in `config/config.php` wird nicht unterstützt. | Einen der vier erlaubten Werte eintragen |
| Dateien im Hauptspeicher bleiben unverschlüsselt | `encryptHomeStorage` steht auf `0`. | Auf `1` setzen, danach `occ encryption:encrypt-all` |
