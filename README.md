# RcAbTesting

Ein Shopware-6-Plugin für A/B-Tests mit Sticky-Bucketing pro Besucher, Funnel-Tracking und statistischer Auswertung.

## Was das Plugin macht

RcAbTesting teilt Besucher deterministisch und geräteübergreifend stabil auf Varianten eines Experiments auf, verfolgt ihren Weg durch den Funnel (Seitenaufruf bis Bestellung) und wertet das Ergebnis statistisch aus (Conversion-Rate, Lift, z-Test, Konfidenzintervall, Signifikanz, Gewinner-Empfehlung). Experimente werden im Administrationsmodul unter **Marketing** angelegt, konfiguriert, gestartet und ausgewertet; alternativ per CLI.

### Test-Typen

- **Twig-Variant, Theme, Feature-Flag:** Eine eigene Vorlage fragt die Variante über `ab_variant('key')` oder ihre
  Einstellungen über `ab_variant_config('key')` ab und entscheidet selbst. Der Server behandelt die drei gleich; der
  Typ ordnet das Experiment nur in der Verwaltung ein.
- **Zwei Seiten vergleichen (CMS-Seite):** No-Code, pro Variante eine Erlebniswelt (CMS-Seite) per Dropdown; die zugewiesene Seite wird auf Navigation-/Landing-/Produktseiten automatisch ausgeliefert (Control = die aktuell live ausgelieferte Seite)
- **Plugin-Verhalten schalten (Frontend-Schalter):** No-Code, pro Variante wird ein registrierter, frontend-wirksamer Schalter auf einen Wert gesetzt (Dropdown statt JSON). Eigene Plugins/Templates lesen den aktiven Wert über die Twig-Funktion `ab_switch('key')` bzw. den `FrontendSwitchResolver`; Fremd-Plugins über einen getaggten `FrontendSwitchAdapter`. Beispiel-Schalter: Checkout-Darstellung, Versandkostenfrei-Hinweis

### Funnel-Events (Standard-Set)

- `page.viewed`, `product.added_to_cart`, `product.removed_from_cart`
- `checkout.started`, `checkout.confirm_viewed`, `checkout.order_placed`
- `cart.abandoned` (heuristisch über Scheduled-Task)
- `customer.registered`, `customer.logged_in`
- `custom.*` (Storefront-JS-API: `window.RcAbTesting.track('custom.<name>', value)`; andere Namen nimmt der Server aus dem Browser nicht an)

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

## Installation

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcAbTesting
php bin/console cache:clear
```

## Konfiguration

Im Admin unter **Einstellungen → System → Plugins → RcAbTesting**.

| Feld | Beschreibung | Standard |
|------|-------------|---------|
| `consentCookieName` | Name des Consent-Cookies eines Consent-Managers (z. B. ComanConsentManager). Ist er gesetzt, gilt Opt-in. | leer |
| `consentGrantedValue` | Cookie-Wert, der als erteilte Einwilligung zählt. | 1 |
| `cartAbandonmentMinutes` | Frist in Minuten, nach der ein begonnener Checkout ohne Bestellung als Warenkorb-Abbruch gilt. | 30 |
| `dataRetentionDays` | Aufbewahrungsfrist in Tagen, danach werden Daten anonymisiert. `0` schaltet die automatische Anonymisierung ab, ein leeres Feld bedeutet 90 Tage. | 90 |

Ohne konfigurierten Consent-Cookie gilt die Einwilligung aus dem Cookie-Banner von Shopware. Ohne Einwilligung nimmt der Besucher nicht teil, und ein vorhandenes Besucher-Cookie wird gelöscht.

## Datenschutz (DSGVO)

- Der Besucher-Cookie `rc_ab_visitor_id` braucht Consent. Ohne Einwilligung wird nichts dauerhaft gespeichert.
- Anonymisierung nach Ablauf der Aufbewahrungsfrist über den täglichen `DataRetentionTask` oder manuell per `bin/console rc:ab:cleanup --older-than-days=N`: `visitor_id` wird zu einem deterministischen SHA-256-Hash, `customer_id` entfernt.
- Löschung nach Art. 17: Wird ein Kunde gelöscht, löscht die Erweiterung vorher in derselben Transaktion seine
  Zuordnungen und Ereignisse (`CustomerDeletedSubscriber`, `CustomerDataEraser`); scheitert das, bleibt auch der Kunde.
- Auskunft nach Art. 15: `bin/console rc:ab:export --customer=<Kunden-ID>` gibt alle zu einem Kunden gespeicherten A/B-Daten aus.

## Interaktion mit anderen Ruhrcoder-Plugins

A/B-Tests berühren mehrere Plugins, die auf denselben Storefront-Bereichen rendern. Die folgenden Interaktions-Verträge gelten:

- **Confirm-Page** (mit RcOrderAttachment, RcCheckoutEnhancer): RcAbTesting rendert **nicht selbst** in die Confirm-Page, sondern entscheidet nur die Variante. Die Block-Hierarchie der anderen Plugins bleibt unberührt, additive UX-Erweiterungen werden also nicht verdrängt.
- **Twig-Variant-Konflikte** (RcCheckout ↔ RcCheckoutEnhancer): Konkurrierende Renderer werden nicht vom AB-Plugin allein aufgelöst. Die Bridge `ActiveVariantQuery` (public API) erlaubt den Ziel-Plugins, ihren Subscriber per Guard zu konditionieren. Prämisse: die Ziel-Subscriber sind additive Erweiterungen, keine konkurrierenden Vollrenderer.
- **Listing-Layout** (RcMinimalisticProductList): Feature-Flag-Varianten schalten boolesche Config-Werte, sie überschreiben keine Template-Overrides.
- **Buy-Widget** (RcDynamicPrice, RcColorPicker, RcCustomFields): Test-Varianten dürfen das `data-rc-id-controller`-Attribut des Plugin-Interaktions-Protokolls **nicht** überschreiben.

**Gleichzeitige Aktivität:** Pro Funnel-Schritt sollte nur ein Experiment laufen (Multi-Test-Interferenz verwässert die Aussagekraft). Die Storefront-Integration ist read-only gegenüber fremden Plugins.

## CLI

```bash
php bin/console rc:ab:list                 # Experimente auflisten (--status filtert)
php bin/console rc:ab:stats <key>          # Auswertung wie in der Verwaltung (Entscheidungskennzahl, Lift, p-Value, Gewinner)
php bin/console rc:ab:end <key> [--winner=] # laufendes oder pausiertes Experiment beenden
php bin/console rc:ab:cleanup --older-than-days=N  # DSGVO-Anonymisierung
php bin/console rc:ab:export --customer=<Kunden-ID>  # DSGVO-Auskunft (Art. 15)
```

## Geschwindigkeit messen

Das Messprojekt unter `benchmarks/` (nicht im Ausrollpaket) misst Auswertung und Wartung mit 10.000 und 100.000
Besuchern sowie Seitenzeiten mit und ohne Experiment; jeder Lauf landet in `benchmarks/results.csv`. Aufruf und
Voraussetzungen stehen in `benchmarks/run.sh`. Ein grober Wächter im Testlauf
(`tests/Integration/Service/PerformanceGuardIntegrationTest.php`) fängt große Rückschritte auch ohne Messlauf.

## Update

```bash
php bin/console plugin:refresh
php bin/console plugin:update RcAbTesting
php bin/console cache:clear
```

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```

Geprüft wird mit PHP CS Fixer (PSR-12), PHPStan Level 8, PHPUnit und `composer audit`, alle Prüfungen sind grün.

## Lizenz

MIT, siehe [LICENSE](LICENSE).

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)
