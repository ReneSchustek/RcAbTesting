<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Idempotenter Guard für die Eindeutigkeit von `technical_key`. Frische
 * Installationen legen den UNIQUE-Constraint bereits inline im `CREATE TABLE`
 * (Migration 600) an — dann läuft diese Migration über den `hasNamedIndex`-Guard
 * ins Early-Return. Sie greift nur auf Altbeständen, deren Tabelle noch ohne den
 * Constraint erstellt wurde: bestehende Duplikate werden auf das früheste
 * Experiment reduziert, danach wird der UNIQUE-Constraint nachgezogen. Der
 * Registry-Lookup erwartet genau ein Experiment je Schlüssel.
 */
final class Migration1747569606AddExperimentKeyUnique extends MigrationStep
{
    private const INDEX = 'uniq.rc_ab_experiment.technical_key';

    public function getCreationTimestamp(): int
    {
        return 1747569606;
    }

    public function update(Connection $connection): void
    {
        // Duplikate je technical_key auf das früheste Experiment reduzieren.
        $connection->executeStatement(<<<'SQL'
            DELETE `dup` FROM `rc_ab_experiment` `dup`
            INNER JOIN `rc_ab_experiment` `keep`
                ON `dup`.`technical_key` = `keep`.`technical_key`
               AND (`dup`.`created_at` > `keep`.`created_at`
                    OR (`dup`.`created_at` = `keep`.`created_at` AND `dup`.`id` > `keep`.`id`))
        SQL);

        if ($this->hasNamedIndex($connection)) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `rc_ab_experiment` ADD UNIQUE KEY `' . self::INDEX . '` (`technical_key`)'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function hasNamedIndex(Connection $connection): bool
    {
        return (bool) $connection->fetchOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index
             LIMIT 1',
            ['table' => 'rc_ab_experiment', 'index' => self::INDEX]
        );
    }
}
