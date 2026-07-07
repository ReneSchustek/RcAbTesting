# Changelog (DE)

## [1.5.0] - 2026-07-07

> **Deployment:** `bin/console plugin:update RcAbTesting` (neue Migration) + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS geaendert).

### Auswertung

- **Ergebnis-Uebersicht mit Entscheidungs-Kennzahl:** Die Auswertung ist jetzt eine zusammenhaengende Uebersicht — ein Klartext-**Verdikt** oben, darunter die **Scorecard** aller Kennzahlen je Variante und **Tabs** fuer die Detail-Ansichten (Zeitverlauf, Segmente, Funnel folgen). Eine je Experiment waehlbare **Entscheidungs-Kennzahl** treibt das Verdikt; Standard ist **Umsatz pro Besucher**, alternativ die Conversion-Rate. Die uebrigen Kennzahlen werden als Kontext gezeigt, entscheiden aber nicht mit — das schuetzt vor Zufallstreffern, wenn man viele Kennzahlen gleichzeitig auf Signifikanz prueft.
- **Signifikanz je Kennzahl:** Fuer „Umsatz pro Besucher" wird ein Mittelwert-Vergleich (mit Streuung) statt des Proportionen-Tests der Conversion-Rate gefahren. Das Verdikt bezieht sich damit ehrlich auf die gewaehlte Kennzahl — inklusive Konfidenzintervall in Euro und benoetigter Fallzahl. Der durchschnittliche Bestellwert bleibt bewusst reine Anzeige-Kennzahl (keine Entscheidungsgrundlage, da seine Analyseeinheit die Bestellung ist, nicht der Besucher).

## [1.4.0] - 2026-07-06

### Auswertung

- **Umsatz je Variante:** Die Auswertung weist jetzt zusaetzlich zur Conversion-Rate den **Umsatz**, den **durchschnittlichen Bestellwert** (Ø Bestellwert) und den **Umsatz pro Besucher** je Variante aus. Der Umsatz kommt aus dem beim Kauf mitgetrackten Bestellwert. Damit sieht man, ob eine Variante nicht nur oefter, sondern auch **wertvoller** verkauft — die Kennzahl, auf die Entscheider im E-Commerce handeln (eine Variante kann oefter konvertieren, aber bei kleinerem Warenkorb weniger Umsatz bringen).

## [1.3.0] - 2026-07-06


### Neu

- **CMS-Seiten-Test (No-Code):** Neuer Test-Typ „CMS-Seite". Pro Variante waehlt man im Admin eine fertige CMS-Seite (Erlebniswelt) aus einem Dropdown — kein Twig/JSON. Ein Storefront-Subscriber liefert je nach Zuweisung die passende Seite aus (Start-/Kategorie-, Landing- und Produktseiten), waehrend Kategorie/URL unveraendert bleiben. Control = die aktuell live ausgelieferte Seite. Zuordnung, Targeting, Consent, Sticky-Bucketing und das Funnel-/Abbruch-Tracking laufen ueber dieselbe Basis wie die uebrigen Test-Typen — die Auswertung fuellt sich also automatisch. Faellt das Laden der Variantenseite aus, bleibt die Control-Seite stehen (der Test bricht die Seite nie).

### Admin

- **50:50-Standardaufteilung:** Beim Hinzufuegen/Entfernen von Varianten werden die Gewichte automatisch gleichmaessig auf 100 verteilt (zwei Varianten = 50/50). Eine abweichende Aufteilung laesst sich weiterhin frei einstellen.
- **Klartext-Empfehlung:** Die Auswertung zeigt zusaetzlich zur Statistik einen verstaendlichen Handlungssatz je Variante — z. B. „Variante B ist signifikant schlechter — nicht ausrollen", „… signifikant besser — kann ausgerollt werden", „kein messbarer Unterschied" oder „noch nicht genug Daten (x von y noetig)" — mit passender Ampelfarbe. Fuer Entscheider, die keine p-Values lesen.

## [1.2.0] - 2026-07-06

> **Deployment:** git-Commit/Tag + Rollout auf die Instanzen erfolgen beim Release.

### Behoben

