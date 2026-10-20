<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Basis für Integrationstests, die das rohe SQL der Tracking- und Datenschutzklassen
 * gegen eine echte MySQL-Datenbank ausführen. Die Abfragen nutzen `SHA2`, Mehrtabellen-
 * `UPDATE` und korrelierte `NOT EXISTS`-Zeitgrenzen; ein Unit-Test mit gestubbter
 * Verbindung sieht davon nur die Zeichenkette, und ein Tippfehler in einer Spalte fiele
 * erst im Shop auf.
 *
 * Die Tests laufen nur, wenn `RC_AB_TEST_DATABASE_URL` auf eine eigene Test-Datenbank
 * zeigt (etwa `mysql://rc_ab_test:pass@127.0.0.1/rc_ab_test`); ohne die Variable werden
 * sie übersprungen, und die Unit-Suite läuft ohne Datenbank weiter. Einrichtung steht in
 * `tests/Integration/README.md`.
 *
 * Das Schema entsteht je Test aus den Migrationen des Plugins, in ihrer Reihenfolge. Von Hand angelegte
 * Tabellen mit verkürzten Spalten prüften nicht gegen das echte Schema. Nach jedem Test werden die Tabellen
 * wieder entfernt, weil liegengebliebene `plugin:install` in `db_test` scheitern ließen.
 *
 * Die Fremdschlüssel der Migrationen zeigen auf Kern-Tabellen (`customer`, `sales_channel`). Damit die Tests
 * ohne echte Kunden und Kanäle auskommen, sind die Prüfungen der Fremdschlüssel für diese Verbindung aus; die
 * Abfragen selbst hängen nur an Spalten und Werten.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        $url = getenv('RC_AB_TEST_DATABASE_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped(
                'RC_AB_TEST_DATABASE_URL nicht gesetzt — Integrationstest übersprungen (siehe tests/Integration/README.md).'
            );
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

    private const TABLES = ['rc_ab_event', 'rc_ab_assignment', 'rc_ab_variant', 'rc_ab_experiment'];

    private const SALES_CHANNEL_ID = '0191aaaabbbbccccddddeeeeffff0001';

    private const LANGUAGE_ID = '0191aaaabbbbccccddddeeeeffff0002';

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropTables();
        }
    }

    /**
     * Führt die Migrationen des Plugins aus, sortiert nach ihrem Zeitstempel im Klassennamen, so wie Shopware
     * sie bei der Installation ausführt.
     */
    private function resetSchema(): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->dropTables();

        $files = glob(\dirname(__DIR__, 2) . '/src/Migration/Migration*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $class = 'Ruhrcoder\\RcAbTesting\\Migration\\' . basename($file, '.php');
            /** @var \Shopware\Core\Framework\Migration\MigrationStep $migration */
            $migration = new $class();
            $migration->update($this->connection);
        }
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            $this->connection->executeStatement(\sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }

    protected function insertExperiment(string $idHex, string $status = 'running'): void
    {
        $this->connection->insert('rc_ab_experiment', [
            'id' => Uuid::fromHexToBytes($idHex),
            // Die ganze Kennung: Die ersten Zeichen einer Uuid v7 sind die Zeit, zwei Experimente aus
            // derselben Millisekunde verletzten sonst den eindeutigen Schlüssel.
            'technical_key' => 'exp-' . $idHex,
            'name' => 'Experiment ' . substr($idHex, 0, 6),
            'status' => $status,
            'test_type' => 'twig',
            'created_at' => '2026-01-01 00:00:00.000',
        ]);
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    protected function insertEvent(
        string $experimentIdHex,
        string $visitorId,
        string $eventType,
        string $occurredAt,
        ?string $customerIdHex = null,
        ?array $meta = null,
        ?string $variantIdHex = null,
        ?float $eventValue = null,
    ): void {
        // Ohne vorgegebene Variante bekommt jedes Ereignis eine eigene zufällige. Tests, die
        // nach Variante gruppieren, müssen `$variantIdHex` deshalb immer setzen.
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
            'created_at' => $occurredAt,
        ]);
    }

    protected function insertAssignment(
        string $experimentIdHex,
        string $visitorId,
        string $lastSeenAt,
        ?string $customerIdHex = null,
        ?string $anonymizedAt = null,
        ?string $device = null,
        ?string $variantIdHex = null,
    ): void {
        $this->connection->insert('rc_ab_assignment', [
            'id' => Uuid::randomBytes(),
            'experiment_id' => Uuid::fromHexToBytes($experimentIdHex),
            'variant_id' => $variantIdHex !== null ? Uuid::fromHexToBytes($variantIdHex) : Uuid::randomBytes(),
            'visitor_id' => $visitorId,
            'customer_id' => $customerIdHex !== null ? Uuid::fromHexToBytes($customerIdHex) : null,
            'device' => $device,
            // Pflichtfelder des echten Schemas, die keine geprüfte Abfrage liest.
            'sales_channel_id' => Uuid::fromHexToBytes(self::SALES_CHANNEL_ID),
            'language_id' => Uuid::fromHexToBytes(self::LANGUAGE_ID),
            'assigned_at' => $lastSeenAt,
            'last_seen_at' => $lastSeenAt,
            'created_at' => $lastSeenAt,
            'anonymized_at' => $anonymizedAt,
        ]);
    }
}
