<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Liefert die aktuell laufenden Experimente aus einem getaggten Cache. Der
 * Bucketing-Pfad läuft pro Storefront-Request — ohne Cache würde jede Seite
 * die Experiment-Tabelle scannen. Invalidiert wird über den Tag bei jedem
 * Experiment-Write (über den Cache-Invalidation-Subscriber).
 */
final class ExperimentRegistry
{
    public const CACHE_TAG = 'rc_ab_running_experiments';

    private const CACHE_KEY = 'rc_ab_running_experiments';
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @param EntityRepository<AbExperimentCollection> $experimentRepository
     */
    public function __construct(
        private readonly EntityRepository $experimentRepository,
        private readonly TagAwareAdapterInterface $cache,
    ) {
    }

    /**
     * @return list<AbExperimentEntity>
     */
    public function getRunning(Context $context): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            /** @var list<AbExperimentEntity> $cached */
            $cached = $item->get();

            return $cached;
        }

        $running = $this->loadRunning($context);

        $item->set($running);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $item->tag(self::CACHE_TAG);
        $this->cache->save($item);

        return $running;
    }

    public function getByTechnicalKey(string $technicalKey, Context $context): ?AbExperimentEntity
    {
        foreach ($this->getRunning($context) as $experiment) {
            if ($experiment->getTechnicalKey() === $technicalKey) {
                return $experiment;
            }
        }

        return null;
    }

    public function invalidate(): void
    {
        $this->cache->invalidateTags([self::CACHE_TAG]);
    }

    /**
     * @return list<AbExperimentEntity>
     */
    private function loadRunning(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('status', AbExperimentStatus::RUNNING));
        $criteria->addAssociation('variants');
        // Defensiver Cap gegen unbegrenzte Result-Sets (AB49); real laufen nur
        // wenige Experimente gleichzeitig.
        $criteria->setLimit(500);

        /** @var list<AbExperimentEntity> $experiments */
        $experiments = array_values($this->experimentRepository->search($criteria, $context)->getEntities()->getElements());

        return $experiments;
    }
}
