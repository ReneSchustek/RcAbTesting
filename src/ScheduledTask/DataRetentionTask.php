<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Periodischer Task, der die DSGVO-Aufbewahrungsfrist durchsetzt: abgelaufene
 * A/B-Daten werden anonymisiert. Täglich genügt — die Frist zählt in Tagen.
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
