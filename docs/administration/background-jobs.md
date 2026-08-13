# Hintergrund-Jobs (Cron)

owncloud.online erledigt Aufräum- und Wartungsarbeiten in Hintergrund-Jobs:
Papierkorb und alte Dateiversionen ausräumen, Freigaben ablaufen lassen,
Vorschaubilder erzeugen, Benachrichtigungen und Aktivitäts-Mails versenden,
Verzeichnisse mit externem Speicher aktualisieren. Ist die Ausführung nicht
korrekt eingerichtet, laufen diese Aufgaben nie — die Instanz wirkt zunächst
normal, wächst aber unbegrenzt und verschickt keine Mails.

## Ausführungsarten

| Modus | Auslöser | Bewertung |
| --- | --- | --- |
| **Cron** | System-Cron ruft `cron.php` auf | **empfohlen**, einzige zuverlässige Variante |
| AJAX | Jeder Seitenaufruf eines angemeldeten Benutzers | Jobs bleiben liegen, solange niemand angemeldet ist |
| Webcron | Externer Dienst ruft `cron.php` per HTTP auf | nur wenn kein System-Cron möglich ist |

## Einrichtung mit System-Cron

```bash
sudo crontab -u www-data -e
```

Eintrag (alle 15 Minuten — kürzere Abstände bringen nichts, längere lassen
Aufgaben liegen):

```
*/15  *  *  *  * /usr/bin/php8.4 -f /var/www/owncloud.online/cron.php
```

Danach den Modus in owncloud.online auf Cron umstellen:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ background:cron
```

Umschalten auf die anderen Modi geht analog mit `background:ajax` bzw.
`background:webcron`.

## Prüfen, ob die Jobs laufen

```bash
# Zustand der Warteschlange
sudo -u www-data php8.4 /var/www/owncloud.online/occ background:queue:status

# eingestellter Modus (erwartet: cron)
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:get core backgroundjobs_mode

# Zeitpunkt des letzten Laufs (Unix-Zeit)
sudo -u www-data php8.4 /var/www/owncloud.online/occ config:app:get core lastcron
```

`lastcron` sollte nie älter als etwa 15 Minuten sein. In der Weboberfläche
zeigt **Einstellungen → Administration → Allgemein** denselben Zeitpunkt an und
warnt, wenn er zu lange zurückliegt.

Einen Lauf zum Testen manuell anstoßen:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/cron.php
```

## Typische Fehler

| Symptom | Ursache |
| --- | --- |
| „Letzte Cron-Ausführung war vor …" | Cron-Eintrag fehlt, oder er läuft unter dem falschen Benutzer |
| Jobs laufen nur bei aktiven Benutzern | Modus steht noch auf AJAX |
| Papierkorb/Versionen wachsen unbegrenzt | Cron läuft nicht — `lastcron` prüfen |
| Keine Benachrichtigungs-Mails | Cron läuft nicht, oder SMTP ist nicht konfiguriert (siehe [Linux-Server](../installation/linux-server.md)) |
| Fehler nur im Cron-Lauf | Cron muss als **www-data** laufen, nicht als root — sonst gehören neue Dateien root |

Hängengebliebene oder fehlerhafte Läufe stehen im Protokoll, siehe
[Serverprotokoll und Fehlermeldungen](logging.md).
