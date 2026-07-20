<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Controller\Storefront;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Service\TrackRateLimiter;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront-Endpunkt für Custom-Event-Tracking aus dem Browser. Bewusst ohne
 * CSRF-Token (Shopware 6.5+ hat den Storefront-CSRF-Mechanismus entfernt) —
 * geschützt durch SameSite=Lax des Besucher-Cookies; getrackt werden ohnehin
 * nur PII-freie Funnel-Events. Anonyme Besucher dürfen tracken (kein Login).
 * Direktes JsonResponse hält den Controller ohne Kernel testbar.
 */
#[Route(defaults: ['_routeScope' => ['storefront'], 'XmlHttpRequest' => true])]
final class TrackingController
{
    /** Spiegelt die VARCHAR(64)-Spaltenbreite von `event_type`. */
    private const MAX_EVENT_TYPE_LENGTH = 64;

    /** Obergrenze für den Roh-Body eines Track-Calls (öffentlicher, unauth. Endpunkt). */
    private const MAX_CONTENT_BYTES = 8192;

    /** JSON-Tiefenlimit gegen tief verschachtelte Meta-Payloads. */
    private const MAX_JSON_DEPTH = 16;

    public function __construct(
        private readonly EventTracker $eventTracker,
        private readonly LoggerInterface $logger,
        private readonly TrackRateLimiter $rateLimiter,
    ) {
    }

    #[Route(path: '/rc-ab-testing/track', name: 'frontend.rcabtesting.track', methods: ['POST'])]
    public function track(Request $request, SalesChannelContext $context): JsonResponse
    {
        $visitorId = $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE);
        if (!\is_string($visitorId)) {
            return new JsonResponse(['ok' => false, 'reason' => 'no_visitor']);
        }

        // Weiche Drosselung des anonymen Endpunkts gegen Flooding (Arbeitspaket AB41).
        if ($this->rateLimiter->tooManyRequests($visitorId . '|' . ($request->getClientIp() ?? ''))) {
            return new JsonResponse(['ok' => false, 'reason' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $content = $request->getContent();
        if (\strlen($content) > self::MAX_CONTENT_BYTES) {
            return new JsonResponse(['ok' => false, 'reason' => 'payload_too_large']);
        }

        $payload = json_decode($content, true, self::MAX_JSON_DEPTH);
        $eventType = \is_array($payload) ? ($payload['eventType'] ?? null) : null;
        // Nur `custom.*` aus dem Browser: Funnel-/Order-Events kommen serverseitig
        // aus den Subscribern. Würde der Endpunkt sie zulassen, könnte ein
        // teilnehmender Besucher Conversions und Umsatz fälschen (Arbeitspaket AB33).
        if (!\is_string($eventType) || \strlen($eventType) > self::MAX_EVENT_TYPE_LENGTH || !AbEventType::isClientTrackable($eventType)) {
            return new JsonResponse(['ok' => false, 'reason' => 'invalid_event_type']);
        }

        try {
            $this->eventTracker->track(
                $eventType,
                $this->readValue($payload),
                $this->readMeta($payload),
                $visitorId,
                $context->getContext(),
                $context->getCustomer()?->getId(),
            );
        } catch (\Throwable $exception) {
            // Tracking ist Nebenpfad: ein DAL-/DB-Fehler darf den Browser nicht mit 500 treffen.
            $this->logger->error('A/B-Track-Endpunkt fehlgeschlagen für {eventType}', [
                'eventType' => $eventType,
                'exception' => $exception,
            ]);

            return new JsonResponse(['ok' => false, 'reason' => 'tracking_failed']);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function readValue(mixed $payload): ?float
    {
        if (!\is_array($payload) || !isset($payload['value']) || !\is_numeric($payload['value'])) {
            return null;
        }

        return (float) $payload['value'];
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(mixed $payload): array
    {
        if (!\is_array($payload) || !\is_array($payload['meta'] ?? null)) {
            return [];
        }

        /** @var array<string, mixed> $meta */
        $meta = $payload['meta'];

        return $meta;
    }
}
