<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

/**
 * Zulässige Werte für `rc_ab_experiment.test_type`.
 *
 * Eigene Auslieferung auf dem Server haben nur `cms_page` und `frontend_switch`. Twig, Theme und
 * Feature-Flag behandelt der Server gleich: Das Template liest die Variante über `ab_variant` oder
 * `ab_variant_config`, und der Typ ordnet das Experiment nur in der Verwaltung ein.
 *
 * Zeichenketten-Konstanten statt Enum: Die Werte laufen als Zeichenketten durch die Datenschicht, die
 * Verwaltungs-API und die Vorlagen; ein Enum müsste an jeder dieser Grenzen in seinen Wert und zurück verwandelt
 * werden.
 */
final class AbExperimentTestType
{
    public const TWIG = 'twig';
    public const THEME = 'theme';
    public const FEATURE_FLAG = 'feature_flag';

    /**
     * Ohne Template-Code: Jede Variante setzt einen registrierten Frontend-Schalter auf einen
     * Wert, etwa den Checkout auf „eine Seite“ oder „geführt“. Der Wert steht in
     * `config.<switchKey>`; ein Template liest ihn über `ab_switch`, ein fremdes Plugin bekommt
     * ihn über seinen Adapter. Siehe
     * {@see \Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver}.
     */
    public const FRONTEND_SWITCH = 'frontend_switch';

    /**
     * Ohne Template-Code: Jede Variante trägt in `config.cmsPageId` eine Erlebniswelt, und der
     * `CmsPageVariantSubscriber` liefert je Zuordnung die passende aus. Die Kontrollvariante trägt
     * die Seite, die ohne Test ausgeliefert würde; an ihr erkennt der Subscriber das Experiment.
     */
    public const CMS_PAGE = 'cms_page';

    /**
     * Schlüssel, unter dem eine CMS-Seiten-Variante ihre Zielseite in der Konfiguration ablegt.
     * Die Verwaltung schreibt ihn mit eigenem Literal `cmsPageId`; wer ihn hier ändert, muss ihn
     * dort mitziehen.
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
