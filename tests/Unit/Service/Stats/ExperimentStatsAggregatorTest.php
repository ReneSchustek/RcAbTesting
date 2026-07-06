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

        self::assertSame(['assignments' => 100, 'conversions' => 3], $stats[self::VARIANT_ID]);
    }

    public function testConversionsAreClampedToAssignments(): void
    {
        // Mehr konvertierende Besucher als Zuordnungen ist unmöglich — wird geklemmt.
        $aggregator = new ExperimentStatsAggregator(
            $this->assignmentRepository(2),
            $this->connection(4),
        );

        $stats = $aggregator->aggregate($this->experiment(), Context::createDefaultContext());

        self::assertSame(['assignments' => 2, 'conversions' => 2], $stats[self::VARIANT_ID]);
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
