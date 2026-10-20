<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zählt je Variante die Zuordnungen (Stichprobe) und die Teilnehmer, die das Ereignis der
 * Hauptkennzahl ausgelöst haben. Ein Teilnehmer mit zwei Bestellungen ist eine Conversion; die
 * Zahl wird zusätzlich auf die Zuordnungen begrenzt, damit die Rate nie über 100 % steigt.
 *
 * Es laufen genau zwei gruppierte Abfragen, eine für Zuordnungen und eine für Ereignisse. Eine
 * Abfrage je Variante ließe die Zahl der Abfragen mit den Varianten wachsen.
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
                // Anzahl der Ereignisse, nicht der Teilnehmer, weil ein Teilnehmer mehrfach kaufen
                // kann; Grundlage des durchschnittlichen Bestellwerts. Bestellungen sind es nur,
                // wenn die Hauptkennzahl `checkout.order_placed` ist.
                'orders' => $totals['orders'],
                // Summe von `event_value` je Variante und die Quadratsumme der Werte je
                // Teilnehmer; aus ihr rechnet der Mittelwerttest die Varianz für „Umsatz je
                // Besucher".
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
            'SELECT LOWER(HEX(variant_id)) AS variant_key, COUNT(*) AS row_count
             FROM rc_ab_assignment
             WHERE experiment_id = :experimentId
             GROUP BY variant_id',
            ['experimentId' => $experimentId],
        );

        $assignments = [];
        foreach ($rows as $row) {
            $assignments[(string) $row['variant_key']] = (int) $row['row_count'];
        }

        return $assignments;
    }

    /**
     * Conversion-Kennzahlen je Variante in einer Abfrage. Die innere fasst die Ereignisse zu einem
     * Wert je Teilnehmer zusammen, die äußere summiert über die Teilnehmer der Variante.
     * Teilnehmer ohne Conversion fehlen hier; sie gehen erst im Evaluator über die Zuordnungszahl
     * in den Nenner ein und tragen dort 0 bei.
     *
     * Gruppiert wird nach Teilnehmer, nicht nach Besucher-Kennung. Der Nenner zählt Zuordnungen,
     * und nach dem Anmelden bleibt je Kunde und Experiment genau eine; die Ereignisse behalten
     * aber die Kennung ihres Geräts. Nach Besucher-Kennung gruppiert, ergäbe ein Kunde, der auf
     * Telefon und Rechner bestellt, zwei Conversions gegen eine Zuordnung.
     *
     * Noch schwerer wöge die Streuung. Beim Umsatz je Besucher geht die Quadratsumme in die
     * Varianz ein; auf zwei Kennungen verteilt entstünde die Summe der Quadrate statt des
     * Quadrats der Summe. Varianz, Standardfehler und p-Wert fielen zu klein aus, und die
     * Auswertung meldete Signifikanz, die es nicht gibt.
     *
     * `COALESCE(customer_id, visitor_id)` zählt deshalb in derselben Einheit wie der Nenner: je
     * angemeldetem Kunden einmal, sonst je Besucher. Ereignisse ohne Variante bleiben außen vor,
     * weil sie keiner Gruppe zugeschlagen werden können.
     *
     * @return array<string, array{conversions: int, orders: int, revenue: float, revenueSumSq: float}>
     */
    private function conversionTotalsPerVariant(string $experimentId, string $eventType): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS variant_key,
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
                 GROUP BY variant_id, COALESCE(customer_id, visitor_id)
             ) AS per_visitor
             GROUP BY variant_id',
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['variant_key']] = [
                'conversions' => (int) $row['conversions'],
                'orders' => (int) $row['orders'],
                'revenue' => (float) $row['revenue'],
                'revenueSumSq' => (float) $row['revenue_sum_sq'],
            ];
        }

        return $totals;
    }
}
