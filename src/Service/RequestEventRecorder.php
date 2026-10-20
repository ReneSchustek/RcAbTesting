<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Das Bindeglied zwischen den Funnel-Subscribern und {@see EventTracker}: holt Besucher-Kennung
 * und Kunden aus dem Request und fängt jeden Fehler ab. Ein misslungenes Ereignis darf den
 * Storefront-Request nie abbrechen; an einer Stelle gefangen, braucht kein Subscriber eigenes
 * Fehlerhandling.
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

        // Keine Kennung heißt: Der Request kam über `/admin` oder `/api`, wo
        // `KernelRequestSubscriber` keine vergibt. Dort gibt es keinen Besucher zu zählen.
        $visitorId = $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE);
        if (!\is_string($visitorId)) {
            return;
        }

        // Die Kundenkennung nur mit Einwilligung, also nur bei dauerhafter Besucherkennung. Ohne sie stünde
        // das Ereignis über die Kundenkennung einem bestimmten Menschen zu, obwohl er der Messung nicht
        // zugestimmt hat.
        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        $customerId = $request->attributes->get(VisitorIdResolver::PERSISTENT_ATTRIBUTE) === true
            && $salesChannelContext instanceof SalesChannelContext
            ? $salesChannelContext->getCustomer()?->getId()
            : null;

        try {
            $this->eventTracker->track($eventType, $value, $meta, $visitorId, $context, $customerId);
        } catch (\Throwable $exception) {
            // `\Throwable` statt einzelner Ausnahmen: Das Zählen ist Nebensache, die Bestellung
            // oder Anmeldung, die das Ereignis ausgelöst hat, muss durchgehen.
            $this->logger->error('A/B-Tracking fehlgeschlagen für {eventType}', [
                'eventType' => $eventType,
                'exception' => $exception,
            ]);
        }
    }
}
