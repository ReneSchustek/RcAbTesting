<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\FrontendSwitch;

use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\DeviceClassResolver;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Ruhrcoder\RcAbTesting\Service\VariantAssigner;
use Ruhrcoder\RcAbTesting\Service\VisitorBucketer;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Baut einen echten {@see FrontendSwitchResolver} mit gestubbten Repositories.
 * `FrontendSwitchResolver` ist final (nicht mockbar), daher wird die reale Kette
 * (Registry + RequestVariantResolver) verdrahtet — geteilt von Resolver- und
 * Dispatcher-Test.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait FrontendSwitchHarness
{
    private const HARNESS_VARIANT_ID = '0191aaaabbbbccccddddeeeeffff0000';
    private const HARNESS_EXPERIMENT_ID = '0191ffff0000111122223333444455aa';

    /**
     * @param array<string, mixed> $variantConfig
     */
    private function switchResolver(array $variantConfig, string $testType = AbExperimentTestType::FRONTEND_SWITCH, bool $withRequest = true): FrontendSwitchResolver
    {
        $variant = new AbVariantEntity();
        $variant->setId(self::HARNESS_VARIANT_ID);
        $variant->setTechnicalKey('control');
        $variant->setName('Control');
        $variant->setWeight(100);
        $variant->setIsControl(true);
        $variant->setConfig($variantConfig);

        $experiment = new AbExperimentEntity();
        $experiment->setId(self::HARNESS_EXPERIMENT_ID);
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType($testType);
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([$variant]));

        $requestStack = $this->harnessRequestStack($withRequest);

        return new FrontendSwitchResolver(
            new ExperimentRegistry($this->harnessExperimentRepository($experiment), new TagAwareAdapter(new ArrayAdapter())),
            $this->harnessVariantResolver($experiment, $requestStack),
            $requestStack,
        );
    }

    private function harnessExperimentRepository(AbExperimentEntity $experiment): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection([$experiment]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    private function harnessVariantResolver(AbExperimentEntity $experiment, RequestStack $requestStack): RequestVariantResolver
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId($experiment->getId());
        $assignment->setVariantId(self::HARNESS_VARIANT_ID);
        $assignment->setVisitorId('visitor-1');

        $assignmentResult = $this->createStub(EntitySearchResult::class);
        $assignmentResult->method('getEntities')->willReturn(new AbAssignmentCollection([$assignment]));
        $assignmentRepository = $this->createMock(EntityRepository::class);
        $assignmentRepository->method('search')->willReturn($assignmentResult);

        $registry = new ExperimentRegistry($this->harnessExperimentRepository($experiment), new TagAwareAdapter(new ArrayAdapter()));
        $variantAssigner = new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());

        return new RequestVariantResolver($variantAssigner, $requestStack, new DeviceClassResolver());
    }

    private function harnessRequestStack(bool $withRequest): RequestStack
    {
        $stack = new RequestStack();
        if (!$withRequest) {
            return $stack;
        }

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());
        $salesChannelContext->method('getSalesChannelId')->willReturn(Uuid::randomHex());
        $salesChannelContext->method('getCustomer')->willReturn(null);
        $salesChannelContext->method('getRuleIds')->willReturn([]);

        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, 'visitor-1');
        $request->attributes->set(VisitorIdResolver::PERSISTENT_ATTRIBUTE, true);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $salesChannelContext);
        $stack->push($request);

        return $stack;
    }
}
