<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bindet einen beliebigen Einwilligungs-Manager an: Ist in der Konfiguration ein Cookie
 * hinterlegt, gilt dessen erwarteter Wert als Einwilligung. Ohne Konfiguration entscheidet der
 * Marker aus Shopwares eigenem Cookie-Banner über {@see DefaultConsentGate}.
 *
 * In beiden Fällen braucht es eine Einwilligung. Das Feld für den Cookie-Namen ist im
 * Auslieferungszustand leer; fiele dieser Zweig auf „erlaubt, solange niemand widerspricht“
 * zurück, setzte jede frische Installation den Jahres-Cookie ohne Zustimmung.
 *
 * Verdrahtet werden nur Cookie-Name und Wert, damit das Plugin keinen bestimmten Manager kennen
 * muss.
 */
final class ConfigurableConsentGate implements ConsentGate
{
    private const COOKIE_NAME_KEY = 'RcAbTesting.config.consentCookieName';
    private const GRANTED_VALUE_KEY = 'RcAbTesting.config.consentGrantedValue';
    private const DEFAULT_GRANTED_VALUE = '1';

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly DefaultConsentGate $defaultGate,
    ) {
    }

    public function allowsTrackingCookie(Request $request): bool
    {
        $cookieName = trim($this->systemConfig->getString(self::COOKIE_NAME_KEY));
        if ($cookieName === '') {
            // Ohne eigenen Einwilligungs-Manager gilt der Marker aus Shopwares Cookie-Banner.
            // Dieser Zweig ist der Auslieferungszustand und darf deshalb nie großzügiger sein
            // als der konfigurierte.
            return $this->defaultGate->allowsTrackingCookie($request);
        }

        // Ein leerer Sollwert ließe nur einen leeren Cookie als Einwilligung gelten, also praktisch keinen.
        // Ersatzweise gilt `1`, derselbe Wert wie bei Shopwares eigenem Marker ({@see ConsentCookie}).
        $grantedValue = $this->systemConfig->getString(self::GRANTED_VALUE_KEY);
        if ($grantedValue === '') {
            $grantedValue = self::DEFAULT_GRANTED_VALUE;
        }

        return $request->cookies->get($cookieName) === $grantedValue;
    }
}
