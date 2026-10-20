<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Consent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Consent\ConsentCookie;
use Ruhrcoder\RcAbTesting\Service\Consent\DefaultConsentGate;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft das Einwilligungstor ohne externen Einwilligungs-Manager. Weil die Konfiguration leer
 * ausgeliefert wird, entscheidet dieses Tor in jedem frisch installierten Shop. Ein Tor, das
 * nach Widerspruch statt nach Einwilligung fragt, setzte dort den Tracking-Cookie für ein Jahr
 * ohne Zustimmung.
 *
 * § 25 TDDDG knüpft an das Speichern auf dem Endgerät an, nicht an den Inhalt des Cookies. Ein
 * A/B-Test ist für den vom Nutzer gewünschten Dienst nicht unbedingt erforderlich, also braucht
 * er die Einwilligung.
 */
#[CoversClass(DefaultConsentGate::class)]
final class DefaultConsentGateTest extends TestCase
{
    private DefaultConsentGate $gate;

    protected function setUp(): void
    {
        $this->gate = new DefaultConsentGate();
    }

    /**
     * Der häufigste Fall: Wer die Seite zum ersten Mal öffnet und das Banner noch nicht
     * beantwortet hat, darf keinen Tracking-Cookie bekommen.
     */
    public function testWithoutAnyDecisionNothingIsAllowed(): void
    {
        self::assertFalse($this->gate->allowsTrackingCookie(new Request()));
    }

    /**
     * Die Gegenprobe: Ein Tor, das auch nach Zustimmung nicht öffnet, macht das Plugin wirkungslos.
     */
    public function testConsentGivenInTheBannerAllowsTheCookie(): void
    {
        $request = new Request();
        $request->cookies->set(ConsentCookie::NAME, ConsentCookie::GRANTED_VALUE);

        self::assertTrue($this->gate->allowsTrackingCookie($request));
    }

    /**
     * Shopware entfernt den Marker beim Abwählen. Steht trotzdem ein anderer Wert da, etwa von
     * Hand gesetzt, darf er nicht als Zustimmung durchgehen; eine Prüfung auf bloßes
     * Vorhandensein ließe ihn durch.
     */
    public function testAnotherValueDoesNotCountAsConsent(): void
    {
        $request = new Request();
        $request->cookies->set(ConsentCookie::NAME, '0');

        self::assertFalse($this->gate->allowsTrackingCookie($request));
    }
}
