<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

use Symfony\Component\HttpFoundation\Request;

/**
 * Das Tor ohne externen Einwilligungs-Manager: Teilnahme nur mit Einwilligung über den Marker
 * aus Shopwares Cookie-Banner ({@see ConsentCookie}).
 *
 * Ein Widerspruchsmodell („erlaubt, bis jemand ablehnt“) scheidet aus, auch wenn der Cookie nur
 * eine zufällige Kennung ohne Personenbezug trägt. § 25 TDDDG knüpft an das Speichern auf dem
 * Endgerät an, nicht an den Inhalt; ohne Einwilligung erlaubt ist nur, was für den gewünschten
 * Dienst unbedingt nötig ist, und ein A/B-Test ist das nicht.
 *
 * Ohne Marker kein Cookie, und ohne Cookie nimmt der Besucher nicht teil und sieht die
 * Kontrollfassung.
 */
final class DefaultConsentGate implements ConsentGate
{
    public function allowsTrackingCookie(Request $request): bool
    {
        return $request->cookies->get(ConsentCookie::NAME) === ConsentCookie::GRANTED_VALUE;
    }
}
