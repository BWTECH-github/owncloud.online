# Verschlüsselung

owncloud.online kann Dateien serverseitig verschlüsselt ablegen. Das schützt die
Inhalte auf dem Speicher — vor allem bei externem Speicher, der nicht unter
eigener Kontrolle steht. Es ist **keine** Ende-zu-Ende-Verschlüsselung: Der
Server verarbeitet die Schlüssel, ein Angreifer mit Zugriff auf den laufenden
Server kann Inhalte lesen.

!!! warning "Vor dem Aktivieren lesen"
    Verschlüsselung ist nachträglich nur mit Aufwand rückgängig zu machen und
    macht bei Schlüsselverlust die Daten **endgültig** unlesbar. Vor der
    Aktivierung ein vollständiges Backup von Daten **und** Datenbank anlegen
    (siehe [Backups und Updates](backups-updates.md)) und die Wiederherstellung
    vorher testen.

## Wann sie sinnvoll ist

| Fall | Empfehlung |
| --- | --- |
| Externer Speicher (S3, SMB, andere Anbieter) | sinnvoll — der fremde Speicher sieht nur Chiffrat |
| Eigener Server, eigene Festplatten | meist Festplattenverschlüsselung (LUKS) vorziehen: einfacher, keine Schlüsselverwaltung in der Anwendung |
| Schutz gegen Server-Administratoren | ungeeignet — der Server hat die Schlüssel |

## Aktivieren

```bash
# App aktivieren
sudo -u www-data php8.4 /var/www/owncloud.online/occ app:enable encryption

# Verschlüsselung einschalten
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:enable

# Standard-Modul aktivieren
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:enable-master-key

# Status prüfen
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:status
```

Bereits vorhandene Dateien werden dabei **nicht** automatisch verschlüsselt —
nur neu geschriebene. Bestand nachträglich verschlüsseln:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:encrypt-all
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

Das läuft je nach Datenmenge lange und sollte in einer Sitzung laufen, die nicht
abbricht (`screen`, `tmux` oder systemd-Unit).

## Master-Key oder benutzereigene Schlüssel

| | Master-Key | Benutzerschlüssel |
| --- | --- | --- |
| Passwortwechsel eines Benutzers | unkritisch | Schlüssel wird umgeschlüsselt |
| Passwort vergessen | Daten bleiben lesbar | Daten ohne Wiederherstellungsschlüssel verloren |
| Freigaben, Federation | unproblematisch | eingeschränkter |
| Empfehlung | **Standard für owncloud.online** | nur mit klarem Bedarf |

Bei benutzereigenen Schlüsseln unbedingt einen Wiederherstellungsschlüssel
aktivieren, sonst führt jedes vergessene Passwort zu Datenverlust.

## Deaktivieren

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --on
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:decrypt-all
sudo -u www-data php8.4 /var/www/owncloud.online/occ encryption:disable
sudo -u www-data php8.4 /var/www/owncloud.online/occ maintenance:mode --off
```

`encryption:decrypt-all` braucht Zugriff auf alle Schlüssel — mit
benutzereigenen Schlüsseln also die Passwörter beziehungsweise den
Wiederherstellungsschlüssel. Erst danach die App abschalten: Ohne aktive App
sind verschlüsselte Restbestände nicht mehr lesbar.

## Betriebshinweise

- **Schlüssel mitsichern**: Sie liegen im Datenverzeichnis unter
  `files_encryption/` beziehungsweise `<benutzer>/files_encryption/`. Ein Backup
  ohne diese Verzeichnisse ist wertlos.
- **Platzbedarf** steigt leicht (Blockstruktur und Header je Datei).
- **Vorschaubilder** müssen serverseitig entschlüsselt werden — das kostet CPU,
  siehe [Performance](performance.md).
- Fehler beim Ver-/Entschlüsseln stehen im Protokoll, siehe
  [Serverprotokoll und Fehlermeldungen](logging.md).
