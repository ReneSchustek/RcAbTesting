<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Erzwingt genau eine Zuordnung je Experiment und Kunde. Im bisherigen Bestand kann ein Kunde über
 * mehrere Geräte mehrere Zuordnungen mit unterschiedlichen Varianten haben; angezeigte und
 * gezählte Variante laufen dann auseinander, und eine Person zählt mehrfach in der Stichprobe.
 *
 * Die Migration behält je Paar die früheste Zeile und legt danach den eindeutigen Schlüssel an.
 * Zeilen ohne Kunden bleiben unberührt, weil MySQL NULL-Werte in einem eindeutigen Schlüssel als
 * verschieden behandelt.
 */
final class Migration1747569605AddCustomerUnique extends MigrationStep
{
    use SchemaLookup;

    private const OLD_INDEX = 'idx.rc_ab_assignment.experiment_customer';
    private const NEW_INDEX = 'uniq.rc_ab_assignment.experiment_customer';

    public function getCreationTimestamp(): int
    {
        return 1747569605;
    }

    public function update(Connection $connection): void
    {
        // Erst die Duplikate entfernen, sonst scheitert das Anlegen des eindeutigen Schlüssels.
        // Bei gleichem Zeitpunkt entscheidet die ID, damit genau eine Zeile übrig bleibt.
        $connection->executeStatement(<<<'SQL'
            DELETE `dup` FROM `rc_ab_assignment` `dup`
            INNER JOIN `rc_ab_assignment` `keep`
                ON `dup`.`experiment_id` = `keep`.`experiment_id`
               AND `dup`.`customer_id`   = `keep`.`customer_id`
               AND `dup`.`customer_id` IS NOT NULL
               AND (`dup`.`assigned_at` > `keep`.`assigned_at`
                    OR (`dup`.`assigned_at` = `keep`.`assigned_at` AND `dup`.`id` > `keep`.`id`))
        SQL);

        if ($this->hasNamedIndex($connection, 'rc_ab_assignment', self::NEW_INDEX)) {
            return;
        }

        if ($this->hasNamedIndex($connection, 'rc_ab_assignment', self::OLD_INDEX)) {
            $connection->executeStatement('ALTER TABLE `rc_ab_assignment` DROP INDEX `' . self::OLD_INDEX . '`');
        }

        $connection->executeStatement(
            'ALTER TABLE `rc_ab_assignment` ADD UNIQUE KEY `' . self::NEW_INDEX . '` (`experiment_id`, `customer_id`)'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
