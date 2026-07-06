<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Command\ExperimentStatsCommand;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;
use Ruhrcoder\RcAbTesting\Service\Stats\StatisticsCalculator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\Console\Tester\CommandTester;

final class ExperimentStatsCommandTest extends TestCase
{
    private const EXPERIMENT_ID = '0191aaaabbbbccccddddeeeeffff0001';
    private const VARIANT_CONTROL = '0191aaaabbbbccccddddeeeeffff00c0';
    private const VARIANT_ENHANCER = '0191aaaabbbbccccddddeeeeffff00e1';

    public function testRendersVariantTableAndSignificance(): void
    {
        $command = new ExperimentStatsCommand(
            new ExperimentLookup($this->experimentRepository()),
            new ExperimentStatsAggregator($this->assignmentRepository(1000), $this->connection(60)),
            new StatisticsCalculator(new NormalDistribution()),
        );

        $tester = new CommandTester($command);
        $tester->execute(['experiment-key' => 'checkout']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('control', $display);
        self::assertStringContainsString('enhancer', $display);
        self::assertStringContainsString('p-Value', $display);
    }

    public function testFailsWhenExperimentMissing(): void
    {
        $emptyResult = $this->createStub(EntitySearchResult::class);
        $emptyResult->method('getEntities')->willReturn(new AbExperimentCollection([]));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($emptyResult);

        $command = new ExperimentStatsCommand(
            new ExperimentLookup($repository),
            new ExperimentStatsAggregator($this->assignmentRepository(0), $this->connection(0)),
            new StatisticsCalculator(new NormalDistribution()),
        );

        $tester = new CommandTester($command);

        self::assertSame(ExperimentStatsCommand::FAILURE, $tester->execute(['experiment-key' => 'fehlt']));
    }

    private function experimentRepository(): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection([$this->experiment()]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function assignmentRepository(int $total): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getTotal')->willReturn($total);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function connection(int $conversions): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($conversions);

        return $connection;
    }

    private function experiment(): AbExperimentEntity
    {
        $control = new AbVariantEntity();
        $control->setId(self::VARIANT_CONTROL);
        $control->setTechnicalKey('control');
        $control->setName('Control');
        $control->setWeight(50);
        $control->setIsControl(true);

        $treatment = new AbVariantEntity();
        $treatment->setId(self::VARIANT_ENHANCER);
        $treatment->setTechnicalKey('enhancer');
        $treatment->setName('Enhancer');
        $treatment->setWeight(50);
        $treatment->setIsControl(false);

        $experiment = new AbExperimentEntity();
        $experiment->setId(self::EXPERIMENT_ID);
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([$control, $treatment]));

        return $experiment;
    }
}
