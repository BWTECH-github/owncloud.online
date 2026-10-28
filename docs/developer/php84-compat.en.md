# PHP 8.4 compatibility

owncloud.online runs on PHP 8.4: `composer.json` requires `>=8.4`. The core
and all plugins have to be tested against it.

## Rules

- No dynamic properties without explicit handling.
- Do not introduce new calls to deprecated PHP functions.
- Match method signatures with parent classes and interfaces.
- Set nullable and return types deliberately.
- Test Composer dependencies against PHP 8.4.
- Do not ignore warnings and deprecation notices.

## Common pitfalls

These constructs produce deprecation notices under PHP 8.4. The server writes
them to the [server log](../administration/logging.en.md) with `app: PHP`.

| Since | Deprecated | Use instead |
| --- | --- | --- |
| PHP 8.1 | Passing `null` to parameters of built-in functions that do not accept `null`, such as `strlen(null)` or `trim(null)` | Check the value first or guard it with `?? ''` |
| PHP 8.2 | Dynamic properties, meaning assignments to properties the class does not declare | Declare the property in the class; use `#[\AllowDynamicProperties]` only where needed |
| PHP 8.2 | `"${var}"` in strings | `"{$var}"` |
| PHP 8.2 | `utf8_encode()` and `utf8_decode()` | `mb_convert_encoding()` |
| PHP 8.4 | Implicitly nullable parameters: `function f(Foo $x = null)` | `function f(?Foo $x = null)` |
| PHP 8.4 | `trigger_error()` with `E_USER_ERROR` | Throw an exception |
| PHP 8.4 | The `E_STRICT` constant | Remove it |

## Checks

In the plugin or server directory, if it contains a `composer.json` or a
`Makefile`:

```bash
composer install
make
```

Then, on an instance running PHP 8.4:

```bash
php8.4 occ app:check-code <app_id>
php8.4 occ maintenance:repair
```

`app:check-code` reports the use of private core classes (such as `OC_DB` or
`OC_Helper`), deprecated core interfaces, and the `==` and `!=` comparisons; it
also validates `appinfo/info.xml`. `maintenance:repair` shows whether the
repair steps run without errors under PHP 8.4.

If a plugin has its own tests:

```bash
composer test
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

## Plugin metadata

`appinfo/info.xml` has to be correct:

- Name: the visible app name, without extra text.
- Author: if the plugin is based on third-party code, list the original authors
  plus `modified by BW-Tech GmbH`. Plugins developed by BW-Tech GmbH name only
  `BW-Tech GmbH`.
- Description: for modified plugins, it mentions that BW-Tech GmbH adapted
  the plugin.
- PHP compatibility: `<php min-version="8.4"/>` under `<dependencies>`; do not
  set a `max-version` that excludes PHP 8.4.

Examples:

```xml
<!-- Plugin based on third-party code -->
<name>PDF Viewer</name>
<author>Original authors, modified by BW-Tech GmbH</author>

<!-- Developed by BW-Tech GmbH -->
<name>MCP Connector</name>
<author>BW-Tech GmbH</author>

<dependencies>
    <php min-version="8.4"/>
</dependencies>
```
