# owncloud.online unter Windows testen

Mit dem Windows-Subsystem für Linux (WSL) läuft ein echtes Ubuntu auf Ihrem
Windows-Rechner. Darin installieren Sie owncloud.online wie auf einem Server und
öffnen es im Browser unter `http://localhost`.

!!! warning "Nur zum Testen"
    Diese Installation ist zum Ausprobieren gedacht, nicht für den Betrieb. Sie
    ist nur auf Ihrem Rechner erreichbar, läuft nur, solange die
    Linux-Umgebung läuft, und hat weder HTTPS noch eine Sicherung. Für den
    Betrieb nehmen Sie einen [Linux-Server](linux-server.md).

## Was Sie brauchen {#voraussetzungen}

- Windows 10 ab Version 2004 (Build 19041) oder Windows 11
- Administratorrechte auf dem Rechner
- eingeschaltete Virtualisierung im BIOS oder UEFI, bei den meisten Rechnern
  ab Werk
- mindestens 5 GB freien Speicherplatz

## 1. WSL und Ubuntu installieren {#wsl}

Öffnen Sie PowerShell als Administrator: Rechtsklick auf *Start*, dann
*Terminal (Administrator)* unter Windows 11 oder *Windows PowerShell
(Administrator)* unter Windows 10. Installieren Sie WSL mit Ubuntu 24.04:

```powershell
wsl --install -d Ubuntu-24.04
```

Verlangt Windows einen Neustart, starten Sie neu. Danach öffnet sich das
Ubuntu-Fenster von selbst, sonst starten Sie *Ubuntu 24.04 LTS* über das
Startmenü. Beim ersten Start legen Sie einen Benutzernamen und ein Passwort für
Linux an. Das Passwort fragt Ubuntu später bei jedem Befehl mit `sudo` ab.

Prüfen Sie in PowerShell, ob Ubuntu unter WSL 2 läuft:

```powershell
wsl -l -v
```

In der Zeile `Ubuntu-24.04` steht in der Spalte `VERSION` eine `2`.

!!! note "Ubuntu-Version angeben"
    `wsl --install` ohne `-d Ubuntu-24.04` installiert die neueste
    Ubuntu-Version. Ubuntu 26.04 bringt nur PHP 8.5 mit, owncloud.online
    braucht PHP 8.4.

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | Fehler `0x80370102` | Virtualisierung ist ausgeschaltet | im BIOS oder UEFI einschalten, oft „Intel VT-x“, „AMD-V“ oder „SVM“ genannt |
    | Fehler `0x8007019e` | Die WSL-Funktion von Windows fehlt | `wsl --install` ohne Zusatz ausführen, neu starten, dann den Befehl oben wiederholen |
    | `wsl -l -v` zeigt `VERSION 1` | Ubuntu läuft unter dem alten WSL 1 | `wsl --set-version Ubuntu-24.04 2` |

## 2. owncloud.online in Ubuntu installieren {#installieren}

Alle folgenden Befehle geben Sie im Ubuntu-Fenster ein. Es sind die Schritte
der Anleitung für den [Linux-Server](linux-server.md) in Kurzform, ohne Domain,
Firewall und HTTPS. Dort steht auch, was zu tun ist, wenn ein Schritt nicht
klappt.

**Programme installieren.** Der Block bringt Ubuntu auf den neuesten Stand und
installiert Apache, MariaDB, Redis und PHP 8.4, dazu cron, curl und nano,
falls sie fehlen:

```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y apache2 mariadb-server redis-server \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-curl php8.4-gd php8.4-intl \
  php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-imagick \
  php8.4-apcu php8.4-redis cron curl nano
```

`systemctl is-active apache2 mariadb redis-server php8.4-fpm` gibt danach
viermal `active` aus.

**PHP einstellen.** Längere Zeitgrenzen und ein größerer OPcache:

```bash
sudo tee /etc/php/8.4/fpm/conf.d/90-owncloud-online.ini > /dev/null <<'EOF'
max_execution_time = 3600
max_input_time = 3600
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 32
opcache.max_accelerated_files = 24000
EOF
echo 'apc.enable_cli = 1' | sudo tee /etc/php/8.4/cli/conf.d/90-owncloud-online.ini > /dev/null
sudo systemctl restart php8.4-fpm
```

**Datenbank anlegen.** Erzeugen Sie ein Passwort und notieren Sie es:

```bash
openssl rand -hex 20
sudo mariadb
```

Ersetzen Sie `DATENBANK_PASSWORT` durch das notierte Passwort und fügen Sie die
Zeilen in die Konsole von MariaDB ein:

```sql
CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'owncloud'@'localhost' IDENTIFIED BY 'DATENBANK_PASSWORT';
GRANT ALL PRIVILEGES ON owncloud_online.* TO 'owncloud'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**Herunterladen, prüfen und auspacken:**

```bash
cd /tmp
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/owncloud-online-11.0.25.tar.gz
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/SHA256SUMS.txt
sha256sum --ignore-missing -c SHA256SUMS.txt
sudo tar -xzf /tmp/owncloud-online-11.0.25.tar.gz -C /var/www
sudo mv /var/www/owncloud /var/www/owncloud.online
sudo mkdir -p /var/oco-data
sudo chown -R www-data:www-data /var/www/owncloud.online /var/oco-data
sudo chmod 750 /var/oco-data
```

Die Prüfung meldet `owncloud-online-11.0.25.tar.gz: OK`.

**Installieren.** Der Befehl fragt zuerst das Datenbank-Passwort und dann ein
neues Passwort für das Administratorkonto `admin` ab:

```bash
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ maintenance:install \
  --database mysql \
  --database-name owncloud_online \
  --database-user owncloud \
  --admin-user admin \
  --data-dir /var/oco-data
