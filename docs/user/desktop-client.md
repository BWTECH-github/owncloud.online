# Der Client für den Arbeitsplatz

Der Desktop-Client hält einen Ordner auf Ihrem Rechner mit owncloud.online
abgeglichen: Was Sie lokal ändern, landet auf dem Server, und was auf dem Server
geschieht, kommt auf den Rechner zurück. Er läuft unter Windows, macOS und
Linux. Die Anmeldung findet im Browser statt, deshalb wird auch ein zweiter
Faktor dort abgefragt und im Client selbst kein Passwort hinterlegt.

Diese Seite beschreibt den Desktop-Client 7.11.1 mit owncloud.online 11.0.25.

## Voraussetzungen

| Punkt | Anforderung |
| --- | --- |
| Adresse der Instanz | Muss über **https** erreichbar sein. Der Client lehnt `http://` mit „Ungültiges URL-Schema. Nur https wird akzeptiert.“ ab |
| Serverversion | 10.0.0 oder neuer. 11.0.25 erfüllt das; bei älteren Versionen warnt der Client mit „nicht unterstützte Server Version“ |
| Anmeldeverfahren | Die Instanz muss OAuth2 anbieten, also die App `oauth2` oder `openidconnect` aktiviert haben |
| Betriebssystem | Windows 64 Bit, macOS auf Apple Silicon, Linux x86_64 |

Ohne eine der beiden Anmelde-Apps kommt die Einrichtung nicht über den
Browser-Schritt hinaus. Ihre Administration prüft und aktiviert sie mit:

```bash
sudo -u www-data php8.4 occ app:list
sudo -u www-data php8.4 occ app:enable oauth2
```

Gehört die App noch nicht zur Installation, kommt sie über den Marketplace dazu
(siehe [Apps und Marketplace](../administration/apps-market.md)). Die
Einrichtung der Anmeldung selbst beschreibt
[Anmeldung über OAuth2 und OpenID Connect](../administration/single-sign-on.md).

## Wo Sie den Client bekommen

Alle Pakete stehen auf der Release-Seite des Clients:

<https://github.com/BWTECH-github/client/releases>

| Datei | Wofür |
| --- | --- |
| `owncloud.online-client-<version>-win-x64-Setup.exe` | Windows-Installer |
| `owncloud.online-client-<version>-win-x64.7z` | Windows, portabel ohne Installation |
| `owncloud.online-client-<version>-macos-arm64.dmg` | macOS auf Apple Silicon |
| `owncloud.online-client-<version>-linux-x86_64.AppImage` | Linux x86_64 |
| `SHA256SUMS.txt` | Prüfsummen aller Pakete dieser Version |

Bis 7.11.0 lag dort zusätzlich `…-win-x64.exe`, ein Installer ohne
Neustart-Logik und ohne Explorer-Anbindung. Seit 7.11.1 ist das Setup der
einzige Windows-Installer.

Auf derselben Seite stehen Vorabversionen der Reihe 11.0, zuletzt 11.0.0-beta4
vom 21. Oktober 2026. Sie sind als Beta gekennzeichnet und nicht für den
Produktivbetrieb gedacht.

Prüfsumme kontrollieren, nachdem Sie Paket und `SHA256SUMS.txt` in dasselbe
Verzeichnis geladen haben:

```bash
sha256sum -c SHA256SUMS.txt
```

Unter macOS lautet der Befehl `shasum -a 256 -c SHA256SUMS.txt`. Meldungen zu
Dateien, die Sie nicht heruntergeladen haben, können Sie übergehen. Unter
Windows zeigt PowerShell die Prüfsumme an:

```powershell
Get-FileHash .\owncloud.online-client-7.11.1-win-x64-Setup.exe -Algorithm SHA256
```

Vergleichen Sie den Wert mit der Zeile derselben Datei in `SHA256SUMS.txt`;
Groß- und Kleinschreibung spielen dabei keine Rolle.

Eine neue Version installieren Sie, indem Sie das neue Paket wie bei der
Erstinstallation ausführen; Konten und Ordner bleiben dabei erhalten.

