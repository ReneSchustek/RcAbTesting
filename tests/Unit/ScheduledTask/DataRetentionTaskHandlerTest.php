<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\ScheduledTask;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\ScheduledTask\DataRetentionTaskHandler;
use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class DataRetentionTaskHandlerTest extends TestCase
{
    public function testAnonymizesWithConfiguredRetention(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturn(1);

        $this->handler($connection, '45')->run();
    }

    public function testUsesDefaultWhenUnconfigured(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturn(0);

        $this->handler($connection, null)->run();
    }

    public function testSkipsWhenRetentionDisabled(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->handler($connection, '0')->run();
    }

    private function handler(Connection $connection, ?string $configuredDays): DataRetentionTaskHandler
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturn($configuredDays);

        return new DataRetentionTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            new VisitorDataAnonymizer($connection),
            $systemConfig,
        );
    }
}
