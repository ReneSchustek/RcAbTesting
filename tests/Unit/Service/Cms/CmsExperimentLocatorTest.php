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

/**
 * Prüft, wie zu einer gerenderten Erlebniswelt das laufende CMS-Experiment gefunden wird.
 * Träfe der Locator auch Twig-Experimente oder eine Nicht-Kontroll-Seite, tauschte die
 * Storefront Seiten, die gar nicht Teil eines CMS-Tests sind.
 */
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

    public function testTheVariantReadsItsCmsPageOrNull(): void
    {
        $id = Uuid::randomHex();

        self::assertSame($id, $this->variant(true, ['cmsPageId' => $id])->getCmsPageId());
        self::assertNull($this->variant(false, null)->getCmsPageId());
        // Eine leere Zeichenkette zählt wie keine Angabe. Sonst gälte die Variante als
        // vollständig konfiguriert, obwohl sie auf keine Seite zeigt.
        self::assertNull($this->variant(false, ['cmsPageId' => ''])->getCmsPageId());
    }

    private function cmsExperiment(
        string $technicalKey,
        string $controlCmsPageId,
        string $variantCmsPageId,
    ): AbExperimentEntity {
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
