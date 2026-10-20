<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CheckoutStartedSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItemCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;

/**
 * Prüft das Ereignis `checkout.started` beim Aufruf der Warenkorbseite. Es ist der Startpunkt
 * der Abbruch-Erkennung; jedes falsche Ereignis wird dort nach der Frist zu einem gemeldeten
 * Abbruch.
 */
final class CheckoutStartedSubscriberTest extends FunnelSubscriberTestCase
{
    public function testTracksCheckoutStartedWithCartValueAndToken(): void
    {
        (new CheckoutStartedSubscriber($this->recorderWithAssignment()))->onCartPageLoaded($this->event());

        self::assertNotNull($this->capturedEvents);
        self::assertSame(AbEventType::CHECKOUT_STARTED, $this->capturedEvents[0]['eventType']);
        self::assertSame(99.9, $this->capturedEvents[0]['eventValue']);
        self::assertSame(['cart_token' => 'cart-token-1'], $this->capturedEvents[0]['meta']);
    }

    /**
     * Ein Warenkorb in einer Fremdwährung (Faktor 2) zählt in der Standardwährung, sonst summierte die
     * Umsatzauswertung Beträge verschiedener Währungen.
     */
    public function testTheCartValueIsConvertedToTheDefaultCurrency(): void
    {
        (new CheckoutStartedSubscriber($this->recorderWithAssignment()))->onCartPageLoaded($this->event(1, 2.0));

        self::assertNotNull($this->capturedEvents);
        self::assertSame(49.95, $this->capturedEvents[0]['eventValue']);
    }

    /**
     * Anders als die Bestätigungsseite rendert Shopware die Warenkorbseite auch ohne Position.
     * Zählte jeder Klick aufs Warenkorb-Symbol als Checkout-Start mit 0,00 Euro, enthielte die
     * Trichterstufe Besucher ohne einen einzigen Artikel, und die Abbruch-Erkennung machte aus
     * jedem Leerklick nach der Standardfrist von 30 Minuten einen erfundenen Abbruch.
     */
    public function testAnEmptyCartIsNoStartedCheckout(): void
    {
        (new CheckoutStartedSubscriber($this->recorderWithAssignment()))
            ->onCartPageLoaded($this->event(0));

        self::assertNull($this->capturedEvents);
    }

    private function event(int $lineItemCount = 1, float $currencyFactor = 1.0): CheckoutCartPageLoadedEvent
    {
        $price = $this->createMock(CartPrice::class);
        $price->method('getTotalPrice')->willReturn(99.9);

        $lineItems = $this->createMock(LineItemCollection::class);
        $lineItems->method('count')->willReturn($lineItemCount);

        $cart = $this->createMock(Cart::class);
        $cart->method('getPrice')->willReturn($price);
        $cart->method('getToken')->willReturn('cart-token-1');
        $cart->method('getLineItems')->willReturn($lineItems);

        $page = $this->createMock(CheckoutCartPage::class);
        $page->method('getCart')->willReturn($cart);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(new Context(
            new SystemSource(),
            [],
            Defaults::CURRENCY,
            [Defaults::LANGUAGE_SYSTEM],
            Defaults::LIVE_VERSION,
            $currencyFactor,
        ));

        $event = $this->createMock(CheckoutCartPageLoadedEvent::class);
        $event->method('getPage')->willReturn($page);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        return $event;
    }
}
