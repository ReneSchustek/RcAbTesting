<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CheckoutStartedSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;

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

    private function event(): CheckoutCartPageLoadedEvent
    {
        $price = $this->createMock(CartPrice::class);
        $price->method('getTotalPrice')->willReturn(99.9);

        $cart = $this->createMock(Cart::class);
        $cart->method('getPrice')->willReturn($price);
        $cart->method('getToken')->willReturn('cart-token-1');

        $page = $this->createMock(CheckoutCartPage::class);
        $page->method('getCart')->willReturn($cart);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $event = $this->createMock(CheckoutCartPageLoadedEvent::class);
        $event->method('getPage')->willReturn($page);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        return $event;
    }
}
