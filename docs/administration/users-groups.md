# Benutzer und Gruppen

owncloud.online verwaltet Konten und Gruppen in der Weboberfläche unter
*Einstellungen → Benutzer* und auf der Kommandozeile über `occ`. Beide Wege
schreiben in dieselben Tabellen, es gibt keine getrennten Datenbestände. Diese
Seite beschreibt, was ein Konto ausmacht, wie Gruppen und
Gruppen-Administratoren wirken, wie Speicherkontingente aufgelöst werden und was
beim Löschen eines Kontos tatsächlich passiert.

## Die Verwaltungsseite

Der Menüpunkt *Benutzer* (`/settings/users`) erscheint für Administratoren und
für Gruppen-Administratoren (`lib/private/legacy/app.php`). Links steht die
Gruppenliste mit den Einträgen *Jeder*, *Administratoren*, den vorhandenen
Gruppen und *Gruppe hinzufügen*; rechts das Anlegeformular und die Kontenliste.

*Jeder* ist keine Gruppe, sondern ein Filter über alle Konten
(`data-gid="_everyone"` in `settings/templates/users/part.grouplist.php`).

Welche Spalten die Kontenliste zeigt, steuern die Ankreuzfelder unter
*Einstellungen* am unteren Rand der linken Spalte. Jedes davon schreibt einen
App-Konfigurationswert der App `core`:

| Ankreuzfeld | Schlüssel (App `core`) | Standard |
| --- | --- | --- |
| Option Aktiviert/Deaktiviert anzeigen | `umgmt_show_is_enabled` | `false` |
| Speicherort anzeigen | `umgmt_show_storage_location` | `false` |
| Erstellungszeitpunkt anzeigen | `umgmt_show_creation_time` | `false` |
| Letzte Anmeldung anzeigen | `umgmt_show_last_login` | `false` |
| Benutzer-Backend anzeigen | `umgmt_show_backend` | `false` |
| E-Mail-Adresse anzeigen | `umgmt_show_email` | `false` |
| Passwort für neue Nutzer anlegen | `umgmt_set_password` | `false` |
| Passwortfeld anzeigen | `umgmt_show_password` | `true` |
| Kontingent-Feld anzeigen | `umgmt_show_quota` | `true` |

Die Werte gelten instanzweit, nicht pro Administrator. Sie lassen sich auch
direkt setzen:

```bash
sudo -u www-data php8.4 occ config:app:set core umgmt_show_last_login --value true
```

## Anmeldename und Anzeigename

Ein Konto hat zwei Namen, die oft verwechselt werden.

| | Anmeldename (uid) | Anzeigename |
| --- | --- | --- |
| Zweck | Identität des Kontos: Anmeldung, Name des Home-Verzeichnisses, Eigentümer von Dateien und Freigaben | Beschriftung in der Oberfläche, Freigabedialog, E-Mails |
| Spalte in der Liste | *Benutzername* | *Vollständiger Name* |
| Änderbar | nein | ja |
| Eindeutig | ja | nein |

Der Anmeldename wird beim Anlegen geprüft (`lib/private/User/Manager.php`):
erlaubt sind `a-z`, `A-Z`, `0-9` sowie `+_.@-'`, mindestens 3 und höchstens 64
Zeichen; führende oder abschließende Leerzeichen werden abgewiesen. Umlaute und
andere Sonderzeichen sind nicht zulässig. Die Zeichenklasse lässt zusätzlich das
Leerzeichen innerhalb des Namens durch, obwohl die Fehlermeldung es nicht nennt
— vermeiden Sie es trotzdem.

Reserviert und damit als Anmeldename gesperrt sind die Namen, die im
Datenverzeichnis neben den Konten liegen (`lib/public/User/Constants.php`):
`avatars`, `meta`, `files_external`, `files_encryption`, `.htaccess`,
`.ocdata`, `htaccesstest.txt`, `owncloud.db`, `owncloud.log`, `index.html`.