- **Admin-Lifecycle & Auswertung funktionieren unter Shopware 6.7:** Starten/Pausieren/Beenden und die statistische Auswertung riefen ihren HTTP-Client über ein `inject: ['httpClient']` auf, das Shopware 6.7 nicht mehr bereitstellt — der Aufruf brach still client-seitig ab, es ging kein Request an den (funktionierenden) Server, sichtbar nur als „Statuswechsel fehlgeschlagen." bzw. „Auswertung konnte nicht geladen werden.". Die Detailseite bezieht den HTTP-Client jetzt aus dem Init-Container (`Shopware.Application.getContainer('init').httpClient`). Per Admin-Smoke-Test gegen einen 6.7-Shop nachgetestet.
- **Targeting wirkt zur Laufzeit:** Ein auf `sales_channel_id` oder `rule_id` eingeschränktes Experiment läuft nun nur noch dort. `VariantAssigner` prüft Sales-Channel und die im Kontext aufgelösten Regel-IDs vor dem Bucketing (vorher wurden beide Felder ignoriert → Experiment lief global).
- **Signifikanzniveau wirkt:** Das je Experiment eingestellte `target_significance` bestimmt jetzt Alpha, den kritischen z-Wert und die Konfidenzintervall-Breite (vorher war alles hart auf 95 % verdrahtet). Die CLI-Auswertung nennt das tatsächliche Niveau.
- **Cross-Device-Konsistenz:** Angezeigte und getrackte Variante stimmen für angemeldete Kunden geräteübergreifend überein (Tracking folgt jetzt wie die Anzeige dem Kunden). Ein neuer `UNIQUE(experiment, customer)` erzwingt genau eine Zuordnung je Kunde und Experiment; beim Login werden redundante Geräte-Zuordnungen zusammengeführt statt dupliziert (vorher: verschiedene Varianten sichtbar/getrackt, Person doppelt in der Stichprobe).
- **Geplanter Start hebt manuelle Pause nicht mehr auf:** Der Scheduler startet nur noch nie gestartete Experimente automatisch; ein bewusst pausiertes Experiment mit vergangenem geplantem Start bleibt pausiert (vorher wurde es bei jedem Lauf reaktiviert).
- **Varianten-Löschung wird gespeichert:** Im Admin entfernte Varianten werden beim Speichern serverseitig gelöscht statt als verwaiste Zeilen zurückzubleiben (vorher kehrte die Variante nach dem Neuladen zurück).
- **Konsistentes Tracking für angemeldete Kunden:** Trifft ein eingeloggter Kunde ohne kanonische Kundenzuordnung auf eine bestehende Gerätezuordnung, wird diese dem Kunden zugeschrieben — die Conversion wird zuverlässig getrackt statt verloren zu gehen.

### Auswertung

- **Statistische Auswertung im Admin:** Die Detailseite zeigt jetzt je Variante Lift, p-Value, Konfidenzintervall und Signifikanz gegen die Control (beim eingestellten Niveau), eine Gewinner-Empfehlung, eine Sample-Ratio-Mismatch-Warnung und die zur Bestätigung benötigte Fallzahl — bisher nur in der CLI verfügbar. Mehr als zwei Varianten werden paarweise gegen die Control ausgewertet.

### Härtung

- **Robustheit & Performance:** Der Track-Endpunkt begrenzt jetzt Body-Größe und JSON-Tiefe; die laufenden Zuordnungen werden pro Request nur einmal geladen (weniger DB-Zugriffe); Varianten-Änderungen leeren den Experiment-Cache sofort; die Cart-Abbruch-Erkennung berücksichtigt nur laufende Experimente.

### Storefront

- **Robusteres Tracking-Script:** Der Fallback ohne Shopware-PluginManager greift jetzt zuverlässig, und die `track`-Funktion wirft auch bei fehlendem `fetch` (sehr alte Browser) nicht mehr — Tracking bleibt ein sicherer Nebenpfad.

### Rechte

- **ACL-Rollen:** Das A/B-Test-Modul registriert nun Rollen (Ansehen/Bearbeiten/Anlegen/Löschen) in der Rechteverwaltung, sodass Nicht-Administratoren gezielt freigeschaltet werden können.

### Scheduling

- **Geplanter Start/Stopp:** Experimente können einen geplanten Start- und Endzeitpunkt bekommen; ein Scheduled-Task startet sie automatisch (nur bei gültiger Konfiguration) bzw. beendet sie. Beendete Experimente lassen sich archivieren.

### Integrität

- **Konfigurations-Schutz:** Varianten-Gewichte können nicht mehr negativ gespeichert werden (DAL-Constraint), und der technische Schlüssel eines Experiments ist jetzt datenbankseitig eindeutig. Der Start prüft zusätzlich, dass genau eine Control-Variante existiert.

### Admin

