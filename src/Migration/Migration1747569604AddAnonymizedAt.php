<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt `anonymized_at` in `rc_ab_assignment` und `rc_ab_event`. Der Marker
 * macht das DSGVO-Cleanup idempotent: bereits anonymisierte Zeilen werden nicht
 * erneut angefasst (sonst würde jeder Lauf alle alten Zeilen neu schreiben).
 */
final class Migration1747569604AddAnonymizedAt extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569604;
    }

    public function update(Connection $connection): void
    {
        // Eigener, eindeutig benannter Idempotenz-Check (kein Verlass auf
        // versionsabhängige MigrationStep-Helfer): ist die Spalte schon da,
        // überspringen wir den ALTER.
        foreach (['rc_ab_assignment', 'rc_ab_event'] as $table) {
            if ($this->anonymizedColumnExists($connection, $table)) {
                continue;
            }

            $connection->executeStatement(\sprintf(
                'ALTER TABLE `%s` ADD COLUMN `anonymized_at` DATETIME(3) NULL AFTER `customer_id`',
                $table,
            ));
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function anonymizedColumnExists(Connection $connection, string $table): bool
    {
        $found = $connection->fetchOne(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => $table, 'column' => 'anonymized_at'],
        );

        return $found !== false;
    }
}
