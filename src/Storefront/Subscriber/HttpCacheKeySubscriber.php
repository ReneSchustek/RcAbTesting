<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Service\NonStorefrontPath;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheKeyEvent;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Nimmt alle Storefront-Seiten aus dem HTTP-Cache, solange irgendein Experiment läuft.
 *
 * Der {@see CacheVarySubscriber} setzt `no-store` erst im `BeforeSendResponseEvent`. Für den
 * Browser genügt das, für den Reverse-Proxy kommt es zu spät: `HttpCacheKernel::handle()` legt die
 * Antwort schon in `parent::handle()` ab, bei externem Proxy über `$this->store->write()`, und
 * löst das Ereignis erst danach aus. Die Variante ist außerdem kein Teil des Cache-Schlüssels.
 * Ohne diesen Abonnenten läge die Seite der Gruppe B im Cache, und der Proxy gäbe sie allen aus,
 * Kontrollgruppe eingeschlossen. Die Messung wäre wertlos, ohne dass etwas kaputt aussieht.
 *
 * `HttpCacheKeyEvent` feuert beim Nachschlagen und beim Schreiben, `isCacheable = false`
 * verhindert also beides.
 *
 * Die Variante in den Cache-Schlüssel aufzunehmen, hielte den Cache wirksam, scheitert aber am
 * Zeitpunkt. Beim Nachschlagen gibt es noch keinen Verkaufskanal-Kontext, die Zuordnung müsste
 * aus dem Cookie neu gerechnet werden. Eine bestehende Zuordnung ist klebrig und kann von der
 * frisch gerechneten abweichen, etwa nach geänderten Gewichten. Dann läge die Seite unter dem
 * falschen Schlüssel, und der Fehler wäre schlimmer als der, den er behebt.
 *
 * Solange ein Experiment läuft, verliert damit jede Storefront-Seite ihren Proxy-Cache, auch eine
 * ohne Experiment. Läuft keines, kostet der Abonnent einen Registry-Zugriff aus dem Objekt-Cache.
 */
final class HttpCacheKeySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [HttpCacheKeyEvent::class => 'onHttpCacheKey'];
    }

    public function onHttpCacheKey(HttpCacheKeyEvent $event): void
    {
        $path = $event->request->getPathInfo();
        if (NonStorefrontPath::matches($path)) {
            return;
        }

        // Der Registry-Zugriff läuft über `cache.object` und kostet im Normalfall keinen Datenbankzugriff. Ein Fehler
        // darf keine Seite abreißen; im Zweifel wird nicht zwischengespeichert, das ist langsamer, aber nie falsch
        // ausgeliefert. Protokolliert, weil sonst jede Seite still ungecacht liefe.
        try {
            $running = $this->experimentRegistry->getRunning(Context::createDefaultContext());
        } catch (\Throwable $e) {
            $this->logger->warning('Laufende Experimente nicht lesbar, Seite wird nicht zwischengespeichert', [
                'path' => $path,
                'exception' => $e,
            ]);
            $event->isCacheable = false;

            return;
        }

        if ($running !== []) {
            $event->isCacheable = false;
        }
    }
}
