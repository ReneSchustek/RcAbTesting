<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExperimentStatsAggregatorTest extends TestCase
{
    private const VARIANT_ID = '0191a1b2c3d4e5f60718293a4b5c6d7e';
    private const EXPERIMENT_ID = '0191ffeeddccbbaa9988776655443322';

    public function testCountsAssignmentsAndDistinctConversions(): void
    {
        $aggregator = new ExperimentStatsAggregator(
            $this->assignmentRepository(100),
            $this->connection(3),
        );

        $stats = $aggregator->aggregate($this->experiment(), Context::createDefaultContext());

        // Der Stub liefert denselben Wert fuer alle fetchOne-Abfragen (Conversions,
        // Bestellungen, Umsatz) — hier geht es nur um die Assignment-/Conversion-Basis.
        self::assertSame(
            ['assignments' => 100, 'conversions' => 3, 'orders' => 3, 'revenue' => 3.0],
            $stats[self::VARIANT_ID],
        );
    }

    public function testAggregatesOrdersAndRevenuePerVariant(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql): int|string {
            if (str_contains($sql, 'COUNT(DISTINCT')) {
                return 5; // konvertierende Besucher
            }
            if (str_contains($sql, 'COUNT(*)')) {
                return 8; // Bestellungen (Events)
            }
            if (str_contains($sql, 'SUM(event_value)')) {
                return '1234.5000'; // Umsatz
            }

            return 0;
        });

        $aggregator = new ExperimentStatsAggregator($this->assignmentRepository(100), $connection);

        $stats = $aggregator->aggregate($this->experiment(), Context::createDefaultContext());

        self::assertSame(
            ['assignments' => 100, 'conversions' => 5, 'orders' => 8, 'revenue' => 1234.5],
            $stats[self::VARIANT_ID],
        );
    }

    public function testConversionsAreClampedToAssignments(): void
    {
        // Mehr konvertierende Besucher als Zuordnungen ist unmöglich — wird geklemmt.
        $aggregator = new ExperimentStatsAggregator(
            $this->assignmentRepository(2),
            $this->connection(4),
        );

        $stats = $aggregator->aggregate($this->experiment(), Context::createDefaultContext());

        self::assertSame(
            ['assignments' => 2, 'conversions' => 2, 'orders' => 4, 'revenue' => 4.0],
            $stats[self::VARIANT_ID],
        );
    }

    private function assignmentRepository(int $total): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getTotal')->willReturn($total);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function connection(int $distinctVisitors): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($distinctVisitors);

        return $connection;
    }

    private function experiment(): AbExperimentEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(self::VARIANT_ID);
        $variant->setTechnicalKey('a');
        $variant->setName('A');
        $variant->setWeight(100);
        $variant->setIsControl(true);

        $experiment = new AbExperimentEntity();
        $experiment->setId(self::EXPERIMENT_ID);
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus('running');
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([$variant]));

        return $experiment;
    }
}
