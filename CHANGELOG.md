# Changelog (DE)

## [1.19.0] - 2026-10-20: Restbefunde der Regelprüfung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`. Verwaltung und
> Storefront-Skript sind neu gebaut.

### Behoben

- Der Warenkorbwert beim Checkout-Start zählt jetzt in der Standardwährung wie Bestätigung und Bestellung. In einem
  Shop mit Fremdwährungen summierte die Umsatzauswertung sonst verschiedene Währungen.
- Der Tracking-Endpunkt antwortet bei zu großem Inhalt mit 413 und bei unzulässigem Ereignistyp mit 400 statt mit 200.
- `rc:ab:export` verlangt eine gültige Kunden-ID, `rc:ab:list --status` einen bekannten Status; die Routen der
  Verwaltungs-API nehmen nur Kennungen aus 32 Hex-Zeichen an.
- Der Besucher-Cookie wird nur noch für Routen der Storefront gesetzt, mit demselben Filter wie beim Vergeben der
  Kennung.

### Intern

- Die Verwaltung spricht die eigenen Routen über einen `ApiService` an; das Währungszeichen geht als Parameter in die
  Formatierung statt über einen Modulzustand. Rechenschritte der Detailseite liegen in einem getesteten Helfer.
- Lange Methoden geteilt (Zuordnung, Ereignis, Auswertung), toter Zweig ohne dauerhafte Kennung entfernt, feste Werte
  benannt, Spalten- und Indexprüfung der Migrationen sowie die Datenbanksperre der geplanten Aufgaben je an einer
  Stelle, Uhr der Zeitplanung als Abhängigkeit, Schnittstelle für den Löschdienst, sprechende Namen in den
  Auswertungsabfragen.
- Zwei PHPStan-Ausnahmen entfallen, weil ihre Ursache behoben ist. Protokolle übergeben die Ausnahme selbst.
- Kommentare und README an den Code angeglichen, darunter der Löschweg nach Art. 17 und die Auskunft nach Art. 15.
  Neue Tests für Cookie-Banner, Checkout-Start in Fremdwährung, Store-API-Routen und die Helfer der Verwaltung.

## [1.18.0] - 2026-10-15: Segmentauswertung schnell, Geschwindigkeit gemessen

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`.

### Behoben

- Die Auswertung nach Segmenten (Gerät, Verkaufskanal) verknüpfte Ereignisse und Zuordnungen über eine Bedingung mit
  „oder", für die keine Datenbank einen Index nutzen kann. Bei 100.000 Besuchern dauerte sie 79 Sekunden und lief in
  der Verwaltung in eine Zeitüberschreitung; jetzt 0,16 Sekunden, bei 10.000 Besuchern 0,03 statt 0,85 Sekunden. Die
  Zahlen bleiben dieselben.

### Neu

- Messprojekt unter `benchmarks/` (nicht im Paket): Seitenzeiten mit und ohne Experiment, Auswertung und Wartung mit
  10.000 und 100.000 Besuchern, Ablage je Lauf in `benchmarks/results.csv`. Ein Wächter im Testlauf hält jeden Schritt
  bei 20.000 Besuchern unter drei Sekunden, Wartungsaufgaben unter 30 Sekunden.

## [1.17.1] - 2026-10-15: Ausfall der Experimentliste sichtbar

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`.

### Behoben

- Lassen sich die laufenden Experimente nicht lesen, steht das jetzt als Warnung im Protokoll. Die Seite wird wie bisher
  nicht zwischengespeichert und läuft weiter.

## [1.17.0] - 2026-10-13: Datenschutz und Statistik berichtigt

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`.
> Keine Migration. Verwaltung und Storefront-Skript sind neu gebaut.

### Datenschutz

- **Löschung nach Art. 17 DSGVO greift wieder.** Wird ein Kunde gelöscht, entfernt das Plugin seine
  Zuordnungen und Ereignisse jetzt vor dem Löschen. Vorher lief es danach, als die Datenbank die
  Kundenkennung schon geleert hatte, und fand nichts mehr.
- **Ein Widerruf der Einwilligung wirkt.** Ohne gültige Einwilligung gilt auch ein vorhandenes
  Besucher-Cookie nicht mehr; die Antwort löscht es, und Ereignisse werden keinem Kunden zugeordnet.
- **Anonymisierung nicht mehr zu früh.** Ereignisse richten sich nach der Zuordnung ihres eigenen
  Experiments, nicht nach irgendeiner abgelaufenen Zuordnung desselben Besuchers.
- **Leere Aufbewahrungsfrist schaltet nichts mehr ab.** Ein geleertes Feld bedeutet 90 Tage; nur eine
  eingetragene 0 schaltet die Anonymisierung ab. Hilfetexte zu Frist und Einwilligung berichtigt.
- **Geteilte Geräte:** Meldet sich ein zweiter Kunde an, bleiben die Zuordnungen des ersten unberührt.
- Die Store-API bekommt keine Besucherkennung mehr; nur Storefront-Routen zählen.

### Statistik

- **Nötige Fallzahl verdoppelt.** Die Schätzung je Variante war um den Faktor 2 zu klein (Formel für
  eine Stichprobe statt für zwei Gruppen).
- **Kunden mit mehreren Geräten zählen einmal** auch in Trichter, Segmenten und Zeitverlauf, wie schon
  in der Gesamtauswertung.
- **Umsatz in der Standardwährung.** Bestellwerte werden vor dem Speichern umgerechnet; die
  Verwaltung zeigt das Zeichen der Standardwährung statt eines festen €.
- **Mehrfachvergleich hält den Gewinner immer zurück**, nicht nur bei gesetzter Mindestzahl.
- Ohne gesetzte Entscheidungskennzahl entscheidet der Umsatz je Besucher, wie die Vorgabe es anzeigt.
- `rc:ab:stats` gibt dasselbe Urteil aus wie die Verwaltung, mit Entscheidungskennzahl und Gründen.

### Verwaltung und Bedienung

- Beenden (Verwaltung und `rc:ab:end`) nur noch aus „läuft“ oder „pausiert“; `rc:ab:end` ohne
  `--winner` löscht einen eingetragenen Gewinner nicht mehr.
- Zum geplanten Ende wird auch ein pausiertes Experiment beendet.
- Die Auswertung nennt die Gründe, wenn ein Gewinner zurückgehalten wird.
- Der aus dem Namen erzeugte technische Schlüssel ist auf 64 Zeichen gekürzt und eindeutig.
- Neue Experimente starten als Erlebniswelt-Test statt als Twig-Test.
- Das Zeitverlaufsdiagramm wird nicht mehr verzerrt.

### Intern

- Ratenbegrenzer mit festem Fenster; Sperren der geplanten Aufgaben je Datenbank statt serverweit.
- `tracking.js` übersteht ein fehlerhaft kodiertes Cookie.
- Integrationstests laufen gegen das Schema der Migrationen.
- Unmögliche Testdaten und schwache Prüfungen berichtigt.

- Kommentare im ganzen Bestand nachgezogen: jeder Typ und jede Testklasse mit Kopf, gewählte Zahlen mit
  Grund, über 30 sachlich falsche Aussagen gegen den Code berichtigt. Ein Wächter hält fest, dass jeder Typ
  unter `src/` einen Kopf hat.

## [1.16.1] - 2026-08-10: Vorbereitet auf die nächste Shopware-Hauptversion

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`.

