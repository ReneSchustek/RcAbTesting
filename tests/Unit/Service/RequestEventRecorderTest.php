<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
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
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prüft das Bindeglied zwischen Shopware-Ereignissen und Tracker. Es läuft in Bestellung,
 * Anmeldung und Warenkorb mit; ein Fehler beim Zählen darf deshalb nie nach oben durchschlagen,
 * und ohne Besucherkennung (Admin, API) darf nichts geschrieben werden.
 */
final class RequestEventRecorderTest extends TestCase
{
    use RunningRegistryTrait;

    public function testSkipsWhenNoMainRequest(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(self::never())->method('create');

        $recorder = new RequestEventRecorder(
            new EventTracker(
                $this->createMock(EntityRepository::class),
                $eventRepository,
                $this->runningRegistry(),
                new NullLogger()
            ),
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
            new EventTracker(
                $this->createMock(EntityRepository::class),
                $eventRepository,
                $this->runningRegistry(),
                new NullLogger()
            ),
            $stack,
            new NullLogger(),
        );

        $recorder->record(AbEventType::PAGE_VIEWED, null, [], Context::createDefaultContext());
    }

    public function testRecordsWhenVisitorPresent(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->expects(
            self::once()
        )->method(
            'create'
        )->willReturn(
            $this->createStub(
                EntityWrittenContainerEvent::class
            )
        );

        $recorder = new RequestEventRecorder(
            new EventTracker(
                $this->assignmentRepository(),
                $eventRepository,
                $this->runningRegistry(),
                new NullLogger()
            ),
            $this->stackWithVisitor(),
            new NullLogger(),
        );

        $recorder->record(AbEventType::PAGE_VIEWED, null, ['route' => '/'], Context::createDefaultContext());
    }

    /**
     * Ein angemeldeter Kunde, mit und ohne Einwilligung. Mit Einwilligung sucht der Tracker über den Kunden, ohne sie
     * nur über die flüchtige Besucherkennung; so verknüpft die Messung einen Besucher ohne Einwilligung nie über seine
     * Kundenkennung mit Zuordnungen von anderen Geräten.
     *
     * @return iterable<string, array{bool, string}>
     */
    public static function consentCases(): iterable
    {
        yield 'mit Einwilligung' => [true, 'customerId'];
        yield 'ohne Einwilligung' => [false, 'visitorId'];
    }

    #[DataProvider('consentCases')]
    public function testTheCustomerIsOnlyUsedWithConsent(bool $persistent, string $expectedField): void
    {
        $usedField = null;
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturnCallback(function (Criteria $criteria) use (&$usedField) {
            foreach ($criteria->getFilters() as $filter) {
                if ($filter instanceof EqualsFilter
                    && \in_array(
                        $filter->getField(),
                        ['customerId', 'visitorId'],
                        true
                    )) {
                    $usedField = $filter->getField();
                }
            }
            $result = $this->createMock(EntitySearchResult::class);
            $result->method('getEntities')->willReturn(new AbAssignmentCollection());

            return $result;
        });

        $customer = new CustomerEntity();
        $customer->setId('kunde-1');
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn($customer);

        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, 'visitor-1');
        $request->attributes->set(VisitorIdResolver::PERSISTENT_ATTRIBUTE, $persistent);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);
        $stack = new RequestStack();
        $stack->push($request);

        (new RequestEventRecorder(
            new EventTracker(
                $assignmentRepository,
                $this->createMock(EntityRepository::class),
                $this->runningRegistry(),
                new NullLogger()
            ),
            $stack,
            new NullLogger(),
        ))->record(AbEventType::PAGE_VIEWED, null, [], Context::createDefaultContext());

        self::assertSame($expectedField, $usedField);
    }

    public function testSwallowsAndLogsTrackingError(): void
    {
        $eventRepository = $this->createMock(EntityRepository::class);
        $eventRepository->method('create')->willThrowException(new \RuntimeException('DB weg'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $recorder = new RequestEventRecorder(
            new EventTracker(
                $this->assignmentRepository(),
                $eventRepository,
                $this->runningRegistry(),
                new NullLogger()
            ),
            $this->stackWithVisitor(),
            $logger,
        );

        // Wirft der Aufruf, scheitert der Test; das Zählen darf die auslösende Bestellung nicht abbrechen.
        $recorder->record(AbEventType::PAGE_VIEWED, null, [], Context::createDefaultContext());
    }

    /**
     * @return EntityRepository<AbAssignmentCollection>
     */
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
