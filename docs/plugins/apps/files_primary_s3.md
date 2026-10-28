# S3 Primary Object Storage

!!! note "Öffentlich im Marktplatz"
    Die App ist ohne API-Schlüssel im Marktplatz sichtbar und lässt sich direkt
    über den Markt installieren. Diese Seite beschreibt Version 1.6.5.

Mit dieser App legt owncloud.online die Inhalte aller Dateien in einem
S3-Bucket ab statt im Datenverzeichnis. Dateinamen, Freigaben und Rechte
bleiben in der Datenbank. Die App ist für Administratoren gedacht, die eine
neue Instanz auf Amazon S3 oder einem S3-kompatiblen Objektspeicher wie Ceph
RGW, MinIO oder Scality aufbauen. Anwender merken davon nichts.

Soll S3 nur als zusätzlicher Ordner erscheinen, ist
[External Storage: S3](files_external_s3.md) die passende App.

!!! warning "Nur auf einer leeren Instanz umstellen"
    owncloud.online verschiebt vorhandene Dateien nicht in den Bucket. Konten,
    die vor der Umstellung schon Dateien hatten, sehen diese danach nicht mehr.
    Die Dateien bleiben im Datenverzeichnis liegen. Richten Sie den
    Objektspeicher deshalb ein, bevor Konten Dateien ablegen.

## Voraussetzungen {#voraussetzungen}

| Komponente | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4 oder neuer, mit den Erweiterungen `curl`, `json`, `mbstring`, `xml` und `openssl` |
| Objektspeicher | Amazon S3 oder S3-kompatibel |
| Bucket | vorab angelegt, Versionierung eingeschaltet |
| Zugangsdaten | Access Key und Secret Key für den Bucket |

## Installation {#installation}

1. App installieren. `market:install` schaltet sie dabei ein:

    ```bash
    sudo -u www-data php8.4 occ market:install files_primary_s3

    # nur nötig, wenn die App schon vorhanden, aber ausgeschaltet ist
    sudo -u www-data php8.4 occ app:enable files_primary_s3
    ```

2. Den Bucket mit den Werkzeugen des Anbieters anlegen und dort die
   Versionierung einschalten. Region, Verschlüsselung, Lebenszyklus-Regeln und
   CORS legen Sie dabei selbst fest.
