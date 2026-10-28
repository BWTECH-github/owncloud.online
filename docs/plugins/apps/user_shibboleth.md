# SAML/SSO-Integration

!!! info "Nur mit API-Schlüssel"
    Diese App zeigt der Marketplace nur mit einem gültigen API-Schlüssel an.
    Den Schlüssel erhalten Sie auf Anfrage von der BW-Tech GmbH. Tragen Sie ihn
    so ein: im App-Menü **Markt** öffnen, in der linken Spalte unter
    **Einstellungen** auf **API Schlüssel hinzufügen** klicken, den Schlüssel in
    das Feld **Dein persönlicher API-Key** einfügen und **Speichern** klicken.
    Der Markt lädt den Katalog danach neu. Bei einem falschen Schlüssel meldet
    er „Der API-Key ist ungültig!“. Mehr dazu unter
    [API-Schlüssel eintragen](market.md#api-schluessel). App-ID
    `user_shibboleth`, Version 4.0.0.

Mit dieser App melden sich Personen per Single Sign-on über einen
SAML-2.0-Identitätsanbieter (IdP) an, etwa den einer Hochschule oder eines
Unternehmens. Die Anmeldung selbst erledigt der
Shibboleth-Service-Provider im Apache vor owncloud.online. Die App liest die
Angaben, die der Service-Provider weitergibt, und ordnet die Person einem Konto
zu: einem Konto, das sie bei der ersten Anmeldung anlegt, oder einem Konto aus
einem anderen Benutzer-Backend wie LDAP.

## Voraussetzungen

| Bereich | Anforderung |
| --- | --- |
| owncloud.online | 11.x |
| PHP | 8.4, als PHP-FPM oder `mod_php` |
| Webserver | Apache 2.4 mit Shibboleth-Service-Provider 3 (Paket `libapache2-mod-shib`, Dienst `shibd`) |
| Identitätsanbieter | SAML 2.0, liefert eine eindeutige Kennung; empfohlen `eduPersonPrincipalName` (Variable `eppn`) oder `subject-id` |
| Marketplace | gültiger API-Schlüssel, siehe Kasten oben |

## Installation

Mit eingetragenem API-Schlüssel: im **Markt** nach „SAML/SSO Integration“
suchen und **Installieren** klicken. Auf der Kommandozeile:

```bash
# API-Schlüssel ohne Weboberfläche hinterlegen
sudo -u www-data php8.4 occ config:app:set market key --value='<API-SCHLÜSSEL>'

# App installieren und einschalten
sudo -u www-data php8.4 occ market:install user_shibboleth
```

Zwischen den beiden Befehlen im Markt **Cache leeren** wählen. Der Markt hält
den Katalog bis zu 30 Minuten im Zwischenspeicher; solange kennt
`occ market:install` die App unter Umständen nicht.

Alternativ steht der Schlüssel in `config/config.php` als
`'marketplace.key' => '<API-SCHLÜSSEL>'`. Dieser Eintrag hat Vorrang; der Markt
kann den Schlüssel dann nicht mehr ändern.

Nach dem Einschalten steht die Betriebsart auf **Nicht aktiv**. Bis Sie eine
andere Betriebsart wählen, ändert die App nichts an der Anmeldung.

## Webserver und Service-Provider

Im folgenden Aufbau ist dieselbe Instanz zweimal erreichbar: unter `/` ohne SSO
(lokale Anmeldung, Clients, öffentliche Links) und unter `/oc-shib` mit dem
Service-Provider. Auf dem SSO-Pfad verlangt der Service-Provider eine
IdP-Sitzung nur auf der Anmeldeseite. Das Beispiel geht von PHP-FPM aus; mit
`mod_php` entfällt der Block `FilesMatch`.

```apache
<VirtualHost *:443>
    ServerName cloud.example.org
    DocumentRoot /var/www/owncloud.online

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/cloud.example.org.crt
    SSLCertificateKeyFile /etc/ssl/private/cloud.example.org.key

    # Ohne AllowOverride All gilt die .htaccess von owncloud.online nicht
    <Directory /var/www/owncloud.online>
        Require all granted
        AllowOverride All
    </Directory>
    # Unter PHP-FPM erreicht die Kopfzeile Authorization PHP nur mit CGIPassAuth On
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
        CGIPassAuth On
    </FilesMatch>

    Alias /oc-shib /var/www/owncloud.online
    # Auf dem ganzen SSO-Pfad: Attribute, sobald eine IdP-Sitzung besteht, aber kein Zwang
    <Location /oc-shib>
        AuthType shibboleth
        ShibRequestSetting requireSession false
        Require shibboleth
    </Location>
    # Nur die Anmeldeseite verlangt eine IdP-Sitzung
    <LocationMatch "^/oc-shib/(index\.php/)?login">
        AuthType shibboleth
        ShibRequestSetting requireSession true
        Require shib-session
    </LocationMatch>

    <Location /Shibboleth.sso>
        AuthType None
        Require all granted
        SetHandler shib
    </Location>
</VirtualHost>
```

Fehlt `AllowOverride All` oder `CGIPassAuth On`, antworten WebDAV, OCS und die
Anmeldung der Clients mit 401. Im Formular der App tragen Sie dazu als
**SSO-Einstieg** `/oc-shib/index.php/login` ein.

Wer nur einen Pfad betreibt, legt `requireSession false` auf `/` und
`requireSession true` auf die Anmeldeseite. Eine lokale Anmeldung ist dann nur
über eine eigene Ausnahme im Webserver möglich.

Der Service-Provider muss die Attribute als Umgebungsvariablen übergeben, nicht
als Kopfzeilen. `REMOTE_USER` setzt er aus den Attributen, die in
`/etc/shibboleth/shibboleth2.xml` stehen:

```xml
<ApplicationDefaults entityID="https://cloud.example.org/shibboleth"
                     REMOTE_USER="eppn subject-id persistent-id">
```

In der mitgelieferten `/etc/shibboleth/attribute-map.xml` sind `mail` und
`displayName` auskommentiert, ein Attribut für das Kontingent fehlt. Aktivieren
oder ergänzen Sie die benötigten Zeilen:

```xml
<Attribute name="urn:oid:0.9.2342.19200300.100.1.3" id="mail"/>
<Attribute name="urn:oid:2.16.840.1.113730.3.1.241" id="displayName"/>
<Attribute name="urn:oid:1.3.6.1.4.1.5923.1.1.1.6" id="eppn"/>
<!-- eigenes Attribut für das Kontingent; die OID ist ein Beispiel -->
<Attribute name="urn:oid:1.3.6.1.4.1.99999.1.1" id="quota"/>
```

Danach `shibd` neu starten und die Metadaten des Identitätsanbieters neu laden.

## Einrichtung

**Einstellungen → Administration → Nutzer-Authentifizierung**, Abschnitt
**SAML/SSO-Integration**. Ganz oben prüft der Kasten **Diese Anfrage** die
aktuelle Anfrage: Öffnen Sie die Seite über den SSO-Pfad, zeigt er Sitzung,
Kennung und das Konto, das die App verwenden würde. Darunter folgt die
Serverumgebung der Anfrage; Cookies, Zugangsdaten, Schlüssel und Tokens sind
dort ausgeblendet.

<figure markdown="span">
  ![Administration, SAML/SSO-Integration mit Betriebsart und Servervariablen](../../assets/screenshots/de/admin-saml.png)
  <figcaption>Für Single Sign-on über SAML wählen Sie die Betriebsart und tragen die Servervariablen des Webservers ein.</figcaption>
</figure>

### Betriebsart

| Auswahl | Wert für `occ` | Wirkung |
| --- | --- | --- |
| Nicht aktiv | `notactive` | Single Sign-on ist aus. Vorgabe. |
| Benutzer automatisch anlegen | `autoprovision` | Wer sich per SSO anmeldet und noch kein Konto hat, bekommt eines. Vorhandene Konten, auch aus LDAP, werden erkannt und genutzt. |
| Nur Single Sign-on (Konten aus anderem Backend, z. B. LDAP) | `ssoonly` | Nur Personen mit vorhandenem Konto, etwa aus LDAP, können sich anmelden. Es entstehen keine neuen Konten. |

In allen Betriebsarten bleibt die Anmeldung mit Passwort für lokale Konten und
LDAP-Konten möglich.

### Servervariablen

| Feld | Vorgabe | Bedeutung |
| --- | --- | --- |
| Sitzung | `Shib-Session-ID` | Variable, an der eine aktive SSO-Sitzung erkannt wird. Pflicht. |
| Benutzerkennung | `eppn` | Variable mit der Kennung des Kontos. Pflicht. |
| E-Mail | `eppn` | Variable mit der E-Mail-Adresse; bei mehreren Adressen gilt die erste gültige |
| Anzeigename | `eppn` | Variable mit dem Anzeigenamen |
| Kontingent | `quota` | Variable mit dem Speicherkontingent, etwa `5 GB`, `none` oder `default` |

E-Mail, Anzeigename und Kontingent dürfen leer bleiben. Dann übernimmt die App
den Wert nicht vom IdP, und das Konto behält seinen gespeicherten Wert.
Die Felder akzeptieren auch Variablen, die auf dieser Seite gerade fehlen; beim
Tippen erscheinen Vorschläge.

### Kennungs-Umsetzer

| Auswahl | Wirkung |
| --- | --- |
| Unverändert (Standard) | Die Kennung bleibt, wie der IdP sie liefert. |
| AD FS: erster Wert, kleingeschrieben | Teil vor dem ersten `;`, ohne Leerzeichen am Rand und kleingeschrieben; für AD FS, das die Kennung oft doppelt liefert |
| GUID: Binärform in Textform umwandeln | wandelt eine GUID aus 32 Hex-Zeichen in die übliche Textform mit Bindestrichen um |

### Anmeldeseite und Abmeldung

| Feld | Bedeutung |
| --- | --- |
| SSO-Einstieg | Adresse der Anmeldeseite hinter Single Sign-on, zum Beispiel `/oc-shib/index.php/login`. Ist sie gesetzt, zeigt die Anmeldeseite einen Knopf, der die aufgerufene Seite beibehält. |
| Knopfbeschriftung | Text des Knopfs, höchstens 100 Zeichen. Leer heißt „Mit Single Sign-on anmelden“. |
| Anmeldeseite überspringen | Wer die Anmeldeseite ohne SSO aufruft, landet direkt beim SSO-Einstieg. Die lokale Anmeldung bleibt unter `/index.php/login?direct=1` erreichbar. |
| Abmelde-Adresse des Service-Providers | zum Beispiel `/Shibboleth.sso/Logout`. Ist sie gesetzt, beendet **Abmelden** die Sitzung in owncloud.online und danach beim Service-Provider. Ist sie leer, bittet owncloud.online per SSO angemeldete Personen, den Browser zu schließen. |

Schalten Sie **Anmeldeseite überspringen** nur ein, wenn sich alle Personen per
Single Sign-on anmelden. Gäste und lokale Konten landen sonst beim
Identitätsanbieter, wo sie kein Konto haben. Die Abmelde-Adresse hilft nur, wenn
der Identitätsanbieter Single Logout beherrscht; sonst meldet er die Person
sofort wieder an.

Steht in `config/config.php` unter `login.alternatives` noch ein eigener Eintrag
für den SSO-Pfad, entfernen Sie ihn, sobald der SSO-Einstieg gesetzt ist. Sonst
zeigt die Anmeldeseite zwei gleiche Knöpfe.

### Kommandozeile

```bash
# Betriebsart anzeigen oder setzen: notactive, autoprovision, ssoonly
sudo -u www-data php8.4 occ shibboleth:mode autoprovision

# Servervariablen und Umsetzer anzeigen oder setzen
sudo -u www-data php8.4 occ shibboleth:mapping --uid eppn --email mail --display-name displayName --quota quota

# Einrichtung prüfen; Rückgabewert 0 = in Ordnung, 1 = Probleme
sudo -u www-data php8.4 occ shibboleth:check
```

`shibboleth:mapping` kennt außerdem `--shib-session` und `--uid-mapper` mit dem
Klassennamen des Umsetzers, etwa `OCA\User_Shibboleth\Mapper\ADFSMapper`.
`shibboleth:check` prüft Betriebsart, Zuordnung, Umsetzer, SSO-Einstieg,
`login.alternatives`, `overwrite.cli.url` und die WebDAV-Adresse und weist auf
unsichere Zuordnungen hin.

## So findet die App das Konto

1. Gibt es ein Konto mit genau dieser Kennung, verwendet die App es mit seinem
   eigenen Backend.
2. Sonst durchsucht sie die übrigen Backends, etwa LDAP. Es zählen nur exakte
   Treffer, Groß- und Kleinschreibung spielen keine Rolle. Bei lokalen Konten
   zählt nur die Kennung, nie die E-Mail-Adresse. Passen mehrere Konten, lehnt
   die App die Anmeldung ab.
3. Ohne Treffer legt die Betriebsart **Benutzer automatisch anlegen** ein neues
   Konto an; **Nur Single Sign-on** lehnt ab.

Lokale Konten mit Administratorrechten meldet Single Sign-on nie an, auch wenn
der IdP eine gleichlautende Kennung liefert. Diese Konten melden sich mit
Passwort unter `/index.php/login?direct=1` an. Wer per SSO Administrator sein
soll, bekommt die Rechte für sein SSO- oder LDAP-Konto.

Ist ein Verzeichnis wie LDAP nicht erreichbar, bricht die Anmeldung ab, statt ein
doppeltes Konto anzulegen.

### Von „Benutzer automatisch anlegen“ zu „Nur Single Sign-on“ wechseln

Konten, die die App angelegt hat, gehören weiter zu ihrem eigenen Backend. Sie
funktionieren, solange ihre Kennung genau zur SSO-Kennung passt. Findet die
Suche dagegen ein LDAP-Konto, dessen Kennung schon einem Konto der App gehört,
lehnt die App die Anmeldung ab. So stellen Sie ein solches Konto auf LDAP um:

1. `occ shibboleth:check` zeigt, wie viele Konten noch zum Backend der App
   gehören.
2. Mit `occ ldap:search <Name>` und `occ user:list -a backend -a email <Kennung>`
   prüfen, unter welcher Kennung LDAP die Person führt.
3. Die Datenbank sichern. Verwendet LDAP dieselbe Kennung, das Backend des
   Kontos umstellen, zum Beispiel unter MariaDB oder MySQL (dort werden
   Backslashes verdoppelt, unter PostgreSQL und SQLite nicht):

    ```sql
    UPDATE oc_accounts SET backend = 'OCA\\User_LDAP\\User_Proxy'
     WHERE user_id = 'carol' AND backend = 'OCA\\User_Shibboleth\\UserBackend';
    ```

    Danach `sudo -u www-data php8.4 occ user:sync "OCA\User_LDAP\User_Proxy" -u carol`.

4. Verwendet LDAP eine andere Kennung, übertragen Sie Dateien und Freigaben auf
   das LDAP-Konto, bevor Sie das alte Konto löschen.

Zurück zu **Benutzer automatisch anlegen** geht es ohne Umstellung.

## Sicherheitshinweise

- **Keine Kopfzeilen als Quelle:** Variablen, die der Browser selbst schicken
  kann (`HTTP_*`, `PHP_AUTH_USER`, `PHP_AUTH_PW`), gehören nicht in die
  Zuordnung. Verwenden Sie die Umgebungsvariablen des Service-Providers und als
  Kennung `REMOTE_USER` oder ein Attribut mit Scope. Panel und
  `shibboleth:check` warnen vor unsicheren Zuordnungen.
- **Eindeutige Kennung:** Ordnen Sie eine Kennung mit Scope zu, etwa `eppn`
  oder `subject-id`, keinen einfachen Namen wie `uid`, den ein IdP-Konto mit dem
  Namen eines lokalen Kontos teilen könnte.
- **Neue Konten** bekommen nur Kennungen ohne Schrägstrich, Backslash und
  Steuerzeichen, höchstens 64 Byte lang und mit genau einem Wert. Liefert der IdP
  mehrere Werte (`a;b`), legt die App kein Konto an. Abhilfe: ein einwertiges
  Attribut zuordnen oder den AD-FS-Umsetzer wählen.
- Das Protokoll enthält bei Fehlern nur Variablennamen und die Zuordnung, keine
  Werte der Serverumgebung.

## Grenzen und Hinweise

- **Clients:** Desktop- und Mobil-Clients melden sich mit App-Passwort oder über
  [OAuth2](oauth2.md) an, nicht über den IdP. Dafür stehen
  `remote.php/nonshib-webdav` und `remote.php/dav` bereit.
- **Verschlüsselung:** Mit Single Sign-on gibt es kein Passwort, aus dem sich
  Schlüssel je Benutzer ableiten lassen. Die
  [Verschlüsselung](../../administration/encryption.md) funktioniert nur mit dem
  Hauptschlüssel. Ist die Verschlüsselungs-App eingeschaltet, ohne dass
  verschlüsselt wird, zeigt sie SSO-Konten einen irreführenden Hinweis zum
  privaten Schlüssel. Schalten Sie sie in diesem Fall aus.
- **Windows-Netzlaufwerke (WND):** Die Anmeldeart, die die Zugangsdaten der
  Sitzung verwendet, braucht ein Passwort und funktioniert mit SSO-Konten nicht.
  Gespeicherte Zugangsdaten funktionieren.
- **Kein `user:sync`:** Das Backend kann keine Konten auflisten; `occ user:sync`
  ist dafür nicht möglich.
- **Links aus E-Mails** führen nach der SSO-Anmeldung zur verlinkten Datei oder
  zum verlinkten Ordner, auch wenn die E-Mail auf die Adresse ohne SSO zeigt.

## Von einer Fassung 3.x aktualisieren

Einstellungen und Konten einer früheren Fassung 3.x dieser App (gleiche App-ID)
gelten weiter. So aktualisieren Sie:

1. Den alten Ordner `user_shibboleth` aus dem App-Verzeichnis entfernen oder
   außerhalb der Instanz ablegen. Das neue Paket nicht darüber entpacken, sonst
   bleiben alte Dateien liegen.
2. Das neue Paket entpacken und den Webserver-Benutzer als Besitzer eintragen
   (`chown -R www-data:www-data user_shibboleth`).
3. `sudo -u www-data php8.4 occ upgrade` ausführen. `occ app:enable` genügt hier
   nicht.
4. `sudo -u www-data php8.4 occ shibboleth:check` ausführen.

Bewusst anders als in 3.x: Lokale Administratorkonten meldet SSO nicht mehr an,
die E-Mail-Adresse lokaler Konten führt nicht mehr zur Zuordnung, und eine
Kennung mit mehreren Werten legt kein neues Konto mehr an. `shibboleth:check`
zeigt betroffene Konten.

Meldet `shibboleth:check`, die WebDAV-Adresse für Clients sei nicht registriert,
stellt dieser Befehl sie wieder her:

```bash
sudo -u www-data php8.4 occ config:app:set core remote_nonshib-webdav --value=user_shibboleth/appinfo/remote.php
```

## Fehlersuche

| Meldung oder Symptom | Ursache | Abhilfe |
| --- | --- | --- |
| „Keine Single-Sign-on-Sitzung in dieser Anfrage“ im Kasten **Diese Anfrage** | Die Seite wurde ohne SSO-Pfad geöffnet. | Seite über den SSO-Pfad öffnen, etwa `/oc-shib/index.php/settings/admin?sectionid=authentication` |
| „Anmeldung nicht möglich: Der Identitätsanbieter hat keine Benutzerkennung übermittelt, oder dein Konto ist hier nicht freigeschaltet.“ | Die Kennungs-Variable fehlt, oder die Kennung trifft ein lokales Administratorkonto. | Zuordnung und `attribute-map.xml` prüfen; Grund steht im Protokoll |
| „Anmeldung nicht möglich: Deine Anmeldung passt zu mehreren Konten.“ | Mehrere Konten passen zur Kennung. | Konten bereinigen oder eine eindeutigere Kennung zuordnen |
| „Anmeldung nicht möglich: Dein Konto wird von einer anderen Benutzerverwaltung geführt.“ | Nach einem Wechsel zu **Nur Single Sign-on** gehört die Kennung schon einem Konto der App. | Konto auf das LDAP-Backend umstellen oder Daten übertragen |
| „Die Anmeldung ist vorübergehend nicht möglich, weil die Benutzerverwaltung nicht erreichbar ist.“ | LDAP ist nicht erreichbar. | Verbindung zum Verzeichnis prüfen |
| WebDAV und Clients bekommen 401 | `AllowOverride All` oder `CGIPassAuth On` fehlt. | Webserver-Konfiguration ergänzen |
| Neue Konten haben keine E-Mail-Adresse | `mail` kommt nicht an. | `attribute-map.xml` ergänzen, `shibd` neu starten |
