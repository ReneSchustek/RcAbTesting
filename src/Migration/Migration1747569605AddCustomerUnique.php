<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Erzwingt genau eine Zuordnung je (Experiment, Kunde). Vorher konnte ein Kunde
 * über mehrere Geräte mehrere Zuordnungen mit unterschiedlichen Varianten haben,
 * wodurch angezeigte und getrackte Variante divergierten und eine Person mehrfach
 * in die Stichprobe zählte. Bestehende Duplikate werden auf die früheste Zeile
 * reduziert, danach greift der UNIQUE-Constraint. NULL-Kunden (anonyme Besucher)
 * bleiben unberührt, da MySQL NULL im UNIQUE als verschieden behandelt.
 */
final class Migration1747569605AddCustomerUnique extends MigrationStep
{
    private const OLD_INDEX = 'idx.rc_ab_assignment.experiment_customer';
    private const NEW_INDEX = 'uniq.rc_ab_assignment.experiment_customer';

    public function getCreationTimestamp(): int
    {
        return 1747569605;
    }

    public function update(Connection $connection): void
    {
        // Duplikate je (Experiment, Kunde) auf die früheste Zeile reduzieren.
        $connection->executeStatement(<<<'SQL'
            DELETE `dup` FROM `rc_ab_assignment` `dup`
            INNER JOIN `rc_ab_assignment` `keep`
                ON `dup`.`experiment_id` = `keep`.`experiment_id`
               AND `dup`.`customer_id`   = `keep`.`customer_id`
               AND `dup`.`customer_id` IS NOT NULL
               AND (`dup`.`assigned_at` > `keep`.`assigned_at`
                    OR (`dup`.`assigned_at` = `keep`.`assigned_at` AND `dup`.`id` > `keep`.`id`))
        SQL);

        if ($this->hasNamedIndex($connection, self::NEW_INDEX)) {
            return;
        }

        if ($this->hasNamedIndex($connection, self::OLD_INDEX)) {
            $connection->executeStatement('ALTER TABLE `rc_ab_assignment` DROP INDEX `' . self::OLD_INDEX . '`');
        }

        $connection->executeStatement(
            'ALTER TABLE `rc_ab_assignment` ADD UNIQUE KEY `' . self::NEW_INDEX . '` (`experiment_id`, `customer_id`)'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function hasNamedIndex(Connection $connection, string $indexName): bool
    {
        return (bool) $connection->fetchOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index
             LIMIT 1',
            ['table' => 'rc_ab_assignment', 'index' => $indexName]
        );
    }
}
