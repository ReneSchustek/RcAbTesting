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
 * Nimmt eigene Ereignisse (`custom.*`) aus dem Storefront-JavaScript entgegen. Anonyme Besucher
 * dürfen melden, ein Login ist nicht nötig.
 *
 * Ein CSRF-Token gibt es nicht, weil die Storefront keinen CSRF-Schutz kennt. Fremde Seiten hält
 * `SameSite=Lax` am Besucher-Cookie ab: Ein seitenübergreifender POST kommt
 * ohne Cookie an, bekommt eine frische Kennung ohne Zuordnung, und der `EventTracker` schreibt
 * dann nichts. Die Angaben in `meta` landen ungeprüft in der Datenbank; begrenzt sind nur Größe
 * und Verschachtelung.
 *
 * `JsonResponse` direkt statt Controller-Helfer hält die Klasse ohne Kernel testbar.
 */
#[Route(defaults: ['_routeScope' => ['storefront'], 'XmlHttpRequest' => true])]
final class TrackingController
{
    /** Breite der Spalte `event_type` (VARCHAR(64)); ein längerer Typ scheitert sonst erst beim Schreiben. */
    private const MAX_EVENT_TYPE_LENGTH = 64;

    /**
     * Ein eigenes Ereignis besteht aus Typ, Zahl und ein paar Meta-Angaben, selten mehr als einige
     * hundert Byte. 8 KiB lassen reichlich Luft und begrenzen, was ein anonymer Aufrufer je Anfrage
     * in die JSON-Spalte `meta` schreiben kann.
     */
    private const MAX_CONTENT_BYTES = 8192;

    /**
     * Meta-Angaben sind flache Schlüssel-Wert-Paare. 16 Ebenen erlauben noch verschachtelte
     * Objekte und brechen das Parsen künstlich tiefer Strukturen früh ab; zu tiefes JSON liefert
     * null und endet als `invalid_event_type`.
     */
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

        // Gedrosselt wird allein nach Absender-Adresse. Die Besucherkennung taugt nicht als
        // Schlüssel, weil der Aufrufer sie bestimmt: Der Cookie ist ohne `httpOnly` lesbar, und ohne
        // Cookie vergibt der Resolver je Anfrage eine neue Kennung. Wer ihn weglässt oder wechselt,
        // bekäme bei jedem Aufruf einen frischen Zähler, und die Grenze griffe nie.
        //
        // Die Adresse ist bei geteilten Anschlüssen ungenau, aber der Aufrufer kann sie nicht frei
        // setzen. 60 Aufrufe je Minute reichen auch für ein Büro hinter einer Adresse.
        if ($this->rateLimiter->tooManyRequests((string) $request->getClientIp())) {
            return new JsonResponse(['ok' => false, 'reason' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $content = $request->getContent();
        if (\strlen($content) > self::MAX_CONTENT_BYTES) {
            return new JsonResponse([
                'ok' => false,
                'reason' => 'payload_too_large',
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $payload = json_decode($content, true, self::MAX_JSON_DEPTH);
        $eventType = \is_array($payload) ? ($payload['eventType'] ?? null) : null;
        // Nur `custom.*` aus dem Browser. Funnel- und Bestellereignisse schreiben die Abonnenten
        // auf dem Server; ließe der Endpunkt sie zu, könnte ein teilnehmender Besucher
        // Conversions und Umsatz fälschen.
        if (
            !\is_string($eventType) ||
            \strlen($eventType) > self::MAX_EVENT_TYPE_LENGTH ||
            !AbEventType::isClientTrackable($eventType)
        ) {
            return new JsonResponse(['ok' => false, 'reason' => 'invalid_event_type'], Response::HTTP_BAD_REQUEST);
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
            // Tracking ist ein Nebenpfad; ein Datenbankfehler soll beim Browser nicht als 500 ankommen.
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
