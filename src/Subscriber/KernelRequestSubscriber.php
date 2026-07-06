<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Löst die Besucher-ID früh im Request-Lebenszyklus auf, damit nachgelagerte
 * Funnel-Subscriber und die Twig-Integration sie aus dem Request-Attribut lesen
 * können. Admin- und API-Requests bleiben unberührt (reiner Storefront-Belang).
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
        // Niedrige Priorität (4): läuft bewusst nach dem Shopware-Routing/
        // Context-Aufbau, damit der SalesChannelContext bereits vorliegt.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (\str_starts_with($path, '/admin') || \str_starts_with($path, '/api')) {
            return;
        }

        $this->visitorIdResolver->resolveForRequest($request);
    }
}
