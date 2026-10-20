<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Geräteklassen für die Segment-Auswertung, gespeichert in `rc_ab_assignment.device`.
 * Drei Klassen plus „unbekannt" reichen, weil Ergebnisse sich zwischen Rechner, Telefon und
 * Tablet unterscheiden; feinere Gerätedetails zerlegten die Stichprobe nur in Segmente, die
 * nie eine Aussage tragen.
 *
 * Wie {@see AbEventType} eine Klasse mit Zeichenketten-Konstanten statt eines Enums: Die Werte
 * kommen als Zeichenkette aus der Datenbank und gehen so in SQL und JSON, ohne Umwandlung.
 */
final class AbDeviceClass
{
    public const DESKTOP = 'desktop';
    public const MOBILE = 'mobile';
    public const TABLET = 'tablet';

    /** Fehlender oder leerer User-Agent; auch der Segmentwert für Zuordnungen ohne gespeichertes Gerät. */
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
