<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

use Symfony\Component\HttpFoundation\Request;

/**
 * Default-Gate ohne externen Consent-Manager. Modell ist Opt-out: der
 * Besucher-Cookie trägt nur eine zufällige, PII-freie UUID, darf also gesetzt
 * werden, solange kein ausdrückliches Opt-out-Signal vorliegt. Ist
 * ComanConsentManager (oder ein anderer CMP) installiert, wird dieses Gate per
 * services.xml durch einen Adapter ersetzt, der die echte Einwilligung abfragt.
 */
final class DefaultConsentGate implements ConsentGate
{
    use OptOutCookieCheck;

    public function allowsTrackingCookie(Request $request): bool
    {
        return $this->isNotOptedOut($request);
    }
}
