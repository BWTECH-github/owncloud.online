# Kompatibilität mit PHP 8.4

owncloud.online läuft auf PHP 8.4: `composer.json` verlangt `>=8.4`. Kern und
Plugins müssen darauf geprüft sein.

## Regeln

- Keine dynamischen Eigenschaften ohne ausdrückliche Behandlung.
- Keine veralteten PHP-Funktionen neu einbauen.
- Methoden-Signaturen mit Elternklassen und Interfaces abgleichen.
- Nullable- und Rückgabetypen bewusst setzen.
- Composer-Abhängigkeiten gegen PHP 8.4 testen.
- Warnungen und Deprecated-Meldungen nicht ignorieren.

## Häufige Stolpersteine

Diese Konstrukte erzeugen unter PHP 8.4 Deprecated-Meldungen. Der Server
schreibt sie mit `app: PHP` ins
[Serverprotokoll](../administration/logging.md).

| Seit | Veraltet | Stattdessen |
| --- | --- | --- |
| PHP 8.1 | `null` als Argument für Parameter eingebauter Funktionen, die `null` nicht erlauben, etwa `strlen(null)` oder `trim(null)` | Wert vorher prüfen oder mit `?? ''` absichern |
| PHP 8.2 | dynamische Eigenschaften, also Zuweisungen an Eigenschaften, die die Klasse nicht deklariert | Eigenschaft in der Klasse deklarieren; nur wo nötig `#[\AllowDynamicProperties]` |
| PHP 8.2 | `"${var}"` in Zeichenketten | `"{$var}"` |
| PHP 8.2 | `utf8_encode()` und `utf8_decode()` | `mb_convert_encoding()` |
| PHP 8.4 | implizit nullable Parameter: `function f(Foo $x = null)` | `function f(?Foo $x = null)` |
| PHP 8.4 | `trigger_error()` mit `E_USER_ERROR` | eine Exception werfen |
| PHP 8.4 | die Konstante `E_STRICT` | weglassen |

## Prüfen

Im Verzeichnis des Plugins oder des Servers, sofern dort eine `composer.json`
bzw. ein `Makefile` liegt:

```bash
composer install
make
```

Danach auf einer Instanz mit PHP 8.4:

```bash
php8.4 occ app:check-code <app_id>
php8.4 occ maintenance:repair
```

`app:check-code` meldet die Nutzung privater Kernklassen (etwa `OC_DB` oder
`OC_Helper`), veralteter Kernschnittstellen und der Vergleiche `==` und `!=`;
außerdem prüft es die `appinfo/info.xml`. `maintenance:repair` zeigt, ob die
Reparaturschritte unter PHP 8.4 ohne Fehler durchlaufen.

Wenn ein Plugin eigene Tests hat:

```bash
composer test
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

## Plugin-Metadaten

`appinfo/info.xml` muss stimmen:

- Name: der sichtbare App-Name ohne Zusatztexte.
- Autor: Baut das Plugin auf fremdem Code auf, stehen hier die ursprünglichen
  Autoren plus `modified by BW-Tech GmbH`. Eigenentwicklungen nennen nur
  `BW-Tech GmbH`.
- Beschreibung: Bei angepassten Plugins enthält sie den Hinweis auf die
  Anpassung durch die BW-Tech GmbH.
- PHP-Kompatibilität: `<php min-version="8.4"/>` unter `<dependencies>`; keine
  `max-version` setzen, die PHP 8.4 ausschließt.

Beispiele:

```xml
<!-- Plugin auf Basis fremden Codes -->
<name>PDF Viewer</name>
<author>Ursprüngliche Autoren, modified by BW-Tech GmbH</author>

<!-- Eigenentwicklung -->
<name>MCP Connector</name>
<author>BW-Tech GmbH</author>

<dependencies>
    <php min-version="8.4"/>
</dependencies>
```