### Geändert

- **Das Plugin lässt sich künftig auch unter Shopware 6.8 installieren.** Bisher war es auf 6.7
  festgelegt und hätte den Sprung auf die nächste Hauptversion blockiert.
- **Die Oberfläche im Backoffice nutzt die aktuellen Bausteine.** Die Übersetzungsaufrufe und die
  Experimente-Tabelle sind auf die Nachfolger der abgekündigten Varianten umgestellt. Sichtbar
  ändert sich nichts; die Administration protokolliert dafür keine Abkündigungswarnung mehr.

## [1.16.0] - 2026-08-03: Die Auswertung zählt richtig und hält vorschnelle Gewinner zurück

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`.

### Behoben

- **Kunden, die auf zwei Geräten bestellen, wurden doppelt gezählt.** Der Nenner zählte Zuordnungen (nach dem Anmelden eine je Kunde), der Zähler dagegen je Gerät. Aus einem Kunden mit zwei Bestellungen wurden so zwei Conversions gegen eine Zuordnung. Schwerer wog die Wirkung auf die Streuung: Auf zwei Kennungen verteilter Umsatz lässt die Schwankung zu klein erscheinen, und damit den Signifikanzwert. Die Auswertung meldete eine Sicherheit, die es nicht gab.
- **Ein Gewinner wurde auch bei zu kleiner Stichprobe ausgewiesen.** Die eingestellte Mindestzahl wurde nur angezeigt, nie beachtet; ein gemeldetes Ungleichgewicht der Gruppengrößen hielt niemanden auf. Da die Zahlen bei jedem Öffnen der Auswertung neu gerechnet werden, ergab sich bei kleinen Fallzahlen früher oder später ein „Gewinner“. Jetzt wird er zurückgehalten, mit Angabe des Grundes.
- **Teilnehmer wurden gezählt, obwohl sie die Kontrollfassung sahen.** Fehlte einer Variante ihre Zielseite, etwa weil die Erlebniswelt während des Tests entzogen wurde, blieb die Kontrollfassung stehen, die Zuordnung wurde aber trotzdem angelegt. Ergebnis: „kein Effekt“, obwohl nie etwas ausgespielt wurde. Solche Experimente werden jetzt gar nicht erst ausgespielt und der Grund protokolliert.
- **Jeder Klick auf das Warenkorb-Symbol galt als begonnener Checkout**, auch bei leerem Warenkorb. Der Trichter-Schritt „Warenkorb → Checkout“ war dadurch unbrauchbar, und die Abbruch-Erkennung erzeugte aus jedem Leerklick nach 30 Minuten einen falschen Warenkorb-Abbruch.
- **Die Drosselung des Tracking-Endpunkts war wirkungslos.** Der Zähler hing an einer Kennung, die der Aufrufer selbst bestimmt. Wer sie wegließ, bekam bei jedem Aufruf ein frisches Budget. Gezählt wird jetzt je Absender-Adresse.
- **Die Anonymisierung zerriss den Zusammenhang zwischen Zuordnung und Ereignissen.** Beide liefen nach eigener Uhr; bei länger laufenden Experimenten verlor die Segment-Auswertung Conversions, die in der Gesamtansicht weiterzählten. Jetzt entscheidet eine Uhr: die letzte Aktivität des Besuchers.
- **Ein beendetes Experiment ließ sich wieder starten** und sammelte in denselben Datenbestand weiter. Gestartet werden kann jetzt nur noch aus Entwurf oder Pause.

## [1.15.0] - 2026-08-03: Verlässliche Messung und Einwilligung als Voreinstellung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`.
>
> **Verhaltensänderung:** Ohne Einwilligung im Cookie-Banner wird nicht mehr getrackt. Bestehende Installationen tracken nach dem Update erst wieder, wenn Besucher zugestimmt haben.

### Behoben

- **Seiten eines laufenden Experiments konnten im Cache landen und danach an alle ausgeliefert werden.** Der bisherige Schutz setzte den Nicht-Speichern-Vermerk zu spät, denn zu diesem Zeitpunkt hatte der Cache die Seite bereits abgelegt. Ein Besucher der Vergleichsgruppe bekam so über den Reverse-Proxy die Seite der anderen Gruppe. Damit war jede Messung wertlos, ohne dass irgendwo etwas kaputt aussah. Solange ein Experiment läuft, sind die betroffenen Seiten jetzt nicht mehr cachebar.
- **Besucher ohne dauerhafte Kennung wechselten bei jedem Seitenaufruf die Variante.** Betroffen war jeder mit Cookie-Blocker und, bei aktivem Einwilligungsbanner, jeder vor der Zustimmung. Sie sahen abwechselnd die eine und die andere Fassung. Wer keine dauerhafte Kennung hat, nimmt jetzt gar nicht teil und sieht die Kontrollfassung.

### Datenschutz

- **Ohne Einwilligung kein Cookie.** Bisher wurde der Besucher-Cookie gesetzt, solange kein Widerspruch vorlag, und weil das Feld leer ausgeliefert wird, galt das in jeder frischen Installation. Ein A/B-Cookie ist nicht unbedingt erforderlich für den Shop-Betrieb; ohne Einwilligung darf er nicht gesetzt werden.
- **Der Cookie steht jetzt im Cookie-Banner.** Der Besucher kann ihn dort annehmen und wieder abwählen; Shopware entfernt ihn beim Abwählen selbst. Bisher tauchte er im Banner nicht auf, und der geprüfte Widerspruchs-Cookie wurde von nichts gesetzt. Ein Widerspruch, den niemand erklären konnte.
- **Löschweg nach Artikel 17.** Wird ein Kunde gelöscht, gehen seine A/B-Daten mit, auch bei Löschungen über die Schnittstelle. Bisher gab es nur einen Auskunftsweg.
- **Die Anonymisierung ist jetzt gesalzen.** Der bisherige Hash ließ sich aus einem bekannten Cookie-Wert nachrechnen; der Bezug zur Person war also wiederherstellbar. Das Salz stammt aus der Umgebung der Installation, nicht aus der Datenbank.

## [1.14.0] - 2026-07-21: Auswertungs-Konsistenz + Cache-Korrektheit

> **Deployment:** `bin/console cache:clear`. Keine Migration, kein Admin-Build (nur PHP geändert).

### Behoben

- **Funnel zeigte teils „negative Verluste" bzw. Anteile über 100 %.** Käufe über den OffCanvas-Warenkorb oder einen Express-Checkout (ohne eigenes Warenkorb-Seiten-Ereignis) ließen eine spätere Stufe größer erscheinen als die frühere. Die Trichter-Stufen sind jetzt monoton: wer eine spätere Stufe erreicht, zählt auch für die früheren; die Anteile sind auf 100 % begrenzt.
- **Die Segment-Auswertung wich in einem Cross-Device-Fall still von Scorecard und Funnel ab.** Meldet sich ein Besucher an, dessen Konto auf einem anderen Gerät bereits einer Variante zugeordnet ist, werden seine anonymen Vor-Login-Ereignisse jetzt konsistent auf diese Zuordnung übernommen, und alle Auswertungen zeigen dieselben Zahlen.
- **Der HTTP-Cache konnte einem Testteilnehmer die Standard-Seite ausliefern.** Auf Seiten, die ein laufendes Experiment berühren, wird der Cache jetzt zuverlässig deaktiviert, auch für Besucher, die per Traffic-Verteilung nicht teilnehmen. So wird keine für andere gespeicherte Seite an Teilnehmer ausgeliefert.

