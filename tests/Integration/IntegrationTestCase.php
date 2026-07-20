<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Basis für Integrationstests, die die rohen SQL-Invarianten der Tracking-/DSGVO-
 * Klassen gegen eine echte MySQL-Datenbank ausführen (MySQL-spezifisch: SHA2,
 * NOT-EXISTS-Zeitgrenze — mit SQLite nicht testbar).
 *
 * Läuft nur, wenn die Umgebungsvariable `RC_AB_TEST_DATABASE_URL` auf eine
 * dedizierte Test-Datenbank zeigt (z. B. `mysql://rc_ab_test:pass@127.0.0.1/rc_ab_test`).
 * Ohne diese Variable werden die Tests sauber übersprungen — das reguläre Gate
 * (Unit-Suite ohne DB) bleibt davon unberührt. Provisionierung: siehe
 * `tests/Integration/README.md`.
 *
 * Das Schema wird bewusst schlank (ohne die Core-FKs der Migrationen) je Test neu
 * aufgebaut; für die geprüften SQL-Semantiken sind nur Spalten und Daten relevant.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('RC_AB_TEST_DATABASE_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('RC_AB_TEST_DATABASE_URL nicht gesetzt — Integrationstest übersprungen (siehe tests/Integration/README.md).');
        }

        $this->connection = $this->createConnection($url);
        $this->resetSchema();
    }

    private function createConnection(string $url): Connection
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            self::fail('RC_AB_TEST_DATABASE_URL ist keine gültige URL (erwartet mysql://user:pass@host/db).');
        }

        return DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 3306,
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : 'root',
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
            'dbname' => ltrim($parts['path'], '/'),
        ]);
    }

    /**
     * Legt die vier rc_ab_*-Tabellen schlank neu an (DROP + CREATE), ohne die
     * Core-Fremdschlüssel der Produktions-Migrationen — die geprüften Queries
     * hängen nur an Spalten/Werten, nicht an den FK-Constraints.
     */
    private function resetSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['rc_ab_event', 'rc_ab_assignment', 'rc_ab_variant', 'rc_ab_experiment'] as $table) {
            $this->connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE `rc_ab_experiment` (
                `id` BINARY(16) NOT NULL PRIMARY KEY,
                `technical_key` VARCHAR(64) NULL,
                `status` VARCHAR(32) NOT NULL,
                `created_at` DATETIME(3) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE `rc_ab_variant` (
                `id` BINARY(16) NOT NULL PRIMARY KEY,
                `experiment_id` BINARY(16) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE `rc_ab_assignment` (
                `id` BINARY(16) NOT NULL PRIMARY KEY,
                `experiment_id` BINARY(16) NOT NULL,
                `variant_id` BINARY(16) NOT NULL,
                `visitor_id` VARCHAR(64) NOT NULL,
                `customer_id` BINARY(16) NULL,
                `sales_channel_id` BINARY(16) NULL,
                `device` VARCHAR(16) NULL,
                `last_seen_at` DATETIME(3) NOT NULL,
                `anonymized_at` DATETIME(3) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE `rc_ab_event` (
                `id` BINARY(16) NOT NULL PRIMARY KEY,
                `experiment_id` BINARY(16) NOT NULL,
                `variant_id` BINARY(16) NOT NULL,
                `visitor_id` VARCHAR(64) NOT NULL,
                `customer_id` BINARY(16) NULL,
                `event_type` VARCHAR(64) NOT NULL,
                `event_value` DOUBLE NULL,
                `meta` LONGTEXT NULL,
                `session_id` VARCHAR(64) NULL,
                `occurred_at` DATETIME(3) NOT NULL,
                `anonymized_at` DATETIME(3) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    protected function insertExperiment(string $idHex, string $status = 'running'): void
    {
        $this->connection->insert('rc_ab_experiment', [
            'id' => Uuid::fromHexToBytes($idHex),
            'technical_key' => 'exp-' . substr($idHex, 0, 6),
            'status' => $status,
            'created_at' => '2026-01-01 00:00:00.000',
        ]);
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    protected function insertEvent(string $experimentIdHex, string $visitorId, string $eventType, string $occurredAt, ?string $customerIdHex = null, ?array $meta = null, ?string $variantIdHex = null, ?float $eventValue = null): void
    {
        $this->connection->insert('rc_ab_event', [
            'id' => Uuid::randomBytes(),
            'experiment_id' => Uuid::fromHexToBytes($experimentIdHex),
            'variant_id' => $variantIdHex !== null ? Uuid::fromHexToBytes($variantIdHex) : Uuid::randomBytes(),
            'visitor_id' => $visitorId,
            'customer_id' => $customerIdHex !== null ? Uuid::fromHexToBytes($customerIdHex) : null,
            'event_type' => $eventType,
            'event_value' => $eventValue,
            'meta' => $meta !== null ? (string) json_encode($meta) : null,
            'occurred_at' => $occurredAt,
        ]);
    }

    protected function insertAssignment(string $experimentIdHex, string $visitorId, string $lastSeenAt, ?string $customerIdHex = null, ?string $anonymizedAt = null, ?string $device = null, ?string $variantIdHex = null): void
    {
        $this->connection->insert('rc_ab_assignment', [
            'id' => Uuid::randomBytes(),
            'experiment_id' => Uuid::fromHexToBytes($experimentIdHex),
            'variant_id' => $variantIdHex !== null ? Uuid::fromHexToBytes($variantIdHex) : Uuid::randomBytes(),
            'visitor_id' => $visitorId,
            'customer_id' => $customerIdHex !== null ? Uuid::fromHexToBytes($customerIdHex) : null,
            'device' => $device,
            'last_seen_at' => $lastSeenAt,
            'anonymized_at' => $anonymizedAt,
        ]);
    }
}
