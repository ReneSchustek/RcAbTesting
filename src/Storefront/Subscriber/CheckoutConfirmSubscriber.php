<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CurrencyNormalizer;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Zeichnet `checkout.confirm_viewed` auf, wenn die Bestellübersicht lädt, den letzten
 * Funnel-Schritt vor der Bestellung. Der Warenkorbwert geht als `event_value` mit, das
 * Warenkorb-Token zur Diagnose in `meta`.
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

        // In der Standardwährung, wie der Bestellwert ({@see OrderPlacedSubscriber}).
        $this->recorder->record(
            AbEventType::CHECKOUT_CONFIRM_VIEWED,
            CurrencyNormalizer::toDefaultCurrency(
                $cart->getPrice()->getTotalPrice(),
                $event->getSalesChannelContext()->getContext()->getCurrencyFactor(),
            ),
            ['cart_token' => $cart->getToken()],
            $event->getSalesChannelContext()->getContext(),
        );
    }
}