## [1.13.1] - 2026-07-20

### Datenschutz

- Der Auskunfts-Export (Art. 15 DSGVO, `ExportCustomerDataCommand`) enthält je Zuordnung jetzt auch Verkaufskanal und Sprache, nicht nur das Gerät. Damit ist der Zuordnungskontext im Export vollständig.

## [1.13.0] - 2026-07-20

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS/Snippets geändert). Keine Migration.

### Barrierefreiheit (BFSG)

- **Auswertungs-Tabs** folgen dem ARIA-Tab-Muster (`tablist`/`tab`/`tabpanel`) und sind per Pfeiltasten, Pos1 und Ende bedienbar; der Fokus ist sichtbar.
- **Hilfe-Erklärungen** in den Tabellenköpfen hängen an einem Button statt an einem reinen MouseOver-Icon. Damit sind sie per Tastatur erreichbar und werden vorgelesen.
- **Zeitverlauf** codiert die Varianten zusätzlich zur Farbe über **Strichmuster** und eine Wert-Beschriftung am Linienende; unter dem Diagramm liegt dieselbe Zahlenreihe als Tabelle für Screenreader. Das SVG trägt eine aussagekräftige Beschreibung.
- **Funnel** liefert dieselben Stufenzahlen (Stufe je Variante mit Anteil und Drop-off) zusätzlich als versteckte Tabelle für Screenreader; die Balken tragen einen aussagekräftigen Namen.
- **Kontraste** angehoben: Textfarben erreichen mindestens 4,5:1, Grafiken und Diagrammfarben mindestens 3:1.
- **Nachgeladene Auswertung** wird in einer `aria-live`-Region angekündigt; die Kennzahl-Auswahl und die Inline-Felder der Varianten-Tabelle haben jetzt einen zugänglichen Namen.
- Segment-Tabellen mit `caption` und `scope`; korrigierte Überschriften-Hierarchie.

### Geändert

- **Auswertungs-Detailseite entkoppelt:** Die vier Auswertungs-Tabs (Übersicht, Segmente, Zeitverlauf, Funnel) sind eigene Komponenten; die Ableitungen (Chart-Geometrie, Verdikt, Funnel, Formatierung, Kennzahl-Katalog) liegen als reine Funktionen unter `helper/` und sind mit 56 Tests abgedeckt. Verhalten unverändert.
- **Weniger Datenbank-Abfragen in der Auswertung:** Der `ExperimentStatsAggregator` aggregiert alle Varianten in zwei gruppierten Abfragen statt in vier Abfragen je Variante.
- Dedizierter Log-Channel `rc_ab_testing` für alle Plugin-Services.
- Die Bridge-Implementierung ist nicht mehr öffentlich im Container; öffentlich ist, wie dokumentiert, nur das Interface `ActiveVariantQuery`.

## [1.12.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` erforderlich (neuer Schalter-Snippet). Keine Migration.

### Neu

- **Frontend-Schalter „Versandkostenfrei-Hinweis":** Zweiter registrierter Schalter (`free_shipping_indicator`, Werte Anzeigen/Ausblenden). Damit lässt sich der Versandkostenfrei-Hinweis von RcCheckout je A/B-Variante an- oder ausschalten und seine Wirkung messen. RcCheckout konsumiert den Wert über `FrontendSwitchResolver` (siehe RcCheckout).

## [1.11.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` erforderlich (neue Services/Subscriber). Kein Admin-Build, keine Migration.

### Neu

- **Frontend-Schalter: Anwendungs-Ebene (flexibel).** Der beim Test gesetzte Schalter-Wert wird jetzt zur Laufzeit bereitgestellt, und zwar auf zwei Wegen, damit sowohl eigene als auch Fremd-Plugins bedient werden:
  - **Eigene Plugins/Templates** lesen den aktiven Wert direkt über die neue Twig-Funktion `ab_switch('checkout_layout')` (bzw. den `FrontendSwitchResolver` in PHP) und passen ihr Frontend-Verhalten an.
  - **Fremd-Plugins** (nicht änderbar) über einen konkreten **Adapter** (`FrontendSwitchAdapter`, getaggter Service): ein Dispatcher löst den aktiven Wert zur Render-Zeit auf und ruft den passenden Adapter, ohne das Zielplugin anzufassen. Adapter mit anderem Timing abonnieren selbst ein Event und nutzen den Resolver.
  Damit ist der Schalter-Mechanismus vollständig und erweiterbar; die konkrete Umschaltung wird je Fall im Ziel-/eigenen Plugin ergänzt.

## [1.10.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS geändert). Keine neue Migration.

### Neu

- **Frontend-Schalter (No-Code, Grundlage):** Neuer Test-Typ „Plugin-Verhalten schalten". Pro Variante wird ein registrierter, frontend-wirksamer Schalter auf einen Wert gesetzt (Dropdown statt JSON). Erster Schalter: **Checkout-Darstellung** mit „Eine Seite" vs. „Schrittweise geführt". Ein konsumierendes Plugin liest den Wert über `ab_variant_config('checkout_layout')` und passt nur sein Frontend-Verhalten an; wo Kunden abbrechen, zeigt die Funnel-Auswertung. Die Schalter-Registry ist über getaggte Services erweiterbar (weitere Fälle folgen je Bedarf). *Die eigentliche Checkout-Umschaltung liegt im jeweiligen Checkout-Plugin (Folge-Arbeit dort).*

## [1.9.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS geändert). Keine neue Migration.

### Admin

- **Geführte Anlage (verständliche Bedienung):** Die Detailseite ist für Nicht-Techniker aufgeräumt. Die Standardansicht zeigt nur die verständlichen Felder: **„Was möchtest Du testen?"** mit Klartext-Test-Typen und Erklärung (No-Code „Zwei Seiten vergleichen" zuerst), Hypothese mit Beispiel, Traffic-Anteil mit Hilfe und die Varianten. Technische Felder (technischer Schlüssel, Ziel-Signifikanz, Targeting, Zeitplan, Gewichte, Roh-JSON) sind hinter **„Erweiterte Einstellungen anzeigen"** eingeklappt. Der technische Schlüssel wird bei Bedarf automatisch aus dem Namen erzeugt, die Gewichte automatisch 50:50 verteilt.

## [1.8.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS geändert). Keine neue Migration.

### Auswertung

- **Funnel je Stufe:** Neuer Tab „Funnel" mit dem Kauftrichter je Variante über vier Stufen (Seite angesehen → In den Warenkorb → Checkout gestartet → Kauf abgeschlossen). Je Stufe der Anteil der Besucher (Bezugsgröße: alle Zuordnungen der Variante) als Balken plus der Drop-off (in Prozentpunkten) gegenüber der vorherigen Stufe. So wird sichtbar, WO eine Variante Besucher verliert und auf welcher Stufe ein Vorsprung entsteht. Damit ist die Auswertung (Übersicht, Segmente, Zeitverlauf, Funnel) vollständig.

## [1.7.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS geändert). Keine neue Migration.

### Auswertung

