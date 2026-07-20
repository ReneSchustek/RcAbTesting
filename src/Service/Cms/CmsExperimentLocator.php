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
 * Experiment. Zuordnungsschlüssel ist die `cmsPageId` der **Control-Variante**:
 * Der Betreuer setzt Control = die aktuell live ausgelieferte Seite; läuft ein
 * Besucher auf genau diese Seite und ist er einer anderen Variante zugeordnet,
 * wird deren Seite ausgeliefert.
 *
 * Reine Lookup-/Lese-Logik über die (gecachte) Liste laufender Experimente —
 * ohne I/O, damit sie isoliert testbar bleibt und den Storefront-Request nicht
 * zusätzlich belastet.
 */
final class CmsExperimentLocator
{
    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
    ) {
    }

    /**
     * Laufendes CMS-Experiment, dessen Control-Variante genau diese Basis-Seite
     * trägt — oder null, wenn keines passt. Dokumentierte Invariante: höchstens
     * ein laufendes CMS-Experiment je Basis-Seite (der erste Treffer gewinnt).
     */
    public function findByBaseCmsPage(string $baseCmsPageId, Context $context): ?AbExperimentEntity
    {
        foreach ($this->experimentRegistry->getRunning($context) as $experiment) {
            if ($experiment->getTestType() !== AbExperimentTestType::CMS_PAGE) {
                continue;
            }

            $control = $experiment->getControlVariant();
            if ($control !== null && $this->cmsPageIdOf($control) === $baseCmsPageId) {
                return $experiment;
            }
        }

        return null;
    }

    /**
     * Ziel-CMS-Seite einer Variante oder null. Delegiert bewusst an die Variante —
     * der Locator bleibt der Ort, an dem Aufrufer danach fragen.
     */
    public function cmsPageIdOf(AbVariantEntity $variant): ?string
    {
        return $variant->getCmsPageId();
    }
}
