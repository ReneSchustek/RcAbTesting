<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Erstellt die Tabelle `rc_ab_variant` — Varianten pro Experiment. UNIQUE auf
 * (experiment_id, technical_key) verhindert Doubletten innerhalb eines Tests.
 * Löscht das Experiment löscht die Variante (CASCADE).
 */
final class Migration1747569601CreateAbVariant extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1747569601;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rc_ab_variant` (
                `id`              BINARY(16)    NOT NULL,
                `experiment_id`   BINARY(16)    NOT NULL,
                `technical_key`   VARCHAR(64)   NOT NULL,
                `name`            VARCHAR(255)  NOT NULL,
                `description`     LONGTEXT      NULL,
                `weight`          INT           NOT NULL DEFAULT 50,
                `is_control`      TINYINT(1)    NOT NULL DEFAULT 0,
                `config`          JSON          NULL,
                `created_at`      DATETIME(3)   NOT NULL,
                `updated_at`      DATETIME(3)   NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.rc_ab_variant.experiment_key` (`experiment_id`, `technical_key`),
                CONSTRAINT `json.rc_ab_variant.config`
                    CHECK (JSON_VALID(`config`)),
                CONSTRAINT `fk.rc_ab_variant.experiment_id`
                    FOREIGN KEY (`experiment_id`) REFERENCES `rc_ab_experiment` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