- **Zeitverlauf:** Neuer Tab „Zeitverlauf" mit dem **kumulativen** Verlauf der Entscheidungs-Kennzahl (Umsatz pro Besucher bzw. Conversion-Rate) je Variante über die Zeit als Liniendiagramm. So lässt sich ein verfrühtes Zufallssignal (früh, bei wenigen Besuchern, stark schwankend) von einem stabilen, belastbaren Trend unterscheiden. Achse in der Einheit der gewählten Kennzahl (Euro bzw. Prozent).

## [1.6.0] - 2026-07-07

> **Deployment:** `bin/console plugin:update RcAbTesting` (neue Migration) + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS geändert).

### Auswertung

- **Segment-Auswertung:** Neuer Tab „Segmente" mit derselben Auswertung je **Gerät** (Desktop/Mobil/Tablet) und je **Verkaufskanal**. So wird sichtbar, dass eine Variante je Segment unterschiedlich wirken kann (z. B. auf Desktop signifikant besser, auf Mobil nicht). Je Segment eine kompakte Scorecard mit Ergebnis-Ampel. Die Geräteklasse wird beim ersten Bucketing aus dem User-Agent abgeleitet und an der Zuordnung gespeichert. Sie greift ab Installation dieser Version; ältere Zuordnungen erscheinen im Segment „Unbekannt". Eine Dimension wird nur gezeigt, wenn sie mindestens zwei Ausprägungen hat.
- **Verständliche Tabellen:** Die Spaltenüberschriften der Auswertung (Lift, p-Value, Konfidenzintervall, Signifikanz, benötigte Fallzahl, Zuordnungen, Rate, Umsatz/Besucher) haben jetzt ein Hilfe-Symbol mit MouseOver-Erklärung in Klartext, gedacht für Entscheider, die die Fachbegriffe nicht täglich lesen.

## [1.5.0] - 2026-07-07

> **Deployment:** `bin/console plugin:update RcAbTesting` (neue Migration) + `bin/build-administration.sh` erforderlich (Admin-JS/SCSS geändert).

### Auswertung

- **Ergebnis-Übersicht mit Entscheidungs-Kennzahl:** Die Auswertung ist jetzt eine zusammenhängende Übersicht: ein Klartext-**Verdikt** oben, darunter die **Scorecard** aller Kennzahlen je Variante und **Tabs** für die Detail-Ansichten (Zeitverlauf, Segmente, Funnel folgen). Eine je Experiment wählbare **Entscheidungs-Kennzahl** treibt das Verdikt; Standard ist **Umsatz pro Besucher**, alternativ die Conversion-Rate. Die übrigen Kennzahlen werden als Kontext gezeigt, entscheiden aber nicht mit. Das schützt vor Zufallstreffern, wenn man viele Kennzahlen gleichzeitig auf Signifikanz prüft.
- **Signifikanz je Kennzahl:** Für „Umsatz pro Besucher" wird ein Mittelwert-Vergleich (mit Streuung) statt des Proportionen-Tests der Conversion-Rate gefahren. Das Verdikt bezieht sich damit ehrlich auf die gewählte Kennzahl, inklusive Konfidenzintervall in Euro und benötigter Fallzahl. Der durchschnittliche Bestellwert bleibt bewusst reine Anzeige-Kennzahl (keine Entscheidungsgrundlage, da seine Analyseeinheit die Bestellung ist, nicht der Besucher).

## [1.4.0] - 2026-07-06

### Auswertung

- **Umsatz je Variante:** Die Auswertung weist jetzt zusätzlich zur Conversion-Rate den **Umsatz**, den **durchschnittlichen Bestellwert** (Ø Bestellwert) und den **Umsatz pro Besucher** je Variante aus. Der Umsatz kommt aus dem beim Kauf mitgetrackten Bestellwert. Damit sieht man, ob eine Variante nicht nur öfter, sondern auch **wertvoller** verkauft. Das ist die Kennzahl, auf die Entscheider im E-Commerce handeln (eine Variante kann öfter konvertieren, aber bei kleinerem Warenkorb weniger Umsatz bringen).

## [1.3.0] - 2026-07-06

> No-Code-Ausbau für Nicht-Techniker, Phase 1. Deployment/Rollout beim Release.

### Neu

- **CMS-Seiten-Test (No-Code):** Neuer Test-Typ „CMS-Seite". Pro Variante wählt man im Admin eine fertige CMS-Seite (Erlebniswelt) aus einem Dropdown, ganz ohne Twig/JSON. Ein Storefront-Subscriber liefert je nach Zuweisung die passende Seite aus (Start-/Kategorie-, Landing- und Produktseiten), während Kategorie/URL unverändert bleiben. Control = die aktuell live ausgelieferte Seite. Zuordnung, Targeting, Consent, Sticky-Bucketing und das Funnel-/Abbruch-Tracking laufen über dieselbe Basis wie die übrigen Test-Typen, die Auswertung füllt sich also automatisch. Fällt das Laden der Variantenseite aus, bleibt die Control-Seite stehen (der Test bricht die Seite nie).

### Admin

- **50:50-Standardaufteilung:** Beim Hinzufügen/Entfernen von Varianten werden die Gewichte automatisch gleichmäßig auf 100 verteilt (zwei Varianten = 50/50). Eine abweichende Aufteilung lässt sich weiterhin frei einstellen.
- **Klartext-Empfehlung:** Die Auswertung zeigt zusätzlich zur Statistik einen verständlichen Handlungssatz je Variante, z. B. „Variante B ist signifikant schlechter — nicht ausrollen", „… signifikant besser — kann ausgerollt werden", „kein messbarer Unterschied" oder „noch nicht genug Daten (x von y nötig)", jeweils mit passender Ampelfarbe. Für Entscheider, die keine p-Values lesen.

## [1.2.0] - 2026-07-06

> **Deployment:** `bin/console plugin:update RcAbTesting` (neue Migrationen: AddCustomerUnique, AddExperimentKeyUnique, AddScheduling) + `bin/console cache:clear` + `bin/build-administration.sh` erforderlich (neues Admin-JS).

### Behoben

