<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt die Geräteklasse an der Zuordnung. Sie wird beim ersten Bucketing aus dem User-Agent
 * abgeleitet und trägt die Auswertung je Gerät (Desktop, Mobil, Tablet). Sie steht an der
 * Zuordnung und nicht am Ereignis, weil sich nur so der Nenner, die Zahl der Zuordnungen, je Gerät
 * aufteilen lässt.
 *
 * Bestehende Zuordnungen kennen kein Gerät und bleiben NULL (Segment „unbekannt“). VARCHAR(16)
 * fasst den längsten Wert aus `AbDeviceClass` mit Luft. Die Spalte kommt nur hinzu, wenn sie fehlt.
 */
final class Migration1747569609AddAssignmentDevice extends MigrationStep
{
    use SchemaLookup;

    public function getCreationTimestamp(): int
    {
        return 1747569609;
    }

    public function update(Connection $connection): void
    {
        if ($this->hasColumn($connection, 'rc_ab_assignment', 'device')) {
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