```

Die Installation meldet `owncloud.online was successfully installed`. Die
Adresse `localhost` ist bereits eingetragen. Schalten Sie danach Adressen ohne
`index.php` ein:

```bash
sudo -u www-data php8.4 occ config:system:set htaccess.RewriteBase --value /
sudo -u www-data php8.4 occ maintenance:update:htaccess
```

**Webserver einrichten.** Schalten Sie die Module ein und öffnen Sie eine neue
Konfigurationsdatei:

```bash
sudo a2enmod rewrite headers env dir mime setenvif proxy_fcgi
sudo nano /etc/apache2/sites-available/owncloud-online.conf
```

Fügen Sie diesen Inhalt ein. Speichern Sie mit Strg+O und der Eingabetaste,
beenden Sie nano mit Strg+X.

```apache
<VirtualHost *:80>
    DocumentRoot /var/www/owncloud.online

    <Directory /var/www/owncloud.online>
        Require all granted
        AllowOverride All
        Options FollowSymLinks
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
```

Schalten Sie die Konfiguration ein und die Standardseite von Apache aus:

```bash
sudo a2ensite owncloud-online
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl restart apache2
```

`configtest` meldet `Syntax OK`. Die Warnung `AH00558` können Sie ignorieren.

**Hintergrund-Jobs und Zwischenspeicher.** Für einen kurzen Test nicht nötig,
für einen Test über mehrere Tage empfohlen:

```bash
echo '*/15 * * * * /usr/bin/php8.4 /var/www/owncloud.online/occ system:cron' | sudo crontab -u www-data -
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ background:cron
sudo -u www-data php8.4 occ config:system:set memcache.local --value '\OC\Memcache\APCu'
sudo -u www-data php8.4 occ config:system:set memcache.locking --value '\OC\Memcache\Redis'
sudo -u www-data php8.4 occ config:system:set redis host --value 127.0.0.1
sudo -u www-data php8.4 occ config:system:set redis port --value 6379 --type integer
```

## 3. owncloud.online öffnen {#oeffnen}

Öffnen Sie in einem Browser unter Windows `http://localhost`. Geben Sie unter
*Benutzername oder E-Mail* `admin` ein, darunter das *Passwort* aus dem
Installationsschritt, und klicken Sie auf *Anmelden*.

![Anmeldeseite von owncloud.online mit den Feldern für Benutzername oder E-Mail und Passwort](../assets/screenshots/de/login.png)

## Starten und stoppen {#starten-stoppen}

| Was | Wie |
| --- | --- |
| Starten | *Ubuntu 24.04 LTS* über das Startmenü öffnen. Apache, MariaDB, Redis und PHP starten von selbst. |
| Laufen lassen | das Ubuntu-Fenster geöffnet lassen |
| Anhalten | in PowerShell `wsl --terminate Ubuntu-24.04` |
| Alle Linux-Umgebungen anhalten | in PowerShell `wsl --shutdown` |
| Alles entfernen | in PowerShell `wsl --unregister Ubuntu-24.04`. Das löscht Ubuntu samt owncloud.online und allen Dateien darin. |

Schließen Sie das Ubuntu-Fenster, hält Windows die Linux-Umgebung nach kurzer
Zeit an, und `http://localhost` antwortet nicht mehr. Öffnen Sie Ubuntu
wieder, ist owncloud.online nach wenigen Sekunden zurück.

## Wenn es nicht klappt {#fehlersuche}

| Was Sie sehen | Ursache | Lösung |
| --- | --- | --- |
| `System has not been booted with systemd as init system` | systemd ist in WSL ausgeschaltet | systemd einschalten, siehe unten |
| `http://localhost` lädt nicht | Ubuntu ist angehalten | Ubuntu über das Startmenü öffnen |
| `http://localhost` zeigt eine fremde Seite | Ein Windows-Programm wie IIS belegt Port 80 | Apache auf Port 8080 umstellen, siehe unten |
| Andere Geräte im Netz erreichen owncloud.online nicht | WSL ist nur auf diesem Rechner erreichbar | gewollt. Für Tests mit mehreren Geräten nehmen Sie einen [Linux-Server](linux-server.md). |

systemd einschalten: Führen Sie in Ubuntu diesen Befehl aus, dann in
PowerShell `wsl --shutdown`, und öffnen Sie Ubuntu neu.

```bash
printf '\n[boot]\nsystemd=true\n' | sudo tee -a /etc/wsl.conf
```

Apache auf Port 8080 umstellen:

```bash
sudo sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf
sudo sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/owncloud-online.conf
sudo systemctl restart apache2
```

Danach erreichen Sie owncloud.online unter `http://localhost:8080`.
