# Externe Speicher

owncloud.online kann fremde Speicherziele — SMB-Freigaben, SFTP- und
WebDAV-Server, S3-Buckets — als Ordner in den Dateibaum einhängen. Die Dateien
bleiben dabei auf dem Quellsystem, owncloud.online hält nur die Metadaten und
reicht Zugriffe durch. Zuständig ist die App `files_external`; weitere Backends
kommen aus den Apps `files_external_s3` und `wnd`.

## Funktion einschalten

Die App `files_external` gehört zur Auslieferung und ist standardmäßig aktiv.
Die Funktion selbst ist es nicht: Sie hängt am App-Wert
`enable_external_storage` der App `core`, dessen Vorgabe `no` lautet
(`apps/files_external/lib/Panels/Admin.php`).

Über die Oberfläche: **Einstellungen → Administration → Speicher**, Abschnitt
*Externer Speicher*, Ankreuzfeld *Externen Speicher aktivieren*.

Über die Kommandozeile:

```bash
sudo -u www-data php8.4 occ config:app:set core enable_external_storage --value yes
```

Erst danach erscheint in der Dateiansicht der Navigationseintrag *Externer
Speicher* (`apps/files_external/appinfo/app.php`). Wird die Funktion wieder
abgeschaltet, verschwinden alle eingebundenen Speicher aus allen Konten; die
Konfiguration bleibt in der Datenbank erhalten.

## Speicher-Backends

Ein Backend ist der Treiber für ein Protokoll. Der Bezeichner in der zweiten
Spalte ist der Wert, den `occ files_external:create` erwartet — er wird exakt
und unterscheidend nach Groß- und Kleinschreibung nachgeschlagen
(`StoragesBackendService::getBackend()`).

| Anzeigename | Bezeichner | Geliefert von | Passende Authentifizierungsschemata |
| --- | --- | --- | --- |
| WebDAV | `dav` | `files_external` | `password` |
| owncloud.online | `owncloud` | `files_external` | `password` |
| SFTP | `sftp` | `files_external` | `password`, `publickey` |
| Google Drive | `googledrive` | `files_external` | `oauth2` |
| SFTP mit dem Login über einen geheimen Schlüssel | `\OC\Files\Storage\SFTP_Key` | `files_external` | `publickey` |
| SMB Persönlich (einzelne Datei-IDs) | `smb` | `files_external` | `password` |
| SMB Kollaborative (freigegebene Datei-IDs) | `smb-coll` | `files_external` | `password::sessioncredentials` |
| SMB / CIFS mit OC-Login | `\OC\Files\Storage\SMB_OC` | `files_external` | `password` |
| Lokal | `local` | `files_external` | `null` |
| Amazon S3 compatible (SDK v3) | `files_external_s3` | `files_external_s3` | `amazons3_accesskey` |
| Windows-Netzlaufwerk | `wnd_custom` | `wnd` | `password`, `wnd_kerberos` |
| Windows-Netzlaufwerk (Kollaborativ) | `wnd_custom_collaborative` | `wnd` | `password`, `wnd_kerberos` |

Anmerkungen, die im Code hinterlegt sind:

* *SFTP mit dem Login über einen geheimen Schlüssel* und *SMB / CIFS mit
  OC-Login* sind als veraltet markiert (`deprecateTo`) und verweisen auf `sftp`
  beziehungsweise `smb`. Neue Einbindungen gehören auf die Nachfolger.
* *Lokal* ist auf die Administration beschränkt und zusätzlich abgeschaltet,
  solange in `config/config.php` nicht
  `'files_external_allow_create_new_local' => true` steht
  (`StoragesBackendChecker::isAllowedAdminBackend()`). Für Konten ist dieses
  Backend unabhängig von jeder Einstellung gesperrt.
* *SMB Kollaborative* und *Windows-Netzlaufwerk (Kollaborativ)* sind ebenfalls
  auf die Administration beschränkt.
