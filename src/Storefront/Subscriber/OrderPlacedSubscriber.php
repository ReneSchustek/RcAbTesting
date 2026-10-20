<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CurrencyNormalizer;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Zeichnet `checkout.order_placed` auf, die Conversion, wenn ein Experiment keine andere
 * Primär-Metrik setzt. Der Bestellwert geht als `event_value` mit, damit die Auswertung Umsatz je
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

        // Der Betrag geht in der Standardwährung des Shops ein. In der Währung des Kunden zählte
        // eine Bestellung über 100 Franken wie 100 Euro, und die Umsatzauswertung mischte Währungen.
        //
        // Nur die Bestell-ID als Bezug; die Bestellnummer wäre ein zweiter, hier unnötiger Bezug
        // auf dieselbe Bestellung (Datenminimierung).
        $this->recorder->record(
            AbEventType::CHECKOUT_ORDER_PLACED,
            CurrencyNormalizer::toDefaultCurrency($order->getAmountTotal(), $order->getCurrencyFactor()),
            ['order_id' => $order->getId()],
            $event->getContext(),
        );
    }
}
