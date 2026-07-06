<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Consent;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Consent\ConfigurableConsentGate;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;

final class ConfigurableConsentGateTest extends TestCase
{
    public function testUnconfiguredFallsBackToOptOut(): void
    {
        $gate = new ConfigurableConsentGate($this->config('', ''));

        self::assertTrue($gate->allowsTrackingCookie(new Request()));

        $optedOut = new Request();
        $optedOut->cookies->set('rc_ab_opt_out', 'true');
        self::assertFalse($gate->allowsTrackingCookie($optedOut));
    }

    public function testConfiguredRequiresMatchingConsentCookie(): void
    {
        $gate = new ConfigurableConsentGate($this->config('CookieConsent', 'yes'));

        $granted = new Request();
        $granted->cookies->set('CookieConsent', 'yes');
        self::assertTrue($gate->allowsTrackingCookie($granted));

        $denied = new Request();
        $denied->cookies->set('CookieConsent', 'no');
        self::assertFalse($gate->allowsTrackingCookie($denied));

        self::assertFalse($gate->allowsTrackingCookie(new Request()), 'Ohne Consent-Cookie kein Tracking.');
    }

    public function testConfiguredCookieNameWithDefaultGrantedValue(): void
    {
        $gate = new ConfigurableConsentGate($this->config('Consent', ''));

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
