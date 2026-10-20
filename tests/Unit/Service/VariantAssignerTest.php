<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Service\VariantAssigner;
use Ruhrcoder\RcAbTesting\Service\VisitorBucketer;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Prüft die Zuteilung eines Besuchers zu einer Variante: Teilnahme nach Traffic-Anteil und
 * Targeting, Wiederfinden bestehender Zuordnungen, Übernahme über Geräte hinweg nach dem
 * Anmelden und das Verhalten bei gleichzeitigen Anfragen. Ein Fehler hier lässt Besucher die
 * Gruppe wechseln oder legt doppelte Zuordnungen an, und beides verfälscht jede spätere
 * Auswertung, ohne dass es auffällt.
 */
final class VariantAssignerTest extends TestCase
{
    public function testReturnsNullWhenExperimentNotRunning(): void
    {
        $registry = $this->registryWith();
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );

        self::assertNull($assigner->assign('unbekannt', 'visitor-1', $this->salesChannelContext()));
    }

    public function testReturnsNullWhenTrafficAllocationExcludesVisitor(): void
    {
        // 0 % Traffic schließt jeden Besucher aus, unabhängig von seinem Bucket.
        $experiment = $this->experiment('checkout', 0, [$this->variant('a', 100)]);
        $registry = $this->registryWith($experiment);
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );

        self::assertNull($assigner->assign('checkout', 'visitor-1', $this->salesChannelContext()));
    }

    public function testExistingAssignmentReturnsItsVariantAndTouchesLastSeen(): void
    {
        $variant = $this->variant('a', 100);
        $experiment = $this->experiment('checkout', 100, [$variant]);
        $registry = $this->registryWith($experiment);

        $existing = new AbAssignmentEntity();
        $existing->setId(Uuid::randomHex());
        $existing->setExperimentId($experiment->getId());
        $existing->setVariantId($variant->getId());
        $existing->setVisitorId('visitor-1');

        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($this->assignmentResult($existing));
        $assignmentRepository->expects(self::once())->method('update');
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext());

        self::assertSame($variant->getId(), $result?->getId());
    }

    public function testNewVisitorGetsAssignedAndPersisted(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 50), $this->variant('b', 50)]);
        $registry = $this->registryWith($experiment);

        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($this->assignmentResult());
        $assignmentRepository->expects(self::once())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext());

        self::assertNotNull($result);
        self::assertContains($result->getTechnicalKey(), ['a', 'b']);
    }

    public function testDeviceIsPersistedOnNewAssignment(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 50), $this->variant('b', 50)]);
        $registry = $this->registryWith($experiment);

        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($this->assignmentResult());
        $assignmentRepository->expects(self::once())->method('create')->with(self::callback(
            static fn (array $payload): bool => ($payload[0]['device'] ?? null) === 'mobile',
        ));

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext(), 'mobile');

        self::assertNotNull($result);
    }

    /**
     * Das Repository liefert die Zuordnung des anderen Geräts für jede Suche. Der Test belegt
     * deshalb nur, dass eine gefundene Zuordnung gewinnt und keine neue entsteht, nicht, dass
     * nach der Kundenkennung gesucht wird.
     */
    public function testLoggedInCustomerFollowsCrossDeviceVariant(): void
    {
        $variant = $this->variant('a', 100);
        $experiment = $this->experiment('checkout', 100, [$variant]);
        $registry = $this->registryWith($experiment);

        $customerAssignment = new AbAssignmentEntity();
        $customerAssignment->setId(Uuid::randomHex());
        $customerAssignment->setExperimentId($experiment->getId());
        $customerAssignment->setVariantId($variant->getId());
        $customerAssignment->setVisitorId('other-device-visitor');

        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($this->assignmentResult($customerAssignment));
        $assignmentRepository->expects(self::once())->method('update');
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'new-visitor', $this->salesChannelContextWithCustomer('cust-1'));

        self::assertSame($variant->getId(), $result?->getId());
    }

    public function testAttachesCustomerToVisitorAssignmentWhenLoggedInWithoutCustomerRow(): void
    {
        $variant = $this->variant('a', 100);
        $experiment = $this->experiment('checkout', 100, [$variant]);
        $registry = $this->registryWith($experiment);

        // Angemeldeter Kunde ohne Zeile für (Experiment, Kunde), aber mit einer Besucherzuordnung
        // ohne `customerId`, etwa weil die Umstellung beim Anmelden sie nicht erfasst hat. Ohne
        // Nachtrag sähe der Kunde die Variante, seine Conversion würde aber nicht gezählt.
        $visitorAssignment = new AbAssignmentEntity();
        $visitorAssignment->setId(Uuid::randomHex());
        $visitorAssignment->setExperimentId($experiment->getId());
        $visitorAssignment->setVariantId($variant->getId());
        $visitorAssignment->setVisitorId('visitor-1');
        $visitorAssignment->setCustomerId(null);

        $repository = $this->assignmentRepositoryByFilter([$visitorAssignment], []);
        $repository->expects(self::once())->method('update')->with(self::callback(
            static fn (array $payload): bool => $payload[0]['id'] === $visitorAssignment->getId()
                && $payload[0]['customerId'] === 'cust-1',
        ));
        $repository->expects(self::never())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $repository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContextWithCustomer('cust-1'));

        self::assertSame($variant->getId(), $result?->getId());
    }

    public function testRaceFallbackReturnsExistingVariantWhenCreateHitsUniqueConstraint(): void
    {
        $variant = $this->variant('a', 100);
        $experiment = $this->experiment('checkout', 100, [$variant]);
        $registry = $this->registryWith($experiment);

        $existing = new AbAssignmentEntity();
        $existing->setId(Uuid::randomHex());
        $existing->setExperimentId($experiment->getId());
        $existing->setVariantId($variant->getId());
        $existing->setVisitorId('visitor-1');

        $assignmentRepository = $this->createMock(EntityRepository::class);
        // Die erste Suche findet nichts, also wird angelegt. Eine parallele Anfrage war
        // schneller, `create()` scheitert am eindeutigen Schlüssel, und die zweite Suche liefert
        // deren Zuordnung. Beide Anfragen sehen so dieselbe Variante.
        $assignmentRepository->method('search')->willReturnOnConsecutiveCalls(
            $this->assignmentResult(),
            $this->assignmentResult($existing),
        );
        $assignmentRepository->method('create')->willThrowException(
            $this->createStub(UniqueConstraintViolationException::class),
        );

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext());

        self::assertSame($variant->getId(), $result?->getId());
    }

    public function testReturnsNullWhenSalesChannelTargetingDoesNotMatch(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 100)]);
        $experiment->setSalesChannelId('sales-channel-a');
        $registry = $this->registryWith($experiment);
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );

        self::assertNull($assigner->assign('checkout', 'visitor-1', $this->salesChannelContext('sales-channel-b')));
    }

    public function testAssignsWhenSalesChannelTargetingMatches(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 100)]);
        $experiment->setSalesChannelId('sales-channel-a');
        $registry = $this->registryWith($experiment);
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($this->assignmentResult());
        $assignmentRepository->expects(self::once())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext('sales-channel-a'));

        self::assertSame('a', $result?->getTechnicalKey());
    }

    public function testReturnsNullWhenRuleTargetingDoesNotMatch(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 100)]);
        $experiment->setRuleId('rule-b2b');
        $registry = $this->registryWith($experiment);
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );

        self::assertNull($assigner->assign('checkout', 'visitor-1', $this->salesChannelContext(null, ['rule-other'])));
    }

    public function testAssignsWhenRuleTargetingMatches(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 100)]);
        $experiment->setRuleId('rule-b2b');
        $registry = $this->registryWith($experiment);
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($this->assignmentResult());
        $assignmentRepository->expects(self::once())->method('create');

        $assigner = new VariantAssigner(
            $registry,
            $assignmentRepository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext(null, ['rule-b2b', 'rule-x']));

        self::assertSame('a', $result?->getTechnicalKey());
    }

    public function testUpgradeAttachesCustomerWhenNoPriorAssignment(): void
    {
        $visitorRow = $this->assignmentRow('exp-1', 'visitor-1', null);
        $repository = $this->assignmentRepositoryByFilter([$visitorRow], []);
        $repository->expects(self::once())->method('update')->with(self::callback(
            static fn (array $payload): bool => $payload[0]['customerId'] === 'cust-1' && isset($payload[0]['id']),
        ));
        $repository->expects(self::never())->method('delete');

        $assigner = new VariantAssigner(
            $this->registryWith(),
            $repository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $assigner->upgradeAssignmentsToCustomer('visitor-1', 'cust-1', Context::createDefaultContext());
    }

    public function testUpgradeDeletesRedundantRowAndReattachesAnonymousEventsToCanonicalVariant(): void
    {
        $experimentId = Uuid::randomHex();
        $customerId = Uuid::randomHex();
        $visitorRow = $this->assignmentRow($experimentId, 'visitor-1', null);
        $customerRow = $this->assignmentRow($experimentId, 'other-device', $customerId);
        $canonicalVariantId = $customerRow->getVariantId();

        $repository = $this->assignmentRepositoryByFilter([$visitorRow], [$customerRow]);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::once())->method('delete')->with(self::callback(
            static fn (array $payload): bool => $payload[0]['id'] === $visitorRow->getId(),
        ));

        // Die anonymen Ereignisse der gelöschten Zuordnung wandern auf den Kunden und die Variante
        // seiner verbleibenden Zuordnung; sonst zählten sie für eine Gruppe, in der er nicht mehr ist.
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('UPDATE rc_ab_event'),
            self::callback(static fn (array $params): bool =>
                $params['customerId'] === Uuid::fromHexToBytes($customerId)
                && $params['variantId'] === Uuid::fromHexToBytes($canonicalVariantId)
                && $params['experimentId'] === Uuid::fromHexToBytes($experimentId)
                && $params['visitorId'] === 'visitor-1'),
        );

        $assigner = new VariantAssigner(
            $this->registryWith(),
            $repository,
            new VisitorBucketer(),
            new NullLogger(),
            $connection
        );
        $assigner->upgradeAssignmentsToCustomer('visitor-1', $customerId, Context::createDefaultContext());
    }

    /**
     * Meldet sich Kunde B auf einem Gerät an, dessen Zuordnung schon Kunde A gehört, wird sie weder umgeschrieben noch
     * gelöscht. Schriebe die Übernahme sie auf B um, verlöre A seine Zuordnung samt Messung.
     */
    public function testUpgradeLeavesAnotherCustomersAssignmentAlone(): void
    {
        $sharedRow = $this->assignmentRow('exp-1', 'visitor-1', 'cust-a');
        $repository = $this->assignmentRepositoryByFilter([$sharedRow], []);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('delete');

        $assigner = new VariantAssigner(
            $this->registryWith(),
            $repository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $assigner->upgradeAssignmentsToCustomer('visitor-1', 'cust-b', Context::createDefaultContext());
    }

    public function testUpgradeSkipsWhenVisitorHasNoAssignments(): void
    {
        $repository = $this->assignmentRepositoryByFilter([], []);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('delete');

        $assigner = new VariantAssigner(
            $this->registryWith(),
            $repository,
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );
        $assigner->upgradeAssignmentsToCustomer('visitor-1', 'cust-1', Context::createDefaultContext());
    }

    public function testAppliesToWhenExperimentRunsAndTargetingMatchesRegardlessOfTrafficBucket(): void
    {
        // Bei 1 % Traffic nehmen fast alle Besucher nicht teil. `appliesTo()` fragt trotzdem
        // nur, ob das Experiment läuft und das Targeting passt. Darauf baut der Cache-Schutz:
        // Auch eine Seite für ausgeschlossene Besucher darf nicht in den gemeinsamen Cache.
        $registry = $this->registryWith($this->experiment('checkout', 1, [$this->variant('a', 100)]));
        $assigner = new VariantAssigner(
            $registry,
            $this->createMock(EntityRepository::class),
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );

        self::assertTrue($assigner->appliesTo('checkout', $this->salesChannelContext()));
    }

    public function testDoesNotApplyWhenNoRunningExperiment(): void
    {
        $assigner = new VariantAssigner(
            $this->registryWith(),
            $this->createMock(EntityRepository::class),
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );

        self::assertFalse($assigner->appliesTo('checkout', $this->salesChannelContext()));
    }

    public function testDoesNotApplyWhenSalesChannelTargetingExcludesContext(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 100)]);
        $experiment->setSalesChannelId('sales-channel-a');
        $assigner = new VariantAssigner(
            $this->registryWith($experiment),
            $this->createMock(EntityRepository::class),
            new VisitorBucketer(),
            new NullLogger(),
            $this->createMock(Connection::class)
        );

        self::assertFalse($assigner->appliesTo('checkout', $this->salesChannelContext('sales-channel-other')));
    }

    private function assignmentRow(string $experimentId, string $visitorId, ?string $customerId): AbAssignmentEntity
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId($experimentId);
        $assignment->setVariantId(Uuid::randomHex());
        $assignment->setVisitorId($visitorId);
        $assignment->setCustomerId($customerId);

        return $assignment;
    }

    /**
     * Repository-Attrappe, die je nach Filter auf `visitorId` oder `customerId` andere
     * Zuordnungen liefert, weil `upgradeAssignmentsToCustomer()` beide abfragt.
     *
     * @param list<AbAssignmentEntity> $byVisitor
     * @param list<AbAssignmentEntity> $byCustomer
     *
     * @return EntityRepository<AbAssignmentCollection>&\PHPUnit\Framework\MockObject\MockObject
     */
    private function assignmentRepositoryByFilter(array $byVisitor, array $byCustomer): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(function (Criteria $criteria) use ($byVisitor, $byCustomer): EntitySearchResult {
            foreach ($criteria->getFilters() as $filter) {
                if ($filter instanceof EqualsFilter && $filter->getField() === 'customerId') {
                    return $this->assignmentResult(...$byCustomer);
                }
            }

            return $this->assignmentResult(...$byVisitor);
        });

        return $repository;
    }

    private function registryWith(AbExperimentEntity ...$experiments): ExperimentRegistry
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection($experiments));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return new ExperimentRegistry($repository, new TagAwareAdapter(new ArrayAdapter()));
    }

    /**
     * @param list<AbVariantEntity> $variants
     */
    private function experiment(string $technicalKey, int $trafficPct, array $variants): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey($technicalKey);
        $experiment->setName($technicalKey);
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct($trafficPct);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection($variants));

        return $experiment;
    }

    private function variant(string $technicalKey, int $weight): AbVariantEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(Uuid::randomHex());
        $variant->setTechnicalKey($technicalKey);
        $variant->setName($technicalKey);
        $variant->setWeight($weight);
        $variant->setIsControl($technicalKey === 'a');

        return $variant;
    }

    /**
     * @return EntitySearchResult<AbAssignmentCollection>
     */
    private function assignmentResult(AbAssignmentEntity ...$assignments): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection($assignments));

        return $result;
    }

    /**
     * @param list<string> $ruleIds
     *
     * @return SalesChannelContext&MockObject
     */
    private function salesChannelContext(?string $salesChannelId = null, array $ruleIds = []): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getSalesChannelId')->willReturn($salesChannelId ?? Uuid::randomHex());
        $context->method('getRuleIds')->willReturn($ruleIds);

        return $context;
    }

    /**
     * @return SalesChannelContext&MockObject
     */
    private function salesChannelContextWithCustomer(string $customerId): SalesChannelContext
    {
        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getId')->willReturn($customerId);

        $context = $this->salesChannelContext();
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
