<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\LineItemRemovedSubscriber;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class LineItemRemovedSubscriberTest extends FunnelSubscriberTestCase
{
    public function testTracksRemovedProductLineItem(): void
    {
        $product = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, 'prod-1', 1);
        $event = $this->event($product);

        (new LineItemRemovedSubscriber($this->recorderWithAssignment()))->onLineItemRemoved($event);

        self::assertNotNull($this->capturedEvents);
        self::assertSame(AbEventType::PRODUCT_REMOVED_FROM_CART, $this->capturedEvents[0]['eventType']);
        self::assertSame(['product_id' => 'prod-1'], $this->capturedEvents[0]['meta']);
    }

    public function testSkipsNonProductLineItem(): void
    {
        $promotion = new LineItem('li-2', LineItem::PROMOTION_LINE_ITEM_TYPE, 'promo-1', 1);
        $event = $this->event($promotion);

        (new LineItemRemovedSubscriber($this->recorderWithAssignment()))->onLineItemRemoved($event);

        self::assertNull($this->capturedEvents);
    }

    private function event(LineItem ...$lineItems): AfterLineItemRemovedEvent
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        $event = $this->createMock(AfterLineItemRemovedEvent::class);
        $event->method('getLineItems')->willReturn($lineItems);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        return $event;
    }
}
