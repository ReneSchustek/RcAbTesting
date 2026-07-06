# Security Policy

## Unterstützte Versionen

| Version | Support |
|---------|---------|
| 1.x     | Aktiv |

## Sicherheitslücken melden

Sicherheitslücken bitte per E-Mail an **security@ruhrcoder.de** melden – nicht über öffentliche GitHub Issues.

Bitte beschreibe:
- Art der Sicherheitslücke
- Betroffene Plugin- und Shopware-Version
- Schritte zur Reproduktion

Wir antworten innerhalb von 48 Stunden und koordinieren die Veröffentlichung eines Fixes.

## Plugin-spezifische Angriffsflächen

A/B-Test-Plugins erheben Verhaltensdaten. Bei Sicherheits-Meldungen besonders berücksichtigen:

- **Visitor-Cookie-Manipulation** — kann ein Angreifer durch manipuliertes `rc_ab_visitor_id`-Cookie Variant-Assignments fälschen oder andere Visitor-IDs ausspionieren?
- **DSGVO-Datenleak** — sind PII in `rc_ab_event.meta` oder `rc_ab_assignment` ungewollt offen (Admin-API ohne ACL)?
- **Storefront-Tracking-Endpoint** — `POST /rc-ab-testing/track` ist bewusst ohne CSRF-Token (Shopware hat den Session-CSRF-Token in 6.5+ entfernt); der Schutz beruht auf `SameSite=Lax`, der POST-Beschränkung, einer Event-Typ-Whitelist, Body-/JSON-Tiefenlimit und einer fail-safe-Verarbeitung, die nie einen 500 zurückgibt. Der Endpoint ist absichtlich anonym; Events entstehen nur bei bestehender Zuordnung zu einem laufenden Experiment.
- **Rate-Limiting** — der Track-Endpoint hat bewusst kein eigenes Rate-Limit: der Missbrauch ist durch die Vorbedingung „nur bei bestehender Zuordnung" eng begrenzt, ein Throttle sollte bei Bedarf auf Infrastruktur-Ebene (Reverse-Proxy/WAF) erfolgen.
- **SQL-Injection in `event_type`/`meta`** — bei Storefront-Custom-Events Input-Sanitization prüfen (parametrisierte DAL-Writes, `meta` per JSON-Roundtrip saniert, `event_type` Whitelist + Längenlimit).
