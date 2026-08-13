# Desktop Client

Der Desktop Client wurde auf owncloud.online umgebrandet und lokal gegen owncloud.online 11.0.0 getestet.

## Lokaler Teststand

| Punkt | Ergebnis |
| --- | --- |
| Build | Windows, MSVC, Qt 6.8.3 |
| Branding | `owncloud.online`, `BW-Tech GmbH` |
| Login | OAuth2 über Browser erfolgreich |
| Server | `owncloud.online 11.0.0` erkannt |
| Sync | Upload, Delete und WebDAV geprüft |

## Getesteter Flow

1. Client starten.
2. Server-URL eintragen.
3. Browser-Login ausführen.
4. OAuth2-Zugriff autorisieren.
5. Konto abschließen.
6. Sync-Ordner prüfen.
7. Testdatei lokal anlegen.
8. Upload per WebDAV prüfen.
9. Testdatei löschen.
10. Remote-Löschung prüfen.

## Produktionshinweis

Der Client akzeptiert kein HTTP für den produktiven Login. Kundeninstanzen müssen HTTPS verwenden.

Für OAuth2 muss die Redirect-URL zum Client passen:

```text
http://localhost:*
```

Offener Punkt aus dem lokalen Test: owncloud.online muss selbst eine gültige OIDC-Discovery unter `/.well-known/openid-configuration` liefern oder der Client braucht einen sauberen Fallback. Der lokale Test nutzte dafür einen HTTPS-Testproxy.
