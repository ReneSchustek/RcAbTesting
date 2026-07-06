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
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Tests\Unit\Support\RunningRegistryTrait;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestEventRecorderTest extends TestCase
{
    use RunningRegistryTrait;

    public function testSkipsWhenNoMainRequest(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $recorder = new RequestEventRecorder(
            new EventTracker($this->createMock(EntityRepository::class), $eventRepository, $this->runningRegistry(), new NullLogger()),
            new RequestStack(),
            new NullLogger(),
        );

        $recorder->record(AbEventType::PAGE_VIEWED, null, [], Context::createDefaultContext());
    }

    public function testSkipsWhenRequestHasNoVisitorId(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $stack = new RequestStack();
        $stack->push(new Request());

        $recorder = new RequestEventRecorder(
            new EventTracker($this->createMock(EntityRepository::class), $eventRepository, $this->runningRegistry(), new NullLogger()),
            $stack,
            new NullLogger(),
        );

        $recorder->record(AbEventType::PAGE_VIEWED, null, [], Context::createDefaultContext());
    }

    public function testRecordsWhenVisitorPresent(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::once())->method('create')->willReturn($this->createStub(EntityWrittenContainerEvent::class));

        $recorder = new RequestEventRecorder(
            new EventTracker($this->assignmentRepository(), $eventRepository, $this->runningRegistry(), new NullLogger()),
            $this->stackWithVisitor(),
            new NullLogger(),
        );

        $recorder->record(AbEventType::PAGE_VIEWED, null, ['route' => '/'], Context::createDefaultContext());
    }

    public function testSwallowsAndLogsTrackingError(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willThrowException(new \RuntimeException('DB weg'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $recorder = new RequestEventRecorder(
            new EventTracker($this->assignmentRepository(), $eventRepository, $this->runningRegistry(), new NullLogger()),
            $this->stackWithVisitor(),
            $logger,
        );

        // Darf nicht werfen — Tracking ist Nebenpfad.
        $recorder->record(AbEventType::PAGE_VIEWED, null, [], Context::createDefaultContext());
    }

    private function assignmentRepository(): EntityRepository
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId('a1');
        $assignment->setExperimentId('exp-a');
        $assignment->setVariantId('var-a');
        $assignment->setVisitorId('visitor-1');

        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection([$assignment]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function stackWithVisitor(): RequestStack
    {
        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, 'visitor-1');
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
