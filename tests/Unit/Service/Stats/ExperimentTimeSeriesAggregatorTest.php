<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentTimeSeriesAggregator;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExperimentTimeSeriesAggregatorTest extends TestCase
{
    public function testBuildsSortedDailyPointsPerVariant(): void
    {
        $aggregator = new ExperimentTimeSeriesAggregator($this->connection(
            // bewusst unsortiert geliefert — der Aggregator sortiert nach Datum.
            assignments: [
                ['v' => 'aa', 'd' => '2026-07-02', 'c' => '50'],
                ['v' => 'aa', 'd' => '2026-07-01', 'c' => '100'],
            ],
            conversions: [
                ['v' => 'aa', 'd' => '2026-07-01', 'c' => '10'],
                ['v' => 'aa', 'd' => '2026-07-02', 'c' => '8'],
            ],
            revenue: [
                ['v' => 'aa', 'd' => '2026-07-01', 'r' => '200.0000'],
                ['v' => 'aa', 'd' => '2026-07-02', 'r' => '160.0000'],
            ],
        ));

        $series = $aggregator->aggregate($this->experiment());

        self::assertSame(
            [
                ['date' => '2026-07-01', 'assignments' => 100, 'conversions' => 10, 'revenue' => 200.0],
                ['date' => '2026-07-02', 'assignments' => 50, 'conversions' => 8, 'revenue' => 160.0],
            ],
            $series['aa'],
        );
    }

    public function testDaysWithoutAllMetricsDefaultToZero(): void
    {
        // Tag mit Zuordnungen aber ohne Conversion/Umsatz -> Nullwerte.
        $aggregator = new ExperimentTimeSeriesAggregator($this->connection(
            assignments: [['v' => 'aa', 'd' => '2026-07-01', 'c' => '40']],
            conversions: [],
            revenue: [],
        ));

        $series = $aggregator->aggregate($this->experiment());

        self::assertSame(
            [['date' => '2026-07-01', 'assignments' => 40, 'conversions' => 0, 'revenue' => 0.0]],
            $series['aa'],
        );
    }

    /**
     * @param list<array<string, string>> $assignments
     * @param list<array<string, string>> $conversions
     * @param list<array<string, string>> $revenue
     */
    private function connection(array $assignments, array $conversions, array $revenue): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql) use ($assignments, $conversions, $revenue): array {
                if (str_contains($sql, 'assigned_at')) {
                    return $assignments;
                }
                if (str_contains($sql, 'first_conversion')) {
                    return $conversions;
                }

                return $revenue;
            },
        );

        return $connection;
    }

    private function experiment(): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey('exp');
        $experiment->setName('exp');
        $experiment->setStatus('running');
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);

        return $experiment;
    }
}
