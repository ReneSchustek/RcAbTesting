<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\LineItemAddedSubscriber;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class LineItemAddedSubscriberTest extends FunnelSubscriberTestCase
{
    public function testTracksProductLineItem(): void
    {
        $product = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'prod-1', 2);
        $event = $this->event($product);

        (new LineItemAddedSubscriber($this->recorderWithAssignment()))->onLineItemAdded($event);

        self::assertNotNull($this->capturedEvents);
        self::assertSame(AbEventType::PRODUCT_ADDED_TO_CART, $this->capturedEvents[0]['eventType']);
        self::assertSame(2.0, $this->capturedEvents[0]['eventValue']);
        self::assertSame(['product_id' => 'prod-1', 'line_item_id' => 'li-1'], $this->capturedEvents[0]['meta']);
    }

    public function testSkipsNonProductLineItem(): void
    {
        $promotion = new LineItem('li-2', LineItem::PROMOTION_LINE_ITEM_TYPE, 'promo-1', 1);
        $event = $this->event($promotion);

        (new LineItemAddedSubscriber($this->recorderWithAssignment()))->onLineItemAdded($event);

        self::assertNull($this->capturedEvents);
    }

    private function event(LineItem ...$lineItems): AfterLineItemAddedEvent
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $event = $this->createMock(AfterLineItemAddedEvent::class);
        $event->method('getLineItems')->willReturn($lineItems);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        return $event;
    }
}
