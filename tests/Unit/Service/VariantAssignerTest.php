<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

final class VariantAssignerTest extends TestCase
{
    public function testReturnsNullWhenExperimentNotRunning(): void
    {
        $registry = $this->registryWith();
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());

        self::assertNull($assigner->assign('unbekannt', 'visitor-1', $this->salesChannelContext()));
    }

    public function testReturnsNullWhenTrafficAllocationExcludesVisitor(): void
    {
        $experiment = $this->experiment('checkout', 0, [$this->variant('a', 100)]);
        $registry = $this->registryWith($experiment);
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());

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

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
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

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext());

        self::assertNotNull($result);
        self::assertContains($result->getTechnicalKey(), ['a', 'b']);
    }

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

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
        $result = $assigner->assign('checkout', 'new-visitor', $this->salesChannelContextWithCustomer('cust-1'));

        self::assertSame($variant->getId(), $result?->getId());
    }

    public function testAttachesCustomerToVisitorAssignmentWhenLoggedInWithoutCustomerRow(): void
    {
        $variant = $this->variant('a', 100);
        $experiment = $this->experiment('checkout', 100, [$variant]);
        $registry = $this->registryWith($experiment);

        // Eingeloggter Kunde ohne (Experiment, Kunde)-Zeile, aber mit bestehender
        // Visitor-Zuordnung ohne customerId (Login-Upgrade hat sie nicht erfasst).
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

        $assigner = new VariantAssigner($registry, $repository, new VisitorBucketer(), new NullLogger());
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
        // Erste Suche: kein Treffer (neuer Besucher) → createAssignment. Ein
        // paralleler Request legt dieselbe Zuordnung an → create() wirft; die
        // zweite Suche im Catch liefert die inzwischen bestehende Zuordnung.
        $assignmentRepository->method('search')->willReturnOnConsecutiveCalls(
            $this->assignmentResult(),
            $this->assignmentResult($existing),
        );
        $assignmentRepository->method('create')->willThrowException(
            $this->createStub(UniqueConstraintViolationException::class),
        );

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext());

        self::assertSame($variant->getId(), $result?->getId());
    }

    public function testNonPersistentVisitorIsBucketedWithoutDatabaseWrite(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 100)]);
        $registry = $this->registryWith($experiment);

        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');
        $assignmentRepository->expects(self::never())->method('search');

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
        $result = $assigner->assign('checkout', 'visitor-1', $this->salesChannelContext(), false);

        self::assertSame('a', $result?->getTechnicalKey());
    }

    public function testReturnsNullWhenSalesChannelTargetingDoesNotMatch(): void
    {
        $experiment = $this->experiment('checkout', 100, [$this->variant('a', 100)]);
        $experiment->setSalesChannelId('sales-channel-a');
        $registry = $this->registryWith($experiment);
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->expects(self::never())->method('create');

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());

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

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
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

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());

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

        $assigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
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

        $assigner = new VariantAssigner($this->registryWith(), $repository, new VisitorBucketer(), new NullLogger());
        $assigner->upgradeAssignmentsToCustomer('visitor-1', 'cust-1', Context::createDefaultContext());
    }

    public function testUpgradeDeletesRedundantRowWhenCustomerAlreadyAssigned(): void
    {
        $visitorRow = $this->assignmentRow('exp-1', 'visitor-1', null);
        $customerRow = $this->assignmentRow('exp-1', 'other-device', 'cust-1');
        $repository = $this->assignmentRepositoryByFilter([$visitorRow], [$customerRow]);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::once())->method('delete')->with(self::callback(
            static fn (array $payload): bool => $payload[0]['id'] === $visitorRow->getId(),
        ));

        $assigner = new VariantAssigner($this->registryWith(), $repository, new VisitorBucketer(), new NullLogger());
        $assigner->upgradeAssignmentsToCustomer('visitor-1', 'cust-1', Context::createDefaultContext());
    }

    public function testUpgradeSkipsWhenVisitorHasNoAssignments(): void
    {
        $repository = $this->assignmentRepositoryByFilter([], []);
        $repository->expects(self::never())->method('update');
        $repository->expects(self::never())->method('delete');

        $assigner = new VariantAssigner($this->registryWith(), $repository, new VisitorBucketer(), new NullLogger());
        $assigner->upgradeAssignmentsToCustomer('visitor-1', 'cust-1', Context::createDefaultContext());
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
     * Repository-Fake, der je nach Filter (visitorId vs. customerId) unterschiedliche
     * Zuordnungen liefert — nötig, weil `upgradeAssignmentsToCustomer` beide abfragt.
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

    private function assignmentResult(AbAssignmentEntity ...$assignments): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection($assignments));

        return $result;
    }

    /**
     * @param list<string> $ruleIds
     */
    private function salesChannelContext(?string $salesChannelId = null, array $ruleIds = []): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getSalesChannelId')->willReturn($salesChannelId ?? Uuid::randomHex());
        $context->method('getRuleIds')->willReturn($ruleIds);

        return $context;
    }

    private function salesChannelContextWithCustomer(string $customerId): SalesChannelContext
    {
        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getId')->willReturn($customerId);

        $context = $this->salesChannelContext();
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
