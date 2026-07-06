<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trackt `checkout.order_placed` — das Conversion-Ziel des Funnels. Der
 * Bestellwert wandert als event_value mit, damit die Auswertung Umsatz je
 * Variante ausweisen kann.
 */
final class OrderPlacedSubscriber implements EventSubscriberInterface
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
        return [CheckoutOrderPlacedEvent::class => 'onOrderPlaced'];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();

        // Nur die Order-ID als Referenz; die Bestellnummer wäre eine zusätzliche,
        // hier unnötige Referenz (Datenminimierung).
        $this->recorder->record(
            AbEventType::CHECKOUT_ORDER_PLACED,
            $order->getAmountTotal(),
            ['order_id' => $order->getId()],
            $event->getContext(),
        );
    }
}
