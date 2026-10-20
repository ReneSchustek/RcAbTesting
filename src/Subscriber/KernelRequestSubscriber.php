<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Legt die Besucherkennung früh im Request ab, damit Funnel-Abonnenten, Twig-Funktionen und der
 * Track-Endpunkt sie aus dem Request-Attribut lesen können. Nur Routen der Storefront bekommen eine.
 * Entschiede der Pfad, blieben `/admin` und `/api` außen vor, `/store-api` aber nicht, und jeder
 * Aufruf einer App oder eines Headless-Frontends zählte als eigener Besucher.
 */
final class KernelRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly VisitorIdResolver $visitorIdResolver,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Priorität 4 liegt hinter dem Router von Symfony (32). Den `SalesChannelContext` gibt es
        // hier noch nicht, Shopware baut ihn erst im Controller-Ereignis auf; die Kennung braucht
        // ihn auch nicht, sie liest nur Cookies und die Einwilligung.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Der Router (Priorität 32) hat die Vorgaben der Route schon in die Attribute gelegt.
        $request = $event->getRequest();
        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, []);
        if (!\is_array($scopes) || !\in_array(StorefrontRouteScope::ID, $scopes, true)) {
            return;
        }

        $this->visitorIdResolver->resolveForRequest($request);
    }
}
