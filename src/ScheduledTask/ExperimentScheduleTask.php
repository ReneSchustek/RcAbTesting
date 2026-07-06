<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Periodischer Task, der Experimente an ihren geplanten Start-/End-Zeitpunkten
 * automatisch startet bzw. beendet. Standard-Intervall 5 Minuten — feiner ist
 * unnötig, da Kampagnen-Zeitpunkte in Minuten-Granularität ausreichen.
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