- **Admin-Lifecycle & Auswertung funktionieren unter Shopware 6.7:** Starten/Pausieren/Beenden und die statistische Auswertung riefen ihren HTTP-Client über ein `inject: ['httpClient']` auf, das Shopware 6.7 nicht mehr bereitstellt. Der Aufruf brach still client-seitig ab, es ging kein Request an den (funktionierenden) Server, sichtbar nur als „Statuswechsel fehlgeschlagen." bzw. „Auswertung konnte nicht geladen werden.". Die Detailseite bezieht den HTTP-Client jetzt aus dem Init-Container (`Shopware.Application.getContainer('init').httpClient`). Per Admin-Smoke-Test gegen einen 6.7-Shop nachgetestet.
- **Targeting wirkt zur Laufzeit:** Ein auf `sales_channel_id` oder `rule_id` eingeschränktes Experiment läuft nun nur noch dort. `VariantAssigner` prüft Sales-Channel und die im Kontext aufgelösten Regel-IDs vor dem Bucketing (vorher wurden beide Felder ignoriert → Experiment lief global).
- **Signifikanzniveau wirkt:** Das je Experiment eingestellte `target_significance` bestimmt jetzt Alpha, den kritischen z-Wert und die Konfidenzintervall-Breite (vorher war alles hart auf 95 % verdrahtet). Die CLI-Auswertung nennt das tatsächliche Niveau.
- **Cross-Device-Konsistenz:** Angezeigte und getrackte Variante stimmen für angemeldete Kunden geräteübergreifend überein (Tracking folgt jetzt wie die Anzeige dem Kunden). Ein neuer `UNIQUE(experiment, customer)` erzwingt genau eine Zuordnung je Kunde und Experiment; beim Login werden redundante Geräte-Zuordnungen zusammengeführt statt dupliziert (vorher: verschiedene Varianten sichtbar/getrackt, Person doppelt in der Stichprobe).
- **Geplanter Start hebt manuelle Pause nicht mehr auf:** Der Scheduler startet nur noch nie gestartete Experimente automatisch; ein bewusst pausiertes Experiment mit vergangenem geplantem Start bleibt pausiert (vorher wurde es bei jedem Lauf reaktiviert).
- **Varianten-Löschung wird gespeichert:** Im Admin entfernte Varianten werden beim Speichern serverseitig gelöscht statt als verwaiste Zeilen zurückzubleiben (vorher kehrte die Variante nach dem Neuladen zurück).
- **Konsistentes Tracking für angemeldete Kunden:** Trifft ein eingeloggter Kunde ohne kanonische Kundenzuordnung auf eine bestehende Gerätezuordnung, wird diese dem Kunden zugeschrieben, und die Conversion wird zuverlässig getrackt statt verloren zu gehen.

### Auswertung

- **Statistische Auswertung im Admin:** Die Detailseite zeigt jetzt je Variante Lift, p-Value, Konfidenzintervall und Signifikanz gegen die Control (beim eingestellten Niveau), eine Gewinner-Empfehlung, eine Sample-Ratio-Mismatch-Warnung und die zur Bestätigung benötigte Fallzahl, die es bisher nur in der CLI gab. Mehr als zwei Varianten werden paarweise gegen die Control ausgewertet.

### Härtung

- **Robustheit & Performance:** Der Track-Endpunkt begrenzt jetzt Body-Größe und JSON-Tiefe; die laufenden Zuordnungen werden pro Request nur einmal geladen (weniger DB-Zugriffe); Varianten-Änderungen leeren den Experiment-Cache sofort; die Cart-Abbruch-Erkennung berücksichtigt nur laufende Experimente.

### Storefront

- **Robusteres Tracking-Script:** Der Fallback ohne Shopware-PluginManager greift jetzt zuverlässig, und die `track`-Funktion wirft auch bei fehlendem `fetch` (sehr alte Browser) nicht mehr. Tracking bleibt ein sicherer Nebenpfad.

### Rechte

- **ACL-Rollen:** Das A/B-Test-Modul registriert nun Rollen (Ansehen/Bearbeiten/Anlegen/Löschen) in der Rechteverwaltung, sodass Nicht-Administratoren gezielt freigeschaltet werden können.

### Scheduling

- **Geplanter Start/Stopp:** Experimente können einen geplanten Start- und Endzeitpunkt bekommen; ein Scheduled-Task startet sie automatisch (nur bei gültiger Konfiguration) bzw. beendet sie. Beendete Experimente lassen sich archivieren.

### Integrität

- **Konfigurations-Schutz:** Varianten-Gewichte können nicht mehr negativ gespeichert werden (DAL-Constraint), und der technische Schlüssel eines Experiments ist jetzt datenbankseitig eindeutig. Der Start prüft zusätzlich, dass genau eine Control-Variante existiert.

### Admin

- **Varianten im Admin verwaltbar:** Varianten lassen sich in der Experiment-Detailseite anlegen, bearbeiten (Schlüssel/Name/Gewicht/Control) und löschen. Ein Experiment ist damit vollständig über die Oberfläche konfigurier- und startbar (vorher nur über CLI/DB möglich).
- **Gewinner im Admin wählbar:** Beim Beenden kann eine Gewinner-Variante gesetzt werden; die API prüft die Zugehörigkeit.
- **Aussagekräftige Fehlermeldungen:** Fehlgeschlagene Lifecycle-/Auswertungs-Aufrufe zeigen jetzt die konkrete Server-Meldung (z. B. „Gewichte müssen 100 ergeben") statt eines generischen Texts (HTTP-Client-Bezug siehe „Behoben" oben).
- **Lokalisierte Fehlermeldungen:** Lifecycle-/Validierungsfehler werden über stabile Fehlercodes in der Admin-Sprache angezeigt (de/en) statt fest auf Deutsch.
- **Targeting im Admin pflegbar:** Verkaufskanal und Regel eines Experiments lassen sich jetzt direkt in der Detailseite auswählen (leer = gilt für alle).
- **Varianten-Config im Admin:** Je Variante ist ein optionales JSON-Config-Objekt (für Theme-/Feature-Flag-Varianten) editierbar; ungültiges JSON wird beim Speichern abgewiesen.
- **Gewinner nachträglich änderbar:** Die Gewinner-Variante wird beim Speichern übernommen und ist damit auch nach dem Beenden anpassbar.
- **Rollen-gerechte Aktionen:** Lifecycle-/Speichern-Buttons sind ohne Bearbeiten-Recht deaktiviert (statt in einen Server-Fehler zu laufen).
- **DSGVO-Auskunft (Art. 15):** Neuer Befehl `rc:ab:export --customer=<id>` gibt alle zu einem Kunden gespeicherten A/B-Zuordnungen und -Events als JSON aus.
- **Klarere Auswertung:** Eine signifikant schlechtere Variante wird jetzt als „signifikant schlechter" ausgewiesen (statt nur „signifikant"); die Spalte mit dem Konfidenzintervall ist eindeutig beschriftet.

### Dokumentation & Kompatibilität

- **Kompatibilitäts-Constraint präzisiert:** Die `composer.json` verlangt jetzt nur noch `shopware/core`/`shopware/storefront` `~6.7.0` statt zusätzlich `~6.8.0`, denn 6.8 existiert nicht als Stable, die bisherige Angabe bewarb ungetestete Kompatibilität. Bei Erscheinen von 6.8 wird der Constraint gezielt wieder aufgenommen.
- **README vervollständigt:** Voraussetzungen, Installation, vollständige Konfigurations-Tabelle (alle vier Felder), Update-, Entwicklungs- und Lizenz-Abschnitt ergänzt.
- **Irreführende/Roadmap-Kommentare bereinigt:** Doc-Kommentare an das reale Verhalten angeglichen (PageViewedSubscriber, KernelRequestSubscriber-Priorität, DECIMAL-Range) und interne Roadmap-/Phasen-Referenzen aus dem Auslieferungscode entfernt. Rein redaktionell, kein Verhaltensunterschied.

### Qualität