## Installation

### Windows

Führen Sie `…-win-x64-Setup.exe` aus. Das Setup beendet einen laufenden Client,
installiert die neue Version, richtet die Explorer-Anbindung ein und bietet am
Ende an, Windows neu zu starten. Der Neustart ist nötig, damit die Anzeige im
Explorer zuverlässig arbeitet.

Verschieben Sie den Neustart, erinnert der Client beim ersten Start der neuen
Version daran: „owncloud.online wurde installiert oder aktualisiert. Um die
Installation abzuschließen, muss Windows neu gestartet werden, damit die
Explorer-Integration korrekt funktioniert.“ Zur Wahl stehen *Windows jetzt neu
starten* und *Später neu starten*. Die Frage kommt je Version nur einmal und
entfällt, wenn Windows vor weniger als 30 Minuten gestartet wurde. Bei einer
Neuinstallation erscheint sie erst, wenn ein Konto eingerichtet ist.

Für eine unbeaufsichtigte Verteilung kennt das Setup diese Schalter:

| Aufruf | Verhalten |
| --- | --- |
| `/S` | Installiert ohne Rückfragen und startet Windows nicht neu. Exit-Code `3010`: Neustart ausstehend, weil gesperrte Dateien erst dabei ersetzt werden; `0`: nichts ausstehend |
| `/S /norestart` | Wie `/S`; bleibt für bestehende Verteilungsaufträge gültig |
| `/S /forcerestart` | Installiert und startet Windows sofort neu |

Bis 7.11.0 startete `/S` Windows nach der Installation selbst neu, und
`/S /norestart` unterdrückte das. Planen Sie den Neustart in Ihrer
Softwareverteilung ein, damit Windows die Explorer-Anbindung lädt.

Ist auf dem Rechner ein Desktop-Client eines anderen Anbieters installiert, der
dieselbe Explorer-Anbindung verwendet (etwa Version 6.0.x), deinstallieren Sie
ihn vorher. Beide Programme melden dieselbe Explorer-Erweiterung und dieselbe
Deinstallations-Kennung an und stören sich gegenseitig.

`…-win-x64.7z` enthält den Client zum Entpacken, ohne Installation und ohne
Explorer-Anbindung.

### macOS

Öffnen Sie `…-macos-arm64.dmg` und ziehen Sie den Client in den
Programme-Ordner. Das Paket ist nicht mit einem Apple-Zertifikat signiert und
nicht notarisiert; beim ersten Start meldet macOS deshalb „Apple konnte nicht
überprüfen …“. Ab macOS 15 öffnen Sie das Programm über *Systemeinstellungen →
Datenschutz & Sicherheit → Dennoch öffnen*, unter macOS 14 genügt ein
Rechtsklick auf das Programm und *Öffnen*. Wahlweise auf der Kommandozeile:

```bash
xattr -dr com.apple.quarantine /Applications/owncloud.online.app
```

Virtuelle Dateien gibt es unter macOS noch nicht; der Client gleicht dort im
klassischen Verfahren ab, die Ordnerauswahl steht zur Verfügung.

### Linux

Das AppImage braucht keine Installation. Machen Sie es ausführbar und starten
Sie es:

```bash
chmod +x owncloud.online-client-<version>-linux-x86_64.AppImage
./owncloud.online-client-<version>-linux-x86_64.AppImage
```

Auch unter Linux arbeitet der Client im klassischen Verfahren, ohne virtuelle
Dateien.

## Konto einrichten

![Anmeldeseite von owncloud.online mit den Feldern für Benutzername oder E-Mail und Passwort](../assets/screenshots/de/login.png)

1. Client starten. Es erscheint *Willkommen bei owncloud.online* mit dem Hinweis
   „Geben Sie Ihre Serveradresse ein, um zu beginnen. Ihr Webbrowser wird
   geöffnet, um die Anmeldung abzuschließen.“
