<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Consent-Gate, das einen beliebigen Consent-Manager (z.B. ComanConsentManager)
 * generisch abbildet: ist in der Konfiguration ein Consent-Cookie hinterlegt,
 * wird der Tracking-Cookie nur bei ausdrücklicher Einwilligung gesetzt
 * (Opt-in). Ohne Konfiguration fällt das Gate auf das Opt-out-Verhalten zurück
 * (PII-freie Zufalls-ID, erlaubt solange kein Opt-out-Signal vorliegt). So bleibt
 * das Plugin frei vom konkreten CMP — der Admin verdrahtet nur Cookie-Name und
 * Granted-Wert.
 */
final class ConfigurableConsentGate implements ConsentGate
{
    use OptOutCookieCheck;

    private const COOKIE_NAME_KEY = 'RcAbTesting.config.consentCookieName';
    private const GRANTED_VALUE_KEY = 'RcAbTesting.config.consentGrantedValue';
    private const DEFAULT_GRANTED_VALUE = '1';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function allowsTrackingCookie(Request $request): bool
    {
        $cookieName = trim($this->systemConfig->getString(self::COOKIE_NAME_KEY));
        if ($cookieName === '') {
            return $this->isNotOptedOut($request);
        }

        $grantedValue = $this->systemConfig->getString(self::GRANTED_VALUE_KEY);
        if ($grantedValue === '') {
            $grantedValue = self::DEFAULT_GRANTED_VALUE;
        }

        return $request->cookies->get($cookieName) === $grantedValue;
    }
}
