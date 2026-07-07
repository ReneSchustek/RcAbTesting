<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentFunnelAggregator;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExperimentFunnelAggregatorTest extends TestCase
{
    public function testCountsAssignmentsAndDistinctVisitorsPerStage(): void
    {
        $aggregator = new ExperimentFunnelAggregator($this->connection(
            assignments: [['v' => 'aa', 'c' => '1000']],
            stages: [
                ['v' => 'aa', 't' => 'page.viewed', 'c' => '900'],
                ['v' => 'aa', 't' => 'product.added_to_cart', 'c' => '340'],
                ['v' => 'aa', 't' => 'checkout.started', 'c' => '210'],
                ['v' => 'aa', 't' => 'checkout.order_placed', 'c' => '100'],
            ],
        ));

        $result = $aggregator->aggregate($this->experiment());

        self::assertSame(1000, $result['aa']['assignments']);
        self::assertSame(
            [
                'page.viewed' => 900,
                'product.added_to_cart' => 340,
                'checkout.started' => 210,
                'checkout.order_placed' => 100,
            ],
            $result['aa']['stages'],
        );
    }

    public function testMissingStagesDefaultToZeroInOrder(): void
    {
        // Nur die erste Stufe getrackt — die uebrigen Stufen bleiben 0.
        $aggregator = new ExperimentFunnelAggregator($this->connection(
            assignments: [['v' => 'aa', 'c' => '50']],
            stages: [['v' => 'aa', 't' => 'page.viewed', 'c' => '40']],
        ));

        $result = $aggregator->aggregate($this->experiment());

        self::assertSame(
            ['page.viewed' => 40, 'product.added_to_cart' => 0, 'checkout.started' => 0, 'checkout.order_placed' => 0],
            $result['aa']['stages'],
        );
        self::assertSame(ExperimentFunnelAggregator::STAGES, array_keys($result['aa']['stages']));
    }

    /**
     * @param list<array<string, string>> $assignments
     * @param list<array<string, string>> $stages
     */
    private function connection(array $assignments, array $stages): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'COUNT(DISTINCT') ? $stages : $assignments,
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
