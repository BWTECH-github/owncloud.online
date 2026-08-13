# Plugins

owncloud.online besteht aus einem Kern und aus Apps. Ein Teil dieser Apps
gehört fest zum Server und wird mit ihm ausgeliefert — Dateien, Freigaben,
Papierkorb, Versionen, DAV. Alles Weitere sind Plugins: eigenständig gepflegte,
eigenständig versionierte Apps, die Sie nachinstallieren, aktualisieren und
wieder entfernen können, ohne den Server auszutauschen.

Diese Seite beschreibt, wo die Plugins herkommen, wie sie versioniert sind, wie
Sie eines einspielen und aktualisieren, was beim Serverupdate mit ihnen
geschieht und wohin ein Fehlerbericht gehört. Welche Plugins es gibt, steht in
der [Plugin-Matrix](plugin-matrix.md).

## Was ein Plugin ist

Ein Plugin ist ein Verzeichnis mit der Datei `appinfo/info.xml`. Darin stehen
die App-Kennung (`<id>`), die Fassung (`<version>`) und die Voraussetzungen
(`<dependencies>`). Ohne diese Datei erkennt der Server das Verzeichnis nicht
als App an; beim Einspielen eines Pakets bricht er mit „App does not provide an
info.xml file" ab.

Der Server kennt zwei App-Verzeichnisse:

| Verzeichnis | Inhalt |
| --- | --- |
| `apps/` | Die mit dem Server ausgelieferten Kern-Apps |
| `apps-external/` | Alle nachinstallierten Plugins |

Welches der beiden Verzeichnisse der Server beschreibt, legt der Schlüssel
`apps_paths` in `config/config.php` fest — maßgeblich ist der erste Eintrag mit
`'writable' => true`. Das Muster in `config/config.sample.php` stellt `apps/`
auf `false` und `apps-external/` auf `true`; damit landet jede Neuinstallation
in `apps-external/` und bleibt vom Austausch des Kerns unberührt. Fehlt
`apps_paths` ganz, benutzt der Server `apps/` und schreibt auch dorthin.

Das Serverarchiv unter
<https://github.com/BWTECH-github/owncloud.online/releases> enthält den Kern und
die Markt-App, sonst kein Plugin. Ein Release besteht aus diesen Dateien:

| Datei | Inhalt |
| --- | --- |
| `owncloud-online-<version>.tar.gz` | Serverarchiv |
| `owncloud-online-<version>.zip` | dasselbe als ZIP |
| `SHA256SUMS.txt` | Prüfsummen der beiden Archive, der Stückliste und von `release-manifest.json` |
| `sbom-owncloud-online-<version>.cdx.json` | Stückliste im CycloneDX-Format |
| `release-manifest.json` | Fassung, Commit, PHP-Fassung des Baus |
| `removed-release-files.txt` | beim Bau entfernte Entwicklungsdateien |

Alle Plugins kommen also nach der Installation dazu, über den Markt oder von
Hand.

## Wo die Plugins liegen

Jedes von uns gepflegte Plugin hat ein eigenes Repository unter
<https://github.com/BWTECH-github>. **Die Dokumentation eines Plugins ist die
README seines Repositories** — dort stehen Zweck, Einstellungen und die
Besonderheiten der jeweiligen App. Diese Dokumentationsseite beschreibt nur den
gemeinsamen Weg des Einspielens und Aktualisierens.

Die genaue Adresse eines Plugins müssen Sie nicht raten: Sie steht in dessen
`appinfo/info.xml` in den Feldern `<repository>` und `<bugs>`. Für Plugins, die
im Server-Repository selbst gepflegt werden, zeigen beide Felder auf
`https://github.com/BWTECH-github/owncloud.online`.

Verteilt werden die Plugins nicht über die Repositories, sondern über einen
eigenen Markt. Welchen, legt der Schlüssel `appstoreurl` fest; ohne Eintrag ist
das `https://marketplace.owncloud.online`.

## Versionen und Verträglichkeit

Plugins tragen eine dreiteilige Fassungsnummer in `appinfo/info.xml` und
erklären dort zugleich, mit welcher Serverfassung sie zusammenarbeiten:

