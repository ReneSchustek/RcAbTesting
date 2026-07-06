<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\ScheduledTask;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CartAbandonmentDetector;
use Ruhrcoder\RcAbTesting\ScheduledTask\CartAbandonmentTaskHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class CartAbandonmentTaskHandlerTest extends TestCase
{
    public function testWritesAbandonedEventPerDetectedCart(): void
    {
        $rows = [
            ['experimentId' => 'e1', 'variantId' => 'v1', 'visitorId' => 'vis1'],
            ['experimentId' => 'e1', 'variantId' => 'v2', 'visitorId' => 'vis2'],
        ];

        $captured = null;
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::once())->method('create')->willReturnCallback(
            function (array $payload) use (&$captured): EntityWrittenContainerEvent {
                $captured = $payload;

                return $this->createStub(EntityWrittenContainerEvent::class);
            },
        );

        $this->handler($rows, $eventRepository)->run();

        self::assertIsArray($captured);
        self::assertCount(2, $captured);
        self::assertSame(AbEventType::CART_ABANDONED, $captured[0]['eventType']);
        self::assertSame('vis1', $captured[0]['visitorId']);
    }

    public function testWritesNothingWhenNoAbandonedCarts(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $this->handler([], $eventRepository)->run();
    }

    public function testSkipsWhenLockNotAcquired(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $detectorConnection = $this->createMock(Connection::class);
        // Würde der Lock-Guard fehlen, würde der Detektor abgefragt; das prüfen wir negativ.
        $detectorConnection->expects(self::never())->method('fetchAllAssociative');

        $handlerConnection = $this->createMock(Connection::class);
        $handlerConnection->method('fetchOne')->willReturn(0); // Lock belegt
        $handlerConnection->expects(self::never())->method('executeStatement');

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(0);

        $handler = new CartAbandonmentTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new CartAbandonmentDetector($detectorConnection),
            $eventRepository,
            $systemConfig,
            $handlerConnection,
        );

        $handler->run();
    }

    public function testReleasesLockAfterRun(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturn($this->createStub(EntityWrittenContainerEvent::class));

        $handlerConnection = $this->createMock(Connection::class);
        $handlerConnection->method('fetchOne')->willReturn(1);
        $handlerConnection->expects(self::once())->method('executeStatement');

        $detectorConnection = $this->createMock(Connection::class);
        $detectorConnection->method('fetchAllAssociative')->willReturn(
            [['experimentId' => 'e1', 'variantId' => 'v1', 'visitorId' => 'vis1']],
        );

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(0);

        $handler = new CartAbandonmentTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new CartAbandonmentDetector($detectorConnection),
            $eventRepository,
            $systemConfig,
            $handlerConnection,
        );

        $handler->run();
    }

    /**
     * @param list<array{experimentId: string, variantId: string, visitorId: string}> $rows
     */
    private function handler(array $rows, EntityRepository $eventRepository): CartAbandonmentTaskHandler
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $handlerConnection = $this->createMock(Connection::class);
        $handlerConnection->method('fetchOne')->willReturn(1); // Lock erteilt

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(0);

        return new CartAbandonmentTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new CartAbandonmentDetector($connection),
            $eventRepository,
            $systemConfig,
            $handlerConnection,
        );
    }
}