- **DB-/Kernel-Integrationstests:** Die kritischen Invarianten werden jetzt gegen eine echte Datenbank bzw. den echten Shopware-Kernel ausgeführt statt nur per String-Vergleich geprüft: Warenkorb-Abbruch-Zeitgrenze inkl. Wiederkehrer, DSGVO-Anonymisierung (deterministischer Hash + Idempotenz) und der EventTracker-Zuordnungsfilter (Kunde- vs. Besucher-Attribution, nur laufende Experimente). Reine Entwicklungs-Tests, für die Installation des Plugins ohne Belang. `.gitattributes` hält Test-/Dev-Dateien aus dem Release-Archiv.
- **Korrekte Umlaute durchgängig:** Kommentare, Docblocks und deutsche Texte (Command-Ausgaben, Admin-Snippets) verwenden jetzt echte Umlaute (ä/ö/ü/ß) statt der bisherigen ASCII-Digraphen (ae/oe/ue). Rein textuell, kein Verhaltensunterschied; für Admin-Nutzer sichtbar in den deutschen Oberflächentexten.
- **Auslieferungs-Hygiene abgeschlossen:** letzte interne Entwicklungs-Referenz aus `services.xml` entfernt. Rein redaktionell.

## [1.1.1] - 2026-07-04: Auslieferungs-Politur

> **Deployment:** `php bin/console cache:clear`

### Geändert

- Interne Entwicklungs-Referenzen aus den ausgelieferten Dateien entfernt (README, CHANGELOG, `services.xml`-Kommentare, einzelne Quell-/Test-Kommentare, `.gitignore`-Header). Rein redaktionell, kein Verhaltensunterschied.
- Zwei irreführende Kommentare korrigiert: Opt-out-Werte im `DefaultConsentGate` und die Aufbewahrungs-Semantik (leer = Default 90 Tage, `0` = aus) im `DataRetentionTaskHandler`.

## [1.1.0] - 2026-06-28: DSGVO-Aufbewahrung + Härtung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Neu

- **DSGVO-Aufbewahrung:** Neuer täglicher `DataRetentionTask` und der Befehl `rc:ab:cleanup --older-than-days=N` anonymisieren abgelaufene A/B-Daten: `visitor_id` wird zu einem deterministischen SHA-256-Hash (Distinct-Zählung der Statistik bleibt gültig), `customer_id` wird entfernt. Die Frist ist als Plugin-Konfiguration `dataRetentionDays` (Default 90, `0` schaltet ab) hinterlegt. Ein `anonymized_at`-Marker (Migration) macht den Lauf idempotent.

### Behoben / Verbessert

- **Cart-Abbruch-Zeitfenster:** Die `cart.abandoned`-Erkennung schließt einen Besucher nicht mehr lebenslang aus, sobald er **einmal** abgebrochen hat; die `NOT EXISTS`-Subquery ist nun an den jeweiligen `checkout.started`-Zeitpunkt gebunden. Wiederkehrer werden korrekt gezählt (vorher Under-Counting).
- **Atomarität Cart-Abbruch:** `CartAbandonmentTaskHandler` umschließt Erkennung und Schreiben mit einem MySQL-Named-Lock (`GET_LOCK`), sodass überlappende Läufe keine Doppel-Events erzeugen.
- **Statistik-Performance:** `ExperimentStatsAggregator` zählt konvertierende Besucher per `COUNT(DISTINCT visitor_id)` direkt in der Datenbank, statt alle Distinct-Buckets in PHP zu materialisieren.
- **Admin-UX:** Die Lifecycle-Buttons (Start/Pause/Beenden) im Experiment-Detail sind je nach Status deaktiviert; die API bleibt serverseitig die Wahrheit.
- **Storefront-JS:** Das Tracking ist nun über den Shopware-PluginManager eingebunden (Lifecycle inkl. `destroy()`), mit direktem `init()`-Fallback. Die framework-freie, in Node getestete Kernlogik bleibt unverändert.

### Cross-Plugin-Interaktionen

- Die Verträge zur Zusammenarbeit mit anderen Storefront-Plugins (Confirm-Page, Twig-Variant-Konflikte, Listing-Layout, Buy-Widget) sind in der `README.md` dokumentiert.

### Qualität

## [1.0.2] - 2026-06-27: Stickiness & Datenminimierung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Behoben

- **Cross-Device-Stickiness:** Bei angemeldetem Kunden folgt die Variante dem Kunden statt dem geräte­gebundenen Visitor-Cookie, `assign()` sucht zuerst per (Experiment, Kunde); neue Zuordnungen tragen die `customer_id`. Vorher sah derselbe Kunde auf zwei Geräten evtl. verschiedene Varianten.
- **Keine Post-hoc-Verzerrung:** `EventTracker` schreibt Events nur noch für Zuordnungen zu **laufenden** Experimenten (vorher landeten spätere Bestellungen in längst beendeten Experimenten). Plus `setLimit`, treffender Methodenname.
- **Start-Validierung:** `POST .../start` lehnt Experimente ohne ≥2 Varianten oder mit Gewichtssumme ≠ 100 ab (422) und verhindert so stille Unter-Allokation.
- **Datenminimierung:** `OrderPlacedSubscriber` speichert nur noch `order_id` (Bestellnummer entfernt).

### Qualität

## [1.0.1] - 2026-06-27: Stichproben- und Consent-Härtung

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Behoben

- **Stichproben-Integrität:** Cookielose Requests (Bots, Opt-out, Pre-Consent) erzeugten pro Aufruf eine neue Zuordnung und blähten die Sample-Größen auf. Jetzt wird nur bei einer aus echtem Cookie stammenden Besucher-ID persistiert; cookielose Besucher werden rein in-memory deterministisch gebucketet. Behebt zugleich die Consent-/DSGVO-Diskrepanz (Opt-out wird nicht mehr persistiert).
- **Status-Wechsel wirken sofort:** Neuer `ExperimentCacheInvalidationSubscriber` auf `rc_ab_experiment.written`. Ein gestartetes/beendetes Experiment wirkte zuvor bis zu 5 Minuten (Cache-TTL) verzögert.
- **Keine Doppelzählung:** Das Storefront-JS feuert `page.viewed` nicht mehr selbst (der Server zählt); vorher waren es zwei Events pro Seitenaufruf.
- **Deinstallation:** `uninstall()` entfernt die `rc_ab_*`-Tabellen (respektiert `keepUserData`), es bleiben keine verwaisten Tabellen mehr.
- **TrackingController robuster:** Fail-safe `try/catch` (kein 500 bei DB-Fehler) + Event-Typ-Längenlimit (64).
- **Konfigurierbar:** `config.xml` mit Consent-Cookie (Opt-in) und Cart-Abbruch-Frist.

### Qualität

## [1.0.0] - 2026-06-27: Erste vollständige Version

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` + Admin-/Storefront-Asset-Build.

### Hinzugefügt: Administrations-Modul

- Administration-Modul `rc-ab-testing` unter `sw-marketing` (Icon `regular-rocket`): Experiment-Liste (`sw-entity-listing` + „Neues Experiment" mit Pflichtfeld-Defaults) und Detail-Seite (Basisdaten, Test-Typ-Auswahl, Traffic/Signifikanz, Varianten-Anzeige).
- Lifecycle-Buttons (Starten/Pausieren/Beenden) rufen die Admin-API; Auswertungs-Karte lädt die Stats-Endpunkt-Daten (Zuordnungen/Conversions/Rate je Variante).
- i18n-Snippets de-DE/en-GB (echte Umlaute). Modul-`.js` als ESM verifiziert, Snippets als valides JSON.

### Funktionsumfang

- Vollständig: Entities + Migrationen, Innenring-Services, Funnel-Subscriber, Twig-Integration, Plugin-Bridge, Statistik-Kern (z-Test/CI/Lift/Sample-Size), CLI-Commands, Cart-Abandonment-Scheduler, konfigurierbarer Consent-Gate, Storefront-Tracking, Admin-API + ACL, Admin-Modul.

### Hinweis

- Admin- und Storefront-Assets werden über die Deploy-Pipeline gebaut (Modul-/JS-Quellen sind verifiziert).

## [0.9.3] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` + Storefront-Asset-Build.