2. Adresse Ihrer Instanz eintragen, mit `https://` beginnend.
3. Der Client öffnet den Browser mit der Anmeldeseite Ihrer Instanz. Melden Sie
   sich dort wie gewohnt an (bei eingeschalteter Zwei-Faktor-Anmeldung fragt der
   Browser auch den Code ab) und bestätigen Sie den Zugriff, mit der App OAuth2
   über *Autorisieren*. Das Client-Fenster bleibt währenddessen offen. Öffnet
   sich der Browser nicht, kopieren Sie die Anmelde-Adresse mit *URL kopieren*
   und rufen Sie sie selbst auf.
4. Zurück im Client legen Sie unter *Erweiterte Einstellungen* fest, wie
   abgeglichen werden soll (siehe Tabelle unten), und wählen unter
   *Download-Speicherort* den lokalen Ordner. Vorbelegt ist `owncloud.online`
   in Ihrem Benutzerordner; der Benutzerordner selbst ist als Ziel nicht
   zulässig.

Die drei Abgleichverfahren zur Auswahl:

| Auswahl | Wirkung |
| --- | --- |
| *Virtuelle Dateien verwenden (Download bei Bedarf)* | Vorgabe unter Windows: Alle Dateien sind sichtbar, heruntergeladen wird beim Öffnen |
| *Alle Dateien herunterladen und synchronisieren* | Der gesamte Bestand liegt lokal |
| *Ordner manuell zum Synchronisieren auswählen* | Sie wählen die Ordner selbst aus (siehe nächster Abschnitt) |

Danach beginnt der erste Abgleich. Das Client-Fenster erreichen Sie später über
das Symbol im Infobereich (Windows) beziehungsweise in der Menüleiste (macOS)
und dort *Zeige owncloud.online*. In der Navigationsleiste des Fensters stehen
Ihr Konto sowie *Aktivität*, *Einstellungen*, *Konto hinzufügen* und *Beenden*.

## Ordner auswählen

Wenn nicht der gesamte Bestand auf den Rechner soll, wählen Sie die Ordner aus.
Im Client-Fenster öffnen Sie beim Ordner das Aktionsmenü und dort
*Synchronisierung von Unterordnern verwalten*. Es erscheint der Dialog *Zu
synchronisierende Elemente auswählen* mit dem Hinweis „Entfernte Ordner
abwählen, die nicht synchronisiert werden sollen.“ und einer Baumansicht mit
den Spalten *Name* und *Größe*.

Abgewählte Ordner werden nicht mehr abgeglichen und lokal entfernt; auf dem
Server bleiben sie unangetastet. Ein später wieder angehakter Ordner wird erneut
heruntergeladen.

Diese Auswahl steht nur zur Verfügung, solange für den Ordner **keine**
virtuellen Dateien eingeschaltet sind. Beide Verfahren lösen dieselbe Aufgabe
auf verschiedene Weise.

## Virtuelle Dateien unter Windows

Mit virtuellen Dateien sehen Sie im Explorer den vollständigen Bestand, belegen
aber nur Platz für das, was Sie tatsächlich öffnen. Das Verfahren gibt es nur
unter Windows und nur unter diesen Bedingungen:

* Der Ordner muss auf einem **NTFS**-Dateisystem liegen. Sonst meldet der
  Client „Virtuelle Dateien benötigt ein NTFS Dateisystem.“
* Ein Laufwerk als Ganzes ist nicht zulässig („Virtuelle Dateien funktionieren
  nicht mit einem Laufwerk als Synchronisationspunkt.“). Wählen Sie einen
  Ordner darunter.
* Netzlaufwerke sind ausgeschlossen („Virtuelle Dateien funktionieren nicht mit
  Netzwerk-Laufwerken.“).

Ein- und ausschalten lässt sich das je Ordner über das Aktionsmenü mit
*Virtuelle Dateien aktivieren* beziehungsweise *Virtuelle Dateien deaktivieren*.
Beim Ausschalten fragt der Client unter *Deaktiviere Unterstützung für virtuelle
Dateien?* nach: Inhalte, die derzeit nur online verfügbar sind, werden
heruntergeladen, und ein laufender Abgleich wird abgebrochen. Dafür steht die
Ordnerauswahl wieder zur Verfügung.

