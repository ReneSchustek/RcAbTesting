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
     * @return array<string, array{assignments: int, conversions: int, orders: int, revenue: float, revenueSumSq: float}>
     */
    public function aggregate(AbExperimentEntity $experiment, Context $context): array
    {
        $primaryMetric = $experiment->getPrimaryMetric() ?? AbEventType::CHECKOUT_ORDER_PLACED;

        $stats = [];
        foreach ($this->variants($experiment) as $variant) {
            $assignments = $this->countAssignments($experiment->getId(), $variant->getId(), $context);
            $conversions = $this->countConvertingVisitors($experiment->getId(), $variant->getId(), $primaryMetric);
            $revenue = $this->revenuePerVisitorTotals($experiment->getId(), $variant->getId(), $primaryMetric);
            $stats[$variant->getId()] = [
                'assignments' => $assignments,
                'conversions' => \min($conversions, $assignments),
                // Bestellungen zaehlen Events (ein Besucher kann mehrfach kaufen) —
                // Basis fuer den durchschnittlichen Bestellwert; Umsatz = Summe der
                // mitgetrackten Bestellwerte (event_value).
                'orders' => $this->countOrders($experiment->getId(), $variant->getId(), $primaryMetric),
                // Umsatz je Variante und Quadratsumme des Umsatzes je Besucher —
                // Letztere ist die Varianz-Basis fuer den Mittelwert-Signifikanztest
                // auf „Umsatz pro Besucher".
                'revenue' => $revenue['sum'],
                'revenueSumSq' => $revenue['sumSquares'],
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
     * Umsatz je Variante: Summe und Quadratsumme des Umsatzes **je Besucher**.
     * Erst wird der Umsatz je Besucher aufsummiert (ein Besucher mit zwei
     * Bestellungen zählt als ein Wert), dann über die Besucher summiert bzw.
     * quadriert. Die Quadratsumme ist die Varianz-Basis für den Mittelwert-
     * Signifikanztest; nicht-konvertierende Besucher tragen 0 bei und werden erst
     * im Evaluator über die Zuordnungszahl (Nenner) berücksichtigt. Bewusst als
     * ein aggregierender SQL-Lauf statt DAL-Materialisierung der Einzelwerte.
     *
     * @return array{sum: float, sumSquares: float}
     */
    private function revenuePerVisitorTotals(string $experimentId, string $variantId, string $eventType): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(visitor_revenue), 0) AS sum,
                    COALESCE(SUM(visitor_revenue * visitor_revenue), 0) AS sum_squares
             FROM (
                 SELECT SUM(event_value) AS visitor_revenue
                 FROM rc_ab_event
                 WHERE experiment_id = :experimentId
                   AND variant_id = :variantId
                   AND event_type = :eventType
                 GROUP BY visitor_id
             ) AS per_visitor',
            [
                'experimentId' => Uuid::fromHexToBytes($experimentId),
                'variantId' => Uuid::fromHexToBytes($variantId),
                'eventType' => $eventType,
            ],
        );

        if ($row === false) {
            return ['sum' => 0.0, 'sumSquares' => 0.0];
        }

        return ['sum' => (float) $row['sum'], 'sumSquares' => (float) $row['sum_squares']];
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