* *SMB Kollaborative* verlangt das Schema `password::sessioncredentials`. Kein
  registrierter Mechanismus trägt dieses Schema — die Mechanismen zu
  Benutzername und Passwort tragen `password` —, also wird an diesem Backend
  keine Authentifizierung angeboten. Für kollaborative SMB-Einbindungen ist
  daher *Windows-Netzlaufwerk (Kollaborativ)* aus der App `wnd` der gangbare
  Weg; dieses Backend führt `password` mit.

## Authentifizierungsverfahren

Jeder Mechanismus gehört zu genau einem Schema. Angeboten werden an einem
Backend nur die Mechanismen, deren Schema in der Spalte oben steht
(`StoragesBackendService::getAuthMechanismsByScheme()`).

| Anzeigename | Bezeichner | Schema | Geliefert von |
| --- | --- | --- | --- |
| Keine | `null::null` | `null` | Core |
| eingebaut | `builtin::builtin` | `builtin` | Core |
| Benutzername und Passwort | `password::password` | `password` | Core |
| Anmeldedaten in Sitzung speichern | `password::sessioncredentials` | `password` | Core |
| OAuth1 | `oauth1::oauth1` | `oauth1` | `files_external` |
| OAuth2 | `oauth2::oauth2` | `oauth2` | `files_external` |
| RSA öffentlicher Schlüssel | `publickey::rsa` | `publickey` | `files_external` |
| OpenStack | `openstack::openstack` | `openstack` | `files_external` |
| Rackspace | `openstack::rackspace` | `openstack` | `files_external` |
| Access key | `amazons3::accesskey` | `amazons3_accesskey` | `files_external_s3` |
| Globale Anmeldedaten | `wnd::global_credentials` | `password` | `wnd` |
| Vom Benutzer eingegeben, in der Datenbank gespeichert | `wnd::userprovided` | `password` | `wnd` |
| Anmeldedaten, in der Datenbank gespeichert | `wnd::logincredentials_db` | `password` | `wnd` |
| Fest in der Konfigurationsdatei hinterlegte Anmeldedaten | `wnd::hardcoded_config` | `password` | `wnd` |
| Kerberos | `wnd::kerberos` | `wnd_kerberos` | `wnd` |

Daraus folgt für die Praxis:

* Die vier Passwort-Mechanismen der App `wnd` tragen das Schema `password` und
  stehen deshalb an jedem Backend mit Passwort-Schema zur Verfügung, nicht nur
  an den beiden Windows-Netzlaufwerken. Nur *Kerberos* hat ein eigenes Schema
  und erscheint ausschließlich dort.
* Für `oauth1` und `openstack` bringt diese Auslieferung kein Backend mit. Die
  drei zugehörigen Mechanismen sind registriert, aber an keinem Backend
  auswählbar.
* *Anmeldedaten in Sitzung speichern* legt Benutzername und Passwort
  verschlüsselt in der Sitzung ab, sobald sich das Konto anmeldet. Ohne
  Anmeldung mit Passwort — etwa bei WebDAV-Zugriff mit App-Kennwort, bei
  Single Sign-on oder im Cron-Lauf — sind die Daten nicht vorhanden und der
  Speicher wird als nicht verfügbar eingehängt.

## Einbinden über die Oberfläche

**Einstellungen → Administration → Speicher**, Abschnitt *Externer Speicher*.
Die Tabelle hat je Zeile die Spalten *Ordnername*, *Externer Speicher*,
*Authentifizierung*, *Konfiguration*, *Verfügbar für* — die letzte Spalte gibt
es nur in der Administrationsansicht.

1. Unter *Externer Speicher* das Backend über *Speicher hinzufügen* wählen.
2. *Ordnername* setzen. Das ist der Einhängepunkt im Dateibaum.
3. Unter *Authentifizierung* einen der zum Backend passenden Mechanismen
   wählen und die Felder unter *Konfiguration* ausfüllen.