- **Varianten im Admin verwaltbar:** Varianten lassen sich in der Experiment-Detailseite anlegen, bearbeiten (Schlüssel/Name/Gewicht/Control) und löschen — ein Experiment ist damit vollständig über die Oberfläche konfigurier- und startbar (vorher nur über CLI/DB möglich).
- **Gewinner im Admin wählbar:** Beim Beenden kann eine Gewinner-Variante gesetzt werden; die API prüft die Zugehörigkeit.
- **Aussagekräftige Fehlermeldungen:** Fehlgeschlagene Lifecycle-/Auswertungs-Aufrufe zeigen jetzt die konkrete Server-Meldung (z. B. „Gewichte müssen 100 ergeben") statt eines generischen Texts (HTTP-Client-Bezug siehe „Behoben" oben).
- **Lokalisierte Fehlermeldungen:** Lifecycle-/Validierungsfehler werden über stabile Fehlercodes in der Admin-Sprache angezeigt (de/en) statt fest auf Deutsch.
- **Targeting im Admin pflegbar:** Verkaufskanal und Regel eines Experiments lassen sich jetzt direkt in der Detailseite auswählen (leer = gilt für alle).
- **Varianten-Config im Admin:** Je Variante ist ein optionales JSON-Config-Objekt (für Theme-/Feature-Flag-Varianten) editierbar; ungültiges JSON wird beim Speichern abgewiesen.
- **Gewinner nachträglich änderbar:** Die Gewinner-Variante wird beim Speichern übernommen und ist damit auch nach dem Beenden anpassbar.
- **Rollen-gerechte Aktionen:** Lifecycle-/Speichern-Buttons sind ohne Bearbeiten-Recht deaktiviert (statt in einen Server-Fehler zu laufen).
- **DSGVO-Auskunft (Art. 15):** Neuer Befehl `rc:ab:export --customer=<id>` gibt alle zu einem Kunden gespeicherten A/B-Zuordnungen und -Events als JSON aus.
- **Klarere Auswertung:** Eine signifikant schlechtere Variante wird jetzt als „signifikant schlechter" ausgewiesen (statt nur „signifikant"); die Rate-Konfidenzintervall-Spalte ist eindeutig als „KI der Variantenrate" beschriftet.

### Dokumentation & Kompatibilität

- **Kompatibilitäts-Constraint präzisiert:** Die `composer.json` verlangt jetzt nur noch `shopware/core`/`shopware/storefront` `~6.7.0` statt zusätzlich `~6.8.0` — 6.8 existiert nicht als Stable, die bisherige Angabe bewarb ungetestete Kompatibilität. Bei Erscheinen von 6.8 wird der Constraint gezielt wieder aufgenommen.
- **README vervollständigt:** Voraussetzungen, Installation, vollständige Konfigurations-Tabelle (alle vier Felder), Update-, Entwicklungs- und Lizenz-Abschnitt ergänzt.
- **Irreführende/Roadmap-Kommentare bereinigt:** Doc-Kommentare an das reale Verhalten angeglichen (PageViewedSubscriber, KernelRequestSubscriber-Priorität, DECIMAL-Range) und interne Roadmap-/Phasen-Referenzen aus dem Auslieferungscode entfernt — rein redaktionell, kein Verhaltensunterschied.

### Qualität

- **DB-/Kernel-Integrationstests:** Die kritischen Invarianten werden jetzt gegen eine echte Datenbank bzw. den echten Shopware-Kernel ausgeführt statt nur per String-Vergleich geprüft — Warenkorb-Abbruch-Zeitgrenze inkl. Wiederkehrer, DSGVO-Anonymisierung (deterministischer Hash + Idempotenz) und der EventTracker-Zuordnungsfilter (Kunde- vs. Besucher-Attribution, nur laufende Experimente). Reine Entwicklungs-Tests — für die Installation des Plugins ohne Belang. `.gitattributes` hält Test-/Dev-Dateien aus dem Release-Archiv.
- **Korrekte Umlaute durchgängig:** Kommentare, Docblocks und deutsche Texte (Command-Ausgaben, Admin-Snippets) verwenden jetzt echte Umlaute (ä/ö/ü/ß) statt der bisherigen ASCII-Digraphen (ae/oe/ue). Rein textuell, kein Verhaltensunterschied; für Admin-Nutzer sichtbar in den deutschen Oberflächentexten.
- **Auslieferungs-Hygiene abgeschlossen:** letzte interne Entwicklungs-Referenz aus `services.xml` entfernt. Rein redaktionell.

## [1.1.1] - 2026-07-04 — Auslieferungs-Politur

> **Deployment:** `php bin/console cache:clear`

### Geändert

- Interne Entwicklungs-Referenzen aus den ausgelieferten Dateien entfernt (README, CHANGELOG, `services.xml`-Kommentare, einzelne Quell-/Test-Kommentare, `.gitignore`-Header) — rein redaktionell, kein Verhaltensunterschied.
- Zwei irreführende Kommentare korrigiert: Opt-out-Werte im `DefaultConsentGate` und die Aufbewahrungs-Semantik (leer = Default 90 Tage, `0` = aus) im `DataRetentionTaskHandler`.

## [1.1.0] - 2026-06-28 — DSGVO-Aufbewahrung + Härtung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Neu

- **DSGVO-Aufbewahrung:** Neuer täglicher `DataRetentionTask` und der Befehl `rc:ab:cleanup --older-than-days=N` anonymisieren abgelaufene A/B-Daten — `visitor_id` wird zu einem deterministischen SHA-256-Hash (Distinct-Zählung der Statistik bleibt gültig), `customer_id` wird entfernt. Die Frist ist als Plugin-Konfiguration `dataRetentionDays` (Default 90, `0` schaltet ab) hinterlegt. Ein `anonymized_at`-Marker (Migration) macht den Lauf idempotent.

