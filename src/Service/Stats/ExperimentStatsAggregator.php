<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zählt je Variante eines Experiments die Zuordnungen (Stichprobengröße) und
 * die konvertierenden Besucher (primäre Metrik). Conversions werden als
 * **distinct** Besucher gezählt — ein Besucher mit zwei Bestellungen ist eine
 * Conversion, nicht zwei. Sicherheitshalber auf die Zuordnungszahl geklemmt,
 * damit die Rate nie über 100 % läuft.
 *
 * Aggregiert wird in genau zwei gruppierten Läufen (Zuordnungen, Events) — nicht
 * je Variante einzeln, sonst wächst die Abfragezahl mit der Variantenzahl.
 */
final class ExperimentStatsAggregator
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, array{assignments: int, conversions: int, orders: int, revenue: float, revenueSumSq: float}>
     */
    public function aggregate(AbExperimentEntity $experiment): array
    {
        $experimentId = Uuid::fromHexToBytes($experiment->getId());
        $primaryMetric = $experiment->getPrimaryMetricOrDefault();

        $assignments = $this->assignmentsPerVariant($experimentId);
        $conversions = $this->conversionTotalsPerVariant($experimentId, $primaryMetric);

        $stats = [];
        foreach ($experiment->getVariantList() as $variant) {
            $variantId = $variant->getId();
            $assignmentCount = $assignments[$variantId] ?? 0;
            $totals = $conversions[$variantId] ?? self::emptyTotals();

            $stats[$variantId] = [
                'assignments' => $assignmentCount,
                'conversions' => \min($totals['conversions'], $assignmentCount),
                // Bestellungen zählen Events (ein Besucher kann mehrfach kaufen) —
                // Basis für den durchschnittlichen Bestellwert; Umsatz = Summe der
                // mitgetrackten Bestellwerte (event_value).
                'orders' => $totals['orders'],
                // Umsatz je Variante und Quadratsumme des Umsatzes je Besucher —
                // Letztere ist die Varianz-Basis für den Mittelwert-Signifikanztest
                // auf „Umsatz pro Besucher".
                'revenue' => $totals['revenue'],
                'revenueSumSq' => $totals['revenueSumSq'],
            ];
        }

        return $stats;
    }

    /**
     * @return array{conversions: int, orders: int, revenue: float, revenueSumSq: float}
     */
    private static function emptyTotals(): array
    {
        return ['conversions' => 0, 'orders' => 0, 'revenue' => 0.0, 'revenueSumSq' => 0.0];
    }

    /**
     * @return array<string, int> Varianten-Id (hex) => Zuordnungen
     */
    private function assignmentsPerVariant(string $experimentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS v, COUNT(*) AS c
             FROM rc_ab_assignment
             WHERE experiment_id = :experimentId
             GROUP BY variant_id',
            ['experimentId' => $experimentId],
        );

        $assignments = [];
        foreach ($rows as $row) {
            $assignments[(string) $row['v']] = (int) $row['c'];
        }

        return $assignments;
    }

    /**
     * Conversion-Kennzahlen je Variante aus einer Abfrage: Der innere Lauf faltet
     * die Events auf einen Wert **je Besucher** (ein Besucher mit zwei Bestellungen
     * ist eine Conversion, sein Umsatz ist die Summe), der äußere summiert über die
     * Besucher der Variante. Die Quadratsumme ist die Varianz-Basis für den
     * Mittelwert-Signifikanztest; nicht-konvertierende Besucher tragen 0 bei und
     * werden erst im Evaluator über die Zuordnungszahl (Nenner) berücksichtigt.
     *
     * @return array<string, array{conversions: int, orders: int, revenue: float, revenueSumSq: float}>
     */
    private function conversionTotalsPerVariant(string $experimentId, string $eventType): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS v,
                    COUNT(*) AS conversions,
                    COALESCE(SUM(event_count), 0) AS orders,
                    COALESCE(SUM(visitor_revenue), 0) AS revenue,
                    COALESCE(SUM(visitor_revenue * visitor_revenue), 0) AS revenue_sum_sq
             FROM (
                 SELECT variant_id,
                        COUNT(*) AS event_count,
                        SUM(event_value) AS visitor_revenue
                 FROM rc_ab_event
                 WHERE experiment_id = :experimentId
                   AND event_type = :eventType
                   AND variant_id IS NOT NULL
                 GROUP BY variant_id, visitor_id
             ) AS per_visitor
             GROUP BY variant_id',
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['v']] = [
                'conversions' => (int) $row['conversions'],
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
                'revenueSumSq' => (float) $row['revenue_sum_sq'],
            ];
        }

        return $totals;
    }
}
