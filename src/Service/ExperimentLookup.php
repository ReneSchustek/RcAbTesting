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
 * Lädt ein Experiment samt Varianten in jedem Status, direkt aus der Datenbank. Die
 * {@see ExperimentRegistry} kennt nur laufende Experimente; Konsolenbefehle und die Admin-API
 * müssen aber auch Entwürfe, pausierte und beendete erreichen.
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
