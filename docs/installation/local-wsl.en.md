# Testing owncloud.online on Windows

With the Windows Subsystem for Linux (WSL), a real Ubuntu runs on your Windows
computer. You install owncloud.online in it as you would on a server and open
it in your browser at `http://localhost`.

!!! warning "For testing only"
    This installation is meant for trying things out, not for production. It
    is only reachable from your computer, only runs while the Linux
    environment runs, and has neither HTTPS nor backups. For production, use a
    [Linux server](linux-server.en.md).

## What you need {#voraussetzungen}

- Windows 10 version 2004 (build 19041) or later, or Windows 11
- administrator rights on the computer
- virtualization turned on in the BIOS or UEFI, which is the factory setting
  on most computers
- at least 5 GB of free disk space

## 1. Install WSL and Ubuntu {#wsl}

Open PowerShell as an administrator: right-click *Start*, then choose
*Terminal (Admin)* on Windows 11 or *Windows PowerShell (Admin)* on
Windows 10. Install WSL with Ubuntu 24.04:

```powershell
wsl --install -d Ubuntu-24.04
```

If Windows asks for a restart, restart. Afterwards, the Ubuntu window opens on
its own. Otherwise, start *Ubuntu 24.04 LTS* from the Start menu. On the first
start, you create a user name and a password for Linux. Ubuntu asks for this
password later with every `sudo` command.

Check in PowerShell that Ubuntu runs under WSL 2:

```powershell
wsl -l -v
```

In the `Ubuntu-24.04` line, the `VERSION` column shows `2`.

!!! note "Specify the Ubuntu version"
    `wsl --install` without `-d Ubuntu-24.04` installs the latest Ubuntu
    version. Ubuntu 26.04 only includes PHP 8.5, and owncloud.online needs
    PHP 8.4.

??? warning "If it doesn't work"
    | What you see | Cause | Fix |
    | --- | --- | --- |
    | Error `0x80370102` | virtualization is turned off | turn it on in the BIOS or UEFI, often called "Intel VT-x", "AMD-V", or "SVM" |
    | Error `0x8007019e` | the WSL feature of Windows is missing | run `wsl --install` without anything else, restart, then repeat the command above |
    | `wsl -l -v` shows `VERSION 1` | Ubuntu runs under the old WSL 1 | `wsl --set-version Ubuntu-24.04 2` |

## 2. Install owncloud.online in Ubuntu {#installieren}

Type all of the following commands in the Ubuntu window. They are the steps
of the [Linux server](linux-server.en.md) guide in short form, without a
domain, firewall, or HTTPS. That guide also explains what to do if a step
fails.

**Install the software.** This block brings Ubuntu up to date and installs
Apache, MariaDB, Redis, and PHP 8.4, plus cron, curl, and nano if they are
missing:

```bash
sudo apt update && sudo apt full-upgrade -y
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y apache2 mariadb-server redis-server \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-curl php8.4-gd php8.4-intl \
  php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-imagick \
  php8.4-apcu php8.4-redis cron curl nano
```

Afterwards, `systemctl is-active apache2 mariadb redis-server php8.4-fpm`
prints `active` four times.

**Configure PHP.** Longer time limits and a larger OPcache:

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

**Create the database.** Generate a password and write it down:

```bash
openssl rand -hex 20
sudo mariadb
```

Replace `DATABASE_PASSWORD` with the password you wrote down and paste the
lines into the MariaDB console:

```sql
CREATE DATABASE owncloud_online CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'owncloud'@'localhost' IDENTIFIED BY 'DATABASE_PASSWORD';
GRANT ALL PRIVILEGES ON owncloud_online.* TO 'owncloud'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**Download, verify, and unpack:**

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

The check reports `owncloud-online-11.0.25.tar.gz: OK`.

**Install.** The command first asks for the database password and then for a
new password for the `admin` administrator account:

```bash
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ maintenance:install \
  --database mysql \
  --database-name owncloud_online \
  --database-user owncloud \
  --admin-user admin \
  --data-dir /var/oco-data
```

The installation reports `owncloud.online was successfully installed`. The
address `localhost` is already registered. Then turn on addresses without
`index.php`:

```bash
sudo -u www-data php8.4 occ config:system:set htaccess.RewriteBase --value /
sudo -u www-data php8.4 occ maintenance:update:htaccess
```

**Set up the web server.** Turn on the modules and open a new configuration
file:

```bash
sudo a2enmod rewrite headers env dir mime setenvif proxy_fcgi
sudo nano /etc/apache2/sites-available/owncloud-online.conf
```

Paste this content. Save with Ctrl+O and Enter, exit nano with Ctrl+X.

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

Turn on the configuration and turn off Apache's default site:

```bash
sudo a2ensite owncloud-online
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl restart apache2
```

`configtest` reports `Syntax OK`. You can ignore the `AH00558` warning.

**Background jobs and caching.** Not needed for a short test, recommended for
a test over several days:

```bash
echo '*/15 * * * * /usr/bin/php8.4 /var/www/owncloud.online/occ system:cron' | sudo crontab -u www-data -
cd /var/www/owncloud.online
sudo -u www-data php8.4 occ background:cron
sudo -u www-data php8.4 occ config:system:set memcache.local --value '\OC\Memcache\APCu'
sudo -u www-data php8.4 occ config:system:set memcache.locking --value '\OC\Memcache\Redis'
sudo -u www-data php8.4 occ config:system:set redis host --value 127.0.0.1
sudo -u www-data php8.4 occ config:system:set redis port --value 6379 --type integer
```

## 3. Open owncloud.online {#oeffnen}

Open `http://localhost` in a browser on Windows. Under *Username or email*,
enter `admin`, below it the *Password* from the installation step, and click
*Login*.

![owncloud.online login page with fields for username or email and password](../assets/screenshots/en/login.png)

## Starting and stopping {#starten-stoppen}

| What | How |
| --- | --- |
| Start | open *Ubuntu 24.04 LTS* from the Start menu. Apache, MariaDB, Redis, and PHP start on their own. |
| Keep running | leave the Ubuntu window open |
| Stop | in PowerShell, `wsl --terminate Ubuntu-24.04` |
| Stop all Linux environments | in PowerShell, `wsl --shutdown` |
| Remove everything | in PowerShell, `wsl --unregister Ubuntu-24.04`. This deletes Ubuntu along with owncloud.online and all files in it. |

If you close the Ubuntu window, Windows stops the Linux environment after a
short time, and `http://localhost` no longer answers. When you open Ubuntu
again, owncloud.online is back within a few seconds.

## If it doesn't work {#fehlersuche}

| What you see | Cause | Fix |
| --- | --- | --- |
| `System has not been booted with systemd as init system` | systemd is turned off in WSL | turn on systemd, see below |
| `http://localhost` does not load | Ubuntu is stopped | open Ubuntu from the Start menu |
| `http://localhost` shows a different page | a Windows program such as IIS uses port 80 | switch Apache to port 8080, see below |
| Other devices on the network cannot reach owncloud.online | WSL is only reachable from this computer | intended. For tests with several devices, use a [Linux server](linux-server.en.md). |

Turn on systemd: run this command in Ubuntu, then `wsl --shutdown` in
PowerShell, and open Ubuntu again.

```bash
printf '\n[boot]\nsystemd=true\n' | sudo tee -a /etc/wsl.conf
```

Switch Apache to port 8080:

```bash
sudo sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf
sudo sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/owncloud-online.conf
sudo systemctl restart apache2
```

After that, owncloud.online is at `http://localhost:8080`.
