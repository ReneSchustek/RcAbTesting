<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Leitet die Geräteklasse (Desktop/Mobile/Tablet) aus dem User-Agent ab. Bewusst
 * eine schlanke Heuristik ohne externe Bibliothek — für die Segment-Auswertung
 * genügt die grobe Einordnung, und ein Geräte-Erkennungs-Paket wäre für diesen
 * Zweck überdimensioniert (Bibliotheks-Gate). Reine Funktion, ohne I/O testbar.
 */
final class DeviceClassResolver
{
    /**
     * Tablets zuerst prüfen: ein Android-Tablet trägt „Android", aber KEIN
     * „Mobile" (Phones tragen beides) — die negative Lookahead-Bedingung trennt
     * beide Fälle. iPad/Kindle/PlayBook sind eindeutige Tablet-Kennungen.
     */
    private const TABLET_PATTERN = '/iPad|Tablet|PlayBook|Silk|Kindle|(?:Android(?!.*Mobile))/i';

    private const MOBILE_PATTERN = '/Mobile|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini|webOS/i';

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