Was lokal liegen soll, steuern Sie über den Eintrag *Manage availability* im
selben Menü (die Beschriftung dieses Dialogs ist noch englisch). In der
Baumansicht zeigt die Spalte *Availability* den Zustand; ein Rechtsklick auf
einen Ordner bietet:

| Eintrag | Wirkung |
| --- | --- |
| *Immer auf diesem Gerät behalten* | Inhalt wird heruntergeladen und lokal gehalten |
| *Speicherplatz freigeben (nur online)* | Lokale Kopie wird verworfen, der Eintrag bleibt sichtbar |
| *Auf Standard zurücksetzen* | Es gilt wieder die Vorgabe des übergeordneten Ordners |

Damit die Ordner im Navigationsbereich des Explorers auftauchen, gibt es
unter *Einstellungen → Allgemeine Einstellungen* das Kästchen *Sync-Ordner im
Navigationsbereich des Explorers anzeigen*.

Im Explorer bietet der Client außerdem ein eigenes Untermenü *Via
owncloud.online teilen* mit *Teilen…*, *Privaten Link in die Zwischenablage
kopieren* und *Dateiversion im Webbrowser anzeigen*.

## Wenn dieselbe Datei an zwei Stellen geändert wurde

Ändern Sie eine Datei lokal, während sie auf dem Server ebenfalls geändert
wurde, entscheidet der Client nicht, welche Fassung die richtige ist. Er
behält beide:

* Ihre lokale Fassung wird umbenannt und bekommt den Zeitpunkt ihrer letzten
  Änderung angehängt, zum Beispiel
  `Angebot (conflicted copy 2026-08-13 141500).odt`.
* Die Fassung vom Server wird unter dem ursprünglichen Namen heruntergeladen.

Es geht dabei nichts verloren. Öffnen Sie beide Dateien, übernehmen Sie die
gewünschten Änderungen und löschen Sie die Konfliktkopie anschließend.

Ist die Datei in diesem Moment durch ein Programm gesperrt, meldet der Client
„Datei … wird gerade benutzt“ und versucht es beim nächsten Durchlauf erneut.
Schließen Sie das Programm, dann löst sich der Punkt von selbst.

Häufen sich Konfliktkopien immer bei denselben Dateien, arbeiten meist zwei
Geräte gleichzeitig daran, oder ein Programm schreibt fortlaufend in eine
Arbeitsdatei. Solche Dateien nehmen Sie über *Einstellungen → Allgemeine
Einstellungen → Ignorierte Dateien bearbeiten* vom Abgleich aus.

## Abgleich anhalten und wieder aufnehmen

Für alle Konten zugleich: Rechtsklick auf das Symbol im Infobereich, dort
*Pause synchronization* mit den Möglichkeiten *For 30 minutes*, *For 1 hour* und
*Until I resume*. Fortsetzen mit *Resume synchronization*; bei befristeter Pause
nennt der Eintrag zusätzlich die Uhrzeit, bis zu der pausiert wird. Diese
Menüeinträge sind noch nicht übersetzt und erscheinen auch in einer deutschen
Oberfläche englisch.

Für einen einzelnen Ordner steht im Aktionsmenü des Ordners *Synchronisation
pausieren* und danach *Synchronisation fortsetzen*. *Jetzt synchronisieren*
stößt einen Durchlauf sofort an, statt auf die nächste Prüfung zu warten. Läuft
gerade ein Abgleich, fragt der Client vor dem Anhalten unter „Synchronisation
läuft“ nach.

Unter *Einstellungen → Allgemeine Einstellungen → Netzwerk* lässt sich außerdem
*Pausiere Synchronisierung, wenn die Internetverbindung getaktet wird* setzen,
nützlich bei Mobilfunkverbindungen. Pausiert der Client aus diesem Grund, steht
beim Konto der Hinweis „Die Synchronisierung ist aufgrund einer getakteten
Internetverbindung pausiert“. Stoßen Sie in diesem Zustand *Jetzt
synchronisieren* an, fragt der Client unter „Die Internetverbindung ist
getaktet.“ nach, ob der Abgleich trotzdem erzwungen werden soll.

