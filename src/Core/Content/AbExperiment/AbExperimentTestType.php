<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

/**
 * Zulässige Werte für `rc_ab_experiment.test_type`. Bestimmt, welcher
 * Mechanismus die Variante anwendet (Twig-Markup, Theme-Override, Feature-Flag).
 */
final class AbExperimentTestType
{
    public const TWIG = 'twig';
    public const THEME = 'theme';
    public const FEATURE_FLAG = 'feature_flag';

    /** @var list<string> */
    public const VALUES = [
        self::TWIG,
        self::THEME,
        self::FEATURE_FLAG,
    ];

    private function __construct()
    {
    }
}