4. Unter *Verfügbar für* Konten oder Gruppen eintragen. Bleibt das Feld leer,
   gilt der Speicher für alle.

Über das Zahnrad einer Zeile öffnen sich die *Erweiterten Einstellungen* mit
den Einhängeoptionen. Sie entsprechen diesen Schlüsseln, die auch
`occ files_external:option` setzt:

| Beschriftung | Schlüssel | Vorgabe |
| --- | --- | --- |
| Verschlüsselung aktivieren | `encrypt` | `true` |
| Schreibgeschützt festlegen | `read_only` | `false` |
| Vorschau aktivieren | `previews` | `true` |
| Freigaben einschalten | `enable_sharing` | `false` |
| Auf Änderungen überprüfen | `filesystem_check_changes` | `1` |
| Kompatibilität mit MAC NFD-Kodierung (langsam) | `encoding_compatibility` | `false` |

*Verschlüsselung aktivieren* wird nur eingeblendet, wenn auf der Instanz
Verschlüsselung aktiv ist. *Freigaben einschalten* erscheint in der
Administrationsansicht immer, in den persönlichen Einstellungen nur, solange
`allow_user_mount_sharing` auf `yes` steht.

## Einbinden über occ

Alle Unterbefehle stammen aus `apps/files_external/lib/Command/`. Sie stehen
nur zur Verfügung, solange die App `files_external` aktiv ist.

Erst die verfügbaren Bezeichner ansehen — der Befehl listet Backends,
Mechanismen und deren Parameter:

```bash
sudo -u www-data php8.4 occ files_external:backends
sudo -u www-data php8.4 occ files_external:backends storage smb
sudo -u www-data php8.4 occ files_external:backends authentication
```

Dann die Einbindung anlegen. Ohne `--user` entsteht ein Speicher für das ganze
System, mit `--user` ein persönlicher:

```bash
sudo -u www-data php8.4 occ files_external:create \
  /Projekte smb password::password \
  -c host=fileserver.example.com -c share=projekte -c domain=EXAMPLE \
  -c user=svc_owncloud -c password=geheim
```

`--dry` legt nichts an, sondern zeigt nur, was entstehen würde. Der Befehl gibt
bei Erfolg die Speicher-ID aus; alle folgenden Befehle arbeiten mit dieser ID.

Zuständige Konten und Gruppen setzen — nur bei Systemspeichern möglich:

```bash
sudo -u www-data php8.4 occ files_external:applicable 3 --add-group projektteam
sudo -u www-data php8.4 occ files_external:applicable 3 --remove-user testkonto
sudo -u www-data php8.4 occ files_external:applicable 3 --remove-all
```

Backend-Konfiguration und Einhängeoptionen lesen und ändern. Ohne Wert wird der
bestehende ausgegeben:

```bash
sudo -u www-data php8.4 occ files_external:config 3 host
sudo -u www-data php8.4 occ files_external:config 3 root /abteilung/projekte
sudo -u www-data php8.4 occ files_external:option 3 read_only true
```

Die Verbindung gegen das Ziel prüfen. `-c` reicht Werte nach, die in der
gespeicherten Konfiguration fehlen — bei Mechanismen, die Anmeldedaten aus der
Sitzung ziehen, ist das zwingend, weil `occ` keine Sitzung hat:

```bash
sudo -u www-data php8.4 occ files_external:verify 3
sudo -u www-data php8.4 occ files_external:verify 3 -c user=svc -c password=geheim
```

Bestand ansehen, sichern, zurückspielen und löschen:

```bash
# Systemspeicher
sudo -u www-data php8.4 occ files_external:list
# persönliche Speicher eines Kontos
sudo -u www-data php8.4 occ files_external:list maxmuster
# alle, inklusive Passwörter
sudo -u www-data php8.4 occ files_external:list --all --show-password

sudo -u www-data php8.4 occ files_external:export > speicher.json
sudo -u www-data php8.4 occ files_external:import speicher.json
sudo -u www-data php8.4 occ files_external:delete 3 --yes
```

