<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;

/**
 * Ob eine Spalte oder ein Index schon besteht, gelesen aus `information_schema`. Die Helfer von `MigrationStep`
 * unterscheiden sich je nach Shopware-Fassung in Namen und Weg; diese Abfrage gilt für jede Fassung.
 */
trait SchemaLookup
{
    private function hasColumn(Connection $connection, string $table, string $column): bool
    {
        return $connection->fetchOne(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column
             LIMIT 1',
            ['table' => $table, 'column' => $column],
        ) !== false;
    }

    private function hasNamedIndex(Connection $connection, string $table, string $index): bool
    {
        return $connection->fetchOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index
             LIMIT 1',
            ['table' => $table, 'index' => $index],
        ) !== false;
    }
}