Ganz beenden lässt sich der Client über *Beenden* im Menü. Solange er nicht
läuft, wird nichts abgeglichen.

## Zwei-Faktor-Anmeldung und App-Passwörter {#zwei-faktor-und-app-passwoerter}

Der Client meldet sich im Browser an. Ein zweiter Faktor wird dort abgefragt und
stört den Client nicht; für den Desktop-Client brauchen Sie **kein**
App-Passwort.

Anders bei Zugängen, die Sie mit Benutzername und Passwort einrichten: ein als
Netzlaufwerk eingebundener WebDAV-Ordner, Kommandozeilen-Werkzeuge oder ältere
Anwendungen. Sobald für Ihr Konto ein zweiter Faktor gilt, weist der Server jede
solche Anmeldung mit dem Kontopasswort ab. Legen Sie dafür ein App-Passwort an:

![Persönliche Einstellungen, Sicherheit: zwei Sitzungen und zwei App-Passwörter](../assets/screenshots/de/personal-security.png)

1. In der Weboberfläche *Einstellungen → Sicherheit* öffnen.
2. Im Abschnitt *App-Passwörter / Token* unter *App-Name* einen Namen für das
   Gerät eintragen und auf *Neuen App-Passcode erstellen* klicken.
3. Der Server zeigt einmalig *Benutzername* und *Passwort / Token* an. Tragen
   Sie beides in das Programm ein; danach ist das Passwort nicht mehr
   abrufbar. Auf derselben Seite steht auch die WebDAV-Adresse Ihrer Instanz.

Ein gelöschtes App-Passwort gilt nicht mehr; das betroffene Programm muss neu
eingerichtet werden. Im Abschnitt *Sitzungen* stehen die Browser, in denen Sie
angemeldet sind; dort beenden Sie einzelne Sitzungen.

Den Desktop-Client selbst finden Sie, wenn die App OAuth2 die Anmeldung
übernimmt, im Abschnitt *OAuth 2.0* unter *Autorisierte Anwendungen* als
*Desktop Client*. Ist ein Gerät abhandengekommen, entziehen Sie dort mit dem
Papierkorb-Symbol den Zugriff. Das gilt für alle Geräte, auf denen Sie den
Desktop-Client mit diesem Konto verbunden haben; jedes davon verlangt danach
eine neue Anmeldung im Browser.

Ihre Administration kann die Anmeldung mit dem Kontopasswort auch unabhängig vom
zweiten Faktor für alle Programme sperren:

```bash
sudo -u www-data php8.4 occ config:system:set token_auth_enforced --value true --type boolean
```

Die Anmeldung im Browser bleibt davon unberührt. Einzelheiten stehen unter
[Zwei-Faktor-Anmeldung](../administration/two-factor.md).

## Protokolle des Clients

Es gibt zwei Protokolle, und nur eines davon läuft ohne Zutun mit.

**Im Abgleichordner** legt der Client bei jedem Durchlauf Zeilen in
`.owncloudsync.log` ab. Die Datei liegt unmittelbar im Wurzelverzeichnis des
abgeglichenen Ordners und ist im Explorer nur bei eingeblendeten versteckten
Dateien zu sehen. Ab 10 MiB wird sie nach `.owncloudsync.log.1` verschoben und
neu begonnen. Sie zeigt, was der Client mit den einzelnen Dateien gemacht hat.

**Das ausführliche Protokoll** ist ab Werk **ausgeschaltet** und muss vor dem
Nachstellen eines Fehlers eingeschaltet werden:

1. *Einstellungen → Allgemeine Einstellungen → Einstellungen für Logging*
   öffnen. Es erscheint das Fenster *Log-Ausgabe*.
