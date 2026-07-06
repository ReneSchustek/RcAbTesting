<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trackt `checkout.confirm_viewed`, wenn die Bestellübersicht geladen wird —
 * der letzte Funnel-Schritt vor der Bestellung. Hohe Abbruchquote zwischen
 * diesem Schritt und `checkout.order_placed` ist ein starkes Test-Signal.
 */
final class CheckoutConfirmSubscriber implements EventSubscriberInterface
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
        return [CheckoutConfirmPageLoadedEvent::class => 'onConfirmPageLoaded'];
    }

    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $cart = $event->getPage()->getCart();

        $this->recorder->record(
            AbEventType::CHECKOUT_CONFIRM_VIEWED,
            $cart->getPrice()->getTotalPrice(),
            ['cart_token' => $cart->getToken()],
            $event->getSalesChannelContext()->getContext(),
        );
    }
}
