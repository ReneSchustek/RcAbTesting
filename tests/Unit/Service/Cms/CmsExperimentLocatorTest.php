<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Cms;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\Cms\CmsExperimentLocator;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class CmsExperimentLocatorTest extends TestCase
{
    public function testFindsCmsExperimentByControlCmsPageId(): void
    {
        $baseCmsPageId = Uuid::randomHex();
        $experiment = $this->cmsExperiment('exp-1', $baseCmsPageId, Uuid::randomHex());
        $locator = new CmsExperimentLocator($this->registryWith($experiment));

        $found = $locator->findByBaseCmsPage($baseCmsPageId, Context::createDefaultContext());

        self::assertNotNull($found);
        self::assertSame('exp-1', $found->getTechnicalKey());
    }

    public function testReturnsNullWhenNoControlMatchesBasePage(): void
    {
        $experiment = $this->cmsExperiment('exp-1', Uuid::randomHex(), Uuid::randomHex());
        $locator = new CmsExperimentLocator($this->registryWith($experiment));

        self::assertNull($locator->findByBaseCmsPage(Uuid::randomHex(), Context::createDefaultContext()));
    }

    public function testIgnoresNonCmsExperimentsEvenIfControlPageMatches(): void
    {
        $baseCmsPageId = Uuid::randomHex();
        $experiment = $this->cmsExperiment('exp-1', $baseCmsPageId, Uuid::randomHex());
        $experiment->setTestType(AbExperimentTestType::TWIG);
        $locator = new CmsExperimentLocator($this->registryWith($experiment));

        self::assertNull($locator->findByBaseCmsPage($baseCmsPageId, Context::createDefaultContext()));
    }

    public function testCmsPageIdOfReadsConfigOrNull(): void
    {
        $locator = new CmsExperimentLocator($this->registryWith());
        $id = Uuid::randomHex();

        self::assertSame($id, $locator->cmsPageIdOf($this->variant(true, ['cmsPageId' => $id])));
        self::assertNull($locator->cmsPageIdOf($this->variant(false, null)));
        self::assertNull($locator->cmsPageIdOf($this->variant(false, ['cmsPageId' => ''])));
    }

    private function cmsExperiment(string $technicalKey, string $controlCmsPageId, string $variantCmsPageId): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey($technicalKey);
        $experiment->setName($technicalKey);
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setTestType(AbExperimentTestType::CMS_PAGE);
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection([
            $this->variant(true, ['cmsPageId' => $controlCmsPageId]),
            $this->variant(false, ['cmsPageId' => $variantCmsPageId]),
        ]));

        return $experiment;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function variant(bool $isControl, ?array $config): AbVariantEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(Uuid::randomHex());
        $variant->setTechnicalKey($isControl ? 'control' : 'variant');
        $variant->setName($isControl ? 'control' : 'variant');
        $variant->setWeight(50);
        $variant->setIsControl($isControl);
        $variant->setConfig($config);

        return $variant;
    }

    private function registryWith(AbExperimentEntity ...$experiments): ExperimentRegistry
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection($experiments));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return new ExperimentRegistry($repository, new TagAwareAdapter(new ArrayAdapter()));
    }
}