### Hinzugefügt: Storefront-Tracking + Cache-Vary

- `Controller\Storefront\TrackingController`: `POST /rc-ab-testing/track`: validiert den Event-Typ (Whitelist inkl. `custom.*`), schreibt über den `EventTracker`, JSON-Antwort `{ok: bool}`. Anonyme Besucher dürfen tracken; kein CSRF-Token (in Shopware 6.5+ entfernt, Schutz über SameSite=Lax). Direktes `JsonResponse` → kernel-frei testbar.
- `Storefront\Subscriber\CacheVarySubscriber`: setzt auf `BeforeSendResponseEvent` gezielt `Cache-Control: private, no-store`, aber **nur** für Seiten, auf denen der Besucher tatsächlich einer Variante zugeordnet wurde (neues Request-Flag im `RequestVariantResolver`). So bleibt der HTTP-Cache für alle anderen Seiten aktiv.
- Storefront-JS (`src/Resources/app/storefront/src/`): framework-freie `window.RcAbTesting.track(eventType, value, meta)`-API, liest den Besucher-Cookie, No-op ohne Cookie, Auto-`page.viewed` bei DOMContentLoaded.
- 5 PHP-Tests + 6 JS-Tests (Node `--test`); PHP gesamt **131/131 grün**, JS **6/6 grün**.

### Qualität

- Router-Compile: `frontend.rcabtesting.track` registriert, `CacheVarySubscriber` als `kernel.event_subscriber`.
- Storefront-JS node-getestet; finales Asset-Bundling über die Deploy-Pipeline.

## [0.9.2] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Qualität

## [0.9.1] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting`

### Hinzugefügt: Cart-Abandonment-Scheduler

- `ScheduledTask\CartAbandonmentTask` (Intervall 15 Min) + `CartAbandonmentTaskHandler`: schreibt für jeden erkannten Warenkorb-Abbruch genau ein `cart.abandoned`-Event.
- `Service\CartAbandonmentDetector`: erkennt Abbrüche **rein aus den eigenen Funnel-Events** (`checkout.started` älter als die Frist, ohne späteres `checkout.order_placed`, ohne bereits geschriebenes `cart.abandoned`). Reine Lese-Abfrage; das Schreiben läuft über die DAL. Idempotenz liegt in der Erkennung (kein Doppel-Event).
- Abbruch-Frist über `RcAbTesting.config.cartAbandonmentMinutes` (Default 30 Min).
- 3 neue Tests; gesamt **123/123 grün**.

### Qualität

## [0.9.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Hinzugefügt: Admin-API + ACL

- `Controller\Api\RcAbExperimentApiController` mit vier Endpunkten: `GET .../experiment/{id}/stats` (Sample-Size + distinct Conversions je Variante) sowie `POST .../start`, `.../pause`, `.../end` für den Lifecycle. Statusübergänge sind geprüft (Pause nur aus `running` → sonst 409), fehlende Experimente liefern 404.
- ACL über die automatisch erzeugten Entity-Privilegien (`rc_ab_experiment:read` für Stats, `:update` für Lifecycle), als Route-Defaults gesetzt.
- `ExperimentLookup::byId()` ergänzt; Endpunkte nutzen den bestehenden `ExperimentStatsAggregator`.
- Bewusst ohne container-gebundenen `$this->json()`-Helfer (direktes `JsonResponse`) → Endpunkte ohne vollständigen Kernel unit-testbar.
- 5 neue Tests (200/404/409, Statusübergänge, Stats-Payload); gesamt **120/120 grün**.

### Qualität

- Router-Compile: alle vier `api.action.rc-ab-testing.experiment.*`-Routen registriert.

## [0.8.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Hinzugefügt: CLI + Aggregator

- `Service\Stats\ExperimentStatsAggregator`: zählt je Variante die Zuordnungen (Stichprobe) und **distinct** konvertierende Besucher (primäre Metrik via `TermsAggregation` auf `visitorId`); Conversions sicher auf die Zuordnungszahl geklemmt (Rate nie > 100 %).
- `Service\ExperimentLookup`: lädt ein Experiment statusunabhängig per technical_key inkl. Varianten.
- CLI-Commands: `rc:ab:list` (Tabelle aller Experimente, `--status`-Filter), `rc:ab:stats <key>` (Raten, 95%-CI, Lift, p-Value, Signifikanz für zwei Varianten), `rc:ab:end <key> [--winner=]` (Status `done` + Gewinner, Bestätigung außer bei `--no-interaction`), `rc:ab:cleanup` (DSGVO-Anonymisierung).
- 9 neue Tests (Aggregator distinct/Klemmung, CommandTester pro Command); gesamt **115/115 grün**.

### Qualität

## [0.7.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting`

### Hinzugefügt: Statistik-Kern

- `Service\Stats\NormalDistribution`: Standardnormalverteilung in reinem PHP: `erf` (Abramowitz-Stegun 7.1.26), `cdf` und Quantil `ppf` (Acklam), validiert gegen die Standardnormaltabelle (`cdf(1.96)=0.975`, `ppf(0.975)=1.96`).
- `Service\Stats\StatisticsCalculator`: Konversionsraten mit 95%-Wald-CI, relativer Lift, gepoolter Zwei-Proportionen-z-Test und zweiseitiger p-Value. Degenerierte Eingaben (leere Stichprobe, keine Streuung, Basisrate 0) liefern null statt Division durch null.
- `Service\Stats\SampleSizeCalculator`: benötigte Fallzahl je Variante über die varianzstabilisierende Arcus-Sinus-Transformation (Cohens h).
- 12 neue Tests (Tabellen-Anker, Lehrbuch-Vergleich, Edge-Cases); gesamt **106/106 grün**.

### Hinweis Korrektheit

- Der gepoolte z-Test für 287/5234 vs 334/5189 ergibt z ≈ 2.056, p ≈ 0.0398 (nachgerechnet gegen die Standardformel).

### Qualität

## [0.6.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Hinzugefügt: Plugin-Bridge

- `Bridge\ActiveVariantQuery` (Interface) + `ActiveVariantQueryImpl`: schmale, stabile Schnittstelle, über die fremde Plugins die aktive Variante eines Besuchers abfragen, ohne harte Abhängigkeit auf RcAbTesting-Interna. Public registriert; Zielplugins injizieren sie per `on-invalid="null"` optional.
- `RequestVariantResolver`: aus der Twig-Extension extrahierte, geteilte Request-Memoization. Twig **und** Bridge bucketen jetzt identisch und ohne doppelten DB-Treffer; `kernel.reset` leert das Memo im Worker-Modus.
- 8 neue/umstrukturierte Tests (Resolver-Memoization/Reset/Null-Pfade, Bridge-Abfrage); gesamt **94/94 grün**.

### Geändert

- `RcAbTwigExtension` ist nun eine dünne Fassade auf `RequestVariantResolver` (Memoization-Logik zentralisiert, DRY).

### Cross-Plugin

