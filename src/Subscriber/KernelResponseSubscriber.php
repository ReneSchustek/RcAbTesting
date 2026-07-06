<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Setzt am Ende des Request-Lebenszyklus den Besucher-Cookie, falls in diesem
 * Request eine neue ID erzeugt wurde und die Einwilligung vorliegt. Gegenstück
 * zu KernelRequestSubscriber; Admin/API bleiben außen vor.
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
        // Niedrige Priorität: nach allen anderen Response-Listenern.
        return [KernelEvents::RESPONSE => ['onResponse', -1024]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (\str_starts_with($path, '/admin') || \str_starts_with($path, '/api')) {
            return;
        }

        $this->visitorIdResolver->writeCookieIfNeeded($request, $event->getResponse());
    }
}
