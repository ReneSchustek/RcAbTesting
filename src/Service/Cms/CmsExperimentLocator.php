<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Cms;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Framework\Context;

/**
 * Findet zu einer aktuell ausgelieferten CMS-Seite das laufende CMS-Seiten-
 * Experiment. Zuordnungsschluessel ist die `cmsPageId` der **Control-Variante**:
 * Der Betreuer setzt Control = die aktuell live ausgelieferte Seite; laeuft ein
 * Besucher auf genau diese Seite und ist er einer anderen Variante zugeordnet,
 * wird deren Seite ausgeliefert.
 *
 * Reine Lookup-/Lese-Logik ueber die (gecachte) Liste laufender Experimente —
 * ohne I/O, damit sie isoliert testbar bleibt und den Storefront-Request nicht
 * zusaetzlich belastet.
 */
final class CmsExperimentLocator
{
    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
    ) {
    }

    /**
     * Laufendes CMS-Experiment, dessen Control-Variante genau diese Basis-Seite
     * traegt — oder null, wenn keines passt. Dokumentierte Invariante: hoechstens
     * ein laufendes CMS-Experiment je Basis-Seite (der erste Treffer gewinnt).
     */
    public function findByBaseCmsPage(string $baseCmsPageId, Context $context): ?AbExperimentEntity
    {
        foreach ($this->experimentRegistry->getRunning($context) as $experiment) {
            if ($experiment->getTestType() !== AbExperimentTestType::CMS_PAGE) {
                continue;
            }

            $control = $this->controlVariant($experiment);
            if ($control !== null && $this->cmsPageIdOf($control) === $baseCmsPageId) {
                return $experiment;
            }
        }

        return null;
    }

    /**
     * Ziel-CMS-Seite einer Variante (aus `config.cmsPageId`) oder null.
     */
    public function cmsPageIdOf(AbVariantEntity $variant): ?string
    {
        $config = $variant->getConfig();
        if (!\is_array($config)) {
            return null;
        }

        $cmsPageId = $config[AbExperimentTestType::CMS_PAGE_CONFIG_KEY] ?? null;

        return \is_string($cmsPageId) && $cmsPageId !== '' ? $cmsPageId : null;
    }

    private function controlVariant(AbExperimentEntity $experiment): ?AbVariantEntity
    {
        $variants = $experiment->getVariants();
        if ($variants === null) {
            return null;
        }

        foreach ($variants as $variant) {
            if ($variant->isControl()) {
                return $variant;
            }
        }

        return null;
    }
}
