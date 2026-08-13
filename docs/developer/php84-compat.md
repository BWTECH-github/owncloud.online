# PHP 8.4 Kompatibilität

owncloud.online wird auf PHP 8.4 betrieben. Core und Plugins müssen darauf geprüft werden.

## Regeln

- Keine dynamischen Properties ohne ausdrückliche Behandlung.
- Keine veralteten PHP-Funktionen neu einbauen.
- Methoden-Signaturen mit Parent-Klassen und Interfaces abgleichen.
- Nullable- und Return-Typen bewusst setzen.
- Composer-Abhängigkeiten gegen PHP 8.4 testen.
- Warnings und Deprecated-Meldungen nicht ignorieren.

## Checks

```bash
composer install
make
php8.4 occ app:check-code <app_id>
php8.4 occ maintenance:repair
```

Wenn ein Plugin eigene Tests hat:

```bash
composer test
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

## Plugin-Metadaten

`appinfo/info.xml` muss stimmen:

- Name: sichtbarer App-Name, keine Zusatztexte in der Überschrift.
- Author: Original-Author plus `modified by BW-Tech GmbH`, wenn das Plugin auf ownCloud-Code basiert.
- Beschreibung: enthält den Hinweis auf die BW-Tech-Anpassung.
- PHP-Kompatibilität: keine alte PHP-Max-Version setzen, wenn PHP 8.4 unterstützt wird.

Beispiel:

```xml
<name>PDF Viewer</name>
<author>ownCloud contributors, modified by BW-Tech GmbH</author>
```
