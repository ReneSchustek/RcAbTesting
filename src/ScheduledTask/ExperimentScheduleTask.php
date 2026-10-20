<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Startet und beendet Experimente zu ihren geplanten Zeitpunkten. Alle fünf Minuten: Ein geplanter
 * Zeitpunkt greift so höchstens fünf Minuten zu spät, für Kampagnen genau genug.
 */
final class ExperimentScheduleTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_ab.experiment_schedule';
    }

    public static function getDefaultInterval(): int
    {
        return 300;
    }
}
