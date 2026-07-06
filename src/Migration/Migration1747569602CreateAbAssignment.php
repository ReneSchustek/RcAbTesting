<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Erstellt die Tabelle `rc_ab_assignment` — Sticky-Bucketing pro Visitor.
 *
 * Die UNIQUE-Constraint (experiment_id, visitor_id) garantiert die Sticky-Regel
 * auf DB-Ebene; parallele Erst-Zuordnungen fängt der Service über den
 * `UniqueConstraintViolationException`-Fallback ab.
 * `last_seen_at`-Index trifft den Anonymisierungs-Cron (90-Tage-Schwelle).
 */
final class Migration1747569602CreateAbAssignment extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569602;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rc_ab_assignment` (
                `id`                 BINARY(16)    NOT NULL,
                `experiment_id`      BINARY(16)    NOT NULL,
                `variant_id`         BINARY(16)    NOT NULL,
                `visitor_id`         VARCHAR(64)   NOT NULL,
                `customer_id`        BINARY(16)    NULL,
                `sales_channel_id`   BINARY(16)    NOT NULL,
                `language_id`        BINARY(16)    NOT NULL,
                `assigned_at`        DATETIME(3)   NOT NULL,
                `last_seen_at`       DATETIME(3)   NOT NULL,
                `created_at`         DATETIME(3)   NOT NULL,
                `updated_at`         DATETIME(3)   NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.rc_ab_assignment.experiment_visitor`
                    (`experiment_id`, `visitor_id`),
                KEY `idx.rc_ab_assignment.experiment_customer`
                    (`experiment_id`, `customer_id`),
                KEY `idx.rc_ab_assignment.last_seen_at` (`last_seen_at`),
                CONSTRAINT `fk.rc_ab_assignment.experiment_id`
                    FOREIGN KEY (`experiment_id`) REFERENCES `rc_ab_experiment` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_assignment.variant_id`
                    FOREIGN KEY (`variant_id`) REFERENCES `rc_ab_variant` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_assignment.customer_id`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_assignment.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_assignment.language_id`
                    FOREIGN KEY (`language_id`) REFERENCES `language` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
