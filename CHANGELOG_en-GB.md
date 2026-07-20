# Changelog (EN)

All notable changes to RcAbTesting are documented here. This file follows the
[Keep a Changelog](https://keepachangelog.com/) style; versions are listed newest first.

## [1.13.0] - 2026-07-20

> **Deployment:** `bin/console cache:clear` and `bin/build-administration.sh` required (admin JS/SCSS/snippets changed). No migration.

### Accessibility (EAA/BFSG)

- **Evaluation tabs** now follow the ARIA tab pattern (`tablist`/`tab`/`tabpanel`) and can be operated with the arrow keys, Home and End; the focus is visible.
- **Help explanations** in table headers are attached to a button instead of a mouse-only icon, making them keyboard reachable and screen-reader friendly.
- **Time series** encodes variants by **dash pattern** and an end-of-line value label in addition to colour; the same figures are available as a table for screen readers below the chart. The SVG carries a meaningful description.
- **Funnel** exposes the same stage figures (stage per variant with share and drop-off) as a hidden table for screen readers as well; the bars carry a meaningful name.
- **Contrast** raised: text colours meet at least 4.5:1, graphics and chart colours at least 3:1.
- The **reloaded evaluation** is announced in an `aria-live` region; the decision-metric select and the inline fields of the variant grid now have an accessible name.
- Segment tables use `caption` and `scope`; heading hierarchy corrected.

### Changed

- **Evaluation detail page decoupled:** the four evaluation tabs (overview, segments, time series, funnel) are separate components; the derivations (chart geometry, verdict, funnel, formatting, metric catalogue) live as pure functions under `helper/` and are covered by 56 tests. Behaviour unchanged.
- **Fewer database queries in the evaluation:** `ExperimentStatsAggregator` aggregates all variants in two grouped queries instead of four queries per variant.
- Dedicated log channel `rc_ab_testing` for all plugin services.
- The bridge implementation is no longer public in the container; as documented, only the `ActiveVariantQuery` interface is.

## [1.12.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` (new switch snippet). No migration.

### Added

- **Frontend switch "free-shipping indicator":** Second registered switch (`free_shipping_indicator`, values Show/Hide) — lets RcCheckout's free-shipping hint be turned on or off per A/B variant so its effect can be measured. RcCheckout consumes the value via `FrontendSwitchResolver`.

## [1.11.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` (new services/subscribers). No admin build, no migration.

### Added

- **Frontend switch, application layer (flexible):** The value set for a test is now provided at runtime on two paths. Own plugins/templates read the active value directly via the new Twig function `ab_switch('checkout_layout')` (or the `FrontendSwitchResolver` in PHP). Third-party plugins are served via a concrete adapter (`FrontendSwitchAdapter`, tagged service): a dispatcher resolves the active value at render time and calls the matching adapter without touching the target plugin. The switch mechanism is now complete and extensible.

## [1.10.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` (admin JS/SCSS changed). No new migration.

### Added

- **Frontend switch (no-code, foundation):** New test type "switch plugin behaviour" — per variant a registered, frontend-effective switch is set to a value (dropdown instead of JSON). First switch: **checkout layout** with "single page" vs. "step by step". A consuming plugin reads the value via `ab_variant_config('checkout_layout')`. The switch registry is extensible through tagged services. (The actual checkout switching lives in the respective checkout plugin.)

## [1.9.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` (admin JS/SCSS changed). No new migration.

### Admin

- **Guided creation (understandable UI):** The detail page is tidied for non-technical users. The default view shows only the plain-language fields — "What do you want to test?" with plain test types and explanations, hypothesis with example, traffic share, and the variants. Technical fields (technical key, target significance, targeting, schedule, weights, raw JSON) are collapsed behind "Show advanced settings". The technical key is generated from the name and weights are split 50:50 automatically.

## [1.8.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` (admin JS/SCSS changed). No new migration.

### Analysis

- **Funnel per stage:** New "Funnel" tab — the purchase funnel per variant across four stages (page viewed → added to cart → checkout started → purchase completed). Each stage shows the share of visitors as a bar plus the drop-off (in percentage points) versus the previous stage, revealing where a variant loses visitors. With this the analysis (overview, segments, timeline, funnel) is complete.

## [1.7.0] - 2026-07-07

> **Deployment:** `bin/console cache:clear` + `bin/build-administration.sh` (admin JS/SCSS changed). No new migration.

### Analysis

- **Timeline:** New "Timeline" tab — the cumulative course of the decision metric (revenue per visitor or conversion rate) per variant over time as a line chart, so an early random signal can be told apart from a stable, reliable trend. Axis in the unit of the chosen metric (euro or percent).

## [1.6.0] - 2026-07-07

> **Deployment:** `bin/console plugin:update RcAbTesting` (new migration) + `bin/build-administration.sh` (admin JS/SCSS changed).

### Analysis

- **Segment analysis:** New "Segments" tab — the same analysis per **device** (desktop/mobile/tablet) and per **sales channel**, each with a compact scorecard. Device class is derived from the user agent on first bucketing and stored on the assignment; it applies from this version onwards (older assignments show as "Unknown"). A dimension is only shown if it has at least two values.
- **Understandable tables:** The analysis column headers (lift, p-value, confidence interval, significance, required sample size, assignments, rate, revenue/visitor) now carry a help icon with a plain-language mouse-over explanation.

## [1.5.0] - 2026-07-07

> **Deployment:** `bin/console plugin:update RcAbTesting` (new migration) + `bin/build-administration.sh` (admin JS/SCSS changed).

### Analysis

- **Result overview with a decision metric:** The analysis is now one coherent overview — a plain-language verdict on top, the scorecard of all metrics per variant below, and tabs for the detail views. A per-experiment selectable decision metric drives the verdict; default is **revenue per visitor**, alternatively the conversion rate. The remaining metrics are shown as context only, guarding against false positives from testing many metrics at once.
- **Significance per metric:** For "revenue per visitor" a mean comparison (with spread) is run instead of the proportion test used for the conversion rate — including a confidence interval in euro and the required sample size. Average order value stays a display-only metric (its analysis unit is the order, not the visitor).

## [1.4.0] - 2026-07-06

### Analysis

- **Revenue per variant:** The analysis now reports, in addition to the conversion rate, the **revenue**, the **average order value** and the **revenue per visitor** per variant, taken from the order value tracked at purchase. This reveals whether a variant not only converts more often but also sells more valuably.

## [1.3.0] - 2026-07-06

> No-code expansion for non-technical users, phase 1. Deployment/rollout at release.

### Added

- **CMS page test (no-code):** New "CMS page" test type. Per variant one picks a ready-made CMS page (shopping experience) from a dropdown — no Twig/JSON. A storefront subscriber serves the matching page per assignment while category/URL stay unchanged. Control = the page currently served live. Assignment, targeting, consent, sticky bucketing and funnel/abandonment tracking run on the same base as the other test types. If loading the variant page fails, the control page stays (the test never breaks the page).

### Admin

- **50:50 default split:** Adding/removing variants now distributes weights evenly to 100 (two variants = 50/50); a custom split remains freely configurable.
- **Plain-language recommendation:** The analysis adds an understandable action sentence per variant (e.g. "variant B is significantly worse — do not roll out", "significantly better — can be rolled out", "no measurable difference", "not enough data yet") with a traffic-light colour.

## [1.2.0] - 2026-07-06

> **Deployment:** `bin/console plugin:update RcAbTesting` (new migrations: AddCustomerUnique, AddExperimentKeyUnique, AddScheduling) + `bin/console cache:clear` + `bin/build-administration.sh` (new admin JS).

### Fixed

- **Admin lifecycle & analysis work under Shopware 6.7:** Start/pause/end and the statistical analysis called their HTTP client via `inject: ['httpClient']`, which Shopware 6.7 no longer provides — the call failed silently client-side. The detail page now takes the HTTP client from the init container. Verified via an admin smoke test against a 6.7 shop.
- **Targeting takes effect at runtime:** An experiment restricted to `sales_channel_id` or `rule_id` now only runs there (both fields were ignored before).
- **Significance level takes effect:** The per-experiment `target_significance` now drives alpha, the critical z-value and the confidence-interval width (previously hard-wired to 95%).
- **Cross-device consistency:** A new `UNIQUE(experiment, customer)` enforces exactly one assignment per customer and experiment; redundant device assignments are merged on login so displayed and tracked variant match.
- **Scheduled start no longer overrides a manual pause;** deleting variants in the admin is now persisted server-side.

### Analysis

- **Statistical analysis in the admin:** The detail page now shows lift, p-value, confidence interval and significance against control, a winner recommendation, a sample-ratio-mismatch warning and the required sample size (previously CLI-only).

### Hardening & Rights

- **Robustness:** The track endpoint limits body size and JSON depth; running assignments are loaded once per request; variant changes clear the experiment cache immediately.
- **ACL roles:** The A/B module registers view/edit/create/delete roles so non-administrators can be granted access.
- **Scheduling & integrity:** Experiments can get a scheduled start/end (auto-started/ended by a scheduled task); variant weights cannot be negative and the technical key is unique in the database.
- **GDPR export:** New command `rc:ab:export --customer=<id>` outputs all A/B assignments and events for a customer as JSON.

## [1.1.1] - 2026-07-04 — Delivery polish

> **Deployment:** `php bin/console cache:clear`

### Changed

- Removed internal development references from the delivered files (README, CHANGELOG, `services.xml` comments, source/test comments); purely editorial, no behaviour change.
- Corrected two misleading comments (opt-out values in `DefaultConsentGate`, retention semantics in `DataRetentionTaskHandler`).

## [1.1.0] - 2026-06-28 — GDPR retention + hardening

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Added

- **GDPR retention:** New daily `DataRetentionTask` and command `rc:ab:cleanup --older-than-days=N` anonymise expired A/B data — `visitor_id` becomes a deterministic SHA-256 hash, `customer_id` is removed. The period is the plugin config `dataRetentionDays` (default 90, `0` disables). An `anonymized_at` marker (migration) makes the run idempotent.

### Fixed / Improved

- **Cart-abandonment window:** The `cart.abandoned` detection no longer excludes a visitor for life after abandoning once; returning visitors are counted correctly.
- **Atomicity:** `CartAbandonmentTaskHandler` wraps detection and writing in a MySQL named lock so overlapping runs cannot produce duplicate events.
- **Stats performance:** Converting visitors are counted via `COUNT(DISTINCT visitor_id)` directly in the database.
- **Storefront JS:** Tracking is wired through the Shopware plugin manager with a direct `init()` fallback.

## [1.0.2] - 2026-06-27 — Stickiness & data minimisation

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Fixed

- **Cross-device stickiness:** For a logged-in customer the variant follows the customer rather than the device-bound visitor cookie.
- **No post-hoc bias:** `EventTracker` writes events only for assignments to running experiments.
- **Start validation:** `POST .../start` rejects experiments without ≥2 variants or with a weight sum ≠ 100 (422).
- **Data minimisation:** `OrderPlacedSubscriber` stores only the `order_id`.

## [1.0.1] - 2026-06-27 — Sample & consent hardening

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Fixed

- **Sample integrity:** Cookie-less requests (bots, opt-out, pre-consent) no longer create a new assignment per call; only visitor IDs from a real cookie are persisted, cookie-less visitors are bucketed deterministically in-memory.
- **Status changes take effect immediately:** New `ExperimentCacheInvalidationSubscriber` removes the up-to-5-minute cache delay.
- **No double counting:** The storefront JS no longer fires `page.viewed` itself (the server counts).
- **Uninstall:** `uninstall()` removes the `rc_ab_*` tables (respecting `keepUserData`).
- **Configurable:** `config.xml` with consent cookie (opt-in) and cart-abandonment period.

## [1.0.0] - 2026-06-27 — First complete version

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` + admin/storefront asset build.

### Added — Administration module

- Administration module `rc-ab-testing` under `sw-marketing`: experiment list and detail page (base data, test-type selection, traffic/significance, variant display).
- Lifecycle buttons (start/pause/end) call the admin API; an analysis card loads the stats endpoint (assignments/conversions/rate per variant).
- de-DE/en-GB snippets (real umlauts).

### Scope

- Complete: entities + migrations, inner-ring services, funnel subscribers, Twig integration, plugin bridge, statistics core (z-test/CI/lift/sample size), CLI commands, cart-abandonment scheduler, configurable consent gate, storefront tracking, admin API + ACL, admin module.
- **131 PHP tests + 6 JS tests**, all gates green.

## [0.9.3] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` + storefront asset build.

### Added — Storefront tracking + cache vary

- `TrackingController` — `POST /rc-ab-testing/track`: validates the event type (whitelist incl. `custom.*`), writes via `EventTracker`, returns `{ok: bool}`. Anonymous visitors may track.
- `CacheVarySubscriber` — sets `Cache-Control: private, no-store` only on pages where the visitor was actually assigned to a variant, keeping the HTTP cache active elsewhere.
- Framework-free storefront JS: `window.RcAbTesting.track(...)`, reads the visitor cookie, no-op without cookie, auto `page.viewed` on DOMContentLoaded.

## [0.9.2] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

## [0.9.1] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting`

### Added — Cart-abandonment scheduler

- `CartAbandonmentTask` (15-min interval) + handler: writes exactly one `cart.abandoned` event per detected abandonment.
- `CartAbandonmentDetector` — detects abandonment purely from the plugin's own funnel events; idempotency lies in the detection. Period via `cartAbandonmentMinutes` (default 30 min).

## [0.9.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Added — Admin API + ACL

- `RcAbExperimentApiController` with four endpoints: `GET .../experiment/{id}/stats` plus `POST .../start`, `.../pause`, `.../end`. Status transitions are checked (e.g. pause only from `running` → else 409, missing experiment → 404).
- ACL via the auto-generated entity privileges (`:read` for stats, `:update` for lifecycle).

## [0.8.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Added — CLI + aggregator

- `ExperimentStatsAggregator` — counts assignments (sample) and distinct converting visitors per variant; conversions clamped to the assignment count (rate never > 100%).
- CLI commands: `rc:ab:list` (with `--status` filter), `rc:ab:stats <key>` (rates, 95% CI, lift, p-value, significance), `rc:ab:end <key> [--winner=]`, `rc:ab:cleanup` (GDPR anonymisation).

## [0.7.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting`

### Added — Statistics core

- `NormalDistribution` — standard normal distribution in pure PHP (`erf`, `cdf`, quantile `ppf`), validated against the standard normal table.
- `StatisticsCalculator` — conversion rates with 95% Wald CI, relative lift, pooled two-proportion z-test and two-sided p-value; degenerate inputs return null instead of dividing by zero.
- `SampleSizeCalculator` — required sample size per variant via the arcsine transform (Cohen's h).

## [0.6.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear`

### Added — Plugin bridge

- `ActiveVariantQuery` (interface) + impl — a narrow, stable interface through which foreign plugins query a visitor's active variant without a hard dependency on RcAbTesting internals.
- `RequestVariantResolver` — shared per-request memoization extracted from the Twig extension; Twig and bridge now bucket identically without a duplicate DB hit. `RcAbTwigExtension` becomes a thin facade (DRY).

## [0.5.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (new Twig functions)

### Added — Twig integration

- `RcAbTwigExtension` with Twig functions `ab_variant(experimentKey)` and `ab_variant_config(experimentKey, configKey=null)` plus the Twig test `is in_experiment`.
- Lazy bucketing: assignment is triggered on the first `ab_variant()` call and memoized per request; `reset()` (tag `kernel.reset`) clears the memo in worker mode.

## [0.4.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (new subscribers)

### Added — Event subscribers for funnel tracking

- Two-phase `VisitorIdResolver`: resolve the visitor ID on kernel request, set the cookie on kernel response only for a new ID with consent.
- `KernelRequestSubscriber` / `KernelResponseSubscriber` — visitor-cookie lifecycle, excluding sub-requests and `/admin`//`/api`.
- Eight funnel subscribers: `page.viewed`, add/remove to cart, `checkout.started`, `checkout.confirm_viewed`, `checkout.order_placed`, `customer.registered`, `customer.logged_in` (incl. cross-device visitor→customer linking). Fail-safe `RequestEventRecorder` so a tracking error never breaks the storefront request.

## [0.3.0] - 2026-06-27

> **Deployment:** `php bin/console plugin:update RcAbTesting && php bin/console cache:clear` (new services)

### Added — Inner-ring services

- `VisitorBucketer` — pure, side-effect-free bucketing (SHA-256, deterministic).
- `ExperimentRegistry` — running experiments in a tagged cache (TTL 5 min).
- `VisitorIdResolver` — reads/creates the PII-free visitor cookie `rc_ab_visitor_id` (UUIDv4, SameSite=Lax, 1 year).
- `VariantAssigner` — sticky visitor→variant assignment, race-condition safe (UNIQUE + violation fallback), plus `upgradeAssignmentsToCustomer()`.
- `EventTracker` — synchronous funnel tracking with an event-type whitelist and JSON meta sanitisation.
- `ConsentGate` (interface) + `DefaultConsentGate` (opt-out model).

## [0.2.0] - 2026-05-19

> **Deployment:** `php bin/console plugin:update RcAbTesting` (new migrations)

### Added

- Four custom entities under `src/Core/Content/`: `rc_ab_experiment`, `rc_ab_variant` (UNIQUE on experiment+technical_key), `rc_ab_assignment` (UNIQUE on experiment+visitor), `rc_ab_event` (indexed).
- Four migrations `Migration1747569600..03` — forward-only, idempotent (`CREATE TABLE IF NOT EXISTS`).
- Constant classes `AbExperimentStatus` (draft/running/paused/done/archived) and `AbExperimentTestType` (twig/theme/feature_flag); four `shopware.entity.definition` entries in `services.xml`.

## [0.1.0] - 2026-05-18

### Added

- Plugin skeleton with namespace `Ruhrcoder\RcAbTesting\`, final plugin class, empty `services.xml`.
- Toolchain: `composer.json` with PHPUnit/PHPStan/CS-Fixer + a `composer quality` script.
- Mandatory files: `SECURITY.md`, `.editorconfig`, `plugin.png`; smoke test; README + CHANGELOG initialised.
