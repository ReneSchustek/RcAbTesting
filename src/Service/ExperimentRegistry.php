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
 * Liefert die laufenden Experimente samt Varianten aus dem Objekt-Cache. Die Zuteilung fragt in
 * jedem Storefront-Request danach; ohne Cache liefe bei jedem Seitenaufruf eine Abfrage auf
 * Experimente und Varianten.
 *
 * Jeder Schreibvorgang an Experiment oder Variante über die DAL leert den Eintrag über den Tag
 * (`ExperimentCacheInvalidationSubscriber`).
 */
final class ExperimentRegistry
{
    /**
     * Obergrenze jeder Abfrage über Experimente, gegen unbegrenzte Ergebnismengen. Gleichzeitig laufen oder fällig
     * werden in einem Shop eine Handvoll Experimente; 500 erreicht nur ein Datenfehler.
     */
    public const QUERY_LIMIT = 500;

    public const CACHE_TAG = 'rc_ab_running_experiments';

    private const CACHE_KEY = 'rc_ab_running_experiments';

    /**
     * Rückfallgrenze für Änderungen, die am Abonnenten vorbeigehen, etwa direktes SQL oder ein
     * eingespielter Datenbankauszug. Fünf Minuten Verzug sind dort hinnehmbar; DAL-Schreibvorgänge
     * leeren den Eintrag ohnehin sofort.
     */
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

    /**
     * Nur laufende Experimente; für andere Status gibt es {@see ExperimentLookup}.
     */
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
        $criteria->setLimit(self::QUERY_LIMIT);

        /** @var list<AbExperimentEntity> $experiments */
        $experiments = array_values(
            $this->experimentRepository->search(
                $criteria,
                $context
            )->getEntities()->getElements()
        );

        return $experiments;
    }
}