2. Das Kästchen *Einschalten von Logging in einen temporären Ordner* setzen.
   Darüber steht unter *Logs werden - wenn eingeschaltet - geschrieben nach:*
   der Pfad, in den geschrieben wird; die Schaltfläche *Ordner öffnen* am
   unteren Rand des Fensters öffnet ihn. Das Fenster hält ausdrücklich fest:
   „Diese Einstellungen bleiben nach einem Neustart des Clients erhalten.“
3. Den Fehler nachstellen.

Geschrieben wird nach `owncloud.online.log`; ältere Läufe stehen daneben als
`owncloud.online-<zeitstempel>.log.gz`. Wie viele davon aufgehoben werden,
regelt im selben Fenster *Zu behaltende Logfiles*. Der Ordner heißt unter
Windows `%TEMP%\owncloud.online-logdir`, unter Linux
`/tmp/owncloud.online-logdir`; unter macOS nehmen Sie den im Fenster
angezeigten Pfad.

Das Fenster warnt zu Recht: „Die Protokolle enthalten sensible Informationen,
die nicht öffentlich verfügbar gemacht werden sollten.“ Darin stehen Datei- und
Ordnernamen, Ihre Serveradresse und Ihr Kontoname. Geben Sie die Protokolle nur
an Ihre eigene Administration weiter, nicht in offene Foren.

