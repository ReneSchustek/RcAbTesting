<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

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
 * Gemeinsame Verdrahtung für Resolver-, Twig- und Bridge-Tests: ein echter
 * VariantAssigner (mit gemocktem Assignment-Repository) hängt am Resolver, damit
 * die Tests Verhalten und DB-Trefferzahl prüfen, ohne finale Klassen zu mocken.
 */
abstract class VariantResolverTestCase extends TestCase
{
    protected const VARIANT_ID = 'variant-a-id';
    protected const EXPERIMENT_KEY = 'checkout';

    protected ?Request $builtRequest = null;

    protected function assignmentRepository(): EntityRepository
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId('exp-checkout');
        $assignment->setVariantId(self::VARIANT_ID);
        $assignment->setVisitorId('visitor-1');

        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection([$assignment]));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    protected function resolver(EntityRepository $assignmentRepository, bool $withRequest = true, bool $withSalesChannelContext = true): RequestVariantResolver
    {
        return new RequestVariantResolver(
            $this->variantAssigner($assignmentRepository),
            $this->requestStack($withRequest, $withSalesChannelContext),
        );
    }

    private function variantAssigner(EntityRepository $assignmentRepository): VariantAssigner
    {
        $experimentResult = $this->createStub(EntitySearchResult::class);
        $experimentResult->method('getEntities')->willReturn(new AbExperimentCollection([$this->experiment()]));
        $experimentRepository = $this->createMock(EntityRepository::class);
        $experimentRepository->method('search')->willReturn($experimentResult);

        $registry = new ExperimentRegistry($experimentRepository, new TagAwareAdapter(new ArrayAdapter()));

        return new VariantAssigner($registry, $assignmentRepository, new VisitorBucketer(), new NullLogger());
    }

    private function requestStack(bool $withRequest, bool $withSalesChannelContext): RequestStack
    {
        $stack = new RequestStack();
        if (!$withRequest) {
            return $stack;
        }

        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, 'visitor-1');
        $request->attributes->set(VisitorIdResolver::PERSISTENT_ATTRIBUTE, true);
        if ($withSalesChannelContext) {
            $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $this->salesChannelContext());
        }
        $stack->push($request);
        $this->builtRequest = $request;

        return $stack;
    }

    private function experiment(): AbExperimentEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(self::VARIANT_ID);
        $variant->setTechnicalKey('a');
        $variant->setName('A');
        $variant->setWeight(100);
        $variant->setIsControl(true);
        $variant->setConfig(['color' => 'red']);

        $experiment = new AbExperimentEntity();
        $experiment->setId('exp-checkout');
        $experiment->setTechnicalKey(self::EXPERIMENT_KEY);
        $experiment->setName('Checkout');
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([$variant]));

        return $experiment;
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getSalesChannelId')->willReturn(Uuid::randomHex());

        return $context;
    }
}