`files_external:export` ist `files_external:list` mit fest gesetzten Optionen
(`--show-password`, `--full`, `--importable-format`, JSON) und enthält damit
Klartext-Zugangsdaten. Die Datei gehört entsprechend behandelt.
`files_external:import` liest wahlweise aus einer Datei oder aus `-` für die
Standardeingabe; das alte `mount.json`-Format wird abgewiesen.

## Administrativ eingebunden oder persönlich

Beide Arten benutzen dieselben Backends, verhalten sich aber unterschiedlich.

| | Administrativ eingebunden | Persönlich |
| --- | --- | --- |
| Angelegt über | Administrationseinstellungen, `files_external:create` ohne `--user` | Persönliche Einstellungen, `files_external:create --user <uid>` |
| Gilt für | alle Konten oder die unter *Verfügbar für* gesetzten Konten und Gruppen | ausschließlich das anlegende Konto |
| Verschieben im Dateibaum | nein | ja |
| Entfernen durch das Konto | nein | ja |
| `files_external:applicable` | ja | nein, der Befehl bricht mit einer Meldung ab |

Persönliche Einbindungen werden als `PersonalMount` eingehängt, und diese
Klasse implementiert `MoveableMount` — daher der Unterschied beim Verschieben
und Entfernen.

Damit Konten überhaupt selbst einbinden dürfen, sind zwei App-Werte nötig. Der
Schalter *Benutzern erlauben, externen Speicher einzubinden* schreibt
`allow_user_mounting` in der App `files_external`; die darunter angekreuzten
Backends landen als Liste ihrer Bezeichner in `user_mounting_backends`. Ist die
Liste leer, bleibt das Einbinden gesperrt, auch wenn der Schalter auf `yes`
steht (`StoragesBackendChecker::isUserMountingAllowed()`).

| App | Schlüssel | Vorgabe | Wirkung |
| --- | --- | --- | --- |
| `core` | `enable_external_storage` | `no` | schaltet die Funktion insgesamt frei |
| `files_external` | `allow_user_mounting` | `no` | erlaubt Konten eigene Einbindungen |
| `files_external` | `user_mounting_backends` | leer | Bezeichner der dafür freigegebenen Backends, kommagetrennt |
| `core` | `allow_user_mount_sharing` | `yes` | Freigaben auf selbst eingebundenen Speichern |

```bash
sudo -u www-data php8.4 occ config:app:set files_external allow_user_mounting --value yes
sudo -u www-data php8.4 occ config:app:set files_external user_mounting_backends --value smb,sftp,dav
sudo -u www-data php8.4 occ config:app:get core allow_user_mount_sharing
```

Steht `allow_user_mount_sharing` auf `no`, setzt owncloud.online beim Einhängen
jedes persönlichen Speichers `enable_sharing` auf `false`, unabhängig davon,
was in den Einhängeoptionen steht (`ConfigAdapter::getMountsForUser()`).

Die occ-Befehle prüfen diese Freigaben nicht. `files_external:create --user`
legt eine persönliche Einbindung auch dann an, wenn `allow_user_mounting` auf
`no` steht.

## Externer Speicher oder primärer Objektspeicher

Beides ist Speicher außerhalb des lokalen Dateisystems, aber die beiden Wege
haben nichts miteinander zu tun.

| | Externer Speicher | Primärer Objektspeicher |
| --- | --- | --- |
| App | `files_external` (+ `files_external_s3`, `wnd`) | `files_primary_s3` |
| Betrifft | einzelne Ordner, die zusätzlich eingehängt werden | sämtliche Dateien aller Konten |
| Eingerichtet über | Oberfläche oder `occ files_external:*` | `'objectstore'` in `config/config.php` |
| Änderbar im Betrieb | ja, jederzeit | nein, Entscheidung vor der Inbetriebnahme |
| Nachträglich umstellen | nicht nötig | vorhandene Dateien werden nicht automatisch verschoben |

