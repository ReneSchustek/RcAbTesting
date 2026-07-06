<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

use Symfony\Component\HttpFoundation\Request;

/**
 * Entscheidet, ob für den aktuellen Request ein persistenter Besucher-Cookie
 * gesetzt werden darf. Entkoppelt das Plugin vom konkreten Consent-Manager:
 * eine Adapter-Implementierung kann ComanConsentManager o.ä. abfragen, ohne
 * dass der Innenring eine harte Abhängigkeit auf ein optionales Plugin bekommt.
 */
interface ConsentGate
{
    /**
     * True, wenn Marketing-/Analyse-Cookies erlaubt sind. Bei Ablehnung läuft
     * der A/B-Test nur in-memory (kein Sticky-Bucketing, keine Persistenz).
     */
    public function allowsTrackingCookie(Request $request): bool;
}
