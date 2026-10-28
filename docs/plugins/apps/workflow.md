# Workflow

!!! info "Nur mit API-Schlüssel"
    Der Marktplatz zeigt diese App nur Instanzen mit API-Schlüssel. Den
    Schlüssel erhalten Sie auf Anfrage von der BW-Tech GmbH. Öffnen Sie im
    **Markt** unter *Einstellungen* den Eintrag *API Schlüssel hinzufügen*,
    fügen Sie den Schlüssel ein und klicken Sie auf *Speichern*. Erscheint die
    App danach nicht im Katalog, wählen Sie *Cache leeren*. Einzelheiten stehen
    unter [Markt: API-Schlüssel eintragen](market.md#api-schluessel). Diese
    Seite beschreibt Version 1.1.4.

Workflow vergibt beim Hochladen nach Regeln automatisch Tags und löscht
Dateien mit einem bestimmten Tag nach einer Frist. Die Regeln legen
Administratoren fest. Anwender sehen nur das Ergebnis: Tags an ihren Dateien
und das Löschen nach Ablauf der Frist.

## Voraussetzungen {#voraussetzungen}

| Komponente | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4. Die App gibt PHP 7.4 bis 8.4 an und lässt sich mit neueren Versionen nicht einschalten |
| Kern-App | `systemtags` für die Tags, eingeschaltet |
| Hintergrund-Jobs | Cron läuft, sonst löscht die Aufbewahrung nichts, siehe [Hintergrund-Jobs (Cron)](../../administration/background-jobs.md) |

## Installation {#installation}

1. API-Schlüssel im Markt eintragen, wie im Kasten oben beschrieben.
2. App installieren. `market:install` schaltet sie dabei ein:

    ```bash
    sudo -u www-data php8.4 occ market:install workflow

    # nur nötig, wenn die App schon vorhanden, aber ausgeschaltet ist
    sudo -u www-data php8.4 occ app:enable workflow
    ```

Der Schlüssel kann auch in `config/config.php` stehen:
`'marketplace.key' => '<SCHLÜSSEL>'`. Er hat dann Vorrang und lässt sich in
der Oberfläche nicht mehr ändern. Findet `market:install` die App trotz
Schlüssel nicht, ist noch der Katalog ohne Schlüssel zwischengespeichert:
Leeren Sie im Markt den Cache oder warten Sie 30 Minuten.

## Einrichtung {#einrichtung}

Beide Teile der App liegen unter **Einstellungen → Administration →
Arbeitsabläufe & Tags**, in den Abschnitten *Workflow* und
*Aufbewahrungsfristen*.

![Administration, Arbeitsabläufe & Tags: Workflow und Aufbewahrungsfristen ohne Regeln](../../assets/screenshots/de/admin-workflow.png)

*Workflows vergeben Tags automatisch. Aufbewahrungsfristen löschen Dateien mit
bestimmten Tags nach der eingestellten Zeit.*

### Tags automatisch vergeben

1. Im Abschnitt *Workflow* bleibt die Auswahl auf *Tags hinzufügen*. Klicken
   Sie auf *+ Neuen Workflow hinzufügen*.
2. Einen Namen eintragen.
3. Unter *Bedingungen:* über *Bedingung auswählen* eine oder mehrere
   Bedingungen hinzufügen. Alle müssen zutreffen.
4. Unter *Tags hinzufügen:* die Tags wählen. Einen Tag, den es noch nicht gibt,
   legt die Auswahl beim Eintippen an.
5. Mit *Workflow hinzufügen* speichern. Der Workflow wirkt sofort.

Treffen mehrere Workflows zu, laufen alle.

Diese Bedingungen bietet die Auswahl an: *Dateigröße*, *Datei-MIME-Typ*,
*Benutzergruppe*, *Gerätetyp*, *Anfragetyp*, *Anfrage-URL*, *User-Agent*,
*Anfragezeit*, *Systemtag der Datei*, *Client-IP-Subnetz (IPv4)* und *(IPv6)*
sowie *Server-IP-Subnetz (IPv4)* und *(IPv6)*. Operatoren und Werte stehen
unter [Regeln und Aufbewahrung](../../administration/workflow-retention.md).

### Aufbewahrungsfristen

1. Im Abschnitt *Aufbewahrungsfristen* auf *+ Neue Regel hinzufügen* klicken.
2. Tag wählen, Zahl eintragen und die Einheit wählen: *Tage*, *Wochen*,
   *Monate* oder *Jahre*.
3. Mit *Regel hinzufügen* speichern.

Jeder Tag hat höchstens eine Frist, und eine Frist darf bis zu 100 Jahre lang
sein. Ein Hintergrund-Job löscht Dateien und Ordner, die älter als die Frist
sind und den Tag selbst oder an einem übergeordneten Ordner tragen. Maßgeblich ist die
Änderungszeit der Datei. Gelöschte Dateien landen im Papierkorb, solange die
App `files_trashbin` eingeschaltet ist. Wird ein Tag gelöscht, entfernt die App
auch seine Frist.

### Optionen in config.php

| Schlüssel | Werte | Wirkung |
| --- | --- | --- |
| `workflow.retention_engine` | `tagbased` (Vorgabe), `userbased` | `tagbased` arbeitet je Tag mit Frist, `userbased` geht die Dateien jedes Kontos durch. Nur `userbased` bietet im Panel *Leere Ordner löschen, die seit … Tagen nicht geändert wurden* |
| `workflow.tag-based-retention.use-upload-time` | `false` (Vorgabe), `true` | Die Frist zählt ab dem Hochladen statt ab der letzten Änderung |
| `workflow.branded_clients` | User-Agent-Muster und Gerätetyp | eigene Clients für die Bedingung *Gerätetyp* |

```bash
sudo -u www-data php8.4 occ config:system:set workflow.retention_engine --value=tagbased
sudo -u www-data php8.4 occ config:system:set \
  workflow.tag-based-retention.use-upload-time --value=true --type=boolean
```

Mit `use-upload-time` löscht die Aufbewahrung nur Dateien, für die ein
Hochladezeitpunkt gespeichert ist. Diesen Zeitpunkt schreibt die App für neue
Dateien erst, wenn Sie es einschalten:

```bash
sudo -u www-data php8.4 occ config:app:set workflow record_upload_time --value yes
```

Fehlt der Zeitpunkt, bleibt die Datei liegen, und im Serverprotokoll steht
`No upload time stored for file id …`.

Eigene Clients für die Bedingung *Gerätetyp* tragen Sie so ein:

```php
'workflow.branded_clients' => [
    'MyBrandedClient/*' => 'desktop_branded',
],
```

## Nutzung {#nutzung}

Anwender stellen nichts ein. Automatisch vergebene Tags erscheinen in der
Seitenleiste der Datei, sofern der Tag sichtbar ist. Läuft eine Frist ab,
verschwindet die Datei und liegt danach im Papierkorb unter *Gelöschte
Dateien*.

## Grenzen und Hinweise {#grenzen}

- Tags vergibt die App nur, wenn eine Datei neu angelegt wird. Spätere
  Änderungen an der Datei werten die Workflows nicht aus.
- Nennt eine Bedingung *Systemtag der Datei* einen Tag, den es nicht mehr gibt,
  gilt sie als nicht erfüllt. Im Serverprotokoll steht dazu eine Warnung.
- Es gibt keinen Probelauf und keinen Testmodus. Probieren Sie eine neue Frist
  zuerst an einem eigens angelegten Tag mit wenigen Dateien aus.
- Die App bringt keine eigenen `occ`-Befehle mit. Workflows und Fristen
  pflegen Sie nur in der Oberfläche.
- Eine neue Frist greift frühestens beim nächsten Lauf. Mit `tagbased` prüft
  ein Verwaltungs-Job stündlich, der Löschlauf je Tag läuft alle 24 Stunden.
- Die Aufbewahrung löscht nie einen Einhängepunkt selbst, also weder eine
  empfangene Freigabe noch den Ordner eines externen Speichers. In empfangene
  Freigaben steigt sie nicht hinab. Getaggte Dateien löscht sie beim
  Besitzer, damit verschwinden sie auch bei den Empfängern.
- Fristen lassen sich nicht nach Konto, Gruppe oder Ordner staffeln. Die
  einzige Stellschraube ist, wo der Tag hängt.
- Eine Altersgrenze für alle Dateien, ganz ohne Tags, bietet die App
  [Auto Expire](oco_expire.md).

Alle Bedingungen mit Operatoren, beide Arten der Aufbewahrung im Detail und
eine Tabelle zur Fehlersuche stehen unter
[Regeln und Aufbewahrung](../../administration/workflow-retention.md).
