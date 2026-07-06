<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Service\RequestEventRecorder;
use Ruhrcoder\RcAbTesting\Service\VariantAssigner;
use Ruhrcoder\RcAbTesting\Service\VisitorBucketer;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CustomerLoginSubscriber;
use Ruhrcoder\RcAbTesting\Tests\Unit\Support\RunningRegistryTrait;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Event\CustomerLoginEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CustomerLoginSubscriberTest extends TestCase
{
    use RunningRegistryTrait;

    public function testTracksLoginAndUpgradesAssignments(): void
    {
        $captured = null;
        $assignmentRepository = $this->assignmentRepository();

        $variantAssigner = new VariantAssigner(
            new ExperimentRegistry($this->createMock(EntityRepository::class), new TagAwareAdapter(new ArrayAdapter())),
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
        );

        $subscriber = new CustomerLoginSubscriber(
            $this->recorder($assignmentRepository, $captured),
            $variantAssigner,
            $this->requestStack(),
            new NullLogger(),
        );

        $subscriber->onCustomerLogin($this->event());

        self::assertNotNull($captured);
        self::assertSame(AbEventType::CUSTOMER_LOGGED_IN, $captured[0]['eventType']);
        self::assertSame(['customer_id' => 'cust-1'], $captured[0]['meta']);
    }

    private function assignmentRepository(): EntityRepository
    {
        $visitorRow = new AbAssignmentEntity();
        $visitorRow->setId('assignment-1');
        $visitorRow->setExperimentId('exp-a');
        $visitorRow->setVariantId('var-a');
        $visitorRow->setVisitorId('visitor-1');

        $repository = $this->createMock(EntityRepository::class);
        // Besucher hat eine Zuordnung, der Kunde noch keine → Attach-Pfad (update).
        $repository->method('search')->willReturnCallback(function (Criteria $criteria) use ($visitorRow): EntitySearchResult {
            foreach ($criteria->getFilters() as $filter) {
                if ($filter instanceof EqualsFilter && $filter->getField() === 'customerId') {
                    return $this->collection();
                }
            }

            return $this->collection($visitorRow);
        });
        $repository->expects(self::once())->method('update');
        $repository->expects(self::never())->method('delete');

        return $repository;
    }

    private function collection(AbAssignmentEntity ...$rows): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection($rows));

        return $result;
    }

    /**
     * @param list<array<string, mixed>>|null $captured
     */
    private function recorder(EntityRepository $assignmentRepository, ?array &$captured): RequestEventRecorder
    {
        $eventResult = $this->createStub(EntitySearchResult::class);
        $eventResult->method('getEntities')->willReturn(new AbAssignmentCollection([$this->loggedInAssignment()]));
        $trackerAssignments = $this->createMock(EntityRepository::class);
        $trackerAssignments->method('search')->willReturn($eventResult);

        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willReturnCallback(function (array $payload) use (&$captured): EntityWrittenContainerEvent {
            $captured = $payload;

            return $this->createStub(EntityWrittenContainerEvent::class);
        });

        $tracker = new EventTracker($trackerAssignments, $eventRepository, $this->runningRegistry(), new NullLogger());

        return new RequestEventRecorder($tracker, $this->requestStack(), new NullLogger());
    }

    private function loggedInAssignment(): AbAssignmentEntity
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId('a1');
        $assignment->setExperimentId('exp-a');
        $assignment->setVariantId('var-a');
        $assignment->setVisitorId('visitor-1');

        return $assignment;
    }

    private function requestStack(): RequestStack
    {
        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, 'visitor-1');
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function event(): CustomerLoginEvent
    {
        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getId')->willReturn('cust-1');

        $event = $this->createMock(CustomerLoginEvent::class);
        $event->method('getCustomer')->willReturn($customer);
        $event->method('getContext')->willReturn(Context::createDefaultContext());

        return $event;
    }
}