Der primäre Objektspeicher ersetzt das Wurzel-Dateisystem der Instanz. Ist
`objectstore` beziehungsweise `objectstore_multibucket` gesetzt, wird beim
Aufbau des Dateisystems statt des lokalen Wurzelspeichers der Objektspeicher
eingerichtet (`lib/private/legacy/util.php`), und jedes Konto bekommt sein
Heimatverzeichnis aus dem `ObjectHomeMountProvider`.

Ein S3-Bucket lässt sich mit `files_external_s3` zusätzlich als gewöhnlicher
externer Speicher einbinden — das ist der Unterschied zwischen „S3 ist ein
Ordner" und „S3 ist der Datenspeicher der Instanz". Über die Oberfläche und
über die Storages-API lässt sich ein Objektspeicher ausdrücklich nicht als
externer Speicher konfigurieren; die Anfrage wird mit *Objekt nicht erlaubt*
abgewiesen (`StoragesController`).

Zwei Befehle bringt `files_primary_s3` mit:

```bash
sudo -u www-data php8.4 occ s3:list
sudo -u www-data php8.4 occ s3:list mein-bucket
sudo -u www-data php8.4 occ s3:create-bucket mein-bucket
```

## SMB im Besonderen

Für SMB und für die Windows-Netzlaufwerke der App `wnd` gilt zusätzlich:

* Es wird entweder die PHP-Erweiterung `smbclient` benötigt (geprüft wird
  `smbclient_state_new`) oder das Kommandozeilenprogramm `smbclient` im `PATH`.
  Fehlt beides, meldet die Speicherseite, dass `smbclient` nicht installiert
  ist, und das Backend verschwindet aus der Auswahl.
* Ist das Feld *Domain* gefüllt, wird der Benutzername vor dem Verbindungsaufbau
  zu `DOMAIN\benutzer` zusammengesetzt (`SMB::manipulateStorageConfig()`).
* Debug-Ausgaben für SMB-Zugriffe schaltet
  `'smb.logging.enable' => true` in `config/config.php` ein.

Zur Groß- und Kleinschreibung: SMB-Server behandeln Dateinamen in aller Regel
ohne Unterscheidung von Groß- und Kleinschreibung, owncloud.online dagegen
unterscheidet. Zwei Stellen sind davon betroffen.

Erstens die Bezeichner. `files_external:create` schlägt Backend und
Mechanismus als exakte Zeichenketten nach. `smb` funktioniert, `SMB` nicht — der
Befehl bricht mit „Storage backend with identifier … not found" ab. Dasselbe
gilt für die Liste in `user_mounting_backends`.

Zweitens die Dateinamen. Wird über das Programm `smbclient` statt über die
PHP-Erweiterung gearbeitet und liefert der Server auf eine Abfrage keinen
Treffer, liest owncloud.online ersatzweise das übergeordnete Verzeichnis und
vergleicht die Namen mit `===`, also unterscheidend nach Groß- und
Kleinschreibung (`SMB::getFileInfo()`). Weicht die Schreibweise auf der
Freigabe von der zwischengespeicherten ab — etwa nachdem eine Datei unter
Windows umbenannt wurde, ohne dass sich der Name für den Server geändert hat —
findet dieser Ersatzweg die Datei nicht. Abhilfe: die PHP-Erweiterung
`smbclient` installieren, damit dieser Zweig gar nicht erst betreten wird.

Die App `wnd` bringt einen eigenen Befehl mit, der eine Freigabe unabhängig von
jeder Einbindung prüft:

```bash
sudo -u www-data php8.4 occ wnd:test fileserver.example.com projekte svc_owncloud --domain EXAMPLE --list
```

Wird das Passwort weggelassen, fragt der Befehl es verdeckt ab.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Kein Abschnitt *Externer Speicher* in den Einstellungen, kein `files_external:*` in `occ list` | App `files_external` deaktiviert | `occ app:enable files_external` |
| Abschnitt vorhanden, aber kein Eintrag in der Dateiansicht | `enable_external_storage` steht auf `no` | `occ config:app:set core enable_external_storage --value yes` |
| „`smbclient` ist nicht installiert. Das Hinzufügen von … ist nicht möglich" | weder die PHP-Erweiterung `smbclient` noch das gleichnamige Programm vorhanden | Erweiterung installieren, PHP-FPM neu laden, Speicherseite neu laden |
| Backend fehlt in der Auswahl, obwohl die App aktiv ist | fehlende Abhängigkeit — `getAvailableBackends()` blendet Backends mit unerfüllten Abhängigkeiten aus | Abhängigkeit nachinstallieren; Meldung oberhalb der Tabelle beachten |
| *Lokal* wird nicht angeboten | `files_external_allow_create_new_local` ist nicht gesetzt | Schlüssel in `config/config.php` auf `true` setzen, oder ein anderes Backend wählen |
| Rote Zeile, Dialog *Anmeldeinformationen benötigt* | Mechanismus erwartet Zugangsdaten je Konto und hat für dieses Konto noch keine | Zugangsdaten im Dialog eintragen; er erscheint nur bei Mechanismen, die Eingaben je Konto entgegennehmen, sonst kommt „Speicher mit der ID … kann nicht vom Benutzer bearbeitet werden" |
| „Anmeldung nicht möglich. Bitte melde dich ab und wieder an" | Mechanismus `password::sessioncredentials`, in der Sitzung liegen keine Anmeldedaten | ab- und wieder anmelden; für Zugriffe ohne Passwort-Anmeldung einen Mechanismus mit gespeicherten Zugangsdaten wählen |
| `files_external:verify` meldet fehlende Daten, die Oberfläche zeigt den Speicher grün | `occ` hat keine Sitzung, aus der ein sitzungsgebundener Mechanismus lesen könnte | Werte mit `-c user=… -c password=…` nachreichen |
| `Storage backend with identifier "…" not found` | Bezeichner falsch geschrieben, Groß- und Kleinschreibung beachtet | Schreibweise aus `occ files_external:backends storage` übernehmen |
| `Authentication backend "…" not valid for storage backend "…"` | Schema des Mechanismus passt nicht zum Backend | `occ files_external:backends storage <bezeichner>` zeigt unter `supported_authentication_backends` die zulässigen Werte |
| Konten sehen den Abschnitt zum Selbst-Einbinden nicht | `allow_user_mounting` auf `no` oder `user_mounting_backends` leer | beide Werte setzen |
| Selbst eingebundener Speicher lässt sich nicht teilen | `allow_user_mount_sharing` steht auf `no` und überschreibt `enable_sharing` | App-Wert auf `yes` setzen |
| `files_external:applicable` bricht mit „Can't change applicables on personal mounts" ab | die ID gehört zu einem persönlichen Speicher | persönliche Speicher haben immer genau ein zuständiges Konto; Systemspeicher verwenden |
| Dateien liegen weiter lokal, obwohl `objectstore` gesetzt ist | primärer Objektspeicher wurde nachträglich eingerichtet | owncloud.online verschiebt Bestandsdaten nicht selbst |

Verbindungsfehler stehen mit Ursache im Protokoll, siehe
[Serverprotokoll und Fehlermeldungen](logging.md). Eine Übersicht aller
`occ`-Befehle steht in der [occ-Referenz](occ-reference.md), Hinweise zur
Installation der Zusatz-Apps unter [Apps und Marketplace](apps-market.md).