### Behoben / Verbessert

- **Cart-Abbruch-Zeitfenster:** Die `cart.abandoned`-Erkennung schließt einen Besucher nicht mehr lebenslang aus, sobald er **einmal** abgebrochen hat — die `NOT EXISTS`-Subquery ist nun an den jeweiligen `checkout.started`-Zeitpunkt gebunden. Wiederkehrer werden korrekt gezählt (vorher Under-Counting).
- **Atomarität Cart-Abbruch:** `CartAbandonmentTaskHandler` umschließt Erkennung und Schreiben mit einem MySQL-Named-Lock (`GET_LOCK`), sodass überlappende Läufe keine Doppel-Events erzeugen.
- **Statistik-Performance:** `ExperimentStatsAggregator` zählt konvertierende Besucher per `COUNT(DISTINCT visitor_id)` direkt in der Datenbank, statt alle Distinct-Buckets in PHP zu materialisieren.
- **Admin-UX:** Die Lifecycle-Buttons (Start/Pause/Beenden) im Experiment-Detail sind je nach Status deaktiviert; die API bleibt serverseitig die Wahrheit.
- **Storefront-JS:** Das Tracking ist nun über den Shopware-PluginManager eingebunden (Lifecycle inkl. `destroy()`), mit direktem `init()`-Fallback. Die framework-freie, in Node getestete Kernlogik bleibt unverändert.

### Cross-Plugin-Interaktionen

- Die Verträge zur Zusammenarbeit mit anderen Storefront-Plugins (Confirm-Page, Twig-Variant-Konflikte, Listing-Layout, Buy-Widget) sind in der `README.md` dokumentiert.

### Qualität

- PHPStan L8 0 · CS-Fixer 0 · **PHPUnit 149/149** · JS 7/7 · composer audit 0 · Container-Compile ok · Migration angewendet + SQL validiert.

## [1.0.2] - 2026-06-27 — Stickiness & Datenminimierung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Behoben

- **Cross-Device-Stickiness:** Bei angemeldetem Kunden folgt die Variante dem Kunden statt dem geräte­gebundenen Visitor-Cookie — `assign()` sucht zuerst per (Experiment, Kunde); neue Zuordnungen tragen die `customer_id`. Vorher sah derselbe Kunde auf zwei Geräten evtl. verschiedene Varianten.
- **Keine Post-hoc-Verzerrung:** `EventTracker` schreibt Events nur noch für Zuordnungen zu **laufenden** Experimenten (vorher landeten spätere Bestellungen in längst beendeten Experimenten). Plus `setLimit`, treffender Methodenname.
- **Start-Validierung:** `POST .../start` lehnt Experimente ohne ≥2 Varianten oder mit Gewichtssumme ≠ 100 ab (422) — verhindert stille Unter-Allokation.
- **Datenminimierung:** `OrderPlacedSubscriber` speichert nur noch `order_id` (Bestellnummer entfernt).

### Qualität

- PHPStan L8 0 · CS-Fixer 0 · **PHPUnit 139/139** · JS 6/6 · composer audit 0 · Container-Compile ok.

## [1.0.1] - 2026-06-27 — Stichproben- und Consent-Härtung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Behoben

- **Stichproben-Integrität:** Cookielose Requests (Bots, Opt-out, Pre-Consent) erzeugten pro Aufruf eine neue Zuordnung und blähten die Sample-Größen auf. Jetzt wird nur bei einer aus echtem Cookie stammenden Besucher-ID persistiert; cookielose Besucher werden rein in-memory deterministisch gebucketet. Behebt zugleich die Consent-/DSGVO-Diskrepanz (Opt-out wird nicht mehr persistiert).
- **Status-Wechsel wirken sofort:** Neuer `ExperimentCacheInvalidationSubscriber` auf `rc_ab_experiment.written` — ein gestartetes/beendetes Experiment wirkte zuvor bis zu 5 Minuten (Cache-TTL) verzögert.
- **Keine Doppelzählung:** Das Storefront-JS feuert `page.viewed` nicht mehr selbst (der Server zählt) — vorher zwei Events pro Seitenaufruf.
- **Deinstallation:** `uninstall()` entfernt die `rc_ab_*`-Tabellen (respektiert `keepUserData`) — keine verwaisten Tabellen mehr.
- **TrackingController robuster:** Fail-safe `try/catch` (kein 500 bei DB-Fehler) + Event-Typ-Längenlimit (64).
- **Konfigurierbar:** `config.xml` mit Consent-Cookie (Opt-in) und Cart-Abbruch-Frist.

