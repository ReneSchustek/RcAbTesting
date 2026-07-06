<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\OrderPlacedSubscriber;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

final class OrderPlacedSubscriberTest extends FunnelSubscriberTestCase
{
    public function testTracksOrderPlacedWithTotalAndMeta(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn('order-1');
        $order->method('getAmountTotal')->willReturn(99.9);
        $event = $this->createMock(CheckoutOrderPlacedEvent::class);
        $event->method('getOrder')->willReturn($order);
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        (new OrderPlacedSubscriber($this->recorderWithAssignment()))->onOrderPlaced($event);

        self::assertNotNull($this->capturedEvents);
        self::assertSame(AbEventType::CHECKOUT_ORDER_PLACED, $this->capturedEvents[0]['eventType']);
        self::assertSame(99.9, $this->capturedEvents[0]['eventValue']);
        self::assertSame(['order_id' => 'order-1'], $this->capturedEvents[0]['meta']);
    }

    public function testSubscribesToOrderPlacedEvent(): void
    {
        self::assertArrayHasKey(CheckoutOrderPlacedEvent::class, OrderPlacedSubscriber::getSubscribedEvents());
    }
}
