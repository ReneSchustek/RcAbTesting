<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentSegmentAggregator;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft, wie der Segment-Aggregator die drei Abfragen (Zuordnungen, Conversions, Umsatz) je
 * Segment und Variante zusammenführt. Die Verbindung ordnet ihre Antworten am SQL-Text zu;
 * die Join-Semantik selbst prüft `ExperimentSegmentAggregatorIntegrationTest` gegen MySQL.
 */
final class ExperimentSegmentAggregatorTest extends TestCase
{
    public function testAggregatesPerSegmentAndVariant(): void
    {
        $aggregator = new ExperimentSegmentAggregator($this->connection(
            assignments: [
                ['variant' => 'aa', 'seg' => 'mobile', 'row_count' => '100'],
                ['variant' => 'bb', 'seg' => 'mobile', 'row_count' => '120'],
                ['variant' => 'aa', 'seg' => 'desktop', 'row_count' => '50'],
            ],
            conversions: [
                ['variant' => 'aa', 'seg' => 'mobile', 'row_count' => '10'],
                ['variant' => 'bb', 'seg' => 'mobile', 'row_count' => '18'],
                ['variant' => 'aa', 'seg' => 'desktop', 'row_count' => '5'],
            ],
            revenue: [
                ['variant' => 'aa', 'seg' => 'mobile', 'revenue_sum' => '200.0000', 'revenue_sum_sq' => '4000.0000'],
                ['variant' => 'bb', 'seg' => 'mobile', 'revenue_sum' => '360.0000', 'revenue_sum_sq' => '7200.0000'],
                ['variant' => 'aa', 'seg' => 'desktop', 'revenue_sum' => '100.0000', 'revenue_sum_sq' => '2000.0000'],
            ],
        ));

        $result = $aggregator->aggregate($this->experiment(), ExperimentSegmentAggregator::DEVICE);

        self::assertSame(
            ['assignments' => 100, 'conversions' => 10, 'revenue' => 200.0, 'revenueSumSq' => 4000.0],
            $result['mobile']['aa'],
        );
        self::assertSame(
            ['assignments' => 120, 'conversions' => 18, 'revenue' => 360.0, 'revenueSumSq' => 7200.0],
            $result['mobile']['bb'],
        );
        self::assertSame(
            ['assignments' => 50, 'conversions' => 5, 'revenue' => 100.0, 'revenueSumSq' => 2000.0],
            $result['desktop']['aa'],
        );
    }

    public function testConversionsAreClampedToAssignmentsPerSegment(): void
    {
        $aggregator = new ExperimentSegmentAggregator($this->connection(
            assignments: [['variant' => 'aa', 'seg' => 'mobile', 'row_count' => '5']],
            conversions: [['variant' => 'aa', 'seg' => 'mobile', 'row_count' => '9']],
            revenue: [],
        ));

        $result = $aggregator->aggregate($this->experiment(), ExperimentSegmentAggregator::DEVICE);

        self::assertSame(5, $result['mobile']['aa']['conversions']);
    }

    /**
     * Die Dimension wird als SQL-Ausdruck eingesetzt. Eine unbekannte muss deshalb abgelehnt
     * werden, statt still auf eine Standardspalte auszuweichen.
     */
    public function testUnknownDimensionThrows(): void
    {
        $aggregator = new ExperimentSegmentAggregator($this->createStub(Connection::class));

        $this->expectException(\InvalidArgumentException::class);
        $aggregator->aggregate($this->experiment(), 'nonsense');
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
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    return $conversions;
                }
                if (str_contains($sql, 'AS revenue_sum_sq')) {
                    return $revenue;
                }

                return $assignments;
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