### Qualität

- PHPStan L8 0 · CS-Fixer 0 · **PHPUnit 136/136** · JS 6/6 · composer audit 0 · Container-Compile ok.

## [1.0.0] - 2026-06-27 — Erste vollständige Version

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` + Admin-/Storefront-Asset-Build.

### Hinzugefügt — Administrations-Modul

- Administration-Modul `rc-ab-testing` unter `sw-marketing` (Icon `regular-rocket`): Experiment-Liste (`sw-entity-listing` + „Neues Experiment" mit Pflichtfeld-Defaults) und Detail-Seite (Basisdaten, Test-Typ-Auswahl, Traffic/Signifikanz, Varianten-Anzeige).
- Lifecycle-Buttons (Starten/Pausieren/Beenden) rufen die Admin-API; Auswertungs-Karte lädt die Stats-Endpunkt-Daten (Zuordnungen/Conversions/Rate je Variante).
- i18n-Snippets de-DE/en-GB (echte Umlaute). Modul-`.js` als ESM verifiziert, Snippets als valides JSON.

### Funktionsumfang

- Vollständig: Entities + Migrationen, Innenring-Services, Funnel-Subscriber, Twig-Integration, Plugin-Bridge, Statistik-Kern (z-Test/CI/Lift/Sample-Size), CLI-Commands, Cart-Abandonment-Scheduler, konfigurierbarer Consent-Gate, Storefront-Tracking, Admin-API + ACL, Admin-Modul.
- **131 PHP-Tests + 6 JS-Tests**, alle Gates grün (PHPStan L8, CS-Fixer, PHPUnit, composer audit).

### Hinweis

- Admin- und Storefront-Assets werden über die Deploy-Pipeline gebaut (Modul-/JS-Quellen sind verifiziert).

## [0.9.3] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` + Storefront-Asset-Build.

### Hinzugefügt — Storefront-Tracking + Cache-Vary

- `Controller\Storefront\TrackingController` — `POST /rc-ab-testing/track`: validiert den Event-Typ (Whitelist inkl. `custom.*`), schreibt über den `EventTracker`, JSON-Antwort `{ok: bool}`. Anonyme Besucher dürfen tracken; kein CSRF-Token (in Shopware 6.5+ entfernt — Schutz über SameSite=Lax). Direktes `JsonResponse` → kernel-frei testbar.
- `Storefront\Subscriber\CacheVarySubscriber` — setzt auf `BeforeSendResponseEvent` gezielt `Cache-Control: private, no-store`, aber **nur** für Seiten, auf denen der Besucher tatsächlich einer Variante zugeordnet wurde (neues Request-Flag im `RequestVariantResolver`). So bleibt der HTTP-Cache für alle anderen Seiten aktiv.
- Storefront-JS (`src/Resources/app/storefront/src/`): framework-freie `window.RcAbTesting.track(eventType, value, meta)`-API, liest den Besucher-Cookie, No-op ohne Cookie, Auto-`page.viewed` bei DOMContentLoaded.
- 5 PHP-Tests + 6 JS-Tests (Node `--test`); PHP gesamt **131/131 grün**, JS **6/6 grün**.

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 131/131 · composer audit: sauber.
- Router-Compile: `frontend.rcabtesting.track` registriert, `CacheVarySubscriber` als `kernel.event_subscriber`.
- Storefront-JS node-getestet; finales Asset-Bundling über die Deploy-Pipeline.

## [0.9.2] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Hinzugefügt — konfigurierbarer Consent-Gate

- `Service\Consent\ConfigurableConsentGate` — bildet einen beliebigen Consent-Manager (z.B. ComanConsentManager) **generisch** ab: ist ein Consent-Cookie konfiguriert (`RcAbTesting.config.consentCookieName` + `…consentGrantedValue`), gilt Opt-in (Cookie nur bei ausdrücklicher Einwilligung); ohne Konfiguration Opt-out-Fallback (PII-freie Zufalls-ID). Das Plugin bleibt frei vom konkreten CMP — der Admin verdrahtet nur Cookie-Name und Granted-Wert.
- Der `ConsentGate`-Alias zeigt jetzt auf dieses Gate (ersetzt das reine Opt-out-Default).
- 3 neue Tests; gesamt **126/126 grün**.

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 126/126 · composer audit: sauber.

## [0.9.1] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting`

### Hinzugefügt — Cart-Abandonment-Scheduler

