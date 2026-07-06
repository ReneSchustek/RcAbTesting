<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Lädt ein Experiment anhand seines technical_key inklusive Varianten —
 * statusunabhängig (anders als die ExperimentRegistry, die nur laufende
 * Experimente cached). Genutzt von den CLI-Commands, die auch beendete oder
 * pausierte Experimente erreichen müssen.
 */
final class ExperimentLookup
{
    /**
     * @param EntityRepository<AbExperimentCollection> $experimentRepository
     */
    public function __construct(
        private readonly EntityRepository $experimentRepository,
    ) {
    }

    public function byTechnicalKey(string $technicalKey, Context $context): ?AbExperimentEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalKey', $technicalKey));
        $criteria->addAssociation('variants');
        $criteria->setLimit(1);

        $experiment = $this->experimentRepository->search($criteria, $context)->getEntities()->first();

        return $experiment instanceof AbExperimentEntity ? $experiment : null;
    }

    public function byId(string $id, Context $context): ?AbExperimentEntity
    {
        $criteria = new Criteria([$id]);
        $criteria->addAssociation('variants');

        $experiment = $this->experimentRepository->search($criteria, $context)->getEntities()->first();

        return $experiment instanceof AbExperimentEntity ? $experiment : null;
    }
}
