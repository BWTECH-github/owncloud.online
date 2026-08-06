# Serverprotokoll und Fehlermeldungen

Wenn die Oberfläche „Interner Serverfehler" meldet, steht der eigentliche Grund
immer im Serverprotokoll. Diese Seite erklärt, wo es liegt, wie man den passenden
Eintrag zu einer Fehlermeldung findet und was in einen Fehlerbericht gehört.

## Wo liegt das Protokoll

Standardmäßig im Datenverzeichnis:

```
<datadirectory>/owncloud.log
```

Das Datenverzeichnis steht in `config/config.php` unter `datadirectory` (typisch
`/var/www/owncloud/data`). Ein anderer Ort lässt sich über `logfile` setzen:

```php
'logfile' => '/var/log/owncloud/owncloud.log',
```

Jede Zeile ist ein JSON-Objekt mit unter anderem diesen Feldern:

| Feld | Bedeutung |
| --- | --- |
| `reqId` | **Anfragekennung** — dieselbe, die der Fehlerbildschirm anzeigt |
| `level` | 0 = Debug, 1 = Info, 2 = Warnung, 3 = Fehler, 4 = Fatal |
| `time` | Zeitstempel |
| `user` | Betroffenes Konto (oder `--`) |
| `method` / `url` | Aufgerufener Endpunkt |
| `message` | Fehlermeldung, bei Ausnahmen mit Klasse und Stack-Trace |

## Den Eintrag zu einer Fehlermeldung finden

Der Fehlerbildschirm zeigt unter „Technische Details" eine **Anfragekennung**
(z. B. `ZXXTJzcZ10tDSMNjmlAf`). Damit lässt sich der exakte Vorgang im Protokoll
finden — auch auf einer Instanz mit vielen Zugriffen:

```bash
grep 'ZXXTJzcZ10tDSMNjmlAf' data/owncloud.log
```

Lesbar formatiert (mit `jq`):

```bash
grep 'ZXXTJzcZ10tDSMNjmlAf' data/owncloud.log | jq '{time, level, app, method, url, message}'
```

Nur die letzten Fehler ansehen, unabhängig von einer Kennung:

```bash
tail -n 200 data/owncloud.log | jq 'select(.level >= 3) | {time, app, message}'
```

## Protokollierung einstellen

```bash
# Detailtiefe: 0 = Debug ... 4 = Fatal (Standard: 2)
occ log:manage --level 1

# aktuelle Einstellungen anzeigen
occ log:manage

# Log-Rotation, damit die Datei nicht unbegrenzt wächst (Bytes, hier 100 MB)
occ config:system:set log_rotate_size --value 104857600 --type integer
```

`--level 0` (Debug) nur vorübergehend zur Fehlersuche einschalten: Es erzeugt sehr
viele Einträge und kann Details enthalten, die nicht dauerhaft gespeichert werden
sollten. Danach wieder auf `2` zurückstellen.

## Was in einen Fehlerbericht gehört

Damit ein Problem ohne Rückfragen bearbeitet werden kann:

1. **Anfragekennung** vom Fehlerbildschirm.
2. **Protokollzeilen** zu dieser Kennung (siehe oben) — Stack-Trace vollständig.
3. **Versionen**: `occ status` (Server) und die Client-Version, falls betroffen.
4. **Was genau gemacht wurde**, Schritt für Schritt, und ob es reproduzierbar ist.
5. **Wann es zuletzt funktioniert hat** (insbesondere: vor oder nach einem Update).

Vor dem Versenden Zugangsdaten, Tokens und personenbezogene Pfade schwärzen.

## Häufige Ursachen

### Interner Serverfehler direkt nach einem Update

Fast immer sind die Datenbank-Migrationen noch nicht gelaufen — der Code erwartet
dann Spalten, die es in der Datenbank noch nicht gibt. Symptomatisch: Anmeldung
oder einzelne Bereiche brechen mit Fehler 500 ab, im Protokoll steht eine
`InvalidFieldNameException` bzw. „no column named …".

```bash
sudo -u www-data php occ upgrade
```

Meldet `occ upgrade`, das System sei aktuell, obwohl Migrationen fehlen, lassen
sie sich einzeln nachziehen:

```bash
sudo -u www-data php occ migrations:status core
sudo -u www-data php occ migrations:execute core <Version>
```

Nach jedem Update gehört `occ upgrade` fest zum Ablauf — siehe
[Backups und Updates](backups-updates.md).

### Wartungsmodus bleibt aktiv

```bash
occ maintenance:mode --off
```

### Weitere Symptome

Anmeldeschleifen, JavaScript-Fehler, fehlende Plugin-Abhängigkeiten und
Client-Anmeldeprobleme sind unter [Troubleshooting](troubleshooting.md)
beschrieben.
