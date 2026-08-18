# Plan: Verschärfung der Legacy-Inhaltsrichtlinie (CSP)

Stand: 18.08.2026 · Betrifft: `lib/private/legacy/response.php` · Vorzulegen vor
jeder Änderung

## Ausgangslage laut Befund

`lib/private/legacy/response.php` setzt:

```
default-src 'self'; script-src 'self' 'unsafe-eval'; style-src 'self' 'unsafe-inline';
frame-src *; img-src * data: blob:; font-src 'self' data:; media-src *; connect-src *
```

`object-src`, `base-uri` und `frame-ancestors` fehlen.

## Was die Messung ergibt

Gemessen an der laufenden Instanz, alle Antworten mitgeschnitten:

| Weg | ausgelieferte Richtlinie |
| --- | --- |
| `/index.php/login` | `default-src 'none'` … (AppFramework, streng) |
| `/index.php/apps/files/` | `default-src 'none'` … `frame-src 'self' blob:` |
| `/index.php/settings/users` | `default-src 'none'` … (streng) |
| **öffentlicher Link** `/index.php/s/<token>` | `default-src 'none'` … (streng) |
| `/remote.php/dav/…` | `default-src 'none';` |
| `/index.php/core/js/oc.js` | **Legacy-Richtlinie** |
| `/status.php`, `/cron.php`, `/public.php`, `/ocs/v2.php/…` | **Legacy-Richtlinie** |

Das verschiebt die Bewertung deutlich: **Kein HTML-Dokument der Oberfläche
läuft über die Legacy-Richtlinie** — auch die öffentliche Freigabeseite nicht,
die als einziger Weg unangemeldeten Besuchern offensteht. Die weite Richtlinie
liegt auf Antworten, die entweder gar keine Dokumente sind (JavaScript, JSON,
XML) oder Fehler- und Zwischenseiten.

Für eine Ressource, die als Skript oder Datensatz eingebunden wird, ist die
mitgelieferte CSP wirkungslos — es zählt die des einbettenden Dokuments. Die
Legacy-Richtlinie wird also erst dann scharf, wenn eine dieser Antworten
**direkt im Browser als Dokument geöffnet** wird.

Das ist kein akutes Loch. Es bleibt trotzdem ein Punkt, den man aufräumt: Die
Richtlinie ist die Rückfallebene für alles, was künftig nicht über das
AppFramework läuft, und drei Direktiven fehlen an *beiden* Richtlinien.

## Die eigentliche Lücke: drei fehlende Direktiven

`base-uri` und `frame-ancestors` fallen **nicht** unter `default-src` — sie
fehlen damit auch in der strengen AppFramework-Richtlinie:

- **`base-uri`** fehlt: Ein eingeschleustes `<base href="…">` lenkt jede
  relative Adresse um, auch die der Skripte. Das hebelt `script-src 'self'`
  praktisch aus, sobald irgendwo Markup eingeschleust werden kann.
- **`frame-ancestors`** fehlt: Der Schutz gegen Einbettung in fremde Seiten
  hängt allein an `X-Frame-Options: SAMEORIGIN` aus der `.htaccess`. Das
  funktioniert, ist aber der abgekündigte Weg und greift nicht, wenn
  `mod_headers` fehlt.
- **`object-src`** fehlt: in der strengen Richtlinie durch `default-src 'none'`
  abgedeckt, in der Legacy-Richtlinie durch `default-src 'self'` — dort also
  nicht auf `'none'`, sondern auf „eigene Herkunft erlaubt".

## Vorgeschlagenes Vorgehen

### Schritt 1 — die drei fehlenden Direktiven ergänzen (geringes Risiko)

Beide Richtlinien um `object-src 'none'`, `base-uri 'self'` und
`frame-ancestors 'self'` erweitern. Keine davon schränkt etwas ein, was die
Oberfläche heute tut: Es gibt keine `<object>`- oder `<embed>`-Einbindung, kein
`<base>`-Element, und Einbettung in fremde Seiten ist schon per
`X-Frame-Options` untersagt.

*Messung vorher/nachher:* alle Seiten des Barrierefreiheitslaufs (28 Adressen)
plus öffentlicher Link, Vorschau, Mediaviewer und PDF-Betrachter mit
mitgeschnittener Browserkonsole. Erwartung: null neue Verstöße.

### Schritt 2 — `frame-src`, `media-src`, `connect-src` in der Legacy-Richtlinie eingrenzen

Von `*` auf `'self'` (plus `blob:` bei `frame-src`, wie es die strenge
Richtlinie bereits führt).

*Risiko:* Hier hängt dran, was **nicht** über das AppFramework läuft. Das sind
vor allem Erweiterungen Dritter, die eigene Einbettungen mitbringen —
Kartendienste, Videokonferenz, externe Vorschaudienste. In unserem Satz ist
nichts davon aktiv; auf einer Kundeninstanz kann es das sein.

*Vorgehen:* zuerst **nur berichten, nicht durchsetzen.** Zusätzlich zur
bestehenden Kopfzeile eine `Content-Security-Policy-Report-Only` mit der
schärferen Fassung ausliefern, samt Sammelpunkt für die Meldungen. Nach zwei
Wochen Betrieb auf einer echten Instanz auswerten und erst dann durchsetzen.
Ohne diese Runde ist die Umstellung ein Blindflug.

### Schritt 3 — `unsafe-eval` in `script-src`

Das ist der größte Brocken und gehört ans Ende.

*Wer braucht es:* Handlebars-Vorlagen, die zur Laufzeit übersetzt werden,
Underscore-`_.template`, und die Ausdrucksauswertung in einzelnen Apps. Ein
Teil davon verschwindet, wenn die Vorlagen zur Bauzeit übersetzt und als
fertige Funktionen ausgeliefert werden — den Weg gehen die `*.handlebars.js`
im SaaS-Bundle bereits.

*Vorgehen:* erst erheben, welche Aufrufe tatsächlich `eval` oder
`new Function` auslösen (Berichtsmodus mit `script-src 'self'` ohne
`unsafe-eval` auf einer Testinstanz, Konsole mitschneiden), dann pro Fundstelle
entscheiden. Das ist ein eigenes Vorhaben, keine Zeile in einer
Sicherheitsrunde.

### `unsafe-inline` in `style-src`

Bleibt vorerst. Die Oberfläche setzt an vielen Stellen Stile direkt am Element
(Fortschrittsbalken, Vorschaubilder, Spaltenbreiten, die Symbolvariable der
Zeilen). Ohne `unsafe-inline` bräuchte jede dieser Stellen einen anderen Weg —
das ist eine Umbauarbeit über den gesamten Bestand, kein
Konfigurationsschalter. `style-src` ohne `unsafe-inline` erlaubt zwar
`nonce`-Werte, die helfen aber nur bei `<style>`-Blöcken, nicht bei
`style`-Attributen; für die gibt es keinen Ausweg außer Umbau.

## Empfehlung

Schritt 1 sofort umsetzen — er kostet fast nichts und schließt die einzige
Lücke, die auch die strenge Richtlinie hat. Schritt 2 mit Berichtsrunde
vorbereiten. Schritt 3 als eigenes Vorhaben führen.

**Nicht** empfehlenswert: die Legacy-Richtlinie in einem Zug auf das Niveau der
AppFramework-Richtlinie ziehen. Der Gewinn ist nach der Messung klein, das
Risiko für Fremd-Apps und für SabreDAV-Wege real, und ein Fehler dort fällt
erst auf, wenn ein Kunde eine Funktion nicht mehr benutzen kann.
