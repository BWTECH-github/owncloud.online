# Apps verwalten

Ein Teil des Funktionsumfangs von owncloud.online steckt in Apps: Papierkorb,
Versionen, externe Speicher und Freigaben sind ebenso Apps wie später
nachinstallierte Erweiterungen. Diese Seite beschreibt die drei Wege, eine App
in eine Instanz zu bekommen, wie Sie Apps ein- und ausschalten, wie Sie Version
und Verträglichkeit prüfen und was beim Entfernen einer App mit ihren Daten
geschieht.

Es gibt zwei Sorten von Apps:

* **Mitgelieferte Apps** stehen in `core/shipped.json` und kommen mit dem
  Server. Sie lassen sich abschalten, aber nicht entfernen. Drei davon —
  `files`, `dav` und `federatedfilesharing` — lassen sich nicht einmal
  abschalten; `occ app:disable` bricht bei ihnen mit „can't be disabled" ab.
* **Nachinstallierte Apps** liegen im selben Verzeichnisbaum, sind aber nicht
  Teil des Servers. Nur sie lassen sich wieder löschen.

## Was ist vorhanden?

```bash
sudo -u www-data php8.4 occ app:list
```

Die Ausgabe ist nach *enabled* und *disabled* getrennt und nennt zu jeder App
ihr Verzeichnis; die Version steht bei den aktiven Apps dabei, bei den
abgeschalteten erst mit `--disabled`. Nützliche Einschränkungen:

```bash
# nur aktive Apps
sudo -u www-data php8.4 occ app:list --enabled

# kurze Liste, nur Name und Version
sudo -u www-data php8.4 occ app:list --minimal

# nur nachinstallierte Apps (mitgelieferte: --shipped true)
sudo -u www-data php8.4 occ app:list --shipped false

# Verzeichnis einer einzelnen App
sudo -u www-data php8.4 occ app:getpath files_external
```

In der Weboberfläche zeigt *Einstellungen → Administration → Apps* dieselbe
Liste unter der Überschrift **Verwaltung der Apps**.

## Weg 1: über den Markt in der Weboberfläche

Der Markt ist selbst eine App (`market`). Ist sie aktiv, erscheint für
Administratoren im App-Menü links oben der Eintrag **Markt**.

Die linke Spalte führt durch den Katalog:

| Eintrag | Inhalt |
| --- | --- |
| *Entdecken* | Startseite des Katalogs |
| *Installierte Apps* | alle in dieser Instanz vorhandenen Apps mit Version, Autor, Status und Kompatibilität |
| *App-Bundles* | zusammengefasste Pakete mehrerer Apps |
| *Updates* | Apps, für die im Katalog eine neuere Fassung liegt |
| *Kategorien* | Katalog nach Kategorien |
| *Cache leeren* | verwirft die zwischengespeicherte Katalogantwort |