Es gibt keinen Weg, den Anmeldenamen später zu ändern — weder in der Oberfläche
noch als `occ`-Befehl. Wer umbenennen muss, legt ein neues Konto an und zieht
die Daten mit `files:transfer-ownership` um (siehe unten).

Anzeigenamen sind nicht eindeutig: zwei Konten dürfen denselben vollständigen
Namen tragen. Steht `allow_user_to_change_display_name` in `config/config.php`
auf `false`, dürfen nur Administratoren und Gruppen-Administratoren den
Anzeigenamen ändern, normale Konten nicht mehr
(`lib/private/User/User.php`, `canChangeDisplayName`).

## Konten anlegen

In der Oberfläche füllen Sie *Benutzername* und je nach Einstellung *Passwort*
oder *E-Mail* aus und wählen optional Gruppen. Passwortfeld und E-Mail-Feld
schließen einander aus: Ist *Passwort für neue Nutzer anlegen* gesetzt, wird das
E-Mail-Feld ausgeblendet und umgekehrt (`settings/js/users/users.js`).

Bleibt das Passwort leer und ist eine E-Mail-Adresse angegeben, erzeugt der
Server ein Zufallspasswort und verschickt eine Einladung mit einem Link zum
Setzen des Passworts (`settings/Controller/UsersController.php`, `create`).
Ohne funktionierenden Mailversand kommt diese Nachricht nicht an, und das Konto
bleibt unbenutzbar.

Auf der Kommandozeile:

```bash
# interaktiv, mit Passwortabfrage
sudo -u www-data php8.4 occ user:add jdoe

# nicht-interaktiv, Passwort aus der Umgebung
OC_PASS='Beispielpasswort' sudo -u www-data php8.4 occ user:add jdoe \
  --password-from-env \
  --display-name "Jane Doe" \
  --email jane.doe@example.com \
  --group projekt-a --group vertrieb
```

`user:add` legt in `--group` genannte Gruppen an, falls sie noch nicht
existieren. Ohne `--password-from-env` und ohne interaktive Sitzung bricht der
Befehl ab, weil er kein Passwort erfragen kann.

Beim ersten Anmelden wird das Home-Verzeichnis aus dem Vorlagenordner befüllt
(`skeletondirectory`, siehe [Konfiguration](config-reference.md)).

## Konten ändern

Anzeigename und E-Mail-Adresse ändern Sie in der Liste über die Stiftsymbole
neben *Vollständiger Name* und *E-Mail* oder auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ user:modify jdoe displayname "Jane Doe"
sudo -u www-data php8.4 occ user:modify jdoe email jane.doe@example.com
```

`user:modify` kennt genau diese beiden Schlüssel (`displayname`, `email`); eine
ungültige E-Mail-Adresse wird abgewiesen.

Passwort zurücksetzen:

```bash
# neues Passwort direkt setzen
OC_PASS='NeuesPasswort' sudo -u www-data php8.4 occ user:resetpassword jdoe \
  --password-from-env

# stattdessen einen Rücksetz-Link erzeugen und anzeigen
sudo -u www-data php8.4 occ user:resetpassword jdoe --output-link

# Rücksetz-Link an die hinterlegte Adresse schicken (und ausgeben)
sudo -u www-data php8.4 occ user:resetpassword jdoe --send-email
```

Ist die App `encryption` aktiv, warnt der interaktive Modus ausdrücklich: Ein
Zurücksetzen des Passworts führt dann zu Datenverlust, sofern kein
Wiederherstellungsschlüssel greift. Siehe
[Verschlüsselung](encryption.md).

Einzelne Kontoeinstellungen lesen und schreiben:

```bash
# alle Einstellungen eines Kontos
sudo -u www-data php8.4 occ user:setting jdoe

# nur die einer App
sudo -u www-data php8.4 occ user:setting jdoe core

