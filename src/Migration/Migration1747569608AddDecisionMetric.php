<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt die Entscheidungs-Kennzahl am Experiment. Sie legt fest, welche Grösse
 * das Verdikt der Auswertung treibt (Conversion-Rate vs. Umsatz pro Besucher).
 * Default `revenue_per_visitor` — auch Bestandsdaten erhalten den Produkt-Default,
 * da die Kennzahl je Experiment jederzeit umstellbar bleibt. Idempotent: fügt die
 * Spalte nur an, wenn sie fehlt.
 */
final class Migration1747569608AddDecisionMetric extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569608;
    }

    public function update(Connection $connection): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column
             LIMIT 1',
            ['table' => 'rc_ab_experiment', 'column' => 'decision_metric']
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            "ALTER TABLE `rc_ab_experiment`
             ADD COLUMN `decision_metric` VARCHAR(32) NOT NULL DEFAULT 'revenue_per_visitor'"
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
