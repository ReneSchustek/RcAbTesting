<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Kernel;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Basis für Kernel-Integrationstests gegen die echte Shopware-DAL.
 * `IntegrationTestBehaviour` kapselt Kernel-Lifecycle, Test-Container und
 * DB-Rollback je Test (reihenfolgenunabhängig). Nur lauffähig innerhalb eines
 * Shopware-Test-Kernels (siehe tests/Integration/README.md, Abschnitt Kernel).
 */
abstract class KernelTestBase extends TestCase
{
    use IntegrationTestBehaviour;

    protected Context $context;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
    }

    protected function connection(): Connection
    {
        $connection = $this->getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * Legt ein Experiment mit zwei Varianten an und gibt [experimentId, variantAId] zurück.
     *
     * @return array{0: string, 1: string}
     */
    protected function createExperiment(string $status): array
    {
        $experimentId = Uuid::randomHex();
        $variantA = Uuid::randomHex();
        $variantB = Uuid::randomHex();

        /** @var EntityRepository<AbExperimentCollection> $repository */
        $repository = $this->getContainer()->get('rc_ab_experiment.repository');
        $repository->create([[
            'id' => $experimentId,
            'technicalKey' => 'exp-' . $experimentId,
            'name' => 'Kernel-Test-Experiment',
            'status' => $status,
            'testType' => 'twig',
            'trafficAllocationPct' => 100,
            'targetSignificance' => 0.95,
            'variants' => [
                ['id' => $variantA, 'technicalKey' => 'a', 'name' => 'A', 'weight' => 50, 'isControl' => true],
                ['id' => $variantB, 'technicalKey' => 'b', 'name' => 'B', 'weight' => 50, 'isControl' => false],
            ],
        ]], $this->context);

        return [$experimentId, $variantA];
    }

    protected function createAssignment(string $experimentId, string $variantId, string $visitorId, ?string $customerId = null): void
    {
        /** @var EntityRepository<AbAssignmentCollection> $repository */
        $repository = $this->getContainer()->get('rc_ab_assignment.repository');
        $repository->create([[
            'id' => Uuid::randomHex(),
            'experimentId' => $experimentId,
            'variantId' => $variantId,
            'visitorId' => $visitorId,
            'customerId' => $customerId,
            'salesChannelId' => $this->aSalesChannelId(),
            'languageId' => Defaults::LANGUAGE_SYSTEM,
            'assignedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'lastSeenAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]], $this->context);
    }

    protected function aSalesChannelId(): string
    {
        $id = $this->connection()->fetchOne('SELECT LOWER(HEX(id)) FROM sales_channel LIMIT 1');
        \assert(\is_string($id) && $id !== '');

        return $id;
    }

    protected function anyCustomerId(): string
    {
        $id = $this->connection()->fetchOne('SELECT LOWER(HEX(id)) FROM customer LIMIT 1');
        \assert(\is_string($id) && $id !== '');

        return $id;
    }

    /**
     * Zwingt den ExperimentRegistry-Cache zur Neuabfrage, damit gerade geseedete
     * laufende Experimente sicher erkannt werden.
     */
    protected function invalidateRegistry(): void
    {
        $registry = $this->getContainer()->get(ExperimentRegistry::class);
        \assert($registry instanceof ExperimentRegistry);
        $registry->invalidate();
    }

    /**
     * Events zu einem bestimmten Experiment (auf die geseedeten Daten eingegrenzt,
     * damit vorhandene Zeilen der kopierten DB die Assertions nicht verfälschen).
     *
     * @return list<array{visitorId: string, customerId: string|null}>
     */
    protected function eventsForExperiment(string $experimentId): array
    {
        /** @var list<array{visitorId: string, customerId: string|null}> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT visitor_id AS visitorId, LOWER(HEX(customer_id)) AS customerId
             FROM rc_ab_event WHERE experiment_id = :id',
            ['id' => Uuid::fromHexToBytes($experimentId)],
        );

        return $rows;
    }

    protected function runningStatus(): string
    {
        return AbExperimentStatus::RUNNING;
    }
}