![Die Übersicht „Installierte Apps" im Markt](../assets/screenshots/owncloud-online-apps.png)

Eine App wird über ihre Detailseite installiert. Nach der Installation liegt sie
im beschreibbaren App-Verzeichnis (siehe
[Schreibrechte](#schreibrechte-auf-das-app-verzeichnis)) und ist aktiviert.

Der Katalog, den der Markt abfragt, steht in `appstoreurl`. Ohne Eintrag ist das
`https://marketplace.owncloud.online`. Zwei weitere Werte sind möglich:

```bash
# fest eingebauter Katalog der Markt-App, ohne Netzzugriff
sudo -u www-data php8.4 occ config:system:set appstoreurl --value local

# eigener Katalog im Dateisystem
sudo -u www-data php8.4 occ config:system:set \
    appstoreurl --value file:///srv/katalog
```

Ein `file://`-Pfad oder das Schlüsselwort `local` schalten den Markt auf einen
örtlichen Katalog um; jeder andere Wert wird als Adresse eines
Katalog-Servers behandelt.

## Weg 2: Paket von Hand nach `apps/`

Dieser Weg braucht weder Netzzugang noch die Markt-App und ist der einzige, der
auch in einer Instanz mit schreibgeschütztem App-Verzeichnis funktioniert — die
Dateien legt dann nicht der Webserver ab, sondern Sie selbst.

Ein App-Paket ist ein `.tar.gz`, das ein einzelnes Verzeichnis mit der Datei
`appinfo/info.xml` enthält. **Der Verzeichnisname muss der `<id>` aus dieser
Datei entsprechen** — der Server sucht eine App ausschließlich unter einem
Verzeichnis mit ihrem Bezeichner.

```bash
cd /var/www/owncloud.online/apps
sudo tar -xzf /pfad/zum/paket.tar.gz
sudo chown -R www-data:www-data /var/www/owncloud.online/apps/<app_id>
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:enable <app_id>
```

Das Aktivieren erledigt die Einrichtung mit: Datenbankmigrationen der App,
`appinfo/install.php`, Registrierung ihrer Hintergrund-Jobs und die
Install-Reparaturschritte laufen dabei. Vorher prüft der Server die
Abhängigkeiten und bricht ab, wenn eine nicht erfüllt ist.

Wird eine bereits vorhandene App durch eine neuere Fassung ersetzt, genügt
`app:enable` nicht — die Migrationen der neuen Version laufen erst mit:

```bash
sudo -u www-data php8.4 occ upgrade
```

Verglichen wird dabei die Version aus `appinfo/info.xml` mit dem in der
Datenbank vermerkten `installed_version`. Unterscheiden sich nur die letzten
Stellen der Versionsnummer, wird der Vermerk stillschweigend nachgezogen, ohne
Migrationen auszuführen.

### Apps außerhalb von `apps/` ablegen

Ein Server-Update ersetzt den Codebaum und damit alles, was unter `apps/`
liegt. Damit nachinstallierte Apps das überstehen, sieht die Konfiguration ein
zweites Verzeichnis vor. In `config/config.php`:

```php
'apps_paths' => [
	[
		'path' => OC::$SERVERROOT.'/apps',
		'url' => '/apps',
		'writable' => false,
	],
	[
		'path' => OC::$SERVERROOT.'/apps-external',
		'url' => '/apps-external',
		'writable' => true,
	],
],
```

Jeder Eintrag braucht alle drei Schlüssel: `path` als absoluter Pfad im
Dateisystem, `url` als Pfad relativ zum Web-Wurzelverzeichnis mit führendem
Schrägstrich, und `writable`. Neue Apps landen ausschließlich in dem
Verzeichnis, dessen `writable` auf `true` steht — gibt es mehrere, gewinnt das
erste. Ist `apps_paths` gar nicht gesetzt, benutzt der Server `apps/` und
behandelt es als beschreibbar.

Das Verzeichnis muss existieren, bevor Sie es eintragen; andernfalls startet
der Server nicht mehr, sondern meldet „App directory … not found!".

## Weg 3: über `occ`

Die vier `market:`-Befehle bringt die Markt-App mit. Sie stehen nur zur
Verfügung, solange diese App aktiviert ist.

```bash
# Bezeichner aller Apps im Katalog
sudo -u www-data php8.4 occ market:list

# App aus dem Katalog installieren
sudo -u www-data php8.4 occ market:install <app_id>

# mehrere auf einmal
sudo -u www-data php8.4 occ market:install <app_id> <app_id>

# ein Paket installieren, das bereits im Dateisystem liegt
sudo -u www-data php8.4 occ market:install --local /pfad/zum/paket.tar.gz
```

`market:install` auf eine bereits vorhandene App spielt ein verfügbares
Minor-Update ein. Liegt im Katalog nur eine neue Hauptversion, passiert nichts;
stattdessen erscheint der Hinweis „Major update is available, use
market:upgrade --major".

Updates:

```bash
# nur anzeigen, welche Updates es gibt
sudo -u www-data php8.4 occ market:upgrade --list

# eine bestimmte App aktualisieren
sudo -u www-data php8.4 occ market:upgrade <app_id>

# alle Apps mit verfügbarem Update
sudo -u www-data php8.4 occ market:upgrade --all

# Hauptversionswechsel ausdrücklich zulassen
sudo -u www-data php8.4 occ market:upgrade --major <app_id>

# aus einem örtlich vorliegenden Paket aktualisieren
sudo -u www-data php8.4 occ market:upgrade --local /pfad/zum/paket.tar.gz
```

Ohne `--major` lehnt der Befehl einen Wechsel der Hauptversion ab. Das ist
Absicht: Ein solcher Wechsel kann Einstellungen und Datenbankschema der App
verändern und gehört auf einen Zeitpunkt, an dem eine Sicherung vorliegt.

`market:install`, `market:upgrade` und `market:uninstall` prüfen zuerst, ob das
App-Verzeichnis beschreibbar ist. `market:install` und `market:upgrade` brechen
sonst mit „Installing apps is not supported because the app folder is not
writable." ab, `market:uninstall` mit „Un-Installing apps is not supported
because the app folder is not writable.".

Die mitgelieferten Apps kommen mit dem Server. Sie stecken im Release-Archiv
`owncloud-online-<version>.tar.gz` beziehungsweise `.zip` unter
[github.com/BWTECH-github/owncloud.online/releases](https://github.com/BWTECH-github/owncloud.online/releases)
und werden nicht einzeln nachinstalliert, sondern mit dem Server aktualisiert.
Zu jedem Release gehören außerdem `SHA256SUMS.txt` zur Prüfung der Archive,
`sbom-owncloud-online-<version>.cdx.json`, `release-manifest.json` und
`removed-release-files.txt`. Aktuelle Fassung ist 11.0.13.

## Aktivieren und abschalten

```bash
sudo -u www-data php8.4 occ app:enable <app_id>
sudo -u www-data php8.4 occ app:disable <app_id>
```

Eine App lässt sich auch auf bestimmte Gruppen beschränken. Sie ist dann nur
für Mitglieder dieser Gruppen vorhanden:

```bash
sudo -u www-data php8.4 occ app:enable <app_id> --groups buchhaltung -g vertrieb
```

In der Weboberfläche stehen unter *Einstellungen → Administration → Apps* an
jeder App die Knöpfe *Aktivieren* beziehungsweise *Deaktivieren* und die
Auswahl *Nur für bestimmte Gruppen aktivieren*.

Zwei Punkte, die regelmäßig überraschen:

* Eine App bringt ihre `occ`-Befehle nur mit, solange sie aktiviert ist. Nach
  `app:disable files_external` verschwinden alle `files_external:*`-Befehle aus
  `occ list`, nach `app:disable market` alle `market:*`-Befehle.
* Die Uninstall-Reparaturschritte einer App laufen nur beim Abschalten über die
  Weboberfläche. `occ app:disable` setzt den Zustand direkt und überspringt sie.
  Die Daten der App bleiben in beiden Fällen erhalten, siehe
  [unten](#eine-app-wieder-loswerden).

Apps mit dem Typ `filesystem`, `prelogin`, `authentication`, `logging`,
`prevent_group_restriction` oder `theme` lassen sich nicht auf Gruppen
beschränken — `app:enable --groups` bricht bei ihnen mit „can't be enabled for
groups." ab. Von den Theme-Apps kann immer nur eine aktiv sein: Das Aktivieren
einer zweiten scheitert mit „… can't be enabled until … is disabled."

## Version und Verträglichkeit prüfen

Jede App erklärt in `appinfo/info.xml`, mit welchen Server- und PHP-Versionen
sie zusammenarbeitet. Vor dem Aktivieren wird das geprüft; schlägt die Prüfung
fehl, nennt die Meldung die unerfüllte Bedingung:

| Meldung | Bedeutung |
| --- | --- |
| „Server version %s or higher is required." | Die App verlangt eine neuere Serverversion |
| „Server version %s or lower is required." | Die App ist für diese Serverversion nicht freigegeben |
| „PHP %s or higher is required." | Die PHP-Version des Servers ist zu alt |
| „PHP with a version lower than %s is required." | Die App verträgt diese PHP-Version nicht |
| „The library %s is not available." | Eine PHP-Erweiterung fehlt |
| „The command line tool %s could not be found" | Ein vorausgesetztes Programm fehlt auf dem Server |
| „Following databases are supported: %s" | Die App unterstützt das eingesetzte Datenbanksystem nicht |

Im Markt steht das Ergebnis unter *Installierte Apps* in der Spalte
*Kompatibilität*: entweder *OK* oder *Fehlende Abhängigkeiten* samt Aufzählung
des Fehlenden. Unter *Einstellungen → Administration → Apps* ist der
*Aktivieren*-Knopf in diesem Fall ausgegraut, darüber steht „Die App kann nicht
installiert werden, weil die folgenden Abhängigkeiten nicht erfüllt sind:".

Zwei weitere Hinweise erscheinen dort, ohne die Installation zu verhindern:
Fehlt in der `info.xml` die minimale oder die maximale Serverversion, meldet die
Oberfläche das ausdrücklich. Solche Apps sind ungeprüft gegen diese
Serverversion.

Zwei Werkzeuge auf der Kommandozeile:

```bash
# Code einer App gegen die Regeln der Server-API prüfen,
# dazu Pflichtfelder in info.xml
sudo -u www-data php8.4 occ app:check-code <app_id>

# Signatur einer App prüfen, sofern sie signiert ist
sudo -u www-data php8.4 occ integrity:check-app <app_id>
```

Bringt ein Paket eine `appinfo/signature.json` mit, prüft der Server die
Signatur bereits bei der Installation und lehnt das Paket bei Abweichungen ab.
Unsignierte Pakete werden ohne diese Prüfung installiert.

## Schreibrechte auf das App-Verzeichnis

Steht im Markt oben der Kasten **„Installieren und Updaten von Apps nicht
unterstützt!"** mit dem Zusatz „Dies ist ein geclustertes Setup oder der
Web-Server hat keine Berechtigung in das apps Verzeichnis zu schreiben.", dann
trifft eine von zwei Bedingungen nicht zu:

1. `operation.mode` steht auf `single-instance`. Beim Wert
   `clustered-instance` unterbleibt die Installation aus dem Katalog
   grundsätzlich, weil sie ohnehin auf jedem Knoten einzeln erfolgen müsste.
2. Das Verzeichnis, dessen `writable` in `apps_paths` auf `true` steht, ist für
   den Webserver-Benutzer les- **und** schreibbar. Ist kein Verzeichnis als
   beschreibbar markiert, vermerkt das Protokoll „No application directories
   are marked as writable."

Der Hinweis bedeutet nicht, dass die Instanz keine Apps bekommen kann. Er
bedeutet nur, dass der Webserver sie nicht selbst ablegen darf. Der Weg über
[Weg 2](#weg-2-paket-von-hand-nach-apps) bleibt offen — dort schreibt Ihr
eigenes Konto die Dateien, nicht der Webserver.

Beide Bedingungen prüfen:

```bash
sudo -u www-data php8.4 occ config:system:get operation.mode
sudo -u www-data test -w /var/www/owncloud.online/apps && echo beschreibbar
```

## Eine App wieder loswerden

Es gibt zwei Stufen, und sie tun Verschiedenes.

**Abschalten** lässt Code und Daten liegen und macht die App nur unsichtbar:

```bash
sudo -u www-data php8.4 occ app:disable <app_id>
```

**Entfernen** löscht zusätzlich das Verzeichnis der App:

```bash
sudo -u www-data php8.4 occ market:uninstall <app_id>
```

In der Weboberfläche entspricht dem der Knopf *App deinstallieren* unter
*Einstellungen → Administration → Apps*. Er erscheint nur an Apps, die nicht
mitgeliefert sind und gerade nicht aktiv sind.

Was dabei mit den Daten geschieht:

| Bestandteil | Beim Abschalten | Beim Entfernen |
| --- | --- | --- |
| Verzeichnis der App | bleibt | wird gelöscht |
| Uninstall-Reparaturschritte der App | laufen nur beim Abschalten über die Weboberfläche | laufen nicht (`market:uninstall` schaltet die App nur ab und löscht das Verzeichnis) |
| Datenbanktabellen der App | bleiben | bleiben |
| Einstellungen in `oc_appconfig` | bleiben | bleiben |
| Einstellungen einzelner Benutzer | bleiben | bleiben |
| Dateien der Benutzer | bleiben | bleiben |

Das Entfernen räumt also nur den Code weg. Der Server löscht ausdrücklich keine
Tabellen, keine Einstellungen und keine Benutzervorgaben — wird dieselbe App
später wieder installiert, findet sie ihren alten Zustand vor. Das ist meist
erwünscht; wer wirklich aufräumen will, muss die Reste von Hand entfernen:

```bash
# vorhandene Einstellungen der App ansehen
sudo -u www-data php8.4 occ config:list <app_id>

# einzelnen Wert löschen
sudo -u www-data php8.4 occ config:app:delete <app_id> <schluessel>
```

Datenbanktabellen einer entfernten App bleiben bestehen und müssen, wenn
gewünscht, direkt in der Datenbank gelöscht werden — nach einer Sicherung, siehe
[Datenbank](database.md).

Drei Fälle, in denen das Entfernen abgelehnt wird:

* Mitgelieferte Apps: „Shipped apps cannot be uninstalled". Für sie gibt es nur
  `app:disable`.
* Die Markt-App selbst: „Market app can not uninstall itself."
* Apps, deren Verzeichnis ein `.git` enthält: „App … is a git clone - it will
  not be deleted." Ein solches Verzeichnis wird nie automatisch gelöscht.

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| Markt zeigt „Installieren und Updaten von Apps nicht unterstützt!" | App-Verzeichnis für den Webserver nicht beschreibbar, oder `operation.mode` steht auf `clustered-instance` | Rechte setzen; sonst das Paket von Hand entpacken, siehe [Weg 2](#weg-2-paket-von-hand-nach-apps) |
| Kein Eintrag *Markt* im App-Menü | Markt-App abgeschaltet, oder das Konto ist kein Administrator | `occ app:enable market`; der Eintrag ist Administratoren vorbehalten |
| `occ market:…` meldet „Command … is not defined" | Die Markt-App ist abgeschaltet und bringt ihre Befehle deshalb nicht mit | `occ app:enable market` |
| Entpackte App erscheint nicht in `app:list` | Verzeichnisname weicht von der `<id>` in `appinfo/info.xml` ab, oder `info.xml` fehlt | Verzeichnis exakt auf den Bezeichner umbenennen |
| `occ app:enable` antwortet „&lt;app_id&gt; not found" | Die App liegt in keinem der Verzeichnisse aus `apps_paths` | Ablageort prüfen, `occ app:getpath <app_id>` nutzen |
| „App … cannot be installed because the following dependencies are not fulfilled" | Server- oder PHP-Version passt nicht, oder eine Bibliothek fehlt | Meldung lesen, passende App-Fassung wählen oder die fehlende Erweiterung nachinstallieren |
| *Aktivieren* ist ausgegraut, oder der Markt meldet *Fehlende Abhängigkeiten* | dieselbe Ursache, nur in der Oberfläche statt auf der Konsole | siehe vorige Zeile |
| „Es wurden keine Apps für deine Version gefunden" | Zur Serverversion passt keine der aufgeführten Apps | Katalog auf eine für diese Serverversion freigegebene Fassung prüfen |
| Neue App-Dateien liegen an Ort und Stelle, es läuft weiter die alte Fassung | Der in der Datenbank vermerkte `installed_version` ist noch der alte, die Migrationen fehlen | `sudo -u www-data php8.4 occ upgrade` |
| Nach einem Server-Update fehlen nachinstallierte Apps | Sie lagen unter `apps/` und wurden vom neuen Codebaum überschrieben | Zweites Verzeichnis über `apps_paths` einrichten und Apps dorthin installieren |
| Fehler „can't be disabled" | `files`, `dav` und `federatedfilesharing` sind fest eingeschaltet | Diese drei Apps lassen sich nicht abschalten |
| Fehler „can't be enabled for groups." | Die App ist vom Typ `filesystem`, `prelogin`, `authentication`, `logging`, `prevent_group_restriction` oder `theme` | Solche Apps nur für die ganze Instanz aktivieren |
| „Shipped apps cannot be uninstalled" | Die App gehört zum Server | Nur `app:disable`; entfernt wird sie nicht |
| „App … is a git clone - it will not be deleted." | Im App-Verzeichnis liegt ein `.git` | Verzeichnis bewusst von Hand entfernen |
| App startet nicht, Protokoll meldet Rechte- oder Lesefehler | Dateien gehören nach dem Entpacken `root` | `sudo chown -R www-data:www-data` auf das App-Verzeichnis |
| Markt zeigt eine veraltete Katalogliste | Die Katalogantwort ist zwischengespeichert | Im Markt *Cache leeren* aufrufen |

Bleibt die Ursache unklar, hilft das Serverprotokoll weiter, siehe
[Serverprotokoll und Fehlermeldungen](logging.md). Eine Übersicht aller
`occ`-Befehle steht unter [occ — die Kommandozeile](occ-reference.md).
