<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Trackt `page.viewed` für ausgelieferte HTML-Storefront-Seiten. Nur echte
 * Seitenaufrufe zählen: GET, Status 200, Content-Type text/html und kein
 * `/admin`- oder `/api`-Pfad — so bleiben Assets, JSON-Antworten und die
 * Backend-Bereiche außen vor, damit der Funnel-Zähler nicht verwässert.
 */
final class PageViewedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestEventRecorder $recorder,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$this->isTrackablePageView($request, $response->getStatusCode(), (string) $response->headers->get('Content-Type'))) {
            return;
        }

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$salesChannelContext instanceof SalesChannelContext) {
            return;
        }

        $this->recorder->record(
            AbEventType::PAGE_VIEWED,
            null,
            ['route' => $request->getPathInfo(), 'route_name' => (string) $request->attributes->get('_route')],
            $salesChannelContext->getContext(),
        );
    }

    private function isTrackablePageView(Request $request, int $statusCode, string $contentType): bool
    {
        if ($request->getMethod() !== Request::METHOD_GET || $statusCode !== 200) {
            return false;
        }

        if (!\str_contains($contentType, 'text/html')) {
            return false;
        }

        $path = $request->getPathInfo();

        return !\str_starts_with($path, '/admin') && !\str_starts_with($path, '/api');
    }
}
