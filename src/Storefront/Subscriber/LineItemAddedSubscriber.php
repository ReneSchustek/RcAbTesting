<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Zeichnet `product.added_to_cart` je hinzugefügter Produktposition auf. Andere Positionen wie
 * Rabatte und Aktionen fallen heraus, damit nur echte Artikel in den Funnel gehen.
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
                // `event_value` trägt hier die Stückzahl, keinen Betrag.
                (float) $lineItem->getQuantity(),
                ['product_id' => $lineItem->getReferencedId(), 'line_item_id' => $lineItem->getId()],
                $context,
            );
        }
    }
}
