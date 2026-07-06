<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

/**
 * Zulässige Werte für `rc_ab_experiment.status`. Bewusst kein DB-ENUM, damit
 * spätere Wertzugänge keine schema-ändernden Migrationen erzwingen.
 */
final class AbExperimentStatus
{
    public const DRAFT = 'draft';
    public const RUNNING = 'running';
    public const PAUSED = 'paused';
    public const DONE = 'done';
    public const ARCHIVED = 'archived';

    /** @var list<string> */
    public const VALUES = [
        self::DRAFT,
        self::RUNNING,
        self::PAUSED,
        self::DONE,
        self::ARCHIVED,
    ];

    private function __construct()
    {
    }
}
