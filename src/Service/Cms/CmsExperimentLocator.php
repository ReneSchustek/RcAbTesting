<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Cms;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Framework\Context;

/**
 * Findet zu einer ausgelieferten CMS-Seite das laufende CMS-Seiten-Experiment. Schlüssel ist die
 * `cmsPageId` der Control-Variante: Control ist die Seite, die ohnehin live steht. Ruft ein
 * Besucher genau diese Seite auf und ist einer anderen Variante zugeordnet, bekommt er deren
 * Seite.
 *
 * Gesucht wird in der zwischengespeicherten Liste aus {@see ExperimentRegistry}, ohne eigene
 * Datenbankabfrage, weil der Aufruf in jedem Seitenaufruf mit CMS-Seite liegt.
 */
final class CmsExperimentLocator
{
    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
    ) {
    }

    /**
     * Laufendes CMS-Experiment, dessen Control-Variante diese Seite trägt, sonst null.
     *
     * Je Seite soll höchstens ein CMS-Experiment laufen. Erzwungen wird das hier nicht: Laufen
     * zwei, gewinnt das erste in der Reihenfolge der Registry, das zweite bekommt keine Besucher.
     */
    public function findByBaseCmsPage(string $baseCmsPageId, Context $context): ?AbExperimentEntity
    {
        foreach ($this->experimentRegistry->getRunning($context) as $experiment) {
            if ($experiment->getTestType() !== AbExperimentTestType::CMS_PAGE) {
                continue;
            }

            $control = $experiment->getControlVariant();
            if ($control !== null && $control->getCmsPageId() === $baseCmsPageId) {
                return $experiment;
            }
        }

        return null;
    }
}
