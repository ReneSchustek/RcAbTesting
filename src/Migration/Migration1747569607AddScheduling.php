<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt geplante Start- und Endzeitpunkte am Experiment, die der `ExperimentScheduleTask`
 * abarbeitet. Jede Spalte kommt nur hinzu, wenn sie fehlt, damit ein zweiter Lauf nicht scheitert.
 */
final class Migration1747569607AddScheduling extends MigrationStep
{
    use SchemaLookup;

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
        if ($this->hasColumn($connection, 'rc_ab_experiment', $column)) {
            return;
        }

        $connection->executeStatement(
            \sprintf('ALTER TABLE `rc_ab_experiment` ADD COLUMN `%s` DATETIME(3) NULL', $column)
        );
    }
}