```xml
<version>2.8.1</version>
<dependencies>
    <owncloud min-version="11" max-version="11" />
    <php min-version="8.4" />
</dependencies>
```

Die eigene Serverfassung — derzeit **11.0.13** — zeigt:

```bash
sudo -u www-data php8.4 occ status
```

Aus `min-version` und `max-version` entscheidet der Server, ob eine App zur
laufenden Fassung passt. Im Markt steht das Ergebnis in der Spalte
*Kompatibilität*; beim Serverupdate ist es der Prüfstein dafür, ob eine App
weiterlaufen darf (siehe [unten](#plugins-beim-serverupdate)).

Der Markt unterscheidet außerdem **kleine** und **große** Updates anhand der
ersten Stelle der Fassungsnummer. Ein Sprung von `2.8.1` auf `2.9.0` gilt als
klein und wird ohne Nachfrage angeboten, der Sprung auf `3.0.0` als groß und
muss ausdrücklich erlaubt werden. Angeboten werden dabei nur Fassungen, die zur
laufenden Serverfassung passen — deshalb kann es sein, dass ein Repository
bereits eine neuere Fassung führt, der Markt aber keine meldet.

## Installieren über die Weboberfläche

![Markt mit den installierten Apps](../assets/screenshots/owncloud-online-apps.png)

Der Markt liegt im App-Menü oben links unter **Markt** und ist nur für
Administratoren sichtbar. Die linke Spalte führt durch:

| Eintrag | Zweck |
| --- | --- |
| *Entdecken* | alle Apps des Marktes |
| *Installierte Apps* | was in dieser Instanz vorhanden ist, mit Fassung und Status |
| *App-Bundles* | zusammengefasste Gruppen von Apps |
| *Updates* | Apps, für die eine neuere Fassung vorliegt |
| *Kategorien* | Automation, Collaboration, Files, Integration, Multimedia, Office, Security, Tools, User Authentication |
| *Einstellungen* | *API Schlüssel hinzufügen* und *Cache leeren* |

Eine App installieren Sie, indem Sie sie öffnen und *Installieren* wählen. Der
Server lädt das Paket, entpackt es in das beschreibbare App-Verzeichnis und
aktiviert die App unmittelbar danach. Schlägt das Aktivieren fehl, wird das
entpackte Verzeichnis wieder entfernt, damit kein halb installierter Zustand
zurückbleibt.

## Installieren auf der Kommandozeile

Die `market:`-Befehle stellt die Markt-App bereit; sie stehen also nur zur
Verfügung, solange diese aktiviert ist — `app:list` und die übrigen
`app:`-Befehle gehören zum Kern. `occ` wird im Installationsverzeichnis des
Servers und immer unter dem Benutzer des Webservers aufgerufen.

```bash
# Was bietet der Markt an?
sudo -u www-data php8.4 occ market:list

# App installieren (wird dabei zugleich aktiviert)
sudo -u www-data php8.4 occ market:install activity

# Ergebnis prüfen
sudo -u www-data php8.4 occ app:list
```

`market:install` nimmt mehrere Kennungen hintereinander entgegen. Ist die App
bereits vorhanden, installiert der Befehl statt dessen das nächste kleine
Update.

Ein Paket, das Sie bereits auf dem Server liegen haben, spielen Sie mit
`--local` ein:

```bash
sudo -u www-data php8.4 occ market:install --local /pfad/activity-2.8.1.tar.gz
```

Der Server akzeptiert ZIP-, gzip- und bzip2-Archive; alles andere lehnt er mit
„Archives of type … are not supported" ab. Das Archiv muss die Datei
`appinfo/info.xml` enthalten, entweder direkt oder in genau einem
Unterverzeichnis.

Ganz ohne Markt geht es auch: Legen Sie das entpackte Verzeichnis unter dem
Namen der App-Kennung in das beschreibbare App-Verzeichnis, übertragen Sie es
an den Benutzer des Webservers und aktivieren Sie die App:

```bash
sudo chown -R www-data:www-data /var/www/owncloud.online/apps-external/activity
sudo -u www-data php8.4 occ app:enable activity

# Wo liegt die App jetzt?
sudo -u www-data php8.4 occ app:getpath activity
```

Soll eine App nur bestimmten Gruppen zur Verfügung stehen:

```bash
sudo -u www-data php8.4 occ app:enable activity --groups redaktion
```

## Aktualisieren

```bash
# Welche Apps hätten ein Update? (klein und groß getrennt aufgeführt)
sudo -u www-data php8.4 occ market:upgrade --list

# eine bestimmte App aktualisieren
sudo -u www-data php8.4 occ market:upgrade activity

# alle Apps mit verfügbarem Update
sudo -u www-data php8.4 occ market:upgrade --all

# auch große Updates zulassen
sudo -u www-data php8.4 occ market:upgrade --all --major

# aus einem lokal vorliegenden Paket
sudo -u www-data php8.4 occ market:upgrade --local /pfad/activity-2.8.1.tar.gz
```

Ohne `--major` bleibt der Befehl innerhalb derselben ersten Stelle der
Fassungsnummer. Ist nur ein großes Update vorhanden und `--major` gesetzt, wird
es genommen; gibt es kein großes, fällt der Befehl auf das kleine zurück.

Ein Rückschritt auf eine ältere Fassung ist ausgeschlossen: Ohne ausdrücklich
verlangte Zielfassung lässt der Markt nur Fassungen zu, die höher sind als die
installierte, und meldet sonst „No newer version available for …".

Der Markt prüft täglich in einem Hintergrund-Job, ob Updates vorliegen, und
benachrichtigt die Administratoren. Läuft kein Cron, bleiben diese Meldungen
aus — siehe [Hintergrund-Jobs (Cron)](../administration/background-jobs.md).

## Entfernen

Zwei verschiedene Schritte, die oft verwechselt werden:

```bash
# App abschalten - Code und Daten bleiben liegen, Rückweg jederzeit möglich
sudo -u www-data php8.4 occ app:disable activity

# App vollständig entfernen - das App-Verzeichnis wird gelöscht
sudo -u www-data php8.4 occ market:uninstall activity
```

Mit dem Server ausgelieferte Apps lassen sich nicht entfernen; der Versuch
endet mit „Mitgelieferte Apps können nicht deinstalliert werden". Für sie ist
`app:disable` der einzige Weg. Die Markt-App kann sich außerdem nicht selbst
entfernen und antwortet in diesem Fall mit der unübersetzten Meldung „Market
app can not uninstall itself."

## Plugins beim Serverupdate

Beim Serverupdate ist nicht der Kern das Risiko, sondern die Plugins: Eine App,
die zur neuen Serverfassung nicht mehr passt, hält das Update an. `occ upgrade`
führt deshalb zuerst den Prüfschritt *Upgrade app code from the marketplace*
aus. Er teilt alle installierten Apps in drei Gruppen — kompatibel,
inkompatibel, fehlend (Verzeichnis weg, Eintrag in der Datenbank noch da) — und
versucht anschließend, sie über den Markt auf eine passende Fassung zu bringen,
in der Reihenfolge inkompatibel, fehlend, kompatibel.

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
  also **nicht** — siehe
  [Umzug auf einen anderen Server](../administration/migration.md).
* Wird das Verzeichnis einer bereits geladenen App im laufenden Betrieb durch
  eine neuere Fassung ersetzt, kann `occ` selbst nicht mehr starten. Der
  Wartungsmodus gehört deshalb **vor** den Verzeichnistausch — siehe
  [Backups und Updates](../administration/backups-updates.md).

## Einstellungen in config.php

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `appstoreurl` | `https://marketplace.owncloud.online` | Adresse des Marktes. Der Wert `local` oder eine `file://`-Adresse schaltet auf einen Katalog um, der ohne Internet auskommt |
| `apps_paths` | nicht gesetzt | Liste der App-Verzeichnisse. Der erste Eintrag mit `'writable' => true` nimmt alle Neuinstallationen auf. Ohne den Schlüssel ist das `apps/` |
| `has_internet_connection` | `true` | Auf `false` verweigert der Markt jeden Zugriff („Die Internetverbindung ist deaktiviert."), und beim Serverupdate wird die Markt-App abgeschaltet |
| `upgrade.automatic-app-update` | `true` | Auf `false` spricht `occ upgrade` den Markt nicht an. Die Apps bleiben unverändert; Updates spielen Sie danach von Hand ein |
| `operation.mode` | `single-instance` | Jeder andere Wert verbietet das Installieren von Apps über die Oberfläche und über `occ` |
| `appstoreenabled` | nicht gesetzt | Wirkt nur beim Update von einer Fassung bis einschließlich 10.0.0: Steht der Schlüssel dort auf `false`, wird die Markt-App abgeschaltet. Bei Updates innerhalb von 11.x hat er keine Wirkung — dafür ist `upgrade.automatic-app-update` zuständig |

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
| „Unbekannte App (%s)" | Die Kennung steht nicht im Katalog des eingestellten Marktes | Kennung mit `occ market:list` prüfen, `appstoreurl` prüfen |
| „Keine kompatible Version für %s gefunden" | Keine Fassung der App passt zur laufenden Serverfassung | Serverfassung mit `occ status` prüfen; das Paket von Hand einspielen ist keine Lösung — die App würde beim nächsten `occ upgrade` das Update anhalten |
| Der Markt meldet ein Update, `market:upgrade` tut nichts | Es ist ein großes Update; ohne `--major` wird es übergangen | `occ market:upgrade <app_id> --major` |
| Eine im Repository veröffentlichte Fassung erscheint nicht im Markt | Der Katalog wird 30 Minuten zwischengespeichert | Im Markt *Einstellungen → Cache leeren* wählen und die Seite neu laden |
| „Die Internetverbindung ist deaktiviert." | `has_internet_connection` steht auf `false` | Schlüssel setzen, oder mit `appstoreurl` auf einen lokalen Katalog umstellen und Pakete mit `--local` einspielen |
| „Mitgelieferte Apps können nicht deinstalliert werden" | Es handelt sich um eine mit dem Server ausgelieferte App | `occ app:disable <app_id>` statt `market:uninstall` |
| „Market app can not uninstall itself." | Der Markt soll sich selbst entfernen | Nicht möglich; nur `occ app:disable market` |
| `occ upgrade` bricht mit „Upgrade is not possible" ab | Inkompatible oder fehlende Apps, die der Markt nicht heilen konnte | Die in der Ausgabe genannten `occ app:disable`-Befehle ausführen, dann `occ upgrade` erneut starten |
| Beim Update: „Market app is unavailable for updating of apps." | Die Markt-App ist abgeschaltet | `occ app:enable market`, danach `occ market:upgrade --all` |
| Nach dem Update fehlt eine App in der Übersicht | Sie wurde als inkompatibel abgeschaltet | `occ app:list --disabled` zeigt sie mit Fassung; nach dem Update der App wieder aktivieren |
| Keine Benachrichtigung über neue App-Fassungen | Der Prüflauf ist ein Hintergrund-Job | Cron prüfen, siehe [Hintergrund-Jobs (Cron)](../administration/background-jobs.md) |
| Jeder `occ`-Aufruf bricht nach dem Austausch eines App-Verzeichnisses mit `OC\NeedsUpdateException` ab | Die App wird schon beim Start jedes Aufrufs geladen | Wartungsmodus setzen, siehe [Backups und Updates](../administration/backups-updates.md) |

Bleibt die Ursache unklar, hilft das Serverprotokoll weiter — siehe
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).

## Ein Problem melden

Fehler gehören in das Repository des betroffenen Plugins, denn dort liegt auch
dessen Dokumentation. Die Adresse steht im Feld `<bugs>` seiner
`appinfo/info.xml`. Betrifft der Fehler den Server selbst oder die Markt-App,
ist der richtige Ort
<https://github.com/BWTECH-github/owncloud.online/issues>.

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

Bei Verdacht auf einen Fehler im Code einer App liefert zusätzlich der
Prüflauf des Servers Hinweise — er meldet die Benutzung interner oder
veralteter Schnittstellen:

```bash
sudo -u www-data php8.4 occ app:check-code activity
```
