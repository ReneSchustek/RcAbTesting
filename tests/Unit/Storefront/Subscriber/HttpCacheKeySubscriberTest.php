<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\HttpCacheKeySubscriber;
use RuntimeException;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheKeyEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prüft, wann eine Storefront-Seite nicht in den Seitenspeicher darf: bei laufenden Experimenten und wenn sich die
 * Experimente nicht lesen lassen.
 */
#[CoversClass(HttpCacheKeySubscriber::class)]
final class HttpCacheKeySubscriberTest extends TestCase
{
    /**
     * Wirft der Zwischenspeicher der Experimente, ist die Seite nicht zwischenspeicherbar, und im Protokoll steht eine
     * Warnung. Die Seite darf daran nicht scheitern, aber ohne Warnung liefe jede Seite still ungecacht.
     */
    public function testAFailingRegistryIsLoggedAndDisablesTheCache(): void
    {
        $cache = $this->createMock(TagAwareAdapterInterface::class);
        $cache->method('getItem')->willThrowException(new RuntimeException('Speicher weg'));
        $registry = new ExperimentRegistry($this->createMock(EntityRepository::class), $cache);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => $context['path'] === '/kategorie'
                && $context['exception'] instanceof RuntimeException
                && $context['exception']->getMessage() === 'Speicher weg'),
        );

        $event = new HttpCacheKeyEvent(Request::create('/kategorie'));
        (new HttpCacheKeySubscriber($registry, $logger))->onHttpCacheKey($event);

        self::assertFalse($event->isCacheable);
    }

    /**
     * Aufrufe der Verwaltung und der Schnittstelle fragen den Zwischenspeicher der Experimente nicht und lassen die
     * Zwischenspeicherbarkeit unverändert; mit Storefront-Experimenten haben sie nichts zu tun.
     */
    public function testAdminAndApiAreLeftAlone(): void
    {
        $cache = $this->createMock(TagAwareAdapterInterface::class);
        $cache->expects(self::never())->method('getItem');
        $subscriber = new HttpCacheKeySubscriber(
            new ExperimentRegistry(
                $this->createMock(EntityRepository::class),
                $cache
            )
        );

        foreach (['/admin', '/api/_info'] as $path) {
            $event = new HttpCacheKeyEvent(Request::create($path));
            $subscriber->onHttpCacheKey($event);

            self::assertTrue($event->isCacheable, $path);
        }
    }
}
