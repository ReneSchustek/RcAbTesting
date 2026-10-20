<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Consent;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Consent\ConfigurableConsentGate;
use Ruhrcoder\RcAbTesting\Service\Consent\ConsentCookie;
use Ruhrcoder\RcAbTesting\Service\Consent\DefaultConsentGate;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft das Einwilligungstor, das wahlweise einem eigenen Einwilligungs-Manager oder dem
 * Cookie-Banner von Shopware folgt. Öffnete es ohne Zustimmung, setzte jeder Shop den
 * Tracking-Cookie für ein Jahr, ohne gefragt zu haben.
 */
final class ConfigurableConsentGateTest extends TestCase
{
    /**
     * Ohne eigenen Einwilligungs-Manager, also im Auslieferungszustand, entscheidet der
     * Marker aus dem Shopware-Banner. Weil das Feld leer ausgeliefert wird, gilt dieser Fall
     * in jedem frisch installierten Shop; ein Rückfall auf „erlaubt, solange niemand
     * widerspricht“ setzte dort den Cookie ohne Zustimmung.
     */
    public function testWithoutOwnManagerTheShopwareBannerDecides(): void
    {
        $gate = new ConfigurableConsentGate($this->config('', ''), new DefaultConsentGate());

        self::assertFalse($gate->allowsTrackingCookie(new Request()));

        $granted = new Request();
        $granted->cookies->set(ConsentCookie::NAME, ConsentCookie::GRANTED_VALUE);
        self::assertTrue($gate->allowsTrackingCookie($granted));
    }

    public function testConfiguredRequiresMatchingConsentCookie(): void
    {
        $gate = new ConfigurableConsentGate($this->config('CookieConsent', 'yes'), new DefaultConsentGate());

        $granted = new Request();
        $granted->cookies->set('CookieConsent', 'yes');
        self::assertTrue($gate->allowsTrackingCookie($granted));

        $denied = new Request();
        $denied->cookies->set('CookieConsent', 'no');
        self::assertFalse($gate->allowsTrackingCookie($denied));

        self::assertFalse($gate->allowsTrackingCookie(new Request()), 'Ohne Consent-Cookie kein Tracking.');
    }

    /**
     * Ist nur der Cookie-Name gesetzt, gilt `1` als Zustimmung.
     */
    public function testConfiguredCookieNameWithDefaultGrantedValue(): void
    {
        $gate = new ConfigurableConsentGate($this->config('Consent', ''), new DefaultConsentGate());

        $granted = new Request();
        $granted->cookies->set('Consent', '1');
        self::assertTrue($gate->allowsTrackingCookie($granted));
    }

    private function config(string $cookieName, string $grantedValue): SystemConfigService
    {
        $config = $this->createMock(SystemConfigService::class);
        $config->method('getString')->willReturnMap([
            ['RcAbTesting.config.consentCookieName', null, $cookieName],
            ['RcAbTesting.config.consentGrantedValue', null, $grantedValue],
        ]);

        return $config;
    }
}
