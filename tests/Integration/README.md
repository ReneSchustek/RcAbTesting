# Integrationstests (DB-gebunden)

Diese Suite führt die **rohen SQL-Invarianten** der Tracking-/DSGVO-Klassen gegen eine
echte MySQL-Datenbank aus, also genau die Semantik, die im Unit-Test nur per String-Match
geprüft ist:

- `CartAbandonmentDetectorIntegrationTest`: NOT-EXISTS-**Zeitgrenze** (Wiederkehrer werden
  korrekt wieder gezählt) + Running-Filter.
- `VisitorDataAnonymizerIntegrationTest`: deterministischer **SHA-256-Hash**, `customer_id`
  auf NULL, **Idempotenz** über den `anonymized_at IS NULL`-Guard.
- `ExperimentStatsAggregatorIntegrationTest` und `ExperimentSegmentAggregatorIntegrationTest`: die Zählungen der
  Auswertung gegen echte Zeilen, gesamt und je Segment.
- `PerformanceGuardIntegrationTest`: grober Geschwindigkeitswächter für Auswertung und Wartung.

Die Tests brauchen MySQL-spezifische Funktionen (`SHA2`, `NOT EXISTS`); SQLite scheidet aus.
Sie laufen **nur**, wenn `RC_AB_TEST_DATABASE_URL` gesetzt ist. Sonst überspringen sie sich
sauber, die reguläre Unit-Suite bleibt unberührt.

> **Hinweis:** Das betrifft ausschließlich die Entwicklung. Für die **Installation** des
> Plugins in Shopware ist nichts davon nötig, die Tests sind Dev-Artefakte und laufen nie
> im Shop.

## Test-Datenbank bereitstellen

Eine isolierte MySQL/MariaDB-Test-Datenbank anlegen (getrennt vom Shop-Schema) und die
Zugangsdaten in `RC_AB_TEST_DATABASE_URL` hinterlegen. Beispiel gegen eine lokale MySQL:

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS rc_ab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
RC_AB_TEST_DATABASE_URL="mysql://<user>:<passwort>@127.0.0.1:<port>/rc_ab_test" \
    vendor/bin/phpunit --testsuite Integration
```

Alternativ das mitgelieferte Provisionierungs-Skript nutzen: in
`tests/Integration/provision-test-db.sql` das Passwort `CHANGE_ME` ersetzen, als MySQL-Admin
ausführen (`mysql < tests/Integration/provision-test-db.sql`). Es legt eine isolierte
Datenbank samt Benutzer an.

Ohne die Env-Variable überspringen sich alle Integrationstests:

```bash
vendor/bin/phpunit --testsuite Integration   # -> alle Tests „skipped"
```

## Kernel-Integrationstests (EventTracker-DAL-Filter)

`tests/Kernel/` testet den **DAL-Criteria-Filter** des EventTracker (customerId- vs.
visitorId-Zweig + Running-`EqualsAnyFilter`) gegen den echten Shopware-Kernel
(`IntegrationTestBehaviour`, DB-Rollback je Test). Das braucht eine **vollständige
Shopware-Installation** (Kernel-Boot), nicht nur den isolierten Plugin-Vendor, daher ein
eigener Lauf über `phpunit.kernel.xml.dist` mit `SHOPWARE_PROJECT_ROOT`, das auf die
Shopware-Installation zeigt:

```bash
# Aus dem Shopware-Root, gegen eine dedizierte Test-DB:
SHOPWARE_PROJECT_ROOT="$PWD" DATABASE_URL="mysql://<user>:<passwort>@127.0.0.1:<port>/shopware_test" \
    vendor/bin/phpunit -c <plugin-verzeichnis>/phpunit.kernel.xml.dist
```

`TestBootstrap.php` liest `SHOPWARE_PROJECT_ROOT` (die echte Shopware-Installation) und
registriert den Test-Namespace, falls die Installation ohne autoload-dev eingerichtet ist.

## Im Gate mitlaufen lassen (optional)

Der Standard-Gate-Lauf überspringt die Integrationstests (keine Env-Variable). Um sie
mitzuführen, `RC_AB_TEST_DATABASE_URL` in der Testumgebung exportieren, bevor
`vendor/bin/phpunit` läuft.

## Wieder entfernen

```sql
DROP DATABASE `rc_ab_test`;
DROP USER 'rc_ab_test'@'localhost', 'rc_ab_test'@'127.0.0.1';
```
