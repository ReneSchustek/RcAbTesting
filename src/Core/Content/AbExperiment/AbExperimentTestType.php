<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

/**
 * Zulässige Werte für `rc_ab_experiment.test_type`. Bestimmt, welcher
 * Mechanismus die Variante anwendet (Twig-Markup, Theme-Override, Feature-Flag,
 * CMS-Seiten-Auslieferung).
 */
final class AbExperimentTestType
{
    public const TWIG = 'twig';
    public const THEME = 'theme';
    public const FEATURE_FLAG = 'feature_flag';

    /**
     * No-Code-Variante: pro Variante wird ein registrierter Frontend-Schalter auf
     * einen Wert gesetzt (z. B. Checkout „eine Seite" vs. „gefuehrt"). Der Wert
     * landet in `config.<switchKey>`; das konsumierende Plugin liest ihn ueber
     * `ab_variant_config`. Siehe {@see \Ruhrcoder\RcAbTesting\Service\FrontendSwitch}.
     */
    public const FRONTEND_SWITCH = 'frontend_switch';

    /**
     * No-Code-Variante: jede Variante traegt in `config.cmsPageId` eine CMS-Seite
     * (Erlebniswelt). Der CmsPageVariantSubscriber liefert je Zuweisung die
     * passende Seite aus. Kein Twig/Theme noetig.
     */
    public const CMS_PAGE = 'cms_page';

    /**
     * Config-Schluessel, unter dem eine CMS-Seiten-Variante ihre Ziel-CMS-Seite
     * ablegt. Bewusst hier als eine Quelle der Wahrheit (Admin schreibt, Backend
     * liest denselben Schluessel).
     */
    public const CMS_PAGE_CONFIG_KEY = 'cmsPageId';

    /** @var list<string> */
    public const VALUES = [
        self::TWIG,
        self::THEME,
        self::FEATURE_FLAG,
        self::CMS_PAGE,
        self::FRONTEND_SWITCH,
    ];

    private function __construct()
    {
    }
}
