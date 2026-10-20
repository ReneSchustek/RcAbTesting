<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\Consent\ConsentCookie;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent;
use Shopware\Core\Content\Cookie\Struct\CookieGroup;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Meldet den A/B-Test beim Cookie-Banner der Storefront an.
 *
 * Ein A/B-Cookie ist im Sinne von § 25 TDDDG nicht unbedingt erforderlich und braucht eine
 * Einwilligung. Über diesen Eintrag sieht der Besucher ihn im Banner; Shopware setzt beim Zustimmen
 * den Einwilligungs-Marker ({@see ConsentCookie}) und entfernt ihn beim Abwählen wieder. Den
 * Besucher-Cookie `rc_ab_visitor_id` löscht danach die nächste Antwort des Servers
 * ({@see VisitorIdResolver::REVOKED_ATTRIBUTE}).
 *
 * Gemeldet wird der Marker, nicht die Besucherkennung. Die Kennung vergibt der Server, sobald
 * eingewilligt ist; der Marker sagt nur, ob eingewilligt wurde. Stünde die Kennung im Banner,
 * vergäbe das Banner sie.
 *
 * Die Gruppe kommt über `CookieGroupCollectEvent`, weil der Kern das `CookieProviderInterface`
 * als veraltet führt und es mit 6.8 streicht.
 */
final class CookieBannerSubscriber implements EventSubscriberInterface
{
    private const SNIPPET_GROUP = 'rc-ab-testing.cookie.group';
    private const SNIPPET_DESCRIPTION = 'rc-ab-testing.cookie.description';

    /** Ein Jahr, so lange wie der Besucher-Cookie selbst lebt. */
    private const EXPIRATION_DAYS = 365;

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [CookieGroupCollectEvent::class => 'onCollectCookieGroups'];
    }

    public function onCollectCookieGroups(CookieGroupCollectEvent $event): void
    {
        $group = new CookieGroup(self::SNIPPET_GROUP);
        $group->description = self::SNIPPET_DESCRIPTION;
        $group->isRequired = false;

        // Mit `value` schreibt Shopware den Marker beim Zustimmen selbst und entfernt ihn beim
        // Abwählen. Ohne Wert gäbe es keinen Marker, und das Einwilligungs-Tor fände nie eine
        // Zustimmung.
        $group->setCookie(ConsentCookie::NAME);
        $group->value = ConsentCookie::GRANTED_VALUE;
        $group->expiration = self::EXPIRATION_DAYS;

        $event->cookieGroupCollection->add($group);
    }
}
