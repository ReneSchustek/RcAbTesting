<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Setzt die Aufbewahrungsfrist der A/B-Daten durch. Täglich genügt, weil die Frist in Tagen zählt.
 */
final class DataRetentionTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_ab.data_retention';
    }

    public static function getDefaultInterval(): int
    {
        return 86400;
    }
}
