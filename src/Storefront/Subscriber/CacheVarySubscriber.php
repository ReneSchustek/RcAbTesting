<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Ruhrcoder\RcAbTesting\Service\ExperimentTouchState;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Schickt Seiten, die ein hier anwendbares laufendes Experiment berühren, mit
 * `Cache-Control: private, no-store` aus. Das gilt für jeden solchen Besucher, auch für den, den
 * die Traffic-Allocation ausschließt: Sonst hielte ein Cache die Kontrollseite eines
 * Ausgeschlossenen und gäbe sie Teilnehmern unter demselben Schlüssel weiter.
 *
 * Den Reverse-Proxy schützt dieser Kopf nicht, weil Shopware dort schon vor diesem Ereignis
 * speichert; das übernimmt der {@see HttpCacheKeySubscriber}.
 *
 * Gelesen wird der Zustand des Dienstes ({@see ExperimentTouchState}), nicht ein
 * Request-Attribut. Bei aktivem HTTP-Cache bekommt der Kernel eine Kopie des Requests; ein
 * Attribut, das beim Rendern gesetzt wird, fehlt auf dem Request dieses Ereignisses, der Dienst
 * ist derselbe.
 */
final class CacheVarySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ExperimentTouchState $experimentTouchState,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Weit hinter Shopwares `CacheControlListener` (Priorität 0), damit kein Listener den
        // Kopf danach wieder lockert.
        return [BeforeSendResponseEvent::class => ['onBeforeSendResponse', -2000]];
    }

    public function onBeforeSendResponse(BeforeSendResponseEvent $event): void
    {
        if (!$this->experimentTouchState->hasTouchedExperiment()) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
    }
}
