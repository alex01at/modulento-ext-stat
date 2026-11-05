# Statistik

Ein Addon für [Modulento](https://github.com/alex01at/modulento): DSGVO-konforme
Besucherstatistik ohne Cookies, ohne IP-Speicherung und ohne Einwilligungsbanner.
Läuft neben jeder App (oder ganz ohne eine) und neben jedem anderen Addon.

- Keine Cookies, kein `localStorage`, keine Session - "Besucher:in" ist ein
  Hash aus IP, User-Agent und einem geheimen Wert, der sich täglich ändert
  und sich nicht zurückrechnen lässt. Dadurch ist keine Einwilligung nötig
  (siehe `HitRecorder`s eigener Docblock).
- Erfasst: Seitenaufrufe über Zeit, meistbesuchte Seiten/Angebote, Herkunft
  (Referrer-Domain), Gerätetyp (grob), Land (nur wenn das Hosting/CDN es
  bereits mitliefert, z. B. Cloudflares `CF-IPCountry` - keine eigene
  IP-Geolokalisierung), Conversion (Bestellungen oder, bei aktiver
  Jobbörse, Bewerbungen).
- **Administration → Statistik**: die gesamte Seite.
- **`/account/stats`**: ein Anbieter sieht die Zahlen nur seiner eigenen
  Angebote.
- Räumt sich selbst auf: ein täglicher Task löscht Zeilen, die älter als
  die längste angebotene Zeitspanne sind.

## Installieren

Dieses Repository nach `extensions/stat` einer Modulento-Installation
klonen oder verlinken, dann unter Administration → Erweiterungen aktivieren.

## Tests

```
MODULENTO_CORE=/pfad/zu/modulento php tests/run.php
```

## Lizenz

Dies ist ein kommerzielles Produkt, lizenziert je Installation unter dem Endnutzer-Lizenzvertrag in `LICENSE` - nicht GPL (der Modulento-Kern selbst bleibt GPL-3.0-or-later; diese Erweiterung ist ein eigenständiges, proprietäres Werk).
