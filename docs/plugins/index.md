# Plugins

owncloud.online besteht aus einem Kern und aus Apps. Ein Teil dieser Apps
gehört fest zum Server und wird mit ihm ausgeliefert, etwa Dateien, Freigaben,
Papierkorb, Versionen und DAV. Alles Weitere sind Plugins: eigenständig
gepflegte und versionierte Apps, die Sie über den Marketplace nachinstallieren,
aktualisieren und wieder entfernen, ohne den Server auszutauschen. Wie das im
Einzelnen geht, steht unter [Apps verwalten](../administration/apps-market.md).

!!! info "Apps mit API-Schlüssel"
    Einige Apps zeigt der Marketplace nur, wenn in der Market-App ein
    API-Schlüssel eingetragen ist. Den Schlüssel erhalten Sie von der BW-Tech
    GmbH; wie Sie ihn eintragen, steht unter
    [Apps mit API-Schlüssel](../administration/apps-market.md#apps-mit-api-schlussel).
    In der Übersicht sind diese Apps mit „API-Schlüssel“ gekennzeichnet.

## Übersicht

Jede App hat eine eigene Seite mit Voraussetzungen, Installation und
Einrichtung.

### Sicherheit und Anmeldung

| App | Wofür | Verfügbarkeit |
| --- | --- | --- |
| [Zwei-Faktor-Anmeldung (TOTP)](apps/twofactor_totp.md) | Mit dieser App fragt owncloud.online bei der Anmeldung im Browser nach dem Passwort zusätzlich einen sechsstelligen Code ab. | Marketplace |
| [Passwortrichtlinie](apps/password_policy.md) | Die App legt fest, wie Passwörter aussehen müssen: für Benutzerkonten und für Passwörter öffentlicher Links. | Marketplace |
| [Brute-Force-Schutz](apps/brute_force_protection.md) | Die App sperrt die Anmeldung vorübergehend, wenn von einer IP-Adresse zu viele Fehlversuche für ein Konto kommen. | Marketplace |
| [OAuth2](apps/oauth2.md) | Die App macht owncloud.online zum OAuth-2.0-Autorisierungsserver. | Marketplace |
| [OpenID Connect](apps/openidconnect.md) | Die App meldet Personen über einen externen Identitätsanbieter an, der OpenID Connect spricht, zum Beispiel Keycloak, AD FS oder Microsoft Entra ID. Passwörter, Mehr-Faktor-Regeln und Kontosperren verwaltet dann der Anbieter; owncloud.online prüft nur dessen Token. | Marketplace |
| [SAML/SSO-Integration](apps/user_shibboleth.md) | Mit dieser App melden sich Personen per Single Sign-on über einen SAML-2.0-Identitätsanbieter (IdP) an, etwa den einer Hochschule oder eines Unternehmens. | API-Schlüssel |
| [LDAP-Integration](apps/user_ldap.md) | Die App bindet owncloud.online an ein LDAP-Verzeichnis oder ein Active Directory an. | Marketplace |
| [Als Benutzer anmelden](apps/impersonate.md) | Mit dieser App melden sich Administratoren, auf Wunsch auch Gruppenadministratoren, vorübergehend als ein anderes Konto an. | Marketplace |

### Daten schützen

| App | Wofür | Verfügbarkeit |
| --- | --- | --- |
| [Standard-Verschlüsselungs-Modul](apps/encryption.md) | Die App `encryption` verschlüsselt den Inhalt jeder Datei, bevor owncloud.online sie auf den Speicher schreibt, und entschlüsselt ihn beim Lesen. | Marketplace |
| [Anti-Virus](apps/files_antivirus.md) | Die App `files_antivirus` lässt jede hochgeladene Datei von einem Virenscanner prüfen, bevor owncloud.online sie speichert. | Marketplace |
| [BW-Tech File Firewall](apps/file_firewall.md) | Die App `file_firewall` sperrt Dateizugriffe nach Regeln, die Sie festlegen: nach IP-Bereich, Gruppe, Gerät, Uhrzeit, Dateigröße, Dateityp, Tag oder Art der Anfrage. | API-Schlüssel |
| [Audit Log](apps/audit_log.md) | Die App `audit_log` schreibt mit, wer wann was getan hat: Anmeldungen, Dateizugriffe, Freigaben, Änderungen an Konten und Gruppen, Tags, Kommentare, das Ein- und Ausschalten von Apps und jeden `occ`-Befehl. | API-Schlüssel |

### Dateien und Speicher

| App | Wofür | Verfügbarkeit |
| --- | --- | --- |
| [Windows-Netzlaufwerk (Custom)](apps/wnd.md) | Die App bindet Freigaben von Windows- und Samba-Dateiservern als externen Speicher in owncloud.online ein. | API-Schlüssel |
| [External Storage: S3](apps/files_external_s3.md) | Die App bindet einen Bucket aus Amazon S3 oder einem S3-kompatiblen Objektspeicher als Ordner in die Dateiansicht ein. | Marketplace |
| [S3 Primary Object Storage](apps/files_primary_s3.md) | Mit dieser App legt owncloud.online die Inhalte aller Dateien in einem S3-Bucket ab statt im Datenverzeichnis. | Marketplace |
| [Auto Expire](apps/oco_expire.md) | Auto Expire räumt alte Dateien automatisch weg. | API-Schlüssel |
| [Workflow](apps/workflow.md) | Workflow vergibt beim Hochladen nach Regeln automatisch Tags und löscht Dateien mit einem bestimmten Tag nach einer Frist. | API-Schlüssel |
| [Texteditor](apps/files_texteditor.md) | Der Texteditor öffnet Textdateien direkt im Browser und speichert Änderungen in die Datei zurück. | Marketplace |
| [PDF-Ansicht](apps/files_pdfviewer.md) | Die PDF-Ansicht zeigt PDF-Dateien im Browser an, statt sie herunterzuladen. | Marketplace |
| [Media Viewer](apps/files_mediaviewer.md) | Der Media Viewer zeigt Bilder und Videos im Browser an, als Vollbild mit Blättern, Drehen, Zoomen und Video-Steuerung. | Marketplace |
| [Galerie](apps/gallery.md) | Die Galerie zeigt die Bilder eines Ordners als Album in einer Rasteransicht. | Marketplace |

### Zusammenarbeit und Office

| App | Wofür | Verfügbarkeit |
| --- | --- | --- |
| [Office (Collabora Online)](apps/oco_office.md) | Office (Collabora Online) verbindet owncloud.online mit einem Collabora-Online-Server. | API-Schlüssel |
| [ONLYOFFICE](apps/onlyoffice.md) | Mit ONLYOFFICE erstellen und bearbeiten Anwender Dokumente, Tabellen, Präsentationen und PDF-Formulare im Browser, auch gleichzeitig mit anderen. | Marketplace |
| [Collabora Online](apps/richdocuments.md) | Mit dieser App bearbeiten Anwender Texte, Tabellen und Präsentationen im Browser. | Marketplace |
| [Kalender](apps/calendar.md) | Der Kalender ist die Weboberfläche für den CalDAV-Server, der in owncloud.online eingebaut ist. | API-Schlüssel |
| [BWTalk](apps/bwtalk.md) | BWTalk bringt Chat sowie Audio- und Videoanrufe in owncloud.online: Einzel- und Gruppengespräche, öffentliche Räume mit Gastlink, Bildschirmfreigabe und Dateien aus der Instanz direkt im Chat. | API-Schlüssel |
| [Guests](apps/guests.md) | Mit Guests teilen Anwender Dateien und Ordner mit Personen außerhalb der Organisation, ohne dass vorher jemand ein Konto anlegt. | Marketplace |
| [Benutzerdefinierte Gruppen](apps/customgroups.md) | Mit dieser App legen Anwender selbst Gruppen an, etwa für ein Projekt oder ein Team, und teilen Dateien mit allen Mitgliedern auf einmal. | Marketplace |
| [Schwarzes Brett](apps/schwarzes_brett.md) | Das Schwarze Brett ist eine gemeinsame Pinnwand für Schulen und Teams. | API-Schlüssel |

### Verwaltung und Werkzeuge

| App | Wofür | Verfügbarkeit |
| --- | --- | --- |
| [Markt](apps/market.md) | Mit dem Markt installieren, aktualisieren und entfernen Administratoren Apps in der Weboberfläche. | im Kern enthalten |
| [Konfigurationsbericht](apps/configreport.md) | Die App fasst die Konfiguration einer Instanz in einer JSON-Datei zusammen: Version, Apps, Datenbank, PHP, externe Speicher und den Inhalt der `config.php`. | Marketplace |
| [owncloud.online Theme](apps/theme-owncloudonline.md) | Das Theme bestimmt das Aussehen von owncloud.online: Farben, Logo, Anmeldeseite und den Kopf der E-Mails. | API-Schlüssel |
| [Willkommensfenster](apps/firstrunwizard.md) | Die App zeigt jedem Konto nach der ersten Anmeldung ein Begrüßungsfenster über der Dateiansicht. | Marketplace |
| [Ankündigungen](apps/announcementcenter.md) | Mit der App schreiben Administratoren Mitteilungen an alle Konten, etwa zu einem Wartungsfenster oder zu geänderten Abläufen. | Marketplace |
| [Aktivität](apps/activity.md) | Die App zeichnet auf, was mit Dateien und Ordnern geschieht: Anlegen, Ändern, Löschen und Wiederherstellen, Freigaben, Kommentare, Tags und Downloads über öffentliche Links. | Marketplace |
| [Benachrichtigungen](apps/notifications.md) | Die App nimmt Meldungen anderer Apps entgegen, zeigt sie unter der Glocke oben rechts an und gibt sie an die Desktop- und Mobil-Clients weiter. | Marketplace |
| [AI Documents](apps/ai_documents.md) | AI Documents analysiert Dokumente mit einem KI-Dienst, den Sie selbst festlegen. | Marketplace |
| [MCP Connector](apps/oco_mcp.md) | Der MCP Connector macht eine owncloud.online-Instanz zum Server für das Model Context Protocol (MCP). | im Kern enthalten |

## Plugins beim Serverupdate

Beim Serverupdate ist nicht der Kern das Risiko, sondern die Plugins: Eine App,
die zur neuen Serverfassung nicht mehr passt, hält das Update an. `occ upgrade`
führt deshalb zuerst den Prüfschritt *Upgrade app code from the marketplace*
aus. Er teilt alle installierten Apps in drei Gruppen (kompatibel, inkompatibel,
fehlend: Verzeichnis weg, Eintrag in der Datenbank noch da) und versucht
anschließend, sie über den Markt auf eine passende Fassung zu bringen, in der
Reihenfolge inkompatibel, fehlend, kompatibel.

Danach entscheidet sich, wie es weitergeht:

* Bleibt eine **inkompatible oder fehlende** App übrig, bricht das Update ab
  („Upgrade is not possible"). Die Ausgabe nennt vorher jede betroffene App mit
  dem passenden `occ app:disable`-Befehl. Arbeiten Sie diese Liste ab und
  starten Sie `occ upgrade` erneut.
* Konnte eine **kompatible** App nicht aktualisiert werden, läuft das Update
  weiter; in der Ausgabe steht „App was not updated: …".

Große Fassungssprünge der Apps nimmt der Server bei einem kleinen Serverupdate
nicht von sich aus vor. Wenn Sie das wollen:

```bash
sudo -u www-data php8.4 occ upgrade --major
```

Zwei Dinge, die beim Update leicht übersehen werden:

* `apps-external/` liegt im Codebaum, nicht im Datenverzeichnis. Eine
  Datensicherung des Datenverzeichnisses enthält Ihre nachinstallierten Plugins
  also **nicht**, siehe
  [Umzug auf einen anderen Server](../administration/migration.md).
* Wird das Verzeichnis einer bereits geladenen App im laufenden Betrieb durch
  eine neuere Fassung ersetzt, kann `occ` selbst nicht mehr starten. Der
  Wartungsmodus gehört deshalb **vor** den Verzeichnistausch, siehe
  [Backups und Updates](../administration/backups-updates.md).

## Einstellungen in config.php

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `appstoreurl` | `https://marketplace.owncloud.online` | Adresse des Marktes. Der Wert `local` oder eine `file://`-Adresse schaltet auf einen Katalog um, der ohne Internet auskommt |
| `apps_paths` | nicht gesetzt | Liste der App-Verzeichnisse. Der erste Eintrag mit `'writable' => true` nimmt alle Neuinstallationen auf. Ohne den Schlüssel ist das `apps/` |
| `has_internet_connection` | `true` | Auf `false` verweigert der Markt jeden Zugriff („Die Internetverbindung ist deaktiviert."), und beim Serverupdate wird die Markt-App abgeschaltet |
| `upgrade.automatic-app-update` | `true` | Auf `false` spricht `occ upgrade` den Markt nicht an. Die Apps bleiben unverändert; Updates spielen Sie danach von Hand ein |
| `operation.mode` | `single-instance` | Jeder andere Wert verbietet das Installieren von Apps über die Oberfläche und über `occ` |
| `marketplace.key` | nicht gesetzt | API-Schlüssel für den Marketplace. Steht er hier, lässt er sich in der Market-App nicht mehr ändern |

Setzen und Prüfen wie bei allen Systemschlüsseln:

```bash
sudo -u www-data php8.4 occ config:system:set appstoreurl \
  --value https://markt.example.com
sudo -u www-data php8.4 occ config:system:get appstoreurl
```

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Markt meldet „Installieren und Updaten von Apps nicht unterstützt!" mit dem Zusatz „Dies ist ein geclustertes Setup oder der Web-Server hat keine Berechtigung in das apps Verzeichnis zu schreiben." | Kein App-Verzeichnis ist beschreibbar, oder `operation.mode` steht nicht auf `single-instance` | Eintrag mit `'writable' => true` in `apps_paths` ergänzen; Verzeichnis dem Benutzer des Webservers übertragen; `operation.mode` prüfen |
| `occ market:install` bricht mit „Installing apps is not supported because the app folder is not writable." ab | dieselbe Ursache | dieselbe Abhilfe |
| „Unbekannte App (%s)" | Die Kennung steht nicht im Katalog des eingestellten Marktes, oder die App braucht einen API-Schlüssel | Kennung mit `occ market:list` prüfen, `appstoreurl` und API-Schlüssel prüfen |
| „Keine kompatible Version für %s gefunden" | Keine Fassung der App passt zur laufenden Serverfassung | Serverfassung mit `occ status` prüfen. Das Paket von Hand einzuspielen ist keine Lösung: Die App würde beim nächsten `occ upgrade` das Update anhalten |
| Der Markt meldet ein Update, `market:upgrade` tut nichts | Es ist ein großes Update; ohne `--major` wird es übergangen | `occ market:upgrade <app_id> --major` |
| Eine neue Fassung erscheint nicht im Markt | Der Katalog wird 30 Minuten zwischengespeichert | Im Markt *Einstellungen → Cache leeren* wählen und die Seite neu laden |
| „Die Internetverbindung ist deaktiviert." | `has_internet_connection` steht auf `false` | Schlüssel setzen, oder mit `appstoreurl` auf einen lokalen Katalog umstellen und Pakete mit `--local` einspielen |
| „Mitgelieferte Apps können nicht deinstalliert werden" | Es handelt sich um eine mit dem Server ausgelieferte App | `occ app:disable <app_id>` statt `market:uninstall` |
| „Market app can not uninstall itself." | Der Markt soll sich selbst entfernen | Nicht möglich; nur `occ app:disable market` |
| `occ upgrade` bricht mit „Upgrade is not possible" ab | Inkompatible oder fehlende Apps, die der Markt nicht heilen konnte | Die in der Ausgabe genannten `occ app:disable`-Befehle ausführen, dann `occ upgrade` erneut starten |
| Beim Update: „Market app is unavailable for updating of apps." | Die Markt-App ist abgeschaltet | `occ app:enable market`, danach `occ market:upgrade --all` |
| Nach dem Update fehlt eine App in der Übersicht | Sie wurde als inkompatibel abgeschaltet | `occ app:list --disabled` zeigt sie mit Fassung; nach dem Update der App wieder aktivieren |
| Keine Benachrichtigung über neue App-Fassungen | Der Prüflauf ist ein Hintergrund-Job | Cron prüfen, siehe [Hintergrund-Jobs (Cron)](../administration/background-jobs.md) |
| Jeder `occ`-Aufruf bricht nach dem Austausch eines App-Verzeichnisses mit `OC\NeedsUpdateException` ab | Die App wird schon beim Start jedes Aufrufs geladen | Wartungsmodus setzen, siehe [Backups und Updates](../administration/backups-updates.md) |

Bleibt die Ursache unklar, hilft das Serverprotokoll weiter, siehe
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).

## Ein Problem melden

Fehler im Server selbst oder in der Markt-App melden Sie unter
<https://github.com/BWTECH-github/owncloud.online/issues>. Für alle anderen
Plugins, vor allem für Apps mit API-Schlüssel, ist der Support der BW-Tech GmbH
zuständig (<https://bw.tech>).

Das gehört in einen brauchbaren Bericht:

```bash
# Serverfassung
sudo -u www-data php8.4 occ status

# Fassung und Zustand der betroffenen App
sudo -u www-data php8.4 occ app:list

# Systemkonfiguration; schutzwürdige Werte werden dabei durch einen
# Platzhalter ersetzt (nur mit --private stünden sie im Klartext dort)
sudo -u www-data php8.4 occ config:list system
```

Dazu der Auszug aus dem Serverprotokoll zum Zeitpunkt des Fehlers
([Serverprotokoll und Fehlermeldungen](../administration/logging.md)) sowie die
Schritte, mit denen sich der Fehler wiederholen lässt.
