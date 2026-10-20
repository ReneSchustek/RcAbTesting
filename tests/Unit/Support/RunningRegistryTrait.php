<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Support;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Liefert eine `ExperimentRegistry` mit laufenden Experimenten für Tests rund um den
 * `EventTracker`, der nur für laufende Experimente schreibt. Ohne Angabe laufen `exp-a` und
 * `exp-b`; die Kennungen passen zu den Zuordnungen, die diese Tests anlegen.
 *
 * @mixin \PHPUnit\Framework\TestCase
 */
trait RunningRegistryTrait
{
    protected function runningRegistry(string ...$experimentIds): ExperimentRegistry
    {
        if ($experimentIds === []) {
            $experimentIds = ['exp-a', 'exp-b'];
        }

        $experiments = array_map(
            function (string $id): AbExperimentEntity {
                $experiment = new AbExperimentEntity();
                $experiment->setId($id);
                $experiment->setTechnicalKey($id);
                $experiment->setName($id);
                $experiment->setStatus(AbExperimentStatus::RUNNING);
                $experiment->setTestType('twig');
                $experiment->setTrafficAllocationPct(100);
                $experiment->setTargetSignificance(0.95);

                return $experiment;
            },
            $experimentIds,
        );

        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection($experiments));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return new ExperimentRegistry($repository, new TagAwareAdapter(new ArrayAdapter()));
    }

    protected function emptyRegistry(): ExperimentRegistry
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbExperimentCollection([]));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return new ExperimentRegistry($repository, new TagAwareAdapter(new ArrayAdapter()));
    }
}
