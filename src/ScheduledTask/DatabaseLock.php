<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Doctrine\DBAL\Connection;

/**
 * Lässt einen Lauf nur einmal zur selben Zeit zu, über einen benannten MySQL-Lock.
 *
 * Timeout 0: Ein überlappender Lauf überspringt sich sofort, statt zu warten. MySQL kennt Sperrnamen nur serverweit;
 * der Name der Datenbank davor trennt zwei Shops auf demselben Server, sonst setzte der eine aus, solange der andere
 * läuft.
 */
final class DatabaseLock
{
    private function __construct()
    {
    }

    /**
     * @param callable(): void $work
     */
    public static function runExclusively(Connection $connection, string $name, callable $work): void
    {
        if ((int) $connection->fetchOne(
            "SELECT GET_LOCK(CONCAT(DATABASE(), ':', :name), 0)",
            ['name' => $name]
        ) !== 1) {
            return;
        }

        try {
            $work();
        } finally {
            $connection->executeStatement(
                "SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':', :name))",
                ['name' => $name],
            );
        }
    }
}