# einzelnen Wert lesen, setzen, löschen
sudo -u www-data php8.4 occ user:setting jdoe core lang
sudo -u www-data php8.4 occ user:setting jdoe core lang --value de
sudo -u www-data php8.4 occ user:setting jdoe core lang --delete
```

`--update-only` schreibt nur, wenn der Schlüssel schon existiert;
`--error-if-not-exists` lässt `--delete` fehlschlagen, wenn nichts zu löschen
war; `--default-value` liefert beim Lesen einen Ersatzwert statt eines Fehlers.

## Konten deaktivieren

Ein deaktiviertes Konto kann sich nicht mehr anmelden — weder über die
Weboberfläche noch über die Authentifizierungsmodule für WebDAV und Clients
(`lib/private/User/Session.php`). Dateien, Freigaben und Gruppenmitgliedschaften
bleiben unverändert bestehen. Das ist der richtige Schritt für Austritte,
Verdachtsfälle und alles, was reversibel bleiben soll.

```bash
sudo -u www-data php8.4 occ user:disable jdoe
sudo -u www-data php8.4 occ user:enable jdoe
```

In der Oberfläche geschieht das über die Spalte *Aktiviert*, die zuvor über
*Option Aktiviert/Deaktiviert anzeigen* eingeblendet werden muss. Das eigene
Konto lässt sich dort weder deaktivieren noch löschen.

Kandidaten finden:

```bash
# Konten, die sich seit 180 Tagen nicht angemeldet haben
sudo -u www-data php8.4 occ user:inactive 180

