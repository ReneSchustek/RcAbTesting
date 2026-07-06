<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;

final class VisitorDataAnonymizerTest extends TestCase
{
    public function testAnonymizesBothTablesWithCutoffAndMarkerGuard(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 3;
            },
        );

        $anonymizer = new VisitorDataAnonymizer($connection);
        $threshold = new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC'));
        $affected = $anonymizer->anonymizeOlderThan($threshold);

        self::assertSame(6, $affected);
        self::assertCount(2, $statements);

        $tables = array_map(
            static fn (array $s): bool => str_contains($s['sql'], 'rc_ab_assignment') || str_contains($s['sql'], 'rc_ab_event'),
            $statements,
        );
        self::assertSame([true, true], $tables);

        foreach ($statements as $statement) {
            self::assertStringContainsString('SHA2(`visitor_id`, 256)', $statement['sql']);
            self::assertStringContainsString('`customer_id` = NULL', $statement['sql']);
            self::assertStringContainsString('`anonymized_at` IS NULL', $statement['sql']);
            self::assertSame('2026-03-01 00:00:00.000', $statement['params']['cutoff']);
        }
    }
}