- `ScheduledTask\CartAbandonmentTask` (Intervall 15 Min) + `CartAbandonmentTaskHandler`: schreibt für jeden erkannten Warenkorb-Abbruch genau ein `cart.abandoned`-Event.
- `Service\CartAbandonmentDetector` — erkennt Abbrüche **rein aus den eigenen Funnel-Events** (`checkout.started` älter als die Frist, ohne späteres `checkout.order_placed`, ohne bereits geschriebenes `cart.abandoned`). Reine Lese-Abfrage; das Schreiben läuft über die DAL. Idempotenz liegt in der Erkennung (kein Doppel-Event).
- Abbruch-Frist über `RcAbTesting.config.cartAbandonmentMinutes` (Default 30 Min).
- 3 neue Tests; gesamt **123/123 grün**.

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 123/123 · composer audit: sauber.
- Container-Compile: Handler mit `messenger.message_handler (handles: CartAbandonmentTask)` registriert.

## [0.9.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Hinzugefügt — Admin-API + ACL

- `Controller\Api\RcAbExperimentApiController` mit vier Endpunkten: `GET .../experiment/{id}/stats` (Sample-Size + distinct Conversions je Variante) sowie `POST .../start`, `.../pause`, `.../end` für den Lifecycle. Statusübergänge sind geprüft (Pause nur aus `running` → sonst 409), fehlende Experimente liefern 404.
- ACL über die automatisch erzeugten Entity-Privilegien (`rc_ab_experiment:read` für Stats, `:update` für Lifecycle), als Route-Defaults gesetzt.
- `ExperimentLookup::byId()` ergänzt; Endpunkte nutzen den bestehenden `ExperimentStatsAggregator`.
- Bewusst ohne container-gebundenen `$this->json()`-Helfer (direktes `JsonResponse`) → Endpunkte ohne vollständigen Kernel unit-testbar.
- 5 neue Tests (200/404/409, Statusübergänge, Stats-Payload); gesamt **120/120 grün**.

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 120/120 · composer audit: sauber.
- Router-Compile: alle vier `api.action.rc-ab-testing.experiment.*`-Routen registriert.

## [0.8.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Hinzugefügt — CLI + Aggregator

- `Service\Stats\ExperimentStatsAggregator` — zählt je Variante die Zuordnungen (Stichprobe) und **distinct** konvertierende Besucher (primäre Metrik via `TermsAggregation` auf `visitorId`); Conversions sicher auf die Zuordnungszahl geklemmt (Rate nie > 100 %).
- `Service\ExperimentLookup` — lädt ein Experiment statusunabhängig per technical_key inkl. Varianten.
- CLI-Commands: `rc:ab:list` (Tabelle aller Experimente, `--status`-Filter), `rc:ab:stats <key>` (Raten, 95%-CI, Lift, p-Value, Signifikanz für zwei Varianten), `rc:ab:end <key> [--winner=]` (Status `done` + Gewinner, Bestätigung außer bei `--no-interaction`), `rc:ab:cleanup` (DSGVO-Anonymisierung).
- 9 neue Tests (Aggregator distinct/Klemmung, CommandTester pro Command); gesamt **115/115 grün**.

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 115/115 · composer audit: sauber.
- CLI-Smoke: `rc:ab:list` und `rc:ab:cleanup` laufen real (Container-Compile + Ausführung).

## [0.7.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting`

### Hinzugefügt — Statistik-Kern

- `Service\Stats\NormalDistribution` — Standardnormalverteilung in reinem PHP: `erf` (Abramowitz-Stegun 7.1.26), `cdf` und Quantil `ppf` (Acklam), validiert gegen die Standardnormaltabelle (`cdf(1.96)=0.975`, `ppf(0.975)=1.96`).
- `Service\Stats\StatisticsCalculator` — Konversionsraten mit 95%-Wald-CI, relativer Lift, gepoolter Zwei-Proportionen-z-Test und zweiseitiger p-Value. Degenerierte Eingaben (leere Stichprobe, keine Streuung, Basisrate 0) liefern null statt Division durch null.
- `Service\Stats\SampleSizeCalculator` — benötigte Fallzahl je Variante über die varianzstabilisierende Arcus-Sinus-Transformation (Cohens h).
- 12 neue Tests (Tabellen-Anker, Lehrbuch-Vergleich, Edge-Cases); gesamt **106/106 grün**.

### Hinweis Korrektheit

- Der gepoolte z-Test für 287/5234 vs 334/5189 ergibt z ≈ 2.056, p ≈ 0.0398 (nachgerechnet gegen die Standardformel).

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 106/106 · composer audit: sauber.

## [0.6.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Hinzugefügt — Plugin-Bridge

- `Bridge\ActiveVariantQuery` (Interface) + `ActiveVariantQueryImpl` — schmale, stabile Schnittstelle, über die fremde Plugins die aktive Variante eines Besuchers abfragen, ohne harte Abhängigkeit auf RcAbTesting-Interna. Public registriert; Zielplugins injizieren sie per `on-invalid="null"` optional.
- `RequestVariantResolver` — aus der Twig-Extension extrahierte, geteilte Request-Memoization. Twig **und** Bridge bucketen jetzt identisch und ohne doppelten DB-Treffer; `kernel.reset` leert das Memo im Worker-Modus.
- 8 neue/umstrukturierte Tests (Resolver-Memoization/Reset/Null-Pfade, Bridge-Abfrage); gesamt **94/94 grün**.

