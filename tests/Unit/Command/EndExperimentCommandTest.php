<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Command\EndExperimentCommand;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentLookup;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prüft das Beenden eines Experiments über die Konsole. Ein Gewinner, der nicht zum
 * Experiment gehört, darf nichts schreiben; sonst stünde das Experiment als beendet da,
 * mit einer Gewinner-Id, die auf keine seiner Varianten zeigt.
 */
final class EndExperimentCommandTest extends TestCase
{
    public function testEndsExperimentAndSetsWinner(): void
    {
        $captured = null;
        $repository = $this->repository($captured);
        $command = new EndExperimentCommand(new ExperimentLookup($repository), $repository);

        $tester = new CommandTester($command);
        $tester->execute(['experiment-key' => 'checkout', '--winner' => 'a'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertIsArray($captured);
        self::assertSame(AbExperimentStatus::DONE, $captured[0]['status']);
        self::assertSame('var-a', $captured[0]['winnerVariantId']);
    }

    /**
     * Ohne `--winner` enthält der Schreibauftrag kein Feld `winnerVariantId`. Sonst schriebe der Befehl null und
     * löschte einen schon eingetragenen Gewinner.
     */
    public function testEndingWithoutWinnerKeepsTheExistingOne(): void
    {
        $captured = null;
        $repository = $this->repository($captured);
        $command = new EndExperimentCommand(new ExperimentLookup($repository), $repository);

        $tester = new CommandTester($command);
        $tester->execute(['experiment-key' => 'checkout'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertIsArray($captured);
        self::assertArrayNotHasKey('winnerVariantId', $captured[0]);
    }

    /**
     * Ein schon beendetes Experiment lässt sich nicht noch einmal beenden: Der Befehl meldet einen Fehler und schreibt
     * nichts, sonst bekäme es ein neues Enddatum.
     */
    public function testFailsWhenTheExperimentIsNotRunning(): void
    {
        $captured = null;
        $repository = $this->repository($captured, AbExperimentStatus::DONE);
        $command = new EndExperimentCommand(new ExperimentLookup($repository), $repository);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['experiment-key' => 'checkout'], ['interactive' => false]);

        self::assertSame(EndExperimentCommand::FAILURE, $exitCode);
        self::assertNull($captured);
    }

    public function testFailsOnUnknownWinner(): void
    {
        $captured = null;
        $command = new EndExperimentCommand(
            new ExperimentLookup(
                $this->repository($captured)
            ),
            $this->repository($captured)
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(
            [
                'experiment-key' => 'checkout',
                '--winner' => 'unbekannt',
            ],
            [
                'interactive' => false,
            ]
        );

        self::assertSame(EndExperimentCommand::FAILURE, $exitCode);
        self::assertNull($captured);
    }

    /**
     * @param list<array<string, mixed>>|null $captured
     *
     * @return EntityRepository<AbExperimentCollection>
     */
    private function repository(?array &$captured, string $status = AbExperimentStatus::RUNNING): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection([$this->experiment($status)]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);
        $repository->method('update')->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
            $captured = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        return $repository;
    }

    private function experiment(string $status): AbExperimentEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId('var-a');
        $variant->setTechnicalKey('a');
        $variant->setName('A');
        $variant->setWeight(100);
        $variant->setIsControl(true);

        $experiment = new AbExperimentEntity();
        $experiment->setId('exp-a');
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus($status);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([$variant]));

        return $experiment;
    }
}
