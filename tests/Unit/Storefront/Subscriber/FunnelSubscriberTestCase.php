<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Tests\Unit\Support\RunningRegistryTrait;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Gemeinsame Verdrahtung für Funnel-Subscriber-Tests: ein echter EventTracker
 * mit gemockten Repositories hängt am Recorder, sodass die Tests den real
 * geschriebenen Event-Payload prüfen (statt finale Klassen zu mocken).
 */
abstract class FunnelSubscriberTestCase extends TestCase
{
    use RunningRegistryTrait;

    /** @var list<array<string, mixed>>|null */
    protected ?array $capturedEvents = null;

    protected function recorderWithAssignment(): RequestEventRecorder
    {
        $assignmentResult = $this->createStub(EntitySearchResult::class);
        $assignmentResult->method('getEntities')->willReturn(new AbAssignmentCollection([$this->assignment()]));
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($assignmentResult);

        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturnCallback(function (array $payload): EntityWrittenContainerEvent {
            $this->capturedEvents = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        $tracker = new EventTracker($assignmentRepository, $eventRepository, $this->runningRegistry(), new NullLogger());

        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, 'visitor-1');
        $stack = new RequestStack();
        $stack->push($request);

        return new RequestEventRecorder($tracker, $stack, new NullLogger());
    }

    private function assignment(): AbAssignmentEntity
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId('exp-a');
        $assignment->setVariantId('var-a');
        $assignment->setVisitorId('visitor-1');

        return $assignment;
    }
}
