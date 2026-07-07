<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt die Geräteklasse an der Zuordnung. Sie wird beim ersten Bucketing aus
 * dem User-Agent abgeleitet und ermöglicht die Segment-Auswertung je Gerät
 * (Desktop/Mobile/Tablet). Bewusst am Assignment (nicht am Event), damit sich der
 * Nenner — die Zuordnungen — je Gerät aufteilen lässt. Nullable, da Bestands-
 * zuordnungen kein Gerät kennen (Segment „unbekannt"). Idempotent.
 */
final class Migration1747569609AddAssignmentDevice extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569609;
    }

    public function update(Connection $connection): void
    {
        $exists = $connection->fetchOne(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column
             LIMIT 1',
            ['table' => 'rc_ab_assignment', 'column' => 'device']
        );

        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `rc_ab_assignment` ADD COLUMN `device` VARCHAR(16) NULL'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