### Geändert

- `RcAbTwigExtension` ist nun eine dünne Fassade auf `RequestVariantResolver` (Memoization-Logik zentralisiert, DRY).

### Cross-Plugin

- Die Bridge erlaubt Zielplugins (z.B. RcCheckout, RcCheckoutEnhancer), ihren Subscriber per Guard auf die aktive Variante zu konditionieren — mit der Prämisse: die Zielplugins sind **keine** konkurrierenden Markup-Renderer, sondern additive UX-Erweiterungen; ein A/B-Guard bedeutet dort „Feature AN/AUS pro Variante" (konfigurierbarer Experiment-Key, kein hartkodierter).

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 94/94 · composer audit: sauber.
- Container-Compile: neue public Bridge + Alias kompilieren fehlerfrei.

## [0.5.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (neue Twig-Funktionen)

### Hinzugefügt — Twig-Integration

- `RcAbTwigExtension` mit Twig-Funktionen `ab_variant(experimentKey)` (technical_key oder null) und `ab_variant_config(experimentKey, configKey=null)` (Variant-Config als Ganzes oder Einzelfeld) sowie Twig-Test `is in_experiment`.
- Lazy Bucketing: die Zuordnung wird erst beim ersten `ab_variant()`-Aufruf ausgelöst und pro Request je Experiment memoized; `reset()` (Tag `kernel.reset`) leert das Memo im Worker-Modus, damit keine Besucher-Zuordnung zwischen Requests durchsickert.
- 6 neue Tests (Variant-Mapping, Memoization über Call-Count, Reset, Null-Pfade ohne Request/SalesChannelContext, Config-Feld-Zugriff); gesamt **89/89 grün**.

### Qualität

- PHPStan L8: 0 · CS-Fixer: 0 · PHPUnit: 89/89 · composer audit: sauber.
- Container-Compile: `RcAbTwigExtension` löst mit `twig.extension` + `kernel.reset` auf.

## [0.4.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (neue Subscriber)

### Hinzugefügt — Event-Subscriber für Funnel-Tracking

- Zwei-Phasen-`VisitorIdResolver`: `resolveForRequest()` (Kernel-Request) legt die Besucher-ID ab, `writeCookieIfNeeded()` (Kernel-Response) setzt den Cookie nur bei neuer ID und vorliegender Einwilligung.
- `KernelRequestSubscriber` / `KernelResponseSubscriber` — Besucher-Cookie-Lebenszyklus, Sub-Requests und `/admin`//`/api` ausgenommen.
- `RequestEventRecorder` — fail-safe Brücke: holt die Besucher-ID aus dem Request und kapselt `try/catch`, damit ein Tracking-Fehler den Storefront-Request nie abreißt.
- Acht Funnel-Subscriber: `page.viewed` (nur GET/200/HTML), `product.added_to_cart` / `product.removed_from_cart` (nur Produkt-Posten), `checkout.started` / `checkout.confirm_viewed` (Warenkorbwert + Cart-Token), `checkout.order_placed` (Bestellwert), `customer.registered`, `customer.logged_in` (inkl. geräteübergreifender Visitor→Kunde-Verknüpfung).
- 14 neue Tests (fail-safe Recorder, Lifecycle-Skips, Funnel-Mapping, Customer-Upgrade); gesamt **83/83 grün**.

### Qualität

- PHPStan Level 8: 0 · PHP CS Fixer: 0 · PHPUnit: 83/83 · composer audit: sauber.
- Container-Compile erfolgreich (`cache:clear`), Subscriber lösen mit `kernel.event_subscriber`-Tag auf.

## [0.3.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (neue Services)

### Hinzugefügt — Innenring-Services

- `VisitorBucketer` — reiner, seiteneffektfreier Bucketing-Algorithmus (SHA-256, deterministisch). Getrennt von I/O, damit Determinismus und Gewichts-Verteilung ohne Datenbank testbar sind.
- `ExperimentRegistry` — laufende Experimente im getaggten Cache (`cache.object`, TTL 5 Min, Tag `rc_ab_running_experiments`); spart pro Storefront-Request einen Tabellen-Scan.
- `VisitorIdResolver` — liest/erzeugt den PII-freien Besucher-Cookie `rc_ab_visitor_id` (UUIDv4, `SameSite=Lax`, `HttpOnly=false`, 1 Jahr), Audit-Log bei korruptem Wert, kein Cookie ohne Einwilligung.
- `VariantAssigner` — Sticky-Zuordnung Besucher → Variante, Race-Condition-sicher (UNIQUE-Constraint + `UniqueConstraintViolationException`-Fallback), `upgradeAssignmentsToCustomer()` für geräteübergreifende Stabilität.
- `EventTracker` — synchrones Funnel-Tracking mit Event-Typ-Whitelist (`AbEventType`) und JSON-Meta-Sanitisierung; ein Event je offener Zuordnung (Batch-Write).
- `Consent\ConsentGate` (Interface) + `DefaultConsentGate` (Opt-out-Modell) — entkoppelt das Plugin vom konkreten Consent-Manager.
- 24 neue Unit-Tests (Bucketing-Determinismus, 10.000er-Verteilung ±3 %, Traffic-Allocation, Whitelist, Meta-Sanitisierung, Cookie-/Consent-Pfade, Cache-Roundtrip, Zuordnungs-Orchestrierung).

