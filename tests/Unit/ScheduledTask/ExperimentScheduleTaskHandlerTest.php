<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\ScheduledTask;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\ScheduledTask\ExperimentScheduleTaskHandler;
use Ruhrcoder\RcAbTesting\Service\ExperimentIntegrityValidator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;

final class ExperimentScheduleTaskHandlerTest extends TestCase
{
    private const NOW = '2026-07-04T12:00:00+00:00';

    public function testStartsDueValidAndEndsDueRunning(): void
    {
        $startDue = $this->experiment('to-start', AbExperimentStatus::DRAFT, [
            $this->variant(50, true),
            $this->variant(50, false),
        ]);
        $endDue = $this->experiment('to-end', AbExperimentStatus::RUNNING, []);

        $captured = null;
        $handler = $this->handler([$startDue], [$endDue], $captured);
        $handler->processDue(Context::createDefaultContext(), new \DateTimeImmutable(self::NOW));

        self::assertIsArray($captured);
        self::assertCount(2, $captured);
        self::assertSame(AbExperimentStatus::RUNNING, $captured[0]['status']);
        self::assertSame(AbExperimentStatus::DONE, $captured[1]['status']);
    }

    public function testSkipsPausedThatAlreadyStarted(): void
    {
        // Manuell pausiertes Experiment mit vergangenem geplantem Start (Normalfall
        // nach dem ersten Start): darf vom Scheduler nicht wieder auf RUNNING gesetzt
        // werden, sonst hebt jeder Lauf die bewusste Pause still auf.
        $paused = $this->experiment('paused', AbExperimentStatus::PAUSED, [
            $this->variant(50, true),
            $this->variant(50, false),
        ]);
        $paused->setStartedAt(new \DateTimeImmutable('2026-07-01T00:00:00+00:00'));

        $captured = null;
        $handler = $this->handler([$paused], [], $captured);
        $handler->processDue(Context::createDefaultContext(), new \DateTimeImmutable(self::NOW));

        self::assertNull($captured);
    }

    public function testSkipsInvalidStart(): void
    {
        $invalid = $this->experiment('broken', AbExperimentStatus::DRAFT, [$this->variant(100, true)]);

        $captured = null;
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(fn (Criteria $criteria): EntitySearchResult => $this->searchResult($this->isStartSearch($criteria) ? [$invalid] : []));
        $repository->expects(self::never())->method('update');

        $handler = new ExperimentScheduleTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            $repository,
            new ExperimentIntegrityValidator(),
            $this->createMock(Connection::class),
        );
        $handler->processDue(Context::createDefaultContext(), new \DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<AbExperimentEntity>        $starts
     * @param list<AbExperimentEntity>        $ends
     * @param list<array<string, mixed>>|null $captured
     */
    private function handler(array $starts, array $ends, ?array &$captured): ExperimentScheduleTaskHandler
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(fn (Criteria $criteria): EntitySearchResult => $this->searchResult($this->isStartSearch($criteria) ? $starts : $ends));
        $repository->method('update')->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
            $captured = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        return new ExperimentScheduleTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            $repository,
            new ExperimentIntegrityValidator(),
            $this->createMock(Connection::class),
        );
    }

    private function isStartSearch(Criteria $criteria): bool
    {
        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsAnyFilter && $filter->getField() === 'status') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<AbExperimentEntity> $experiments
     */
    private function searchResult(array $experiments): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection($experiments));

        return $result;
    }

    /**
     * @param list<AbVariantEntity> $variants
     */
    private function experiment(string $key, string $status, array $variants): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey($key);
        $experiment->setName($key);
        $experiment->setStatus($status);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection($variants));

        return $experiment;
    }

    private function variant(int $weight, bool $isControl): AbVariantEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(Uuid::randomHex());
        $variant->setTechnicalKey('v');
        $variant->setName('v');
        $variant->setWeight($weight);
        $variant->setIsControl($isControl);

        return $variant;
    }
}
