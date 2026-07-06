<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Fail-safe-Brücke zwischen Funnel-Subscribern und dem EventTracker. Holt die
 * Besucher-ID aus dem aktuellen Request und kapselt das try/catch — ein
 * fehlerhaftes Tracking-Event darf den Storefront-Request niemals abreißen.
 * So bleiben die einzelnen Subscriber dünn und ohne dupliziertes Fehler-Handling.
 */
final class RequestEventRecorder
{
    public function __construct(
        private readonly EventTracker $eventTracker,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function record(string $eventType, ?float $value, array $meta, Context $context): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return;
        }

        $visitorId = $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE);
        if (!\is_string($visitorId)) {
            return;
        }

        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        $customerId = $salesChannelContext instanceof SalesChannelContext
            ? $salesChannelContext->getCustomer()?->getId()
            : null;

        try {
            $this->eventTracker->track($eventType, $value, $meta, $visitorId, $context, $customerId);
        } catch (\Throwable $exception) {
            // Bewusst breit gefangen und protokolliert: Tracking ist Nebenpfad,
            // ein Fehler hier darf die eigentliche Storefront-Aktion nicht stoppen.
            $this->logger->error('A/B-Tracking fehlgeschlagen für {eventType}', [
                'eventType' => $eventType,
                'exception' => $exception,
            ]);
        }
    }
}
