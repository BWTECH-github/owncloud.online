# Webhosting ohne Root-Rechte

Diese Variante ist für Webhosting-Pakete gedacht, bei denen kein `apt`, kein Composer und kein npm auf dem Zielserver ausgeführt werden kann.

## Voraussetzungen

- PHP 8.4 ist im Hosting-Menü auswählbar.
- MySQL oder MariaDB ist verfügbar.
- ZIP-Dateien können im Dateimanager entpackt werden.
- `.htaccess` und Rewrite-Regeln funktionieren.
- Webcron oder PHP-Cron ist verfügbar.
- `memory_limit` mindestens 256 MB, besser 512 MB.

## Paket bauen

Auf dem Windows-PC:

```powershell
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Build-PluginPackages.ps1
powershell -ExecutionPolicy Bypass -File C:\git\owncloud-online-release-tools\Create-WebhostingBundle.ps1
```

Ergebnis:

```text
C:\git\_webhosting_bundle\owncloud-online-webhosting-bundle-YYYYMMDD-HHMMSS.zip
```

## Upload

1. Datenbank im Hosting-Menü anlegen.
2. ZIP-Datei hochladen.
3. ZIP-Datei im Dateimanager entpacken.
4. Inhalt aus `owncloud/` in den gewünschten Webroot verschieben.
5. Domain im Browser öffnen.
6. Admin-User, Datenverzeichnis und Datenbankdaten eintragen.

## Datenverzeichnis

Empfohlen ist ein Pfad außerhalb des Webroots:

```text
/home/WEBUSER/owncloud-data
```

Wenn der Hoster das nicht erlaubt, kann `data/` im Installationsordner liegen. Dann muss `.htaccess` funktionieren, sonst ist das Setup nicht sicher.

## Cron ohne SSH

Wenn nur URL-Cron möglich ist:

```text
https://cloud.example.com/cron.php
```

Intervall: alle 15 Minuten.

## Typische Fehler

| Fehler | Ursache |
| --- | --- |
| `vendor/autoload.php missing` | Falsches Paket hochgeladen, nicht das Webhosting-Bundle |
| `500 Internal Server Error` | PHP-Version, Extension oder `.htaccess` fehlerhaft |
| Data-Verzeichnis erreichbar | Data-Verzeichnis liegt im Webroot und `.htaccess` greift nicht |
| Apps fehlen | Nicht das Bundle mit fertigen Plugin-Paketen verwendet |