- Die Bridge erlaubt Zielplugins (z.B. RcCheckout, RcCheckoutEnhancer), ihren Subscriber per Guard auf die aktive Variante zu konditionieren. Die Prämisse: die Zielplugins sind **keine** konkurrierenden Markup-Renderer, sondern additive UX-Erweiterungen; ein A/B-Guard bedeutet dort „Feature AN/AUS pro Variante" (konfigurierbarer Experiment-Key, kein hartkodierter).

### Qualität

## [0.5.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (neue Twig-Funktionen)

### Hinzugefügt: Twig-Integration

- `RcAbTwigExtension` mit Twig-Funktionen `ab_variant(experimentKey)` (technical_key oder null) und `ab_variant_config(experimentKey, configKey=null)` (Variant-Config als Ganzes oder Einzelfeld) sowie Twig-Test `is in_experiment`.
- Lazy Bucketing: die Zuordnung wird erst beim ersten `ab_variant()`-Aufruf ausgelöst und pro Request je Experiment memoized; `reset()` (Tag `kernel.reset`) leert das Memo im Worker-Modus, damit keine Besucher-Zuordnung zwischen Requests durchsickert.
- 6 neue Tests (Variant-Mapping, Memoization über Call-Count, Reset, Null-Pfade ohne Request/SalesChannelContext, Config-Feld-Zugriff); gesamt **89/89 grün**.

### Qualität

## [0.4.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (neue Subscriber)

### Hinzugefügt: Event-Subscriber für Funnel-Tracking

- Zwei-Phasen-`VisitorIdResolver`: `resolveForRequest()` (Kernel-Request) legt die Besucher-ID ab, `writeCookieIfNeeded()` (Kernel-Response) setzt den Cookie nur bei neuer ID und vorliegender Einwilligung.
- `KernelRequestSubscriber` / `KernelResponseSubscriber`: Besucher-Cookie-Lebenszyklus, Sub-Requests und `/admin`//`/api` ausgenommen.
- `RequestEventRecorder`: fail-safe Brücke: holt die Besucher-ID aus dem Request und kapselt `try/catch`, damit ein Tracking-Fehler den Storefront-Request nie abreißt.
- Acht Funnel-Subscriber: `page.viewed` (nur GET/200/HTML), `product.added_to_cart` / `product.removed_from_cart` (nur Produkt-Posten), `checkout.started` / `checkout.confirm_viewed` (Warenkorbwert + Cart-Token), `checkout.order_placed` (Bestellwert), `customer.registered`, `customer.logged_in` (inkl. geräteübergreifender Visitor→Kunde-Verknüpfung).
- 14 neue Tests (fail-safe Recorder, Lifecycle-Skips, Funnel-Mapping, Customer-Upgrade); gesamt **83/83 grün**.

### Qualität

## [0.3.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (neue Services)

### Hinzugefügt: Innenring-Services

- `VisitorBucketer`: reiner, seiteneffektfreier Bucketing-Algorithmus (SHA-256, deterministisch). Getrennt von I/O, damit Determinismus und Gewichts-Verteilung ohne Datenbank testbar sind.
- `ExperimentRegistry`: laufende Experimente im getaggten Cache (`cache.object`, TTL 5 Min, Tag `rc_ab_running_experiments`); spart pro Storefront-Request einen Tabellen-Scan.
- `VisitorIdResolver`: liest/erzeugt den PII-freien Besucher-Cookie `rc_ab_visitor_id` (UUIDv4, `SameSite=Lax`, `HttpOnly=false`, 1 Jahr), Audit-Log bei korruptem Wert, kein Cookie ohne Einwilligung.
- `VariantAssigner`: Sticky-Zuordnung Besucher → Variante, Race-Condition-sicher (UNIQUE-Constraint + `UniqueConstraintViolationException`-Fallback), `upgradeAssignmentsToCustomer()` für geräteübergreifende Stabilität.
- `EventTracker`: synchrones Funnel-Tracking mit Event-Typ-Whitelist (`AbEventType`) und JSON-Meta-Sanitisierung; ein Event je offener Zuordnung (Batch-Write).
- `Consent\ConsentGate` (Interface) + `DefaultConsentGate` (Opt-out-Modell) — entkoppelt das Plugin vom konkreten Consent-Manager.
- 24 neue Unit-Tests (Bucketing-Determinismus, 10.000er-Verteilung ±3 %, Traffic-Allocation, Whitelist, Meta-Sanitisierung, Cookie-/Consent-Pfade, Cache-Roundtrip, Zuordnungs-Orchestrierung).

### Designentscheidungen

- **`Shopware\Core\Framework\Uuid\Uuid` statt `Symfony\Component\Uid`** für die Besucher-ID : vermeidet ein neues Composer-Paket, Shopware-idiomatisch, weiterhin valider UUIDv4.
- **`VariantAssigner::assign()` nimmt `SalesChannelContext`** (nicht `Context`), denn `rc_ab_assignment` verlangt `sales_channel_id` und `language_id` (beide Required); der SalesChannelContext liefert beides und liegt im Storefront-Subscriber ohnehin vor.
- **Consent als Opt-out-Default**: Der generische Consent-Gate für konkrete CMPs folgt in 0.9.2.

### Qualität

- PHPStan Level 8: 0 Errors
- PHP CS Fixer (PSR-12): 0 Verstöße (27 Dateien)
- Hinweis: `composer audit` meldete twig/twig-CVEs aus dem Shopware-Vendor-Baum (shop-weit, nicht Plugin-Code).

## [0.2.0] - 2026-05-19

> **Deployment:** `php bin/console plugin:update RcAbTesting` (neue Migrationen)

### Hinzugefügt

- Vier Custom Entities mit Definition/Entity/Collection unter `src/Core/Content/`:
  - `rc_ab_experiment`: Test-Definition (Lifecycle, Metriken, optionales Targeting auf Sales-Channel/Rule).
  - `rc_ab_variant`: Varianten pro Experiment, UNIQUE auf (experiment, technical_key).
  - `rc_ab_assignment`: Sticky-Bucketing Visitor → Variante, UNIQUE auf (experiment, visitor).
  - `rc_ab_event`: Funnel-Events mit Index (experiment, event_type, occurred_at).
- Vier Migrationen `Migration1747569600..03` — forward-only, idempotent (CREATE TABLE IF NOT EXISTS).
- Konstanten-Klassen `AbExperimentStatus` (draft/running/paused/done/archived) und `AbExperimentTestType` (twig/theme/feature_flag).
- `services.xml`: vier `shopware.entity.definition`-Einträge, Repositories über DI verfügbar.
- Tests: Schema-Pinning pro Entity (Tabellenname, Entity-/Collection-Klasse, Feldliste) + Migration-Smoke (Timestamp, CREATE-Statement, no-op destructive).

### Hinweis Schema-Detail

- `winner_variant_id` in `rc_ab_experiment` ist BINARY(16) ohne FK-Constraint, das vermeidet ein Henne-Ei-Problem beim ersten Migration-Lauf; Konsistenz garantiert der Service-Layer.
- `target_significance` als DECIMAL(4,3) im Schema, DAL-seitig als float.

### Qualität

- PHP CS Fixer: 0 Verstöße (19 Dateien)
- PHPStan Level 8: 0 Errors
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
- composer audit: 0 Advisories
- `plugin:refresh`: Plugin in Registry sichtbar (v0.1.0)
