<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Erstellt die Tabelle `rc_ab_experiment` — Test-Definition mit Lifecycle.
 *
 * `winner_variant_id` bleibt absichtlich ohne FK-Constraint: rc_ab_variant
 * existiert beim ersten Lauf noch nicht (Henne-Ei). Konsistenz garantiert die
 * Anwendungsschicht beim Setzen eines Gewinners.
 */
final class Migration1747569600CreateAbExperiment extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569600;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rc_ab_experiment` (
                `id`                      BINARY(16)    NOT NULL,
                `technical_key`           VARCHAR(64)   NOT NULL,
                `name`                    VARCHAR(255)  NOT NULL,
                `hypothesis`              LONGTEXT      NULL,
                `status`                  VARCHAR(32)   NOT NULL,
                `test_type`               VARCHAR(32)   NOT NULL,
                `primary_metric`          VARCHAR(64)   NULL,
                `secondary_metrics`       JSON          NULL,
                `traffic_allocation_pct`  INT           NOT NULL DEFAULT 100,
                `min_sample_size`         INT           NULL,
                `target_significance`     DECIMAL(4,3)  NOT NULL DEFAULT 0.950,
                `sales_channel_id`        BINARY(16)    NULL,
                `rule_id`                 BINARY(16)    NULL,
                `started_at`              DATETIME(3)   NULL,
                `ended_at`                DATETIME(3)   NULL,
                `winner_variant_id`       BINARY(16)    NULL,
                `created_by_id`           BINARY(16)    NULL,
                `notes`                   LONGTEXT      NULL,
                `created_at`              DATETIME(3)   NOT NULL,
                `updated_at`              DATETIME(3)   NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.rc_ab_experiment.technical_key` (`technical_key`),
                KEY `idx.rc_ab_experiment.status_started_at` (`status`, `started_at`),
                CONSTRAINT `json.rc_ab_experiment.secondary_metrics`
                    CHECK (JSON_VALID(`secondary_metrics`)),
                CONSTRAINT `fk.rc_ab_experiment.sales_channel_id`
                    FOREIGN KEY (`sales_channel_id`) REFERENCES `sales_channel` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_experiment.rule_id`
                    FOREIGN KEY (`rule_id`) REFERENCES `rule` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_ab_experiment.created_by_id`
                    FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