Anmeldedaten, Zugriffstoken und Cookies schreibt der Client seit 7.11.1 nicht
mehr in das ausführliche Protokoll. Protokolle älterer Versionen können solche
Daten im Klartext enthalten. Löschen Sie diese Protokolle. Entfernen Sie
außerdem die betroffenen App-Passwörter und entziehen Sie dem Desktop-Client
die Autorisierung (siehe
[Zwei-Faktor-Anmeldung und App-Passwörter](#zwei-faktor-und-app-passwoerter)).

Die Einstellungen des Clients selbst liegen unter Windows in
`%APPDATA%\owncloud.online`, unter Linux in `~/.config/owncloud.online` und
unter macOS in `~/Library/Preferences/owncloud.online`. Dort steht auch die
eigene Ausschlussliste `sync-exclude.lst`.

## Was Sie bei einer Störung mitschicken

Damit Ihre Administration nicht raten muss:

* Version des Clients und Betriebssystem. Beides steht im Client unter
  *Einstellungen* im Abschnitt *Über*.
* Adresse der Instanz und Ihr Kontoname.
* Der Wortlaut der Meldung, am besten als Bildschirmfoto des Client-Fensters.
* Zeitpunkt des Fehlers, auf die Minute genau. Ohne ihn ist das Serverprotokoll
  kaum zu durchsuchen.
* Der Pfad der betroffenen Datei oder des Ordners, samt Größe.
* Das ausführliche Protokoll aus dem oben genannten Ordner, sofern es vor dem
  Fehler eingeschaltet war, und `.owncloudsync.log` aus dem Abgleichordner.

Auf der Serverseite gehört das passende Stück Protokoll dazu, siehe
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).

## Fehlersuche

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Ungültiges URL-Schema. Nur https wird akzeptiert.“ | Die Adresse wurde mit `http://` eingetragen | Adresse mit `https://` eintragen. Ist die Instanz nur über HTTP erreichbar, muss zuerst TLS eingerichtet werden |
| „OAuth2 Anmeldung benötigt eine gesicherte Verbindung.“ | Die Anmeldung würde über eine ungesicherte Verbindung laufen | Wie vorige Zeile; auch ein Reverse-Proxy muss HTTPS durchreichen |
| Der Browser öffnet sich, danach meldet der Client „Fehler beim Versuch sich bei einem OAuth2-fähigen Server anzumelden.“ | Weder `oauth2` noch `openidconnect` ist aktiv, oder die hinterlegte Rücksprungadresse passt nicht | Administration: `sudo -u www-data php8.4 occ app:enable oauth2`, dann [Anmeldung über OAuth2 und OpenID Connect](../administration/single-sign-on.md) |
| „Die Capabilities konnten nicht vom Server abgerufen werden.“ | Die Instanz ist vom Rechner aus nicht erreichbar, ein Proxy blockt, oder das Zertifikat wird nicht anerkannt | Adresse im Browser desselben Rechners aufrufen; Zertifikatskette und Proxy prüfen |
| „Dieser Client unterstützt diesen Server nicht.“ oder Hinweis „nicht unterstützte Server Version“ | Die Instanz ist älter als 10.0.0 | Server aktualisieren; bis dahin über den Browser arbeiten |
| „Sie sind bereits mit einem Konto mit diesen Anmeldedaten verbunden.“ | Dasselbe Konto ist im Client bereits eingerichtet | Vorhandenes Konto benutzen oder zuerst entfernen |
| Die Auswahl *Virtuelle Dateien verwenden* fehlt oder wird abgewiesen | Zielordner liegt nicht auf NTFS, ist ein Laufwerk als Ganzes oder ein Netzlaufwerk; unter macOS und Linux gibt es das Verfahren nicht | Ordner auf einer lokalen NTFS-Platte wählen, nicht das Laufwerk selbst |
| „Ihr Benutzerverzeichnis kann nicht als Synchronisierungsstammverzeichnis ausgewählt werden.“ | Der Benutzerordner selbst wurde als Abgleichordner gewählt | Unterordner wählen, etwa `owncloud.online` im Benutzerordner |
| *Synchronisierung von Unterordnern verwalten* ist nicht anwählbar | Für diesen Ordner sind virtuelle Dateien eingeschaltet | Entweder über *Manage availability* steuern oder virtuelle Dateien deaktivieren |
| Keine Zustandssymbole im Explorer, kein Eintrag im Navigationsbereich | Der nach der Installation nötige Windows-Neustart steht noch aus, oder das Kästchen für den Navigationsbereich ist nicht gesetzt | Windows neu starten; *Sync-Ordner im Navigationsbereich des Explorers anzeigen* setzen |
| Ständig neue Dateien „(conflicted copy …)“ | Dieselbe Datei wird an zwei Stellen bearbeitet, oder ein Programm schreibt laufend hinein | Datei nur an einer Stelle bearbeiten; Arbeitsdateien über *Ignorierte Dateien bearbeiten* ausnehmen |
| „Datei … wird gerade benutzt“ | Die Datei ist durch ein geöffnetes Programm gesperrt | Programm schließen; der Client wiederholt den Vorgang selbständig |
| Es wird nichts mehr abgeglichen, das Symbol zeigt eine Pause | Abgleich pausiert, oder die Verbindung ist als getaktet erkannt | *Resume synchronization* wählen; gegebenenfalls das Kästchen für getaktete Verbindungen abschalten |
| Eine auf dem Server geänderte Datei kommt erst spät auf dem Rechner an | Der Client 7.11 erfährt von Änderungen auf dem Server erst beim nächsten vollständigen Abgleich (etwa stündlich) oder nach Ihrer nächsten lokalen Änderung | Im Aktionsmenü des Ordners *Jetzt synchronisieren* wählen |
| Ein Programm wird trotz richtigem Passwort abgewiesen | Für das Konto gilt ein zweiter Faktor, oder `token_auth_enforced` ist gesetzt | App-Passwort anlegen und dort eintragen |
| Auf dem Server abgelegte Dateien erscheinen nicht im Client | Die Dateien wurden am Server vorbei in den Speicher gelegt und stehen nicht im Verzeichnisbestand | Administration: `sudo -u www-data php8.4 occ files:scan <konto>` |
| „Speicherplatz fast voll“ | Das Kontingent des Kontos ist nahezu ausgeschöpft | Aufräumen, auch den Papierkorb in der Weboberfläche; sonst Kontingent erhöhen lassen |
| Lokal gelöschte Dateien fehlen auch auf dem Server | Der Client hat die Löschung wie jede andere Änderung übertragen | Datei im Papierkorb der Weboberfläche wiederherstellen |

Bleibt die Ursache unklar, schalten Sie das ausführliche Protokoll ein, stellen
den Fehler nach und geben die Angaben aus dem vorigen Abschnitt weiter.
