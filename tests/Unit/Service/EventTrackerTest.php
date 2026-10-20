<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Tests\Unit\Support\RunningRegistryTrait;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft, wann der Tracker Ereignisse schreibt und wann nicht. Jede Lücke hier verfälscht die
 * Auswertung unmittelbar: doppelt gezählte Bestellungen blähen Umsatz und Conversions auf,
 * ein verlorenes `meta` kostet das ganze Ereignis, und ein ausbleibendes `lastSeenAt` lässt
 * die Anonymisierung Zuordnung und Ereignisse auseinanderreißen.
 *
 * Das Repository liefert hier jede Zuordnung unabhängig vom Criteria-Objekt. Ob der Filter
 * nach Kunde oder Besucher greift, zeigt erst `EventTrackerKernelTest`.
 */
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

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(), $logger);
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

        $tracker = new EventTracker(
            $assignmentRepository,
            $eventRepository,
            $this->runningRegistry(),
            new NullLogger()
        );
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

        $tracker = new EventTracker(
            $assignmentRepository,
            $eventRepository,
            $this->runningRegistry(),
            new NullLogger()
        );
        $tracker->track(
            AbEventType::CHECKOUT_ORDER_PLACED,
            49.9,
            ['order' => 'A1'],
            'visitor-1',
            Context::createDefaultContext()
        );

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

        $tracker = new EventTracker(
            $assignmentRepository,
            $eventRepository,
            $this->runningRegistry(),
            new NullLogger()
        );
        // `json_encode()` scheitert an `NAN`; ohne Rückfall ginge das Ereignis beim Schreiben verloren.
        $tracker->track(
            AbEventType::PAGE_VIEWED,
            null,
            [
                'broken' => \NAN,
            ],
            'visitor-1',
            Context::createDefaultContext()
        );

        self::assertIsArray($captured);
        self::assertSame([], $captured[0]['meta']);
    }

    /**
     * Belegt nur, dass die Kundenkennung der Zuordnung ins Ereignis übernommen wird.
     */
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

        $tracker = new EventTracker(
            $assignmentRepository,
            $eventRepository,
            $this->runningRegistry(),
            new NullLogger()
        );
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

        $tracker = new EventTracker(
            $assignmentRepository,
            $eventRepository,
            $this->runningRegistry(),
            new NullLogger()
        );
        $context = Context::createDefaultContext();

        // Zwei Ereignisse in derselben Anfrage teilen sich eine Zuordnungsabfrage.
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', $context);
        $tracker->track(AbEventType::CHECKOUT_STARTED, null, [], 'visitor-1', $context);

        // `reset()` steht für den Beginn der nächsten Anfrage. Danach muss neu gelesen werden,
        // sonst arbeitete ein langlebiger Worker mit veralteten Zuordnungen.
        $tracker->reset();
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', $context);
    }

    public function testSameOrderIsRecordedOncePerExperimentOnRedispatch(): void
    {
        $assignmentRepository = $this->repositoryReturning($this->assignment('exp-a', 'var-a'));

        $written = [];
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturnCallback(function (array $payload) use (&$written): EntityWrittenContainerEvent {
            foreach ($payload as $row) {
                $event = new AbEventEntity();
                $event->setId(Uuid::randomHex());
                $event->setExperimentId($row['experimentId']);
                $event->setMeta($row['meta']);
                $written[] = $event;
            }

            return $this->createStub(EntityWrittenContainerEvent::class);
        });
        $eventRepository->method('search')->willReturnCallback(function () use (&$written): EntitySearchResult {
            $result = $this->createStub(EntitySearchResult::class);
            $result->method('getEntities')->willReturn(new AbEventCollection($written));

            return $result;
        });

        $tracker = new EventTracker(
            $assignmentRepository,
            $eventRepository,
            $this->runningRegistry(),
            new NullLogger()
        );
        $context = Context::createDefaultContext();

        // Der zweite Aufruf mit derselben `order_id` steht für ein erneut ausgelöstes
        // Bestellereignis; er darf Umsatz und Conversion nicht noch einmal zählen. Das
        // `reset()` dazwischen schließt aus, dass nur der Anfragespeicher die Dublette abfängt.
        $tracker->track(AbEventType::CHECKOUT_ORDER_PLACED, 20.0, ['order_id' => 'order-1'], 'visitor-1', $context);
        $tracker->reset();
        $tracker->track(AbEventType::CHECKOUT_ORDER_PLACED, 20.0, ['order_id' => 'order-1'], 'visitor-1', $context);

        self::assertCount(1, $written);
    }

    public function testEventWriteBumpsAssignmentLastSeenOncePerRequest(): void
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection([$this->assignment('exp-a', 'var-a')]));

        $updateCalls = 0;
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($result);
        $assignmentRepository->method('update')->willReturnCallback(function (array $updates) use (&$updateCalls): EntityWrittenContainerEvent {
            ++$updateCalls;
            self::assertArrayHasKey('lastSeenAt', $updates[0]);

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturn($this->createStub(EntityWrittenContainerEvent::class));

        $tracker = new EventTracker(
            $assignmentRepository,
            $eventRepository,
            $this->runningRegistry(),
            new NullLogger()
        );
        $context = Context::createDefaultContext();

        // Zwei Ereignisse in derselben Anfrage schreiben `last_seen_at` nur einmal; eine Seite
        // löst mehrere Ereignisse aus, und jedes Mal zu schreiben kostete je Aufruf ein Update.
        $tracker->track(AbEventType::PAGE_VIEWED, null, [], 'visitor-1', $context);
        $tracker->track(AbEventType::CHECKOUT_STARTED, null, [], 'visitor-1', $context);

        self::assertSame(1, $updateCalls);
    }

    /**
     * @return EntityRepository<AbAssignmentCollection>
     */
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
