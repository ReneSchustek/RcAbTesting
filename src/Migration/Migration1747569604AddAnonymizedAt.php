<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt `anonymized_at` in `rc_ab_assignment` und `rc_ab_event`. Der Marker macht die
 * Anonymisierung wiederholbar: Einmal anonymisierte Zeilen bleiben unberührt, sonst schriebe jeder
 * Lauf alle alten Zeilen neu und hashte ihre Kennung ein weiteres Mal.
 */
final class Migration1747569604AddAnonymizedAt extends MigrationStep
{
    use SchemaLookup;

    public function getCreationTimestamp(): int
    {
        return 1747569604;
    }

    public function update(Connection $connection): void
    {
        // Steht die Spalte schon, entfällt das `ALTER`.
        foreach (['rc_ab_assignment', 'rc_ab_event'] as $table) {
            if ($this->hasColumn($connection, $table, 'anonymized_at')) {
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
}
