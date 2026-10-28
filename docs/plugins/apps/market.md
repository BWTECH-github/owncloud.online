# Markt

Mit dem Markt installieren, aktualisieren und entfernen Administratoren Apps in
der Weboberfläche. Die Apps kommen aus dem Marketplace unter
`https://marketplace.owncloud.online`. Für Server ohne Internetzugang lässt sich
stattdessen ein Katalog im Dateisystem einrichten. Die App bringt außerdem die
`occ`-Befehle `market:*` mit.

!!! note "Verfügbarkeit"
    Im Kern enthalten. Das Serverarchiv bringt die App mit und schaltet sie bei
    der Installation ein. App-ID `market`, Version 0.10.10 in owncloud.online
    11.0.25. Der Marketplace führt sie zusätzlich als öffentliche App.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 oder neuer |
| Netzwerk | ausgehende HTTPS-Verbindungen vom Server zu `marketplace.owncloud.online`. Ohne Internetzugang siehe [Ohne Internetzugang](#ohne-internetzugang). |
| App-Verzeichnis | für den Benutzer des Webservers beschreibbar, `operation.mode` steht auf `single-instance` |
| Cron | für die tägliche Suche nach Updates |

## Installation

Nichts zu tun: Die App gehört zum Server. Wurde sie abgeschaltet:

```bash
sudo -u www-data php8.4 occ app:enable market
```

Solange die App aus ist, fehlen der Eintrag **Markt** im App-Menü und alle
Befehle `occ market:*`.

## Apps installieren und aktualisieren

Den Markt öffnen Administratoren im App-Menü links oben unter **Markt**. Andere
Konten sehen den Eintrag nicht.

![Market-App, Ansicht Entdecken mit App-Kacheln und Kategorien](../../assets/screenshots/de/market.png)

*Die Ansicht Entdecken: links Suche, Kategorien und Einstellungen, rechts die Apps aus dem Marketplace.*

| Eintrag in der Seitenleiste | Inhalt |
| --- | --- |
| **Apps suchen …** | filtert die angezeigte Liste |
| **Entdecken** | alle Apps des Katalogs |
| **Installierte Apps** | alle Apps dieser Instanz mit Version, Status und Kompatibilität |
| **App-Bundles** | Pakete aus mehreren Apps |
| **Updates** | Apps, für die der Katalog eine neuere Version hat |
| **Kategorien** | der Katalog nach Kategorien |
| **Einstellungen** | **API Schlüssel hinzufügen** und **Cache leeren** |

So installieren Sie eine App:

1. Die App unter **Entdecken** oder über die Suche finden und **Details**
   öffnen.
2. **Installieren** klicken.

Der Markt lädt das Paket, entpackt es in das beschreibbare App-Verzeichnis und
schaltet die App ein. Führt der Katalog eine SHA-256-Prüfsumme (der Marketplace
tut das), vergleicht der Markt das Paket vorher damit. Scheitert das
Einschalten, löscht er das entpackte Verzeichnis wieder.

Neue Versionen stehen unter **Updates**. **Aktualisieren** spielt sie ein.

Dieselben Schritte auf der Kommandozeile:

| Befehl | Wirkung |
| --- | --- |
| `occ market:list` | App-IDs aller Apps im Katalog |
| `occ market:install <app-id>` | installiert die App und schaltet sie ein. Ist sie schon da, spielt der Befehl ein Update innerhalb der Hauptversion ein. |
| `occ market:install --local <paket.tar.gz>` | installiert ein Paket aus dem Dateisystem, schaltet die App aber nicht ein. Danach `occ app:enable <app-id>` ausführen. |
| `occ market:upgrade --list` | zeigt verfügbare Updates |
| `occ market:upgrade <app-id>` oder `--all` | aktualisiert eine oder alle Apps |
| `occ market:upgrade --major <app-id>` | lässt den Wechsel der Hauptversion zu |
| `occ market:uninstall <app-id>` | schaltet die App ab und löscht ihr Verzeichnis |

Alle Befehle laufen als Benutzer des Webservers, zum Beispiel
`sudo -u www-data php8.4 occ market:list`. Was beim Entfernen mit den Daten
einer App geschieht, steht unter
[Apps und Marketplace](../../administration/apps-market.md).

## API-Schlüssel eintragen {#api-schluessel}

Manche Apps zeigt der Marketplace nur mit einem API-Schlüssel. Ihre Seiten in
dieser Dokumentation beginnen mit dem Kasten „Nur mit API-Schlüssel“. Ohne
Schlüssel fehlen diese Apps im Katalog, und `occ market:install` meldet
„Unbekannte App (…)“.

1. Den Schlüssel bei der BW-Tech GmbH anfragen.
2. Im **Markt** in der Seitenleiste unter **Einstellungen** auf
   **API Schlüssel hinzufügen** klicken.
3. Den Schlüssel in das Feld **Dein persönlicher API-Key** einfügen und
   **Speichern** klicken.

![Market-App mit geöffnetem Dialog Marketplace-API-Key und Eingabefeld](../../assets/screenshots/de/market-api-key.png)

*„API Schlüssel hinzufügen“ öffnet diesen Dialog. Den Schlüssel erhalten Sie von der BW-Tech GmbH.*

Der Markt prüft den Schlüssel beim Marketplace und lädt danach den Katalog neu.
Ist der Schlüssel angenommen, heißt der Eintrag in der Seitenleiste
**Bearbeite API Schlüssel**, und die zusätzlichen Apps stehen unter
**Entdecken**. Steht dort weiter **API Schlüssel hinzufügen**, hat der
Marketplace den Schlüssel abgelehnt, und der Markt hat ihn nicht gespeichert.

Auf der Kommandozeile:

```bash
sudo -u www-data php8.4 occ config:app:set market key --value '<API-SCHLÜSSEL>'
```

Danach im Markt **Cache leeren** wählen. Sonst arbeiten Oberfläche und
`occ market:install` bis zu 30 Minuten lang mit dem zwischengespeicherten
Katalog ohne die zusätzlichen Apps.

Steht der Schlüssel als `'marketplace.key' => '<API-SCHLÜSSEL>',` in
`config/config.php`, hat dieser Eintrag Vorrang. Der Markt blendet
**API Schlüssel hinzufügen** dann aus. Ändern lässt sich der Schlüssel nur noch
in `config.php`.

## Einstellungen in config.php

| Schlüssel | Vorgabe | Wirkung |
| --- | --- | --- |
| `appstoreurl` | `https://marketplace.owncloud.online` | Adresse des Marketplace. `file:///pfad` schaltet auf einen eigenen Katalog im Dateisystem um, `local` auf den Beispielkatalog in der App. |
| `marketplace.key` | nicht gesetzt | API-Schlüssel. Hat Vorrang vor dem Schlüssel aus der Oberfläche. |
| `marketplace.ca` | nicht gesetzt | Pfad zu einer CA-Datei, mit der der Markt das TLS-Zertifikat des Marketplace prüft, etwa hinter einem Proxy mit eigener CA |
| `has_internet_connection` | `true` | `false` sperrt Katalog und Downloads vom Marketplace. Ein Katalog im Dateisystem funktioniert weiter. Der Markt erkennt nur den Wahrheitswert `true`; mit `occ config:system:set` deshalb immer `--type boolean` angeben. |

Geht der Server über einen Proxy ins Internet, nutzt der Markt die Einträge
`proxy` und `proxyuserpwd` aus derselben Datei.

## Benachrichtigung über Updates

Ein Hintergrund-Job sucht einmal täglich nach neueren Versionen der
installierten Apps. Gemeldet werden nur Versionen, die zur laufenden
Serverversion passen. Jedes Mitglied der Gruppe `admin` bekommt dazu eine
Benachrichtigung: „Ein Update für … auf Version … ist verfügbar.“
Voraussetzung sind laufende Hintergrund-Jobs (siehe
[Hintergrund-Jobs](../../administration/background-jobs.md)) und die
eingeschaltete App [Benachrichtigungen](notifications.md).

Andere oder zusätzliche Gruppen legen Sie als JSON-Liste fest:

```bash
sudo -u www-data php8.4 occ config:app:set market notify_groups --value '["admin","it-betrieb"]'
```

## Ohne Internetzugang {#ohne-internetzugang}

Einzelne Pakete spielen Sie direkt aus dem Dateisystem ein und schalten die App
danach ein:

```bash
sudo -u www-data php8.4 occ market:install --local /srv/pakete/configreport-0.4.5.tar.gz
sudo -u www-data php8.4 occ app:enable configreport
```

Für einen eigenen Katalog legen Sie ein Verzeichnis mit den Dateien
`apps.json`, `categories.json` und `bundles.json` an, kopieren die Pakete
hinein und tragen das Verzeichnis ein:

```bash
sudo -u www-data php8.4 occ config:system:set appstoreurl --value 'file:///srv/app-katalog'
```

Ein Eintrag in `apps.json`:

```json
[
  {
    "id": "configreport",
    "name": "Admin Config Report",
    "categories": ["tools"],
    "downloadable": true,
    "releases": [
      {
        "version": "0.4.5",
        "platformMin": "11",
        "platformMax": "11.99",
        "license": "AGPL-3.0",
        "download": "configreport-0.4.5.tar.gz",
        "checksum": "sha256:<Prüfsumme>"
      }
    ]
  }
]
```

- Jede Kategorie aus `categories` muss in `categories.json` stehen, zum
  Beispiel `[{"id": "tools", "translations": {"en": {"name": "Tools"}, "de": {"name": "Werkzeuge"}}}]`.
- `bundles.json` enthält `[]`, wenn Sie keine Bundles brauchen.
- `download` ist ein Pfad relativ zum Katalogverzeichnis oder eine
  HTTPS-Adresse. Pfade außerhalb des Katalogverzeichnisses lehnt der Markt ab
  („Local app archive … is not inside the catalog directory.“).
- Mit `checksum` prüft der Markt jedes Paket nach dem Laden. Den Wert liefert
  `sha256sum <paket>`; übernehmen Sie nur die 64 Zeichen vor dem Dateinamen.
- Verzeichnis und Pakete müssen für den Benutzer des Webservers lesbar sein.
- Der Markt liest die drei Dateien bei jedem Aufruf neu.

Das vollständige Format beschreibt die Datei `marketplace/README.md` im
Verzeichnis der App. Mit dem Wert `local` für `appstoreurl` zeigt der Markt den
Katalog aus diesem Verzeichnis. Er enthält nur einen Beispieleintrag und dient
als Vorlage.

## Grenzen und Hinweise

- Den Markt sehen nur Administratoren.
- Die App kann sich nicht selbst entfernen („Market app can not uninstall
  itself.“). Mitgelieferte Apps lassen sich nur abschalten.
- Der Katalog bleibt bis zu 30 Minuten im Zwischenspeicher. **Cache leeren**
  lädt ihn sofort neu.
- Ältere Versionen spielt der Markt nicht ein. Er bietet nur Versionen an, die
  höher als die installierte sind und zur Serverversion passen.
- Ist das App-Verzeichnis nicht beschreibbar oder steht `operation.mode` auf
  `clustered-instance`, installiert der Markt nichts. Den Weg über ein Paket von
  Hand beschreibt [Apps und Marketplace](../../administration/apps-market.md).
- Ein App-Verzeichnis mit `.git` löscht `occ market:uninstall` nie.

## Fehlersuche

| Meldung | Ursache | Abhilfe |
| --- | --- | --- |
| „Unbekannte App (…)“ | Die App-ID steht nicht im Katalog: Tippfehler, App nur mit API-Schlüssel sichtbar oder der Katalog liegt noch im Zwischenspeicher | `occ market:list` prüfen, [API-Schlüssel eintragen](#api-schluessel), **Cache leeren** |
| „Ungültigen API-Key für den Marktplatz eingegeben“ | Der Marketplace lehnt den hinterlegten Schlüssel ab | Schlüssel prüfen oder bei der BW-Tech GmbH einen neuen anfragen |
| „No marketplace connection: …“ (nur englisch) | Der Server erreicht den Marketplace nicht: DNS, Firewall, Proxy oder TLS-Prüfung | Verbindung vom Server aus testen, siehe unten. Bei eigener CA `marketplace.ca` setzen. |
| „Die Internetverbindung ist deaktiviert.“ | `has_internet_connection` steht auf `false` oder wurde ohne `--type boolean` gesetzt | `occ config:system:set has_internet_connection --value true --type boolean` oder einen eigenen Katalog nutzen |
| „Downloaded package for … failed the sha256 checksum verification.“ (nur englisch) | Das geladene Paket passt nicht zur Prüfsumme im Katalog | Erneut versuchen. Bleibt der Fehler, der BW-Tech GmbH melden. |
| „Keine kompatible Version für … gefunden“ | Keine Version der App passt zur laufenden Serverversion | Serverversion mit `occ status` prüfen |

Verbindung vom Server aus testen:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://marketplace.owncloud.online/api/v1/categories.json
```

Gibt der Befehl `200` aus, stimmen Namensauflösung, Firewall und Zertifikat.
Hinter einem Proxy ergänzen Sie `-x http://<proxy>:<port>`.
