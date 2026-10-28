# Installation

owncloud.online läuft auf einem Linux-Server mit PHP 8.4, einem Webserver und
einer Datenbank. Es gibt drei Wege zur fertigen Installation. Die Tabelle hilft
bei der Wahl.

## Welche Variante passt? {#variante}

| Variante | Wofür | Was Sie brauchen | Anleitung |
| --- | --- | --- | --- |
| Linux-Server | Betrieb, empfohlen | eigener Server oder VPS mit Ubuntu 24.04 LTS oder Debian 12, Zugang per SSH mit Administratorrechten | [Installation auf einem Linux-Server](linux-server.md) |
| Webhosting mit Plesk | kleine Teams ohne eigenen Server | Hosting-Paket mit PHP 8.4, MySQL oder MariaDB und geplanten Aufgaben | [Installation im Webhosting mit Plesk](webhosting.md) |
| Windows mit WSL | Ausprobieren auf dem eigenen Rechner, nicht für den Betrieb | Windows 10 ab Version 2004 oder Windows 11 | [owncloud.online unter Windows testen](local-wsl.md) |

Im Zweifel nehmen Sie den Linux-Server. Nur dort stehen alle Wartungsbefehle
zur Verfügung. Im Webhosting lässt sich owncloud.online ohne Kommandozeile nur
bis 50 Konten aktualisieren, Einzelheiten unter
[Grenzen des Webhostings](webhosting.md#grenzen).

## Voraussetzungen auf einen Blick {#voraussetzungen}

| Bestandteil | Anforderung |
| --- | --- |
| Betriebssystem | Linux, 64 Bit. Beschrieben sind Ubuntu 24.04 LTS und Debian 12. Windows nur über WSL und nur zum Testen. |
| PHP | 8.4 mit PHP-FPM. Ältere Versionen starten nicht. PHP 8.5 wird nicht unterstützt. |
| Datenbank | MariaDB oder MySQL. SQLite nur zum Testen. |
| Webserver | Apache 2.4 oder nginx |
| Arbeitsspeicher | mindestens 2 GB |
| Speicherplatz | rund 120 MB für das Programm, dazu Platz für Apps und für Ihre Dateien |
| Adresse | ein eigener Name wie `cloud.example.com`, der auf den Server zeigt |
| HTTPS | Pflicht für den Betrieb. Ein Zertifikat von Let's Encrypt genügt. |
| Hintergrund-Jobs | ein Zeitplan, der alle 15 Minuten einen Befehl startet (Cron oder „Geplante Aufgaben“) |

Legen Sie vor dem Start außerdem bereit:

- Zugang zu den DNS-Einstellungen Ihrer Domain,
- einen SMTP-Zugang für den Mailversand (Passwort zurücksetzen, Einladungen,
  Benachrichtigungen),
- einen Speicherort für Sicherungen außerhalb des Servers.

## Das Release {#release}

Alle Varianten verwenden das fertige Release von
<https://github.com/BWTECH-github/owncloud.online/releases>. Gebaut oder
kompiliert wird nichts.

| Datei | Inhalt |
| --- | --- |
| `owncloud-online-<version>.tar.gz` | das Programm für Linux-Server und WSL |
| `owncloud-online-<version>.zip` | derselbe Inhalt für das Hochladen im Webhosting |
| `SHA256SUMS.txt` | Prüfsummen, um den Download zu kontrollieren |

Beide Archive enthalten einen einzigen Ordner `owncloud/` mit dem Server, den
mitgelieferten Apps und der App „Market“, über die Sie weitere Apps aus dem
Marketplace installieren. Eine `config/config.php` ist nicht enthalten, die
schreibt die Installation.

??? note "Weitere Dateien im Release"
    | Datei | Inhalt |
    | --- | --- |
    | `sbom-owncloud-online-<version>.cdx.json` | Stückliste der enthaltenen Komponenten im Format CycloneDX |
    | `release-manifest.json` | Version und PHP-Version des Builds |
    | `removed-release-files.txt` | Entwicklungsdateien, die beim Bau entfernt wurden |

## Nach der Installation {#danach}

1. Melden Sie sich an und prüfen Sie unter *Einstellungen → Administration →
   Allgemein* den Abschnitt *Sicherheits- & Einrichtungswarnungen*, siehe
   [Sicherheit und Setup-Warnungen](../administration/security-hardening.md).
2. Richten Sie den [E-Mail-Versand](../administration/email.md) ein.
3. Richten Sie eine Sicherung ein, siehe
   [Backups und Updates](../administration/backups-updates.md). Dort steht
   auch, wie Sie owncloud.online aktualisieren.
4. Installieren Sie weitere Apps, siehe
   [Apps und Marketplace](../administration/apps-market.md).
5. Legen Sie Konten und Gruppen an, siehe
   [Benutzer und Gruppen](../administration/users-groups.md).
6. Verteilen Sie den [Desktop-Client](../user/desktop-client.md).

## Kurzer Funktionstest {#funktionstest}

Rufen Sie im Browser `https://cloud.example.com/status.php` auf, mit Ihrer
eigenen Adresse. Eine laufende Installation antwortet so:

```json
{"installed":true,"maintenance":false,"needsDbUpgrade":false,"version":"11.0.25","versionstring":"11.0.25","edition":"Community","productname":"owncloud.online","product":"owncloud.online"}
```

`installed` muss `true` sein, `maintenance` und `needsDbUpgrade` müssen
`false` sein.
