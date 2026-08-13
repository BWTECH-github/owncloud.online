# Gestaltung von owncloud.online

## Worum es geht

owncloud.online bringt ein eigenes Erscheinungsbild mit — Farben, Typografie und
Logos weichen bewusst vom Ursprungsprojekt ab. Wer daran mitarbeiten möchte,
findet hier den Rahmen.

## Wo das Design liegt

* **Farben und Grundlayout** — `core/css/`, insbesondere `styles.css`,
  `apps.css` und `global.css`
* **Logos und Symbole** — `core/img/`
* **Theming je Instanz** — die App `theme-owncloudonline` überschreibt Farben und
  Logos, ohne den Kern anzufassen
* **Eigenes Erscheinungsbild** — der Zweig `main` des Repositorys
  [owncloud.onlineRedesign](https://github.com/BWTECH-github/owncloud.onlineRedesign)
  legt eine Design-Ebene über den jeweils aktuellen Kern

## Zwei Regeln, die hier zählen

**Minifizierte Geschwister mitliefern.** Der Server bevorzugt `.min.css` und
`.min.js`. Wer nur die Quelldatei ändert, ändert nichts am Ergebnis. Nach jeder
Änderung `make minify-assets` laufen lassen und das Ergebnis mitcommitten; die
CI prüft das und wird sonst rot.

**Barrierefreiheit ist Teil der Gestaltung, nicht die Kür danach.** Wir prüfen
gegen WCAG 2.1 AA. Konkret heißt das unter anderem:

* Kontrast mindestens 4,5:1 für Text, 3:1 für Bedienelemente und deren Zustände
* jedes bedienbare Element per Tastatur erreichbar **und** auslösbar — bei
  `role="button"` auch mit der Leertaste
* ein sichtbarer Fokusindikator, der nicht von einem `overflow`-Container
  abgeschnitten wird; im Zweifel `outline-offset: -2px` statt eines positiven
  Versatzes
* Zustände nie allein über Farbe unterscheiden

## Mitarbeiten

Vorschläge und Entwürfe bitte als
[Issue](https://github.com/BWTECH-github/owncloud.online/issues) mit öffentlich
erreichbaren Bilddateien. Konkrete Änderungen als Pull Request, siehe
[CONTRIBUTING](.github/CONTRIBUTING.md).
