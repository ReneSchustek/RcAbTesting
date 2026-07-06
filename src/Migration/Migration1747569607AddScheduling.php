<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt geplante Start-/End-Zeitpunkte am Experiment. Ein Scheduled-Task startet
 * bzw. beendet Experimente automatisch, sobald der jeweilige Zeitpunkt erreicht ist.
 * Idempotent: fügt jede Spalte nur an, wenn sie fehlt.
 */
final class Migration1747569607AddScheduling extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569607;
    }

    public function update(Connection $connection): void
    {
        $this->addColumnIfMissing($connection, 'scheduled_start_at');
        $this->addColumnIfMissing($connection, 'scheduled_end_at');
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function addColumnIfMissing(Connection $connection, string $column): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column
             LIMIT 1',
            ['table' => 'rc_ab_experiment', 'column' => $column]
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            \sprintf('ALTER TABLE `rc_ab_experiment` ADD COLUMN `%s` DATETIME(3) NULL', $column)
        );
    }
}
