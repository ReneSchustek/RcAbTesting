<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CurrencyNormalizer;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Zeichnet `checkout.started` auf, wenn die Warenkorb-Seite mit mindestens einer Position lädt.
 * Der Warenkorbwert geht in der Standardwährung als `event_value` mit, wie bei Bestätigung und Bestellung, das
 * Warenkorb-Token nur zur Diagnose in `meta`;
 * die Abbruch-Erkennung arbeitet über Besucher und Zeitpunkt, nicht über das Token.
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

        // Ein leerer Warenkorb ist kein begonnener Checkout. Anders als die Bestätigungsseite
        // rendert Shopware die Warenkorb-Seite auch ohne Position, und jeder Klick auf das
        // Warenkorb-Symbol zählte sonst als Checkout-Start mit 0,00 Euro. Die Trichterstufe
        // „Warenkorb → Checkout“ enthielte dann Besucher ohne Artikel, und die Abbruch-Erkennung
        // machte nach Ablauf der Frist aus jedem Leerklick einen abgebrochenen Warenkorb.
        if ($cart->getLineItems()->count() === 0) {
            return;
        }

        $this->recorder->record(
            AbEventType::CHECKOUT_STARTED,
            CurrencyNormalizer::toDefaultCurrency(
                $cart->getPrice()->getTotalPrice(),
                $event->getSalesChannelContext()->getContext()->getCurrencyFactor(),
            ),
            ['cart_token' => $cart->getToken()],
            $event->getSalesChannelContext()->getContext(),
        );
    }
}
