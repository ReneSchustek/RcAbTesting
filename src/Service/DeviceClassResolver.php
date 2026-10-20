<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Leitet die Geräteklasse aus dem User-Agent ab, mit zwei regulären Ausdrücken statt einer
 * Geräteerkennungs-Bibliothek. Die Segment-Auswertung braucht nur drei Klassen; eine Bibliothek
 * mit Gerätedatenbank brächte Pflegeaufwand für Unterscheidungen, die niemand auswertet.
 *
 * Grenze der Heuristik: iPads ab iPadOS 13 melden sich standardmäßig wie Safari auf dem Mac und
 * landen deshalb bei Desktop.
 */
final class DeviceClassResolver
{
    /**
     * Wird vor dem Telefon-Muster geprüft, weil Tablets oft auch auf das Telefon-Muster passen.
     * Ein Android-Tablet trägt „Android", aber kein „Mobile", ein Android-Telefon trägt beides;
     * das negative Lookahead trennt die zwei. Silk ist der Browser der Kindle-Fire-Tablets.
     */
    private const TABLET_PATTERN = '/iPad|Tablet|PlayBook|Silk|Kindle|(?:Android(?!.*Mobile))/i';

    private const MOBILE_PATTERN = '/Mobile|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|webOS/i';

    /**
     * Ohne verwertbaren User-Agent `unknown` statt einer geratenen Klasse, damit solche Zuordnungen
     * kein echtes Segment verfälschen.
     */
    public function resolve(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return AbDeviceClass::UNKNOWN;
        }

        if (preg_match(self::TABLET_PATTERN, $userAgent) === 1) {
            return AbDeviceClass::TABLET;
        }

        if (preg_match(self::MOBILE_PATTERN, $userAgent) === 1) {
            return AbDeviceClass::MOBILE;
        }

        return AbDeviceClass::DESKTOP;
    }
}
