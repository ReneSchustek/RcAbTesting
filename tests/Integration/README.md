# Integrationstests (DB-gebunden)

Diese Suite führt die **rohen SQL-Invarianten** der Tracking-/DSGVO-Klassen gegen eine
echte MySQL-Datenbank aus — genau die Semantik, die im Unit-Test nur per String-Match
geprüft war (Abnahme-Befund N3):

- `CartAbandonmentDetectorIntegrationTest` — NOT-EXISTS-**Zeitgrenze** (Wiederkehrer werden
  korrekt wieder gezählt) + Running-Filter.
- `VisitorDataAnonymizerIntegrationTest` — deterministischer **SHA-256-Hash**, `customer_id`
  auf NULL, **Idempotenz** über den `anonymized_at IS NULL`-Guard.

Die Tests brauchen MySQL-spezifische Funktionen (`SHA2`, `NOT EXISTS`); SQLite scheidet aus.
Sie laufen **nur**, wenn `RC_AB_TEST_DATABASE_URL` gesetzt ist — sonst überspringen sie sich
sauber, das reguläre Gate (Unit-Suite) bleibt unberührt.

> **Hinweis:** Das betrifft ausschließlich die Entwicklung. Für die **Installation** des
> Plugins in Shopware ist nichts davon nötig — die Tests sind Dev-Artefakte und laufen nie
> im Shop.

## Variante A — DDEV (die hiesige Dev-Umgebung, verifiziert)

Die Shopware-Instanzen laufen unter DDEV; die MariaDB ist auf einem lokalen TCP-Port erreichbar
(root/root). Eine isolierte Test-DB im DB-Container der Ziel-Instanz anlegen und die Suite laufen
lassen (Beispiel dev-67111, Port 32801 — `docker ps` bzw. `ddev describe` zeigt den Port):

```bash
mysql -h 127.0.0.1 -P 32801 -u root -proot -e "CREATE DATABASE IF NOT EXISTS rc_ab_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cd /workspace/plugins/RcAbTesting
RC_AB_TEST_DATABASE_URL="mysql://root:root@127.0.0.1:32801/rc_ab_test" vendor/bin/phpunit --testsuite Integration
```

Die Test-DB `rc_ab_test` ist vom Shop-Schema getrennt; entfernen mit
`DROP DATABASE rc_ab_test;` im selben Container.

## Variante B — eigenständige MySQL/MariaDB (Provisionierungs-Skript)

1. In `provision-test-db.sql` das Passwort `CHANGE_ME` durch ein echtes ersetzen.
2. Als MySQL-Admin ausführen: `mysql < tests/Integration/provision-test-db.sql` (isolierte DB + Benutzer).
3. Ausführen:

   ```bash
   export RC_AB_TEST_DATABASE_URL="mysql://rc_ab_test:DEIN_PASSWORT@127.0.0.1/rc_ab_test"
   vendor/bin/phpunit --testsuite Integration
   ```

Ohne die Env-Variable:

```bash
vendor/bin/phpunit --testsuite Integration   # -> alle Tests „skipped"
```

## Kernel-Integrationstests (EventTracker-DAL-Filter)

`tests/Kernel/` testet den **DAL-Criteria-Filter** des EventTracker (customerId- vs.
visitorId-Zweig + Running-`EqualsAnyFilter`) gegen den echten Shopware-Kernel
(`IntegrationTestBehaviour`, DB-Rollback je Test). Das braucht eine **vollständige
Shopware-Installation** (Kernel-Boot), nicht nur den isolierten Plugin-Vendor — daher ein
eigener Lauf über `phpunit.kernel.xml.dist` mit `SHOPWARE_PROJECT_ROOT`.

Verifizierter Ablauf auf der DDEV-DevBox (Instanz dev-67111, Plugin dort aktiv):

```bash
I=/workspace/shopware/instances/dev-67111
# 1. Test-DB als Kopie der Instanz-DB + Plugin-Migrationen nachziehen (einmalig):
mysql -h127.0.0.1 -P32801 -uroot -proot -e "CREATE DATABASE IF NOT EXISTS shopware_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysqldump -h127.0.0.1 -P32801 -uroot -proot --single-transaction --no-tablespaces db | mysql -h127.0.0.1 -P32801 -uroot -proot shopware_test
cd $I && DATABASE_URL='mysql://root:root@127.0.0.1:32801/shopware_test' php bin/console database:migrate RcAbTesting --all --no-interaction
# 2. Kernel-Suite (aus dem Instanz-Root, mit dem Instanz-Vendor):
cd $I && SHOPWARE_PROJECT_ROOT=$PWD DATABASE_URL='mysql://root:root@127.0.0.1:32801/shopware_test' \
    vendor/bin/phpunit -c /workspace/plugins/RcAbTesting/phpunit.kernel.xml.dist
# -> OK (2 tests, 6 assertions)
```

Der `TestBootstrap.php` liest `SHOPWARE_PROJECT_ROOT` (die echte Shopware-Installation) und
registriert den Test-Namespace, falls die Instanz ohne autoload-dev installiert ist.

## Im Gate mitlaufen lassen (optional)

Der Standard-Gate-Lauf überspringt die Integrationstests (keine Env-Variable). Um sie auf der
DevBox mitzuführen, `RC_AB_TEST_DATABASE_URL` in der Testumgebung exportieren, bevor
`vendor/bin/phpunit` läuft (bzw. im Gate-Runner ergänzen).

## Wieder entfernen

```sql
DROP DATABASE `rc_ab_test`;
DROP USER 'rc_ab_test'@'localhost', 'rc_ab_test'@'127.0.0.1';
```
