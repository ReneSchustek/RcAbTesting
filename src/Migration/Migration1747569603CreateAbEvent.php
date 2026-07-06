<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Erstellt die Tabelle `rc_ab_event` — Funnel-Events pro Variante.
 *
 * Index (experiment_id, event_type, occurred_at) deckt die Stats-Hot-Query
 * "Conversion-Count pro Variante pro Tag". Bei wachsendem Volume ließe sich
 * später eine Partitionierung auf occurred_at ergänzen — ohne Schema-Bruch.
 */
final class Migration1747569603CreateAbEvent extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569603;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rc_ab_event` (
                `id`              BINARY(16)      NOT NULL,
                `experiment_id`   BINARY(16)      NOT NULL,
                `variant_id`      BINARY(16)      NOT NULL,
                `visitor_id`      VARCHAR(64)     NOT NULL,
                `customer_id`     BINARY(16)      NULL,
                `event_type`      VARCHAR(64)     NOT NULL,
                `event_value`     DECIMAL(20,4)   NULL,
                `meta`            JSON            NULL,
                `session_id`      VARCHAR(64)     NULL,
                `occurred_at`     DATETIME(3)     NOT NULL,
                `created_at`      DATETIME(3)     NOT NULL,
                `updated_at`      DATETIME(3)     NULL,
                PRIMARY KEY (`id`),
                KEY `idx.rc_ab_event.experiment_type_time`
                    (`experiment_id`, `event_type`, `occurred_at`),
                KEY `idx.rc_ab_event.visitor_experiment`
                    (`visitor_id`, `experiment_id`),
                CONSTRAINT `json.rc_ab_event.meta`
                    CHECK (JSON_VALID(`meta`)),
                CONSTRAINT `fk.rc_ab_event.experiment_id`
                    FOREIGN KEY (`experiment_id`) REFERENCES `rc_ab_experiment` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_event.variant_id`
                    FOREIGN KEY (`variant_id`) REFERENCES `rc_ab_variant` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_event.customer_id`
                    FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
