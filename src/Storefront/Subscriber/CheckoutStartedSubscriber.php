<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trackt `checkout.started`, wenn die Warenkorb-Seite geladen wird. Der
 * Warenkorbwert wandert als event_value mit, der Cart-Token als Korrelations-
 * Schlüssel für die Abbruch-Erkennung.
 */
final class CheckoutStartedSubscriber implements EventSubscriberInterface
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
        return [CheckoutCartPageLoadedEvent::class => 'onCartPageLoaded'];
    }

    public function onCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $cart = $event->getPage()->getCart();

        $this->recorder->record(
            AbEventType::CHECKOUT_STARTED,
            $cart->getPrice()->getTotalPrice(),
            ['cart_token' => $cart->getToken()],
            $event->getSalesChannelContext()->getContext(),
        );
    }
}
