<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zählt je Variante eines Experiments die Zuordnungen (Stichprobengröße) und
 * die konvertierenden Besucher (primäre Metrik). Conversions werden als
 * **distinct** Besucher gezählt — ein Besucher mit zwei Bestellungen ist eine
 * Conversion, nicht zwei. Sicherheitshalber auf die Zuordnungszahl geklemmt,
 * damit die Rate nie über 100 % läuft.
 */
final class ExperimentStatsAggregator
{
    /**
     * @param EntityRepository<AbAssignmentCollection> $assignmentRepository
     */
    public function __construct(
        private readonly EntityRepository $assignmentRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, array{assignments: int, conversions: int, orders: int, revenue: float}>
     */
    public function aggregate(AbExperimentEntity $experiment, Context $context): array
    {
        $primaryMetric = $experiment->getPrimaryMetric() ?? AbEventType::CHECKOUT_ORDER_PLACED;

        $stats = [];
        foreach ($this->variants($experiment) as $variant) {
            $assignments = $this->countAssignments($experiment->getId(), $variant->getId(), $context);
            $conversions = $this->countConvertingVisitors($experiment->getId(), $variant->getId(), $primaryMetric);
            $stats[$variant->getId()] = [
                'assignments' => $assignments,
                'conversions' => \min($conversions, $assignments),
                // Bestellungen zaehlen Events (ein Besucher kann mehrfach kaufen) —
                // Basis fuer den durchschnittlichen Bestellwert; Umsatz = Summe der
                // mitgetrackten Bestellwerte (event_value).
                'orders' => $this->countOrders($experiment->getId(), $variant->getId(), $primaryMetric),
                'revenue' => $this->sumRevenue($experiment->getId(), $variant->getId(), $primaryMetric),
            ];
        }

        return $stats;
    }

    private function countAssignments(string $experimentId, string $variantId, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('experimentId', $experimentId));
        $criteria->addFilter(new EqualsFilter('variantId', $variantId));
        $criteria->setLimit(1);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $this->assignmentRepository->search($criteria, $context)->getTotal();
    }

    /**
     * Zählt die konvertierenden Besucher direkt per `COUNT(DISTINCT ...)` in der
     * Datenbank. Die frühere DAL-TermsAggregation materialisierte erst alle
     * Distinct-Visitor-Buckets in PHP, nur um sie zu zählen — bei vielen
     * Conversions unnötig speicherintensiv.
     */
    private function countConvertingVisitors(string $experimentId, string $variantId, string $eventType): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(DISTINCT visitor_id)
             FROM rc_ab_event
             WHERE experiment_id = :experimentId
               AND variant_id = :variantId
               AND event_type = :eventType',
            [
                'experimentId' => Uuid::fromHexToBytes($experimentId),
                'variantId' => Uuid::fromHexToBytes($variantId),
                'eventType' => $eventType,
            ],
        );

        return (int) $count;
    }

    /**
     * Anzahl der Bestell-Events (nicht distinct) — Nenner fuer den
     * durchschnittlichen Bestellwert.
     */
    private function countOrders(string $experimentId, string $variantId, string $eventType): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM rc_ab_event
             WHERE experiment_id = :experimentId
               AND variant_id = :variantId
               AND event_type = :eventType',
            [
                'experimentId' => Uuid::fromHexToBytes($experimentId),
                'variantId' => Uuid::fromHexToBytes($variantId),
                'eventType' => $eventType,
            ],
        );

        return (int) $count;
    }

    /**
     * Summe der mitgetrackten Bestellwerte (event_value) — der Umsatz je Variante.
     */
    private function sumRevenue(string $experimentId, string $variantId, string $eventType): float
    {
        $sum = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(event_value), 0)
             FROM rc_ab_event
             WHERE experiment_id = :experimentId
               AND variant_id = :variantId
               AND event_type = :eventType',
            [
                'experimentId' => Uuid::fromHexToBytes($experimentId),
                'variantId' => Uuid::fromHexToBytes($variantId),
                'eventType' => $eventType,
            ],
        );

        return (float) $sum;
    }

    /**
     * @return list<AbVariantEntity>
     */
    private function variants(AbExperimentEntity $experiment): array
    {
        $variants = $experiment->getVariants();

        return $variants === null ? [] : array_values($variants->getElements());
    }
}