# letzte Anmeldung eines einzelnen Kontos
sudo -u www-data php8.4 occ user:lastseen jdoe
```

## Konten löschen

Das Löschen ist endgültig und wird nicht zurückgefragt, sobald es angestoßen
ist. `lib/private/User/User.php` (`delete`) und die daran hängenden Hooks
entfernen der Reihe nach:

| Was | Wirkung |
| --- | --- |
| Gruppenmitgliedschaften | Konto wird aus allen Gruppen entfernt |
| Gruppen-Administratorrechte | Einträge in `group_admin` fallen weg (`lib/private/SubAdmin.php`) |
| Kontoeinstellungen | alle Werte des Kontos in `preferences` |
| Externer Speicher | persönliche Einbindungen werden gelöscht, aus globalen Einbindungen wird das Konto ausgetragen |
| Home-Verzeichnis | wird vollständig entfernt — samt Papierkorb und Dateiversionen |
| Kommentare | Beiträge und Lesemarken des Kontos |
| Konto- und Account-Datensatz | zuletzt, damit ein abgebrochener Lauf wiederholbar bleibt |

Bei den Freigaben greift `OC\Share20\Hooks::post_deleteUser`
(`lib/private/Share20/Hooks.php`). Gelöscht werden:

- Benutzer-Freigaben, die dem Konto gehören **oder** an das Konto gingen,
- Gruppen-Freigaben, die dem Konto gehören, sowie dessen persönliche Ableitungen
  von Gruppen-Freigaben,
- öffentliche Links, die das Konto besitzt oder angelegt hat,
- Freigaben zu und von entfernten Instanzen (`apps/files_sharing/lib/Hooks.php`).

Ein Empfänger verliert damit sofort den Zugriff auf alles, was das gelöschte
Konto geteilt hatte. Sollen Daten erhalten bleiben, übertragen Sie sie **vor**
dem Löschen:

```bash
sudo -u www-data php8.4 occ files:transfer-ownership jdoe jsmith
sudo -u www-data php8.4 occ user:delete jdoe
```

`files:transfer-ownership` verschiebt Dateien und Ordner und nimmt die Freigaben
mit; `--path` grenzt auf einen Unterordner ein.

Bleibt nach einem gescheiterten Lauf ein verwaister Datenbestand zurück, räumt

```bash
sudo -u www-data php8.4 occ user:delete jdoe --force
```

auch dann auf, wenn das Konto selbst nicht mehr auffindbar ist.

## Gruppen

Gruppen dienen der Rechtevergabe und dem Teilen. Sie werden über die linke
Spalte der Verwaltungsseite oder auf der Kommandozeile gepflegt:

```bash
sudo -u www-data php8.4 occ group:add projekt-a
sudo -u www-data php8.4 occ group:add-member projekt-a -m jdoe -m jsmith
sudo -u www-data php8.4 occ group:list-members projekt-a
sudo -u www-data php8.4 occ group:remove-member projekt-a -m jsmith
sudo -u www-data php8.4 occ group:delete projekt-a
```

Zwei Eigenheiten sind wichtig:

- Die Gruppe `admin` ist geschützt und lässt sich nicht löschen
  (`lib/private/Group/Group.php`). Wer in ihr Mitglied ist, ist Administrator
  der Instanz.
- Gruppen lassen sich nicht umbenennen. Der Gruppenname ist zugleich die
  Kennung; es gibt weder einen `occ`-Befehl noch eine Schaltfläche dafür.

Beim Löschen einer Gruppe entfernt der Server alle Freigaben an diese Gruppe
mitsamt den daraus abgeleiteten persönlichen Freigaben der Mitglieder
(`lib/private/Share20/DefaultShareProvider.php`, `groupDeleted`). Wird nur ein
Mitglied aus der Gruppe entfernt, bleibt die Gruppen-Freigabe bestehen; nur der
Zugang dieses einen Kontos verschwindet.

## Gruppen-Administratoren

Ein Gruppen-Administrator verwaltet die Konten seiner Gruppen, ohne
Administrator der Instanz zu sein. Zuweisen können Sie ihn nur in der
Oberfläche, in der Spalte *Gruppenadministrator für* — die Spalte erscheint nur
für echte Administratoren.

Der Rahmen ergibt sich aus `lib/private/SubAdmin.php` und
`settings/Controller/UsersController.php`:

| Darf | Darf nicht |
| --- | --- |
| Konten in den eigenen Gruppen anlegen, ändern, deaktivieren und löschen | Konten außerhalb der eigenen Gruppen anfassen |
| Speicherkontingente dieser Konten setzen | Administratoren bearbeiten |
| Anzeigenamen dieser Konten ändern, auch wenn `allow_user_to_change_display_name` auf `false` steht | sich selbst oder andere zum Gruppen-Administrator der Gruppe `admin` machen |

Legt ein Gruppen-Administrator ein Konto ohne Gruppenangabe an, landet es
automatisch in seinen eigenen Gruppen.

Das ganze Verfahren lässt sich abschalten. Mit

```php
'allow_subadmins' => false,
```

in `config/config.php` gilt niemand mehr als Gruppen-Administrator; nur echte
Administratoren behalten Zugriff auf die Verwaltungsseite.

Zum Prüfen, wer wo Mitglied ist:

```bash
sudo -u www-data php8.4 occ user:list-groups jdoe
```

## Speicherkontingent

Das Kontingent hängt **am Konto**, nicht an der Gruppe. Ein Kontingent je Gruppe
gibt es in owncloud.online nicht; wer nach Abteilungen staffeln will, setzt den
Wert je Konto oder arbeitet mit dem Standardwert.

Aufgelöst wird in dieser Reihenfolge (`lib/private/legacy/util.php`,
`getUserQuota`):

1. der Wert am Konto, sofern er nicht `default` ist,
2. sonst `default_quota` der App `files`,
3. ist auch dieser `none`, gilt kein Limit.

Zwei App-Konfigurationswerte steuern die Auswahllisten:

| Schlüssel | App | Standard | Bedeutung |
| --- | --- | --- | --- |
| `default_quota` | `files` | `none` | Vorgabe für alle Konten, die auf *Standard* stehen |
| `quota_preset` | `files` | `1 GB, 5 GB, 10 GB` | Werte, die im Auswahlfeld angeboten werden |

```bash
sudo -u www-data php8.4 occ config:app:set files default_quota --value "10 GB"
sudo -u www-data php8.4 occ config:app:set files quota_preset --value "1 GB, 5 GB, 10 GB, 100 GB"
```

Das Kontingent eines einzelnen Kontos setzen Sie in der Spalte *Quota*: neben
*Standard* und *Unbegrenzt* stehen die Vorgabewerte und *Andere …* für eine
freie Eingabe wie `512 MB` oder `12 GB`. Gespeicherte Werte prüfen Sie mit:

```bash
sudo -u www-data php8.4 occ user:list --attributes uid --attributes quota
```

## Benutzerdefinierte Gruppen

Die App `customgroups` ergänzt die zentral gepflegten Gruppen um Gruppen, die
Konten selbst anlegen — gedacht zum Teilen, nicht zur Rechtevergabe. Sie liegen
unter *Einstellungen → Benutzerdefinierte Gruppe*; im Freigabedialog erscheinen
sie wie normale Gruppen.

Innerhalb einer solchen Gruppe gibt es zwei Rollen: *Gruppenbesitzer* darf
Mitglieder aufnehmen und entfernen, umbenennen, löschen und Rollen vergeben;
*Mitglied* darf mit der Gruppe teilen, die Mitgliederliste sehen und die Gruppe
verlassen. Administratoren sehen und ändern alle benutzerdefinierten Gruppen.

Zwei Schalter unter *Einstellungen → Administration → Teilen*:

| Ankreuzfeld | Schlüssel (App `customgroups`) | Standard |
| --- | --- | --- |
| Nur Gruppen-Administratoren sind berechtigt benutzerdefinierte Gruppen zu erstellen | `only_subadmin_can_create` | `false` |
| Das Erstellen von mehreren Gruppen mit dem selben Namen erlauben | `allow_duplicate_names` | `false` |

```bash
sudo -u www-data php8.4 occ config:app:set customgroups only_subadmin_can_create --value true
```

Ist `shareapi_only_share_with_group_members` der App `core` aktiv, kann ein
Mitglied nur Konten aufnehmen, mit denen es mindestens eine Gruppe teilt
(`lib/Service/MembershipHelper.php` der App).

Wichtig für die Fehlersuche: Das Gruppen-Backend der App meldet sich nur für den
Bereich „sharing" zuständig (`isVisibleForScope`). Benutzerdefinierte Gruppen
erscheinen deshalb **nicht** in `occ group:list` und **nicht** in
`occ user:list-groups`, obwohl es echte Gruppen mit dem Kennungspräfix
`customgroup_` sind.

Das Repository der App: <https://github.com/BWTECH-github/customgroups>.

## occ-Befehle im Überblick

| Befehl | Argumente und Optionen |
| --- | --- |
| `user:add` | `uid`; `--password-from-env`, `--display-name`, `--email`, `-g/--group` (mehrfach) |
| `user:delete` | `uid`; `-f/--force` |
| `user:disable` / `user:enable` | `uid` |
| `user:modify` | `uid key value` — Schlüssel: `displayname`, `email` |
| `user:resetpassword` | `user`; `--password-from-env`, `--send-email`, `--output-link` |
| `user:setting` | `uid [app] [key]`; `--value`, `--update-only`, `--delete`, `--error-if-not-exists`, `--default-value`, `--ignore-missing-user` |
| `user:list` | `[suchmuster]`; `-a/--attributes` (mehrfach), `-s/--show-all-attributes`, `--output` |
| `user:list-groups` | `uid`; `--output` |
| `user:lastseen` | `uid` |
| `user:inactive` | `days`; `--output` |
| `user:report` | keine |
| `user:sync` | `[backend-class]`; `-l/--list`, `-u/--uid`, `-s/--seenOnly`, `-c/--showCount`, `-m/--missing-account-action` (`disable`, `remove`) |
| `user:home:list-dirs` | `--output` |
| `user:home:list-users` | `[path]`; `--all`, `--output` |
| `user:move-home` | `user_id new_location` |
| `group:add` | `group` |
| `group:delete` | `group` |
| `group:add-member` | `group`; `-m/--member` (mehrfach) |
| `group:remove-member` | `group`; `-m/--member` (mehrfach) |
| `group:list` | `[suchmuster]`; `--output` |
| `group:list-members` | `group`; `--output` |

`--output` versteht `plain` (Vorgabe), `json` und `json_pretty`. Für `user:list`
sind als Attribute möglich: `uid`, `displayName`, `email`, `quota`, `enabled`,
`lastLogin`, `creationTime`, `home`, `backend`, `cloudId`, `searchTerms`; ohne
Angabe wird `displayName` ausgegeben.

Ein Überblick über den Bestand:

```bash
sudo -u www-data php8.4 occ user:report
sudo -u www-data php8.4 occ user:list --show-all-attributes --output json_pretty
```

`user:sync` gleicht die Konten eines Backends mit der `accounts`-Tabelle ab;
`--list` zeigt die dafür in Frage kommenden Backend-Klassen. Achtung:
`--missing-account-action remove` löscht Konten samt Daten, sobald sie im
Backend fehlen — im Zweifel `disable` wählen und die Liste erst prüfen.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| `occ user:add` bricht mit „Interactive input or --password-from-env is needed" ab | Aufruf ohne Terminal, etwa aus einem Skript | `OC_PASS` setzen und `--password-from-env` angeben |
| Anmeldename mit Umlaut oder Punkt am Anfang wird abgewiesen | Zeichenprüfung in `lib/private/User/Manager.php` | nur `a-z`, `A-Z`, `0-9`, `+_.@-'`, 3 bis 64 Zeichen verwenden |
| Neues Konto bekommt keine Einladungsmail | bei gesetztem *Passwort für neue Nutzer anlegen* wird das E-Mail-Feld ausgeblendet — oder der Mailversand ist nicht eingerichtet | Ankreuzfeld abwählen; SMTP prüfen, siehe [Linux-Server](../installation/linux-server.md) |
| Konto gelöscht, Dateien und Freigaben sind weg | `user:delete` entfernt Home-Verzeichnis, Papierkorb, Versionen und alle Freigaben | vorher `files:transfer-ownership`; Wiederherstellung nur aus dem Backup |
| Benutzerdefinierte Gruppe fehlt in `occ group:list` und `occ user:list-groups` | das Backend ist nur für den Bereich „sharing" sichtbar | über *Einstellungen → Benutzerdefinierte Gruppe* prüfen |
| Gruppe soll umbenannt werden | Gruppenname ist die Kennung, es gibt keine Umbenennung | neue Gruppe anlegen, Mitglieder und Freigaben umziehen, alte löschen |
| `occ group:delete admin` schlägt fehl | die Gruppe `admin` ist fest geschützt | Mitglieder einzeln entfernen |
| Spalte *Gruppenadministrator für* fehlt | Spalte wird nur für Administratoren gerendert, oder `allow_subadmins` steht auf `false` | als Administrator anmelden, Wert in `config.php` prüfen |
| Konto kann seinen Anzeigenamen nicht ändern | `allow_user_to_change_display_name` steht auf `false` | Wert entfernen oder Änderung durch einen Administrator vornehmen |
| `occ user:setting <uid> files quota --value "5 GB"` bleibt wirkungslos | maßgeblich ist der Wert am Konto in der `accounts`-Tabelle; die Einstellung wird erst bei einem Abgleich des Kontos ausgewertet | Kontingent in der Spalte *Quota* setzen und mit `occ user:list -a uid -a quota` prüfen |
| Passwort zurückgesetzt, Dateien nicht mehr lesbar | die App `encryption` war aktiv | Wiederherstellungsschlüssel verwenden, siehe [Verschlüsselung](encryption.md) |
| Deaktiviertes Konto belegt weiter Speicher | Deaktivieren löscht nichts, es sperrt nur die Anmeldung | Daten übertragen und Konto löschen |
