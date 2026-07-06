<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trackt `product.removed_from_cart` pro entferntem Produkt-Posten. Spiegelbild
 * zu LineItemAddedSubscriber; nicht-Produkt-Posten werden ignoriert.
 */
final class LineItemRemovedSubscriber implements EventSubscriberInterface
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
        return [AfterLineItemRemovedEvent::class => 'onLineItemRemoved'];
    }

    public function onLineItemRemoved(AfterLineItemRemovedEvent $event): void
    {
        $context = $event->getSalesChannelContext()->getContext();

        foreach ($event->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $this->recorder->record(
                AbEventType::PRODUCT_REMOVED_FROM_CART,
                null,
                ['product_id' => $lineItem->getReferencedId()],
                $context,
            );
        }
    }
}
