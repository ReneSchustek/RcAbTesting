<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt die Entscheidungs-Kennzahl am Experiment, die festlegt, welche Größe das Urteil der
 * Auswertung trägt: Conversion-Rate oder Umsatz pro Besucher. Vorgabe ist `revenue_per_visitor`;
 * auch bestehende Experimente bekommen sie, weil sich die Kennzahl je Experiment jederzeit
 * umstellen lässt. Die Spalte kommt nur hinzu, wenn sie fehlt.
 */
final class Migration1747569608AddDecisionMetric extends MigrationStep
{
    use SchemaLookup;

    public function getCreationTimestamp(): int
    {
        return 1747569608;
    }

    public function update(Connection $connection): void
    {
        if ($this->hasColumn($connection, 'rc_ab_experiment', 'decision_metric')) {
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
