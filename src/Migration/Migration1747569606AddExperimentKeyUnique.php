<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Sichert die Eindeutigkeit von `technical_key` auch in Beständen, deren Tabelle ohne den
 * eindeutigen Schlüssel angelegt wurde. Bei einer frischen Installation legt
 * Migration1747569600 ihn schon im `CREATE TABLE` an; dann findet das Löschen nichts, und der
 * `hasNamedIndex`-Wächter beendet die Migration. Sonst bleibt je Schlüssel das früheste
 * Experiment, und der Schlüssel wird nachgezogen. `ExperimentRegistry` und `ExperimentLookup`
 * erwarten genau ein Experiment je Schlüssel.
 */
final class Migration1747569606AddExperimentKeyUnique extends MigrationStep
{
    use SchemaLookup;

    private const INDEX = 'uniq.rc_ab_experiment.technical_key';

    public function getCreationTimestamp(): int
    {
        return 1747569606;
    }

    public function update(Connection $connection): void
    {
        // Erst die Duplikate entfernen, sonst scheitert der eindeutige Schlüssel. Mit einem
        // doppelten Experiment verschwinden über CASCADE auch seine Varianten, Zuordnungen und
        // Ereignisse.
        $connection->executeStatement(<<<'SQL'
            DELETE `dup` FROM `rc_ab_experiment` `dup`
            INNER JOIN `rc_ab_experiment` `keep`
                ON `dup`.`technical_key` = `keep`.`technical_key`
               AND (`dup`.`created_at` > `keep`.`created_at`
                    OR (`dup`.`created_at` = `keep`.`created_at` AND `dup`.`id` > `keep`.`id`))
        SQL);

        if ($this->hasNamedIndex($connection, 'rc_ab_experiment', self::INDEX)) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `rc_ab_experiment` ADD UNIQUE KEY `' . self::INDEX . '` (`technical_key`)'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
