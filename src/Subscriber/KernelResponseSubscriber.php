<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Setzt am Ende des Requests den Besucher-Cookie, wenn die Kennung in diesem Request neu entstanden
 * ist und die Einwilligung vorliegt. Gegenstück zum `KernelRequestSubscriber` und wie er nur für Routen der
 * Storefront.
 */
final class KernelResponseSubscriber implements EventSubscriberInterface
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
        // Sehr spät, damit ein Listener, der die Antwort austauscht, das vor dem Cookie tut und ihn
        // nicht mit der alten Antwort verwirft.
        return [KernelEvents::RESPONSE => ['onResponse', -1024]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $scopes = $request->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, []);
        if (!\is_array($scopes) || !\in_array(StorefrontRouteScope::ID, $scopes, true)) {
            return;
        }

        $this->visitorIdResolver->writeCookieIfNeeded($request, $event->getResponse());
    }
}
