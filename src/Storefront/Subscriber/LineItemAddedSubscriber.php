<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Trackt `product.added_to_cart` pro hinzugefügtem Produkt-Posten. Nicht-Produkt-
 * Posten (Versand, Rabatte, Promotions) werden übersprungen, damit nur echte
 * Warenkorb-Adds in den Funnel gehen.
 */
final class LineItemAddedSubscriber implements EventSubscriberInterface
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
        return [AfterLineItemAddedEvent::class => 'onLineItemAdded'];
    }

    public function onLineItemAdded(AfterLineItemAddedEvent $event): void
    {
        $context = $event->getSalesChannelContext()->getContext();

        foreach ($event->getLineItems() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $this->recorder->record(
                AbEventType::PRODUCT_ADDED_TO_CART,
                (float) $lineItem->getQuantity(),
                ['product_id' => $lineItem->getReferencedId(), 'line_item_id' => $lineItem->getId()],
                $context,
            );
        }
    }
}
