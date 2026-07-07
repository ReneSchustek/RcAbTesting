<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Geräteklassen für die Segment-Auswertung. Bewusst grob (drei Klassen plus
 * „unbekannt"), weil A/B-Entscheidungen sich nach Desktop/Mobile/Tablet
 * unterscheiden, feinere Geräte-Details für die Auswertung aber irrelevant sind.
 * Analog zu {@see AbEventType} eine Konstanten-Klasse, kein Enum.
 */
final class AbDeviceClass
{
    public const DESKTOP = 'desktop';
    public const MOBILE = 'mobile';
    public const TABLET = 'tablet';

    /** Kein/leerer User-Agent (z. B. Zuordnung ohne HTTP-Request). */
    public const UNKNOWN = 'unknown';

    /** @var list<string> */
    public const VALUES = [
        self::DESKTOP,
        self::MOBILE,
        self::TABLET,
        self::UNKNOWN,
    ];

    private function __construct()
    {
    }
}
