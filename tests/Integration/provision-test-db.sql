-- Legt eine eigene Test-Datenbank samt Benutzer für die Integrationstests von
-- RcAbTesting an. Als MySQL-Administrator ausführen
-- (etwa `sudo mysql < tests/Integration/provision-test-db.sql`).
--
-- 'CHANGE_ME' vor dem Ausführen durch ein echtes Passwort ersetzen und dasselbe
-- Passwort in RC_AB_TEST_DATABASE_URL eintragen (siehe README.md).
--
-- Die Tests löschen und erzeugen ihre Tabellen bei jedem Lauf neu; deshalb eine
-- getrennte Datenbank, die keine Shop-Daten enthält. Wieder entfernen:
--   DROP DATABASE `rc_ab_test`; DROP USER 'rc_ab_test'@'localhost', 'rc_ab_test'@'127.0.0.1';

CREATE DATABASE IF NOT EXISTS `rc_ab_test`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Zwei Hosts, weil MySQL eine Verbindung über den Socket als 'localhost' und eine
-- über TCP an 127.0.0.1 als getrennte Konten behandelt.
CREATE USER IF NOT EXISTS 'rc_ab_test'@'localhost'   IDENTIFIED BY 'CHANGE_ME';
CREATE USER IF NOT EXISTS 'rc_ab_test'@'127.0.0.1'   IDENTIFIED BY 'CHANGE_ME';

GRANT ALL PRIVILEGES ON `rc_ab_test`.* TO 'rc_ab_test'@'localhost';
GRANT ALL PRIVILEGES ON `rc_ab_test`.* TO 'rc_ab_test'@'127.0.0.1';

FLUSH PRIVILEGES;
