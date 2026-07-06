-- Provisioniert eine dedizierte Test-Datenbank + Benutzer fuer die
-- rc-ab-testing-Integrationstests. Als MySQL-Administrator ausfuehren
-- (z. B. `sudo mysql < tests/Integration/provision-test-db.sql`).
--
-- WICHTIG: 'CHANGE_ME' vor dem Ausfuehren durch ein echtes Passwort ersetzen
-- und dasselbe Passwort in RC_AB_TEST_DATABASE_URL verwenden (siehe README.md).
--
-- Eigene, isolierte Datenbank -- beruehrt keine Shop-Daten. Wieder entfernen:
--   DROP DATABASE `rc_ab_test`; DROP USER 'rc_ab_test'@'localhost', 'rc_ab_test'@'127.0.0.1';

CREATE DATABASE IF NOT EXISTS `rc_ab_test`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'rc_ab_test'@'localhost'   IDENTIFIED BY 'CHANGE_ME';
CREATE USER IF NOT EXISTS 'rc_ab_test'@'127.0.0.1'   IDENTIFIED BY 'CHANGE_ME';

GRANT ALL PRIVILEGES ON `rc_ab_test`.* TO 'rc_ab_test'@'localhost';
GRANT ALL PRIVILEGES ON `rc_ab_test`.* TO 'rc_ab_test'@'127.0.0.1';

FLUSH PRIVILEGES;
