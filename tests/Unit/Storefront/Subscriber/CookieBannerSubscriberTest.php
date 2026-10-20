<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Consent\ConsentCookie;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CookieBannerSubscriber;
use Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent;
use Shopware\Core\Content\Cookie\Struct\CookieGroup;
use Shopware\Core\Content\Cookie\Struct\CookieGroupCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft den Eintrag des A/B-Tests im Cookie-Banner. Fehlte er oder trüge er keinen Wert, schriebe Shopware beim
 * Zustimmen keinen Marker, und das Einwilligungs-Tor fände nie eine Zustimmung.
 */
final class CookieBannerSubscriberTest extends TestCase
{
    public function testListensToTheCollectEvent(): void
    {
        self::assertSame(
            [CookieGroupCollectEvent::class => 'onCollectCookieGroups'],
            CookieBannerSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * Die Gruppe ist abwählbar und meldet den Marker samt Wert; den Besucher-Cookie selbst vergibt der Server erst
     * nach der Einwilligung.
     */
    public function testAddsAnOptionalGroupWithTheConsentMarker(): void
    {
        $event = new CookieGroupCollectEvent(
            new CookieGroupCollection(),
            new Request(),
            $this->createStub(SalesChannelContext::class),
        );

        (new CookieBannerSubscriber())->onCollectCookieGroups($event);

        self::assertCount(1, $event->cookieGroupCollection);
        $group = $event->cookieGroupCollection->first();
        self::assertInstanceOf(CookieGroup::class, $group);
        self::assertFalse($group->isRequired);
        self::assertSame(ConsentCookie::NAME, $group->getCookie());
        self::assertSame(ConsentCookie::GRANTED_VALUE, $group->value);
        self::assertSame(365, $group->expiration);
        self::assertSame('rc-ab-testing.cookie.description', $group->description);
    }
}