3. Den Eintrag `objectstore` in `config/config.php` ergänzen, siehe
   [Einrichtung](#einrichtung).
4. Ohne OPcache verwendet schon die nächste Anfrage den neuen Speicher. Läuft
   PHP-FPM mit OPcache, laden Sie es einmal neu:

    ```bash
    sudo systemctl reload php8.4-fpm
    ```

5. Prüfen: `occ s3:list <bucket>` aufrufen, eine Datei hochladen und den
   Befehl wiederholen. Die neue Datei erscheint als Objekt
   `urn:oid:<Datei-ID>`.

## Einrichtung {#einrichtung}

Die App hat keine eigene Einstellungsseite. Eingerichtet wird sie in
`config/config.php`:

```php
'objectstore' => [
    'class' => 'OCA\\Files_Primary_S3\\S3Storage',
    'arguments' => [
        'bucket' => 'oco-daten',
        'options' => [
            'version' => 'latest',
            'region' => 'eu-central-1',
            'endpoint' => 'https://s3.example.com',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => '<ACCESS_KEY>',
                'secret' => '<SECRET_KEY>',
            ],
        ],
        // optional
        'serversideencryption' => 'AES256',
    ],
],
```

| Schlüssel | Pflicht | Bedeutung |
| --- | --- | --- |
| `class` | ja | genau `OCA\Files_Primary_S3\S3Storage` |
| `bucket` | ja | Name des vorhandenen Buckets |
| `options.version` | ja | API-Version des S3-Clients, in der Regel `latest` |
| `options.region` | ja | Region des Buckets, zum Beispiel `eu-central-1` |
| `options.endpoint` | für alle Dienste außer AWS | vollständige Adresse des Endpunkts mit `https://` |
| `options.use_path_style_endpoint` | nein | `true` für Ceph und MinIO, `false` für AWS |
| `options.credentials.key` und `.secret` | ja | Zugangsdaten |
| `serversideencryption` | nein | Verschlüsselung im Bucket, zum Beispiel `AES256` |
| `part_size` | nein | Größe der Teile beim Hochladen großer Dateien in Byte, mindestens 5 MiB. Vorgabe 5 MiB |
| `concurrency` | nein | Zahl der Teile, die gleichzeitig hochgeladen werden. Vorgabe 3 |

Große Dateien lädt die App in Teilen hoch (Multipart-Upload). Damit gehen auch
Dateien über 5 GB.

### Befehle

| Befehl | Wirkung |
| --- | --- |
| `occ s3:list` | alle Buckets des Kontos mit dem Stand von Versionierung und CORS. Der Schlüssel muss Buckets auflisten dürfen |
| `occ s3:list <bucket>` | Objekte im Bucket |
| `occ s3:list <bucket> <objekt>` | alle Versionen und Löschmarker eines Objekts |
| `occ s3:create-bucket <bucket>` | legt einen Bucket an und schaltet die Versionierung ein. Nur für Tests gedacht |

`s3:create-bucket` fragt vor dem Anlegen nach. `--accept-warning` überspringt
die Frage, `--update-configuration` schaltet die Versionierung auch für einen
schon vorhandenen Bucket ein.

```bash
sudo -u www-data php8.4 occ s3:list oco-daten
sudo -u www-data php8.4 occ s3:list oco-daten urn:oid:42
```

## Nutzung {#nutzung}

Für Anwender ändert sich nichts. Weboberfläche, Desktop-Client, mobile Apps
und WebDAV arbeiten wie gewohnt. Ältere Stände einer Datei stammen aus der
Versionierung des Buckets und erscheinen wie sonst im Reiter *Versionen*.

## Grenzen und Hinweise {#grenzen}

- **Verschlüsselung:** Die Verschlüsselung von owncloud.online (App
  `encryption`) passt nicht zum primären Objektspeicher. Unter
  **Einstellungen → Administration → Verschlüsselung** steht dann
  „Speicherverschlüsselung ist mit S3 Object Storage nicht kompatibel.“ und
  das Einschalten ist gesperrt. Verschlüsseln Sie stattdessen im Bucket mit
  `serversideencryption`.
- **Versionen:** Versionen entstehen nur, wenn die Versionierung im Bucket
  eingeschaltet ist. owncloud.online löscht im Bucket keine älteren Versionen.
  Auch endgültig gelöschte Dateien bleiben dort als ältere Version liegen.
  Eine Lebenszyklus-Regel beim Anbieter, die ältere Versionen nach einer Frist
  entfernt, hält den Bucket klein.
- **Datensicherung:** Datenbank und Bucket gehören zusammen. Sichern Sie beide
  zum selben Zeitpunkt oder nutzen Sie Replikation und Versionierung des
  Anbieters. Siehe [Backups und Updates](../../administration/backups-updates.md).
- **Platzbedarf:** `du` auf dem Datenverzeichnis zeigt fast nichts mehr. Den
  belegten Platz nennt der Anbieter.
- **Externe Speicher:** Einen Objektspeicher als primären Speicher und
  zusätzlich eingebundene externe Speicher können Sie nebeneinander betreiben.
  Den Vergleich zeigt [Externe Speicher](../../administration/external-storage.md).

## Fehlersuche {#fehlersuche}

| Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Kein S3-Objektspeicher verfügbar“ | Endpunkt nicht erreichbar, oder er antwortet mit einem Fehler | `endpoint` und `region` prüfen, dann `occ s3:list <bucket>` |
| Hochladen und Öffnen schlagen fehl, im Serverprotokoll steht ein Zugriffsfehler (403) | Access Key, Secret Key oder die Rechte des Schlüssels stimmen nicht. Die Prüfung beim ersten Zugriff lässt Zugriffsfehler durch, damit auch Schlüssel ohne Recht auf `HeadBucket` funktionieren | `credentials` und die Rechte des Schlüssels auf den Bucket prüfen |
| „Bucket <…> existiert nicht.“ | Der Endpunkt kennt keinen Bucket mit diesem Namen, etwa wegen eines Tippfehlers oder weil `endpoint` auf einen anderen Dienst zeigt | `bucket` und `endpoint` prüfen |
| „Verbindungsoptionen und der Bucket müssen konfiguriert sein.“ | `options` oder `bucket` fehlt im Eintrag `objectstore` | Eintrag ergänzen |
| „Das Hochladen ist fehlgeschlagen. …“, im Serverprotokoll `MultipartUploadException` | Netzproblem oder Störung beim Anbieter | Serverprotokoll prüfen. Bei Backblaze B2 wiederholt die App den Upload einmal selbst |
| `S3 Primary Object Storage dependencies are missing` | Das Verzeichnis `vendor/` der App fehlt, etwa nach einer Installation aus Git | App über den Markt installieren |
| `occ s3:list` ohne Bucket meldet einen Zugriffsfehler | Der Schlüssel darf Buckets nicht auflisten | `occ s3:list <bucket>` verwenden |
| `No object store is configured.` bei `s3:list` | Der Eintrag `objectstore` fehlt in `config/config.php` | Eintrag ergänzen |
| `Cannot modify readonly property` nach einem Update | veralteter OPcache | `sudo systemctl reload php8.4-fpm` |

Die vollständige Fehlermeldung des S3-Clients steht im Serverprotokoll, siehe
[Serverprotokoll und Fehlermeldungen](../../administration/logging.md).
