<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CheckoutConfirmSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;

final class CheckoutConfirmSubscriberTest extends FunnelSubscriberTestCase
{
    public function testTracksConfirmViewedWithCartValueAndToken(): void
    {
        (new CheckoutConfirmSubscriber($this->recorderWithAssignment()))->onConfirmPageLoaded($this->event());

        self::assertNotNull($this->capturedEvents);
        self::assertSame(AbEventType::CHECKOUT_CONFIRM_VIEWED, $this->capturedEvents[0]['eventType']);
        self::assertSame(49.5, $this->capturedEvents[0]['eventValue']);
        self::assertSame(['cart_token' => 'cart-token-2'], $this->capturedEvents[0]['meta']);
    }

    private function event(): CheckoutConfirmPageLoadedEvent
    {
        $price = $this->createMock(CartPrice::class);
        $price->method('getTotalPrice')->willReturn(49.5);

        $cart = $this->createMock(Cart::class);
        $cart->method('getPrice')->willReturn($price);
        $cart->method('getToken')->willReturn('cart-token-2');

        $page = $this->createMock(CheckoutConfirmPage::class);
        $page->method('getCart')->willReturn($cart);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $event = $this->createMock(CheckoutConfirmPageLoadedEvent::class);
        $event->method('getPage')->willReturn($page);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        return $event;
    }
}
