<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Deaktiviert den HTTP-Cache gezielt für Seiten, auf denen der Besucher einer
 * A/B-Variante zugeordnet wurde — sonst würde der Reverse-Proxy eine Variante
 * an alle Besucher ausliefern und das Sample verzerren. Bewusst die sichere
 * `no-store`-Variante: sie schließt Fehlauslieferung zuverlässig aus, ohne
 * einen fehleranfälligen variantenspezifischen Vary-Key pflegen zu müssen.
 */
final class CacheVarySubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [BeforeSendResponseEvent::class => 'onBeforeSendResponse'];
    }

    public function onBeforeSendResponse(BeforeSendResponseEvent $event): void
    {
        if ($event->getRequest()->attributes->get(RequestVariantResolver::IN_EXPERIMENT_ATTRIBUTE) !== true) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
    }
}