### Designentscheidungen

- **`Shopware\Core\Framework\Uuid\Uuid` statt `Symfony\Component\Uid`** für die Besucher-ID — vermeidet ein neues Composer-Paket (Paket-Disziplin), Shopware-idiomatisch, weiterhin valider UUIDv4.
- **`VariantAssigner::assign()` nimmt `SalesChannelContext`** (nicht `Context`) — `rc_ab_assignment` verlangt `sales_channel_id` und `language_id` (beide Required); der SalesChannelContext liefert beides und liegt im Storefront-Subscriber ohnehin vor.
- **Consent als Opt-out-Default** — der generische Consent-Gate für konkrete CMPs folgt in 0.9.2.

### Qualität

- PHPStan Level 8: 0 Errors
- PHP CS Fixer (PSR-12): 0 Verstöße (27 Dateien)
- PHPUnit: 69/69 grün, 0 Deprecations
- Hinweis: `composer audit` meldete twig/twig-CVEs aus dem Shopware-Vendor-Baum (shop-weit, nicht Plugin-Code).

## [0.2.0] - 2026-05-19

> **Deployment:** `php bin/console plugin:update RcAbTesting` (neue Migrationen)

### Hinzugefügt

- Vier Custom Entities mit Definition/Entity/Collection unter `src/Core/Content/`:
  - `rc_ab_experiment` — Test-Definition (Lifecycle, Metriken, optionales Targeting auf Sales-Channel/Rule).
  - `rc_ab_variant` — Varianten pro Experiment, UNIQUE auf (experiment, technical_key).
  - `rc_ab_assignment` — Sticky-Bucketing Visitor → Variante, UNIQUE auf (experiment, visitor).
  - `rc_ab_event` — Funnel-Events mit Index (experiment, event_type, occurred_at).
- Vier Migrationen `Migration1747569600..03` — forward-only, idempotent (CREATE TABLE IF NOT EXISTS).
- Konstanten-Klassen `AbExperimentStatus` (draft/running/paused/done/archived) und `AbExperimentTestType` (twig/theme/feature_flag).
- `services.xml`: vier `shopware.entity.definition`-Einträge, Repositories über DI verfügbar.
- Tests: Schema-Pinning pro Entity (Tabellenname, Entity-/Collection-Klasse, Feldliste) + Migration-Smoke (Timestamp, CREATE-Statement, no-op destructive).

### Hinweis Schema-Detail

- `winner_variant_id` in `rc_ab_experiment` ist BINARY(16) ohne FK-Constraint — vermeidet Henne-Ei beim ersten Migration-Lauf; Konsistenz garantiert der Service-Layer.
- `target_significance` als DECIMAL(4,3) im Schema, DAL-seitig als float.

### Qualität

- PHP CS Fixer: 0 Verstöße (19 Dateien)
- PHPStan Level 8: 0 Errors
- PHPUnit: 26/26 grün, 38 Assertions
- `plugin:install --activate RcAbTesting`: erfolgreich, 4 Tabellen angelegt
- `plugin:update RcAbTesting`: idempotent (no-op)
- `dal:refresh:index`: läuft ohne Exception
- `rc_ab_experiment.repository`: über DI auflösbar

## [0.1.0] - 2026-05-18

### Hinzugefügt

- Plugin-Skeleton mit Namespace `Ruhrcoder\RcAbTesting\`, **finaler** Plugin-Klasse, leerer `services.xml`.
- Toolchain: `composer.json` mit PHPUnit/PHPStan/CS-Fixer + `composer quality`-Skript.
- Pflicht-Files: `SECURITY.md`, `.editorconfig`, `src/Resources/config/plugin.png`.
- Smoke-Test `tests/Unit/RcAbTestingTest.php` (Plugin extends Plugin + final-Konvention).
- README + CHANGELOG initialisiert.

### Qualität

- PHP CS Fixer: 0 Verstöße
- PHPStan Level 8: 0 Errors
- PHPUnit: 2/2 Tests grün (2 Assertions)
- composer audit: 0 Advisories
- `plugin:refresh`: Plugin in Registry sichtbar (v0.1.0)
