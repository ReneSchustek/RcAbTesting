<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Tests\Unit\Support\RunningRegistryTrait;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

final class EventTrackerTest extends TestCase
{
    use RunningRegistryTrait;

    public function testInvalidEventTypeIsSkippedAndLogged(): void
    {
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('search');
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(),$logger);
        $tracker->track('page.scrolled', null, [], 'visitor-1', Context::createDefaultContext());
    }

    public function testNoRunningExperimentsMeansNoWrite(): void
    {
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('search');
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->emptyRegistry(), new NullLogger());
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', Context::createDefaultContext());
    }

    public function testNoAssignmentsMeansNoWrite(): void
    {
        $assignmentRepository = $this->repositoryReturning();
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(),new NullLogger());
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', Context::createDefaultContext());
    }

    public function testWritesOneEventPerAssignmentWithCleanMeta(): void
    {
        $assignmentRepository = $this->repositoryReturning(
            $this->assignment('exp-a', 'var-a'),
            $this->assignment('exp-b', 'var-b'),
        );

        $captured = null;
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::once())
            ->method('create')
            ->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
                $captured = $payload;

                return $this->createStub(EntityWrittenContainerEvent::class);
            });

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(),new NullLogger());
        $tracker->track(AbEventType::CHECKOUT_ORDER_PLACED, 49.9, ['order' => 'A1'], 'visitor-1', Context::createDefaultContext());

        self::assertIsArray($captured);
        self::assertCount(2, $captured);
        self::assertSame(AbEventType::CHECKOUT_ORDER_PLACED, $captured[0]['eventType']);
        self::assertSame(49.9, $captured[0]['eventValue']);
        self::assertSame(['order' => 'A1'], $captured[0]['meta']);
        self::assertSame('exp-a', $captured[0]['experimentId']);
        self::assertSame('exp-b', $captured[1]['experimentId']);
    }

    public function testNonSerializableMetaFallsBackToEmptyArray(): void
    {
        $assignmentRepository = $this->repositoryReturning($this->assignment('exp-a', 'var-a'));

        $captured = null;
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
            $captured = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(),new NullLogger());
        $tracker->track(AbEventType::PAGE_VIEWED, null, ['broken' => \NAN], 'visitor-1', Context::createDefaultContext());

        self::assertIsArray($captured);
        self::assertSame([], $captured[0]['meta']);
    }

    public function testTracksByCustomerWhenLoggedIn(): void
    {
        $assignment = $this->assignment('exp-a', 'var-a');
        $assignment->setCustomerId('cust-1');
        $assignmentRepository = $this->repositoryReturning($assignment);

        $captured = null;
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
            $captured = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(), new NullLogger());
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', Context::createDefaultContext(), 'cust-1');

        self::assertIsArray($captured);
        self::assertSame('cust-1', $captured[0]['customerId']);
    }

    public function testAssignmentsAreMemoizedPerRequestAndClearedOnReset(): void
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection([$this->assignment('exp-a', 'var-a')]));

        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::exactly(2))->method('search')->willReturn($result);

        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturn($this->createStub(EntityWrittenContainerEvent::class));

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(), new NullLogger());
        $context = Context::createDefaultContext();

        // Zwei Events im selben Request -> nur eine Zuordnungs-Abfrage.
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', $context);
        $tracker->track(AbEventType::CHECKOUT_STARTED, null, [], 'visitor-1', $context);

        // Nach reset() (neuer Request) wird erneut abgefragt.
        $tracker->reset();
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', $context);
    }

    private function repositoryReturning(AbAssignmentEntity ...$assignments): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection($assignments));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function assignment(string $experimentId, string $variantId): AbAssignmentEntity
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId($experimentId);
        $assignment->setVariantId($variantId);
        $assignment->setVisitorId('visitor-1');

        return $assignment;
    }
}
