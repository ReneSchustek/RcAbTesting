<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Command\ListExperimentsCommand;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\Console\Tester\CommandTester;

final class ListExperimentsCommandTest extends TestCase
{
    private const EXPERIMENT_ID = '0191aaaabbbbccccddddeeeeffff0001';
    private const VARIANT_A = '0191aaaabbbbccccddddeeeeffff000a';

    public function testListsExperiments(): void
    {
        $command = new ListExperimentsCommand(
            $this->experimentRepository([$this->experiment()]),
            new ExperimentStatsAggregator($this->assignmentRepository(), $this->connection()),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('checkout', $tester->getDisplay());
    }

    public function testWarnsWhenEmpty(): void
    {
        $command = new ListExperimentsCommand(
            $this->experimentRepository([]),
            new ExperimentStatsAggregator($this->assignmentRepository(), $this->connection()),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Keine Experimente', $tester->getDisplay());
    }

    /**
     * @param list<AbExperimentEntity> $experiments
     */
    private function experimentRepository(array $experiments): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection($experiments));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function assignmentRepository(): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getTotal')->willReturn(500);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);

        return $connection;
    }

    private function experiment(): AbExperimentEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(self::VARIANT_A);
        $variant->setTechnicalKey('a');
        $variant->setName('A');
        $variant->setWeight(100);
        $variant->setIsControl(true);

        $experiment = new AbExperimentEntity();
        $experiment->setId(self::EXPERIMENT_ID);
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([$variant]));

        return $experiment;
    }
}
