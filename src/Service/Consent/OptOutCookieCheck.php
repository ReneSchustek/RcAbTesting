<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

use Symfony\Component\HttpFoundation\Request;

/**
 * Gemeinsame Opt-out-Cookie-Prüfung für die Consent-Gates. Ein bekanntes
 * Opt-out-Cookie mit Wert `1`/`true`/`denied` signalisiert eine ausdrückliche
 * Ablehnung. Als Trait, damit Default- und Configurable-Gate dieselbe Regel
 * teilen (eine Quelle der Wahrheit statt Duplikat je Gate).
 */
trait OptOutCookieCheck
{
    /** Bekannte Opt-out-Cookies, die ein „nein zu Analyse-Cookies" signalisieren. */
    private const OPT_OUT_COOKIES = ['rc_ab_opt_out'];

    /** Werte, die als ausdrückliche Ablehnung zählen. */
    private const OPT_OUT_VALUES = ['1', 'true', 'denied'];

    private function isNotOptedOut(Request $request): bool
    {
        foreach (self::OPT_OUT_COOKIES as $name) {
            $value = $request->cookies->get($name);
            if ($value !== null && \in_array(\strtolower($value), self::OPT_OUT_VALUES, true)) {
                return false;
            }
        }

        return true;
    }
}
