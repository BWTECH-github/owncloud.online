# Installation auf einem Linux-Server

Nach diesen zwölf Schritten läuft owncloud.online mit HTTPS auf Ihrem Server.
Sie brauchen dafür nur einen SSH-Zugang mit Administratorrechten. Jeder Befehl
lässt sich kopieren und einfügen.

Die Befehle gelten für **Ubuntu 24.04 LTS**. Wo **Debian 12** abweicht, steht
ein Hinweis. Geprüft wurde die Anleitung mit owncloud.online 11.0.25 auf
frischen Installationen beider Systeme.

Ersetzen Sie in den Befehlen diese Platzhalter:

| Im Text | Bedeutung | Was Sie tun |
| --- | --- | --- |
| `cloud.example.com` | Adresse Ihrer Cloud | durch Ihre Adresse ersetzen |
| `DATENBANK_PASSWORT` | Passwort des Datenbankkontos | durch das Passwort aus [Schritt 4](#datenbank) ersetzen |
| `/var/www/owncloud.online` | Programmverzeichnis | so lassen |
| `/var/oco-data` | Datenverzeichnis mit allen hochgeladenen Dateien | so lassen oder einen eigenen Pfad wählen, etwa auf einer zweiten Platte |

Die Schritte im Überblick:

1. [Server vorbereiten](#server-vorbereiten)
2. [Programme installieren](#programme)
3. [PHP einstellen](#php-einstellen)
4. [Datenbank anlegen](#datenbank)
5. [owncloud.online herunterladen und prüfen](#herunterladen)
6. [Auspacken und Rechte setzen](#auspacken)
7. [Installieren](#installieren)
8. [Webserver einrichten](#webserver)
9. [HTTPS einschalten](#https)
10. [Hintergrund-Jobs einrichten](#hintergrund-jobs)
11. [Zwischenspeicher einschalten](#zwischenspeicher)
12. [Anmelden und prüfen](#erste-anmeldung)

Die Installation in Schritt 7 läuft über die Kommandozeile, bevor der
Webserver die Seite ausliefert. So ist der Einrichtungsassistent zu keinem
Zeitpunkt offen im Internet erreichbar.

## 1. Server vorbereiten {#server-vorbereiten}

??? note "Debian 12: sudo fehlt"
    Debian richtet `sudo` nur ein, wenn Sie bei der Installation kein
    root-Passwort vergeben haben. Meldet der erste Befehl unten
    `sudo: command not found`, melden Sie sich mit `su -` als root an und
    richten es ein. Ersetzen Sie `IHR_BENUTZER` durch Ihren Anmeldenamen,
    danach ab- und wieder anmelden:

    ```bash
    apt install -y sudo
    usermod -aG sudo IHR_BENUTZER
    ```

### Updates einspielen

Bringen Sie das System auf den neuesten Stand, bevor Sie etwas installieren:

```bash
sudo apt update
sudo apt full-upgrade -y
sudo reboot
```

Erscheint während der Aktualisierung ein Fenster mit einer Frage zu Diensten
oder zum Kernel, bestätigen Sie mit der Eingabetaste. Nach dem Neustart melden
Sie sich wieder per SSH an.

### Adresse einrichten

Browser und Let's Encrypt finden den Server über seinen Namen. Dafür braucht
er einen Eintrag im DNS.

1. Ermitteln Sie die öffentliche IP-Adresse des Servers. Sie steht im
   Kundenmenü Ihres Anbieters. Auf den meisten Servern zeigt dieser Befehl sie
   auch an, die erste Adresse ist die IPv4-Adresse:

    ```bash
    hostname -I
    ```

2. Legen Sie bei Ihrem Domain-Anbieter einen A-Eintrag an, zum Beispiel mit dem
   Namen `cloud` und der IPv4-Adresse als Ziel. Einen AAAA-Eintrag für IPv6
   legen Sie nur an, wenn der Server unter dieser IPv6-Adresse erreichbar ist.
3. Prüfen Sie den Eintrag auf Ihrem eigenen Rechner:

    ```bash
    nslookup cloud.example.com
    ```

    Die Antwort nennt die IP-Adresse des Servers. Neue Einträge brauchen
    manchmal bis zu einer Stunde, bis sie überall bekannt sind.

### Firewall einschalten

Die Firewall lässt nur SSH, HTTP und HTTPS durch. Der erste Befehl installiert
sie, falls sie fehlt:

```bash
sudo apt install -y ufw
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

Die Rückfrage `Command may disrupt existing ssh connections. Proceed with
operation (y|n)?` beantworten Sie mit `y`. Prüfen Sie das Ergebnis:

```bash
sudo ufw status
```

Die Ausgabe beginnt mit `Status: active` und nennt `22/tcp`, `80/tcp` und
`443/tcp` mit `ALLOW`.

!!! warning "Port 22 zuerst freigeben"
    Geben Sie den SSH-Port frei, bevor Sie `ufw enable` ausführen. Läuft SSH
    bei Ihnen auf einem anderen Port als 22, setzen Sie diesen ein. Hat Ihr
    Anbieter zusätzlich eine Firewall im Kundenmenü, öffnen Sie dort dieselben
    drei Ports.

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | `nslookup` meldet `NXDOMAIN` oder eine fremde Adresse | Eintrag fehlt, ist falsch geschrieben oder noch nicht verteilt | Eintrag beim Domain-Anbieter prüfen, eine Stunde warten |
    | Nach `ufw enable` reißt die SSH-Verbindung ab | Port 22 war nicht freigegeben | über die Web-Konsole des Anbieters anmelden und `sudo ufw allow 22/tcp` ausführen |

## 2. Programme installieren {#programme}

### Paketquelle für PHP 8.4 hinzufügen

Ubuntu 24.04 bringt PHP 8.3 mit. PHP 8.4 kommt aus der Paketquelle
`ondrej/php`. Ondřej Surý betreut auch die PHP-Pakete von Debian.

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
```

Der zweite Befehl zeigt eine Beschreibung der Paketquelle. Darin steht, dass
sie nach `packages.sury.org` umzieht. Für Ubuntu 24.04 gilt sie weiter.

Prüfen Sie, ob PHP 8.4 jetzt angeboten wird:

```bash
apt-cache policy php8.4-fpm
```

In der Zeile `Candidate:` steht eine Version, die mit `8.4.` beginnt.

??? note "Debian 12 und Debian 13"
    Unter **Debian 12** kommt PHP 8.4 aus der Paketquelle
    `packages.sury.org`. Ersetzen Sie die beiden Befehle oben durch:

    ```bash
    sudo apt install -y lsb-release ca-certificates curl
    curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
    sudo apt update
    ```

    **Debian 13** enthält PHP 8.4 bereits. Dort lassen Sie diesen Schritt weg,
    die Paketnamen im nächsten Schritt sind dieselben.

!!! warning "Nicht Ubuntu 26.04"
    Ubuntu 26.04 enthält nur PHP 8.5, und die Paketquelle `ondrej/php` gibt
    es für 26.04 nicht. PHP 8.4 für 26.04 bietet nur `packages.sury.org` an
    (Stand Oktober 2026). Mit dieser Kombination ist die Anleitung nicht
    geprüft. Installieren Sie Ubuntu 24.04 LTS.

### Pakete installieren

Ein Befehl installiert den Webserver Apache, die Datenbank MariaDB, Redis, PHP
mit allen nötigen Erweiterungen, Certbot für das HTTPS-Zertifikat und die
Werkzeuge cron, curl und nano, falls sie fehlen:

```bash
sudo apt install -y apache2 mariadb-server redis-server \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-curl php8.4-gd php8.4-intl \
  php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-imagick \
  php8.4-apcu php8.4-redis certbot python3-certbot-apache cron curl nano
```

Zwischendurch erscheinen Zeilen mit `NOTICE`, die `a2enconf php8.4-fpm`
empfehlen. Das ist nicht nötig: Schritt 8 bindet PHP-FPM in der Konfiguration
der Website ein.

Prüfen Sie das Ergebnis:

```bash
php8.4 -v
systemctl is-active apache2 mariadb redis-server php8.4-fpm
```

Die erste Zeile beginnt mit `PHP 8.4`. Der zweite Befehl gibt viermal `active`
aus.

!!! warning "Immer die Paketnamen mit 8.4"
    Pakete ohne Versionsnummer wie `php-apcu` oder `php-redis` holen aus der
    Paketquelle `ondrej/php` die Erweiterungen für PHP 8.5 und damit PHP 8.5
    selbst. owncloud.online läuft nicht mit PHP 8.5.

!!! tip "nginx statt Apache"
    Wer nginx verwenden möchte, ersetzt im Befehl oben `apache2` durch `nginx`
    und `python3-certbot-apache` durch `python3-certbot-nginx`. Die Schritte 8
    und 9 sehen dann anders aus, siehe [nginx statt Apache](#nginx).

??? note "Hintergrund: welche Erweiterung wofür"
    owncloud.online prüft bei jedem Start diese PHP-Erweiterungen und startet
    ohne sie nicht: zip, dom, XMLWriter, XMLReader, intl, libxml, mbstring,
    ctype, JSON, GD, zlib, iconv, SimpleXML, hash, cURL und PDO. Außerdem muss
    `default_charset` auf `UTF-8` stehen.

    | Paket | Zweck |
    | --- | --- |
    | `php8.4-fpm` | PHP für den Webserver |
    | `php8.4-cli` | PHP für die Kommandozeile `occ` und die Hintergrund-Jobs |
    | `php8.4-mysql` | Zugriff auf MariaDB und MySQL |
    | `php8.4-curl`, `php8.4-gd`, `php8.4-intl`, `php8.4-mbstring`, `php8.4-xml`, `php8.4-zip` | Pflichterweiterungen, siehe oben |
    | `php8.4-bcmath` | schnellere Rechnungen mit großen Zahlen |
    | `php8.4-imagick` | Vorschaubilder für weitere Dateitypen |
    | `php8.4-apcu` | lokaler Zwischenspeicher, siehe [Schritt 11](#zwischenspeicher) |
    | `php8.4-redis` | Dateisperren über Redis, siehe [Schritt 11](#zwischenspeicher) |
    | `cron`, `curl`, `nano` | Zeitplan für die Hintergrund-Jobs, Download des Release, Editor. Auf den meisten Servern schon vorhanden, nicht aber bei minimal installiertem Ubuntu. |

    ctype, exif, fileinfo, iconv, PDO, posix und OPcache kommen mit den
    Paketen `php8.4-common` und `php8.4-opcache`, die automatisch mit
    installiert werden.

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | `Unable to locate package php8.4-fpm` oder `Candidate: (none)` | Paketquelle fehlt | ersten Teil dieses Schritts wiederholen, dann `sudo apt update` |
    | Ein Dienst meldet `failed` oder `inactive` statt `active` | Start fehlgeschlagen | `sudo systemctl status <dienst>` zeigt den Grund, etwa einen belegten Port |

## 3. PHP einstellen {#php-einstellen}

owncloud.online braucht für große Uploads und für Aktualisierungen längere
Zeitgrenzen und mehr Platz im OPcache, als PHP vorgibt. Diese Befehle legen je
eine kleine Einstellungsdatei für PHP-FPM und für die Kommandozeile an:

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

Prüfen Sie, ob PHP-FPM die Werte übernommen hat:

```bash
sudo php-fpm8.4 -i | grep max_execution_time
```

Die Ausgabe lautet `max_execution_time => 3600 => 3600`.

??? note "Hintergrund: welche PHP-Werte woher kommen"
    Das Release bringt im Programmverzeichnis eine Datei `.user.ini` mit.
    PHP-FPM liest sie bei jeder Anfrage und setzt damit:

    | Einstellung | Wert |
    | --- | --- |
    | `upload_max_filesize`, `post_max_size` | `513M` |
    | `memory_limit` | `512M` |
    | `default_charset` | `UTF-8` |
    | `output_buffering` | `0` |
    | `session.upload_progress.enabled` | `0` |

    Die Grenze von 513 MB gilt für eine einzelne Anfrage. Die Weboberfläche und
    der Desktop-Client laden große Dateien in Teilen hoch und sind davon nicht
    betroffen. Die Kommandozeile liest `.user.ini` nicht. `occ` und die
    Hintergrund-Jobs richten sich nach den Einstellungen für die
    Kommandozeile, unter Ubuntu und Debian ohne Speichergrenze.

    `apc.enable_cli = 1` erlaubt `occ` und den Hintergrund-Jobs, APCu zu
    nutzen. Die `php_value`-Zeilen in der mitgelieferten `.htaccess` gelten nur
    für das alte Apache-Modul mod_php und wirken unter PHP-FPM nicht.

    Mehr zur Größe des OPcache unter
    [Performance](../administration/performance.md).

## 4. Datenbank anlegen {#datenbank}

owncloud.online speichert Konten, Freigaben und die Dateiliste in MariaDB. Es
bekommt eine eigene Datenbank und ein eigenes Datenbankkonto.

Erzeugen Sie zuerst ein zufälliges Passwort für das Datenbankkonto:

```bash
openssl rand -hex 20
```

Notieren Sie die Ausgabe. Sie brauchen sie gleich und in Schritt 7. Öffnen
Sie dann die Konsole von MariaDB:

```bash
sudo mariadb
```

Ersetzen Sie in diesen Zeilen `DATENBANK_PASSWORT` durch das notierte Passwort.
Die Anführungszeichen bleiben stehen. Fügen Sie die Zeilen dann ein:

```sql
CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'owncloud'@'localhost' IDENTIFIED BY 'DATENBANK_PASSWORT';
GRANT ALL PRIVILEGES ON owncloud_online.* TO 'owncloud'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Jede Zeile antwortet mit `Query OK`, `EXIT` mit `Bye`.

??? note "Hintergrund: Zeichensatz"
    `utf8mb4` ist nötig, damit Dateinamen mit Emojis und seltenen Zeichen
    funktionieren. Die Installation erkennt das selbst und trägt
    `'mysql.utf8mb4' => true` in die `config/config.php` ein. Die Isolationsstufe `READ COMMITTED` setzt
    owncloud.online bei jeder Verbindung selbst, an MariaDB müssen Sie dafür
    nichts ändern. Mehr unter [Datenbank](../administration/database.md).

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | `ERROR 1007 (HY000): Can't create database 'owncloud_online'; database exists` | Datenbank stammt aus einem früheren Versuch | nur auf einem neuen Server ohne Daten: `DROP DATABASE owncloud_online;` und die Zeilen erneut einfügen |
    | `ERROR 1396 (HY000): Operation CREATE USER failed` | Datenbankkonto gibt es schon | `DROP USER 'owncloud'@'localhost';` und die Zeilen erneut einfügen |

## 5. owncloud.online herunterladen und prüfen {#herunterladen}

Laden Sie das Release und die Prüfsummen herunter und kontrollieren Sie, dass
die Datei unverändert angekommen ist:

```bash
cd /tmp
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/owncloud-online-11.0.25.tar.gz
curl -fLO https://github.com/BWTECH-github/owncloud.online/releases/download/v11.0.25/SHA256SUMS.txt
sha256sum --ignore-missing -c SHA256SUMS.txt
```

Die letzte Zeile lautet `owncloud-online-11.0.25.tar.gz: OK`.

Steht unter <https://github.com/BWTECH-github/owncloud.online/releases> eine
neuere Version, ersetzen Sie `11.0.25` in diesem und im nächsten Schritt
überall durch die neue Nummer.

??? note "Hintergrund: Prüfsumme"
    `SHA256SUMS.txt` enthält die Prüfsummen beider Archive und zweier weiterer
    Dateien des Release. `--ignore-missing` überspringt die Dateien, die Sie
    nicht heruntergeladen haben. Ohne diese Option meldet `sha256sum` für jede
    davon einen Fehler.

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | `curl: (22) The requested URL returned error: 404` | Versionsnummer falsch geschrieben | Nummer mit der Release-Seite vergleichen |
    | `owncloud-online-11.0.25.tar.gz: FAILED` | Datei unvollständig oder verändert | Datei nicht verwenden, mit `rm /tmp/owncloud-online-11.0.25.tar.gz` löschen und neu herunterladen |

## 6. Auspacken und Rechte setzen {#auspacken}

Das Archiv enthält einen einzigen Ordner `owncloud`. Er wird nach `/var/www`
ausgepackt und umbenannt. Das Datenverzeichnis liegt außerhalb davon, damit
der Webserver es nie ausliefert. Beide gehören dem Benutzer `www-data`, unter
dem Webserver und PHP laufen:

```bash
sudo tar -xzf /tmp/owncloud-online-11.0.25.tar.gz -C /var/www
sudo mv /var/www/owncloud /var/www/owncloud.online
sudo mkdir -p /var/oco-data
sudo chown -R www-data:www-data /var/www/owncloud.online /var/oco-data
sudo chmod 750 /var/oco-data
```

Prüfen Sie das Ergebnis:

```bash
ls /var/www/owncloud.online
```

Die Liste enthält unter anderem `apps`, `config`, `core`, `index.php` und
`occ`.

??? note "Hintergrund: Rechte und Verzeichnisse"
    Drei Orte muss `www-data` beschreiben können:

    | Ort | Grund |
    | --- | --- |
    | `config/` | die Installation schreibt `config/config.php` |
    | `apps-external/` | hier landen Apps aus dem Marketplace |
    | `/var/oco-data` | hochgeladene Dateien, Protokoll, Vorschaubilder |

    Läge das Datenverzeichnis im Programmverzeichnis, hinge sein Schutz allein
    an der `.htaccess`, und die liest nginx nicht.

    Strengere Rechte für das Programmverzeichnis setzen Sie erst nach der
    Installation, siehe [Schritt 12](#strengere-rechte).

## 7. Installieren {#installieren}

Die Installation legt die Tabellen an, erstellt das Administratorkonto und
schreibt die Konfiguration nach `config/config.php`:

```bash
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ maintenance:install \
  --database mysql \
  --database-name owncloud_online \
  --database-user owncloud \
  --admin-user admin \
  --data-dir /var/oco-data
```

Der Befehl fragt zwei Passwörter ab. Die Eingabe bleibt unsichtbar.

1. `What is the password to access the database with user <owncloud>?`:
   das Datenbank-Passwort aus Schritt 4.
2. `What is the password you like to use for the admin account <admin>?`:
   ein neues, langes Passwort für das Administratorkonto. Notieren Sie es.

Nach kurzer Zeit erscheint `owncloud.online was successfully installed`.

!!! tip "Eigener Name für das Administratorkonto"
    `admin` ist der Name, den Angreifer zuerst probieren. Mit einem anderen
    Wert bei `--admin-user` machen Sie es ihnen schwerer.

Die Installation kennt Ihre Adresse noch nicht. Tragen Sie sie ein und schalten
Sie Adressen ohne `index.php` ein:

```bash
sudo -u www-data php8.4 occ config:system:set trusted_domains 0 --value cloud.example.com
sudo -u www-data php8.4 occ config:system:set overwrite.cli.url --value https://cloud.example.com
sudo -u www-data php8.4 occ config:system:set htaccess.RewriteBase --value /
sudo -u www-data php8.4 occ maintenance:update:htaccess
```

Jeder `config:system:set`-Befehl bestätigt mit einer Zeile, die mit
`System config value` beginnt. Der letzte meldet `.htaccess has been updated`.

??? note "Hintergrund: was die Befehle einstellen"
    | Einstellung | Bedeutung |
    | --- | --- |
    | `trusted_domains` | Liste der Adressen, unter denen die Instanz antwortet. Die Installation über die Kommandozeile trägt `localhost` ein. Index `0` ersetzt diesen Eintrag, eine weitere Adresse bekommt Index `1`. |
    | `overwrite.cli.url` | Adresse für Links in Mails, die Hintergrund-Jobs verschicken. Ohne Anfrage aus dem Browser kennt der Server seinen Namen nicht. |
    | `htaccess.RewriteBase` | Mit dieser Einstellung schreibt `maintenance:update:htaccess` Umleitungsregeln in die `.htaccess`. Danach erzeugt owncloud.online Adressen ohne `index.php`. Das wirkt nur unter Apache. |

    Weitere Optionen von `occ maintenance:install`:

    | Option | Vorgabe | Anmerkung |
    | --- | --- | --- |
    | `--database` | `sqlite` | `mysql` für MariaDB und MySQL, `pgsql` für PostgreSQL |
    | `--database-host` | `localhost` | Name oder Adresse des Datenbankservers |
    | `--database-pass` | – | ohne diese Option fragt der Befehl verdeckt nach |
    | `--database-table-prefix` | `oc_` | |
    | `--admin-pass` | – | ohne diese Option fragt der Befehl verdeckt nach |
    | `--data-dir` | `<Programmverzeichnis>/data` | immer angeben |

    Die verdeckte Abfrage ist der sicherere Weg: Die Passwörter landen dann
    weder im Verlauf der Shell noch in der Prozessliste.

    Die Installation schreibt `config/config.php`, legt die Tabellen an und
    schaltet die mitgelieferten Apps ein, darunter die App „Market“. Sie trägt
    `apps/` als festes und `apps-external/` als beschreibbares
    App-Verzeichnis ein, setzt die Zeitzone des Protokolls auf die des Systems
    und legt im Datenverzeichnis die Markierungsdatei `.ocdata` und eine
    schützende `.htaccess` an.

    Im Programmverzeichnis legt die Installation außerdem einen fast leeren
    Ordner `data/` an, um den Schutz durch die `.htaccess` zu prüfen. Er bleibt
    gesperrt und wird nicht benutzt. Das kurze Protokoll darin enthält die
    Meldung `Specific user creation failed`. Sie ist harmlos: Die Installation
    prüft, ob sie ein eigenes Datenbankkonto anlegen darf, und verwendet danach
    das Konto `owncloud`.

??? note "Alternative: Einrichtung im Browser"
    Statt mit `occ` können Sie owncloud.online auch im Browser einrichten.
    Lassen Sie dann diesen Schritt aus, erledigen Sie die Schritte 8 und 9 und
    rufen Sie danach `https://cloud.example.com` auf.

    | Feld | Eingabe |
    | --- | --- |
    | *Administrator-Konto anlegen*: *Benutzername*, *Passwort* | Ihr künftiges Administratorkonto |
    | *Speicher & Datenbank* (zum Aufklappen anklicken), *Datenverzeichnis* | `/var/oco-data`. Vorgeschlagen ist `/var/www/owncloud.online/data`, das ändern Sie. |
    | *Datenbank einrichten* | Es steht nur MySQL/MariaDB zur Wahl. |
    | *Datenbank-Benutzer*, *Datenbank-Passwort*, *Datenbank-Name*, *Datenbank-Host* | `owncloud`, das Passwort aus Schritt 4, `owncloud_online`, `localhost` |

    Ein Klick auf *Installation abschließen* startet die Einrichtung. Der
    Assistent trägt die aufgerufene Adresse selbst in `trusted_domains` und
    `overwrite.cli.url` ein. Für Adressen ohne `index.php` führen Sie danach
    die letzten beiden Befehle von oben aus.

    Bis Sie auf *Installation abschließen* klicken, kann jeder, der die Adresse
    aufruft, die Installation übernehmen. Richten Sie die Instanz deshalb
    direkt nach Schritt 9 ein.

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | `PHP module … not installed.` | Eine Erweiterung fehlt | Paket aus Schritt 2 nachinstallieren, `sudo systemctl restart php8.4-fpm` |
    | `Console has to be executed with the user that owns the file config/config.php` | `sudo -u www-data` vergessen | Befehl mit `sudo -u www-data` am Anfang wiederholen |
    | `Can't create or write into the data directory /var/oco-data` | Rechte fehlen | Schritt 6 wiederholen |
    | `Failed to connect to the database` oder `Access denied for user 'owncloud'@'localhost'` | MariaDB läuft nicht oder das Passwort stimmt nicht | `systemctl is-active mariadb` prüfen, Passwort vergleichen |

    Bricht die Installation mittendrin ab, beginnen Sie sauber neu. Diese
    Befehle löschen die Konfiguration und legen die Datenbank leer wieder an.
    Das Datenbankkonto und seine Rechte bleiben erhalten:

    ```bash
    sudo rm /var/www/owncloud.online/config/config.php
    sudo mariadb -e "DROP DATABASE owncloud_online; CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    ```

    Wiederholen Sie danach diesen Schritt.

## 8. Webserver einrichten {#webserver}

Apache liefert owncloud.online unter Ihrer Adresse aus und gibt PHP-Dateien an
PHP-FPM weiter. Schalten Sie zuerst die nötigen Module ein:

```bash
sudo a2enmod rewrite headers env dir mime setenvif proxy_fcgi ssl
```

Öffnen Sie dann eine neue Konfigurationsdatei im Editor nano:

```bash
sudo nano /etc/apache2/sites-available/owncloud-online.conf
```

Fügen Sie diesen Inhalt ein und ersetzen Sie `cloud.example.com` durch Ihre
Adresse. Speichern Sie mit Strg+O und der Eingabetaste, beenden Sie nano mit
Strg+X.

```apache
<VirtualHost *:80>
    ServerName cloud.example.com
    DocumentRoot /var/www/owncloud.online

    <Directory /var/www/owncloud.online>
        Require all granted
        AllowOverride All
        Options FollowSymLinks
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # Große Uploads: keine Größengrenze, Zeit für das Zusammensetzen
    TimeOut 3600
    LimitRequestBody 0

    ErrorLog  ${APACHE_LOG_DIR}/owncloud-online-error.log
    CustomLog ${APACHE_LOG_DIR}/owncloud-online-access.log combined
</VirtualHost>
```

Schalten Sie die neue Konfiguration ein und die Standardseite von Apache aus:

```bash
sudo a2ensite owncloud-online
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl restart apache2
```

`configtest` meldet `Syntax OK`. Eine Warnung, die mit `AH00558` beginnt,
können Sie ignorieren. Prüfen Sie dann, ob owncloud.online antwortet:

```bash
curl -s http://cloud.example.com/status.php
```

Die Antwort beginnt mit `{"installed":true`. Melden Sie sich noch nicht im
Browser an. Ohne HTTPS ginge Ihr Passwort unverschlüsselt über das Netz.

??? note "Hintergrund: Apache"
    `AllowOverride All` ist Pflicht. Ohne diese Zeile liest Apache die
    mitgelieferte `.htaccess` nicht. Dann fehlen die Sicherheits-Kopfzeilen,
    die Sperren für `config/`, `lib/` und andere interne Ordner, die
    Umleitungen unter `/.well-known/` und die Adressen ohne `index.php`.

    Den Header `Strict-Transport-Security` (HSTS) setzt die `.htaccess` selbst,
    sobald Apache HTTPS ausliefert. Setzen Sie ihn nicht zusätzlich in der
    Konfiguration.

    Steht vor owncloud.online ein eigener Reverse-Proxy, lesen Sie
    [Reverse-Proxy und TLS](../administration/reverse-proxy.md).

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | `Invalid command 'Header'` oder `Invalid command 'RewriteEngine'` | Modul nicht eingeschaltet | `a2enmod`-Befehl von oben wiederholen |
    | Die Apache-Standardseite „It works“ statt owncloud.online | Standardseite noch aktiv oder Adresse falsch geschrieben | `sudo a2dissite 000-default`, `ServerName` prüfen, `sudo systemctl reload apache2` |
    | `Service Unavailable` | PHP-FPM läuft nicht | `sudo systemctl restart php8.4-fpm`, Grund mit `sudo systemctl status php8.4-fpm` |
    | „Sie greifen auf den Server über eine nicht vertrauenswürdige Domain zu.“ (bei Du-Anrede „Du greifst …“) | Adresse fehlt in `trusted_domains` | ersten Befehl des zweiten Blocks aus Schritt 7 mit der richtigen Adresse wiederholen |

## 9. HTTPS einschalten {#https}

Certbot holt ein kostenloses Zertifikat von Let's Encrypt, trägt es in Apache
ein und leitet HTTP auf HTTPS um:

```bash
sudo certbot --apache --redirect -d cloud.example.com
```

Beim ersten Aufruf stellt Certbot drei Fragen:

1. nach einer E-Mail-Adresse für Hinweise zum Zertifikat,
2. ob Sie den Nutzungsbedingungen von Let's Encrypt zustimmen: `Y`,
3. ob Certbot Ihre Adresse an die Electronic Frontier Foundation weitergeben
   darf. Das ist freiwillig, `N` genügt.

Am Ende steht `Congratulations! You have successfully enabled HTTPS on
https://cloud.example.com`.

Certbot erneuert das Zertifikat danach selbst. Ob das klappen wird, prüft
dieser Probelauf:

```bash
sudo certbot renew --dry-run
```

Er endet mit `Congratulations, all simulated renewals succeeded`.

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | `Timeout during connect (likely firewall problem)` | Port 80 ist von außen nicht erreichbar | Firewall aus Schritt 1 und die Firewall im Kundenmenü des Anbieters prüfen |
    | `DNS problem: NXDOMAIN looking up A for cloud.example.com` | DNS-Eintrag fehlt oder ist noch nicht verteilt | Schritt 1 prüfen, später erneut versuchen |
    | Fehler beim Zugriff über IPv6 | AAAA-Eintrag zeigt auf eine Adresse, unter der der Server nicht antwortet | AAAA-Eintrag korrigieren oder löschen |

    Nach mehreren Fehlversuchen in kurzer Zeit lehnt Let's Encrypt weitere
    Versuche vorübergehend ab. Beheben Sie zuerst die Ursache.

## 10. Hintergrund-Jobs einrichten {#hintergrund-jobs}

owncloud.online erledigt alle 15 Minuten Arbeiten im Hintergrund: Papierkorb
und alte Versionen aufräumen, abgelaufene Freigaben entfernen,
Benachrichtigungen und Mails verschicken. Ohne diesen Zeitplan wächst die
Instanz unbegrenzt und verschickt keine Mails.

```bash
echo '*/15 * * * * /usr/bin/php8.4 /var/www/owncloud.online/occ system:cron' | sudo crontab -u www-data -
sudo -u www-data php8.4 /var/www/owncloud.online/occ background:cron
```

Der zweite Befehl antwortet mit `Set mode for background jobs to 'cron'`. Der
erste ersetzt einen vorhandenen Zeitplan des Benutzers `www-data`. Auf einem
neuen Server gibt es keinen. Prüfen Sie den Eintrag:

```bash
sudo crontab -u www-data -l
```

Nach spätestens 15 Minuten zeigt *Einstellungen → Administration → Allgemein*
im Abschnitt *Cron* die Zeile „Letzte Cron-Job-Ausführung: …“ mit einem grünen
Punkt.

![Administration, Allgemein: Cron mit den Optionen AJAX, Webcron und Cron, darunter das Log](../assets/screenshots/de/admin-background-jobs.png)

Ausgewählt ist die Option *Cron*. Sie bedeutet, dass der Cron-Dienst des
Systems die Hintergrund-Jobs startet.

??? note "Hintergrund: Cron"
    Der Zeitplan muss als `www-data` laufen. Liefe er als `root`, gehörten neu
    angelegte Dateien danach `root`, und der Webserver könnte sie nicht mehr
    schreiben. Die Einstellungsseite nennt die Datei `cron.php`. Sie ruft
    intern denselben Befehl `occ system:cron` auf, gibt dabei aber bei jedem
    Lauf eine Zeile aus, die Cron als Mail zustellen will. Einzelheiten unter
    [Hintergrund-Jobs (Cron)](../administration/background-jobs.md).

??? warning "Wenn es nicht klappt"
    Steht dort nach 30 Minuten noch „Cron wurde bis jetzt noch nicht
    ausgeführt!“, starten Sie den Lauf einmal von Hand und lesen Sie die
    Fehlermeldung:

    ```bash
    sudo -u www-data php8.4 /var/www/owncloud.online/occ system:cron
    ```

    Gibt der Befehl nichts aus, ist er erfolgreich gelaufen. Prüfen Sie dann,
    ob der Cron-Dienst läuft: `systemctl is-active cron`.

## 11. Zwischenspeicher einschalten {#zwischenspeicher}

APCu hält häufig gelesene Daten im Arbeitsspeicher von PHP. Redis verwaltet
die Dateisperren, damit sich gleichzeitige Zugriffe auf dieselbe Datei nicht
in die Quere kommen:

```bash
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ config:system:set memcache.local --value '\OC\Memcache\APCu'
sudo -u www-data php8.4 occ config:system:set memcache.locking --value '\OC\Memcache\Redis'
sudo -u www-data php8.4 occ config:system:set redis host --value 127.0.0.1
sudo -u www-data php8.4 occ config:system:set redis port --value 6379 --type integer
```

Jeder Befehl bestätigt mit einer Zeile, die mit `System config value` beginnt.
Prüfen Sie, ob Redis antwortet:

```bash
redis-cli ping
```

Die Antwort lautet `PONG`.

??? note "Hintergrund: Zwischenspeicher"
    Ohne `memcache.local` liest owncloud.online bei jeder Anfrage alle Sprach-
    und App-Dateien neu ein, und die Einstellungsseite meldet „Es wurde kein
    PHP Memory Cache konfiguriert.“ Ohne Redis laufen die Dateisperren über die
    Datenbank, was langsamer ist und ebenfalls eine Warnung erzeugt. Die
    Dateisperren selbst sind ohne weiteres Zutun eingeschaltet. Mehr unter
    [Performance](../administration/performance.md).

??? warning "Wenn es nicht klappt"
    | Was Sie sehen | Ursache | Lösung |
    | --- | --- | --- |
    | Fehlerseite `Memcache \OC\Memcache\APCu not available for local cache` | APCu fehlt oder PHP-FPM wurde nicht neu gestartet | `sudo apt install -y php8.4-apcu`, dann `sudo systemctl restart php8.4-fpm` |
    | `redis-cli ping` meldet `Connection refused` | Redis läuft nicht | `sudo systemctl restart redis-server` |

## 12. Anmelden und prüfen {#erste-anmeldung}

Öffnen Sie `https://cloud.example.com` im Browser. Geben Sie unter
*Benutzername oder E-Mail* den Namen des Administratorkontos aus Schritt 7 ein,
darunter das *Passwort*, und klicken Sie auf *Anmelden*.

![Anmeldeseite von owncloud.online mit den Feldern für Benutzername oder E-Mail und Passwort](../assets/screenshots/de/login.png)

Öffnen Sie danach oben rechts über Ihren Namen *Einstellungen* und links
*Administration → Allgemein*. Im Abschnitt *Sicherheits- &
Einrichtungswarnungen* steht nach dieser Anleitung keine Warnung, sondern nur
„Alle Checks bestanden.“ oder, bei der Anrede mit Du, „Alle Überprüfungen
bestanden.“ Was eine andere Meldung bedeutet, steht unter
[Sicherheit und Setup-Warnungen](../administration/security-hardening.md).

Zum Schluss prüfen Sie den Zustand auf der Kommandozeile:

```bash
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ status
sudo -u www-data php8.4 occ check
```

`occ status` zeigt `installed: true` und `versionstring: 11.0.25`. `occ check`
gibt nichts aus, wenn alle Voraussetzungen erfüllt sind.

??? warning "Wenn es nicht klappt"
    Haben Sie das Passwort des Administratorkontos vergessen, setzen Sie ein
    neues. Der Befehl fragt es verdeckt ab:

    ```bash
    sudo -u www-data php8.4 /var/www/owncloud.online/occ user:resetpassword admin
    ```

### Strengere Rechte (freiwillig) {#strengere-rechte}

Wer andere Benutzer des Servers ganz aussperren will, setzt jetzt strengere
Rechte. Nach jedem Update sind die beiden Befehle zu wiederholen:

```bash
sudo find /var/www/owncloud.online -type d -exec chmod 750 {} \;
sudo find /var/www/owncloud.online -type f -exec chmod 640 {} \;
```

Danach öffnet nur noch `www-data` das Programmverzeichnis. Ihr eigenes Konto
erhält bei `cd /var/www/owncloud.online` die Meldung `Permission denied`.
Rufen Sie `occ` deshalb mit vollem Pfad auf:

```bash
sudo -u www-data php8.4 /var/www/owncloud.online/occ status
```

## Wie es weitergeht {#wie-es-weitergeht}

| Aufgabe | Seite |
| --- | --- |
| Mailversand einrichten, damit Passwort-Links und Einladungen ankommen | [E-Mail-Versand](../administration/email.md) |
| Sicherung einrichten und owncloud.online aktualisieren | [Backups und Updates](../administration/backups-updates.md) |
| Apps aus dem Marketplace installieren | [Apps und Marketplace](../administration/apps-market.md) |
| Konten und Gruppen anlegen | [Benutzer und Gruppen](../administration/users-groups.md) |
| Den Desktop-Client verteilen | [Desktop Client](../user/desktop-client.md) |
| Fehlermeldungen lesen | [Serverprotokoll und Fehlermeldungen](../administration/logging.md) |

## nginx statt Apache {#nginx}

nginx liest keine `.htaccess`. Alles, was dort steht, muss deshalb in der
Konfiguration von nginx stehen. Der Ablauf weicht an vier Stellen ab:

1. **Schritt 2:** Installieren Sie `nginx` statt `apache2` und
   `python3-certbot-nginx` statt `python3-certbot-apache`.
2. **Schritt 7:** Die letzten beiden Befehle zu `htaccess.RewriteBase` und
   `maintenance:update:htaccess` entfallen. Kurze Adressen schaltet unter nginx
   die Zeile `fastcgi_param front_controller_active true;` ein.
3. **Schritt 8:** Legen Sie statt der Apache-Konfiguration diese Datei an:

    ```bash
    sudo nano /etc/nginx/sites-available/owncloud-online
    ```

    Inhalt, wieder mit Ihrer Adresse statt `cloud.example.com`:

    ```nginx
    upstream php-handler {
        server unix:/run/php/php8.4-fpm.sock;
    }

    server {
        listen 80;
        listen [::]:80;
        server_name cloud.example.com;

        root /var/www/owncloud.online;
        index index.php index.html;

        # Ersatz für die Kopfzeilen der .htaccess
        add_header Strict-Transport-Security "max-age=15552000; includeSubDomains" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-XSS-Protection "0" always;
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Robots-Tag "none" always;
        add_header X-Download-Options "noopen" always;
        add_header X-Permitted-Cross-Domain-Policies "none" always;

        # Große Uploads
        client_max_body_size 0;
        client_body_timeout 300s;

        # Umleitungen unter /.well-known
        location = /.well-known/host-meta      { rewrite ^ /public.php?service=host-meta last; }
        location = /.well-known/host-meta.json { rewrite ^ /public.php?service=host-meta-json last; }
        location ^~ /.well-known/carddav { return 301 /remote.php/dav/; }
        location ^~ /.well-known/caldav  { return 301 /remote.php/dav/; }
        location ^~ /.well-known/acme-challenge/ { }
        location ^~ /.well-known/pki-validation/ { }

        # Gesperrte Pfade wie in der .htaccess
        location ~ ^/(?:build|tests|config|lib|3rdparty|templates|changelog)/ { return 404; }
        location ~ ^/(?:\.|autotest|occ|issue|indie|db_|console) { return 404; }
        location = /core/signature.json { return 404; }
        location ^~ /core/skeleton/ { return 404; }
        location ^~ /data/ { return 404; }
        location ~ ^/(?:core|apps|apps-external)/.*/(?:package|package-lock|composer|bower|\.bower|installed|testem)\.json$ { return 404; }
        location ~ ^/(?:core|apps|apps-external)/.*/(?:composer|yarn)\.lock$ { return 404; }
        location ~ ^/(?:apps|apps-external)/[^/]+/(?:vendor|tests)/ { return 404; }

        # Vorhandene Dateien direkt ausliefern, alles andere über index.php
        location / {
            rewrite ^/remote/(.*) /remote.php last;
            try_files $uri /index.php$request_uri;
        }

        location ~ \.php(?:$|/) {
            fastcgi_split_path_info ^(.+?\.php)(/.*)$;
            try_files $fastcgi_script_name =404;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param PATH_INFO $fastcgi_path_info;
            fastcgi_param PATH /usr/local/bin:/usr/bin:/bin;
            fastcgi_param HTTPS $https if_not_empty;
            fastcgi_param HTTP_AUTHORIZATION $http_authorization;
            fastcgi_param front_controller_active true;
            fastcgi_param modHeadersAvailable true;
            fastcgi_pass php-handler;
            fastcgi_read_timeout 3600s;
            fastcgi_request_buffering off;
        }

        # Föderation: diese Ordner liefern ihre eigene index.php aus
        location ~ ^/(?:ocs-provider|ocm-provider)(?:$|/) {
            try_files $uri/ =404;
            index index.php;
        }

        location ~ \.(?:css|js|mjs|woff2?|svg|gif|png|jpg|jpeg|ico|map)$ {
            try_files $uri /index.php$request_uri;
            access_log off;
            expires 7d;
        }
    }
    ```

    Schalten Sie die Konfiguration ein und die Standardseite aus:

    ```bash
    sudo ln -s /etc/nginx/sites-available/owncloud-online /etc/nginx/sites-enabled/
    sudo rm /etc/nginx/sites-enabled/default
    sudo nginx -t
    sudo systemctl reload nginx
    ```

    `nginx -t` meldet `syntax is ok` und `test is successful`.

4. **Schritt 9:** Certbot rufen Sie mit `--nginx` auf:

    ```bash
    sudo certbot --nginx --redirect -d cloud.example.com
    ```

??? note "Hintergrund: Was die nginx-Konfiguration erledigt"
    - Die `add_header`-Zeilen ersetzen die Kopfzeilen aus der `.htaccess`. Die
      Einstellungsseite prüft jede einzelne. Fehlt zum Beispiel
      `X-XSS-Protection "0"`, zeigt sie dazu eine Warnung.
    - `try_files $uri /index.php$request_uri;` liefert vorhandene Dateien
      direkt aus und gibt alles andere an `index.php`. Ein zusätzliches
      `$uri/` wäre falsch: Adressen wie `/apps/files/` sind zugleich echte
      Ordner, nginx antwortete dort mit 403 statt mit der App.
    - `fastcgi_param HTTP_AUTHORIZATION` reicht die Anmeldedaten an PHP
      weiter. Fehlt die Zeile, erhalten WebDAV-Programme und der
      Desktop-Client trotz richtiger Zugangsdaten den Fehler 401.
    - `fastcgi_param PATH` gibt PHP einen Suchpfad für Programme. Fehlt er,
      meldet die Einstellungsseite, dass PHP keine Umgebungsvariablen
      abfragen kann.
    - `/ocs-provider/` und `/ocm-provider/` brauchen den eigenen Block, damit
      Freigaben zwischen zwei Servern funktionieren.
    - Die Sperre `location ~ ^/(?:\.|…)` würde auch `/.well-known/` treffen.
      Die `location ^~`-Blöcke davor haben Vorrang vor regulären Ausdrücken.
      So bleiben die Umleitungen und die Zertifikatsausstellung frei.
    - `fastcgi_split_path_info ^(.+?\.php)(/.*)$;` trennt an der ersten
      Stelle mit `.php`. Eine gierige Variante ohne `?` bricht Routen von Apps,
      deren Pfad selbst `.php` enthält.
    - Die Sperren für `vendor/` und `tests/` gelten auch für
      `apps-external/`, wo die Apps aus dem Marketplace liegen.

## Fehlersuche {#fehlersuche}

Die meisten Fehler stehen mit Ursache im Protokoll
`/var/oco-data/owncloud.log`, Fehler des Webservers in
`/var/log/apache2/owncloud-online-error.log`. Mehr unter
[Serverprotokoll und Fehlermeldungen](../administration/logging.md).

| Was Sie sehen | Ursache | Lösung |
| --- | --- | --- |
| „PHP-Modul … nicht installiert.“ | Eine PHP-Erweiterung fehlt | Paket aus Schritt 2 nachinstallieren, `sudo systemctl restart php8.4-fpm`, dann `occ check` |
| `This version of owncloud.online requires at least PHP 8.4.0` | `occ` mit `php` statt `php8.4` aufgerufen, oder falsches PHP | immer `php8.4` schreiben |
| „Sie greifen auf den Server über eine nicht vertrauenswürdige Domain zu.“ (bei Du-Anrede „Du greifst …“) | Aufgerufene Adresse fehlt in `trusted_domains` | `occ config:system:set trusted_domains 1 --value <adresse>` |
| Alle Adressen enthalten `index.php` | `htaccess.RewriteBase` fehlt (Apache) oder `front_controller_active` (nginx) | Schritt 7 bzw. Abschnitt nginx prüfen |
| `occ maintenance:update:htaccess` ändert nichts | `overwrite.cli.url` ist leer | erst `overwrite.cli.url` setzen, dann erneut ausführen |
| Sicherheits-Kopfzeilen fehlen unter Apache | `AllowOverride All` fehlt | im `<Directory>`-Block ergänzen, `sudo systemctl reload apache2` |
| Desktop-Client oder WebDAV erhält 401 trotz richtiger Daten (nginx) | `HTTP_AUTHORIZATION` wird nicht weitergereicht | Zeile aus der nginx-Konfiguration übernehmen |
| „Letzte Cron-Job-Ausführung: … Möglicherweise liegt ein Fehler vor.“ | Zeitplan fehlt oder läuft als falscher Benutzer | Schritt 10 wiederholen |
| Dateinamen mit Emojis schlagen fehl | Datenbank nicht auf `utf8mb4` | siehe [Datenbank](../administration/database.md) |
| Großer Upload bricht kurz vor dem Ende ab | Das Zusammensetzen der Teile überschreitet eine Zeitgrenze | `TimeOut` (Apache) bzw. `fastcgi_read_timeout` (nginx) erhöhen |
| Keine Mails für Passwort-Links und Freigaben | Mailversand nicht eingerichtet | [E-Mail-Versand](../administration/email.md) |
