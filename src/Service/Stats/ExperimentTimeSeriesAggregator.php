<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Je Variante die täglichen Zuwächse für den Zeitverlauf: neue Zuordnungen, neue
 * konvertierende Besucher, Umsatz. Geliefert werden nur die Zuwächse; die Verwaltung summiert
 * sie auf, und am aufsummierten Verlauf lässt sich ein frühes Zufallssignal von einem stabilen
 * Trend unterscheiden. Tage ohne Ereignis fehlen, die Summe über die vorhandenen Punkte bleibt
 * trotzdem richtig. Gerechnet wird in SQL, weil es wenige Tage, aber viele Zeilen gibt.
 *
 * Eine Conversion zählt am Tag des ersten Ereignisses der Hauptkennzahl, damit ein Besucher mit
 * mehreren Bestellungen in der Summe nur einmal steht. Gezählt wird wie in der Gesamtauswertung je Kunde,
 * sonst je Besucher (`COALESCE(customer_id, visitor_id)`). Je Gerät gezählt, läge die Summe bei Kunden
 * mit mehreren Geräten über der Gesamtzahl.
 *
 * Die Tage sind UTC-Tage, weil die Zeitstempel in UTC gespeichert sind.
 */
final class ExperimentTimeSeriesAggregator
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, list<array{date: string, assignments: int, conversions: int, revenue: float}>>
     *   Varianten-Id (hex) => nach Datum sortierte Tagespunkte
     */
    public function aggregate(AbExperimentEntity $experiment): array
    {
        $eventType = $experiment->getPrimaryMetricOrDefault();
        $experimentId = Uuid::fromHexToBytes($experiment->getId());

        $days = [];
        foreach ($this->assignmentsPerDay($experimentId) as $row) {
            $this->point(
                $days,
                (string) $row['variant_key'],
                (string) $row['visit_day']
            )['assignments'] = (int) $row['row_count'];
        }
        foreach ($this->conversionsPerDay($experimentId, $eventType) as $row) {
            $this->point(
                $days,
                (string) $row['variant_key'],
                (string) $row['visit_day']
            )['conversions'] = (int) $row['row_count'];
        }
        foreach ($this->revenuePerDay($experimentId, $eventType) as $row) {
            $this->point(
                $days,
                (string) $row['variant_key'],
                (string) $row['visit_day']
            )['revenue'] = (float) $row['revenue'];
        }

        return $this->sortByDate($days);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentsPerDay(string $experimentId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS variant_key, DATE(assigned_at) AS visit_day, COUNT(*) AS row_count
             FROM rc_ab_assignment
             WHERE experiment_id = :experimentId
             GROUP BY variant_id, visit_day',
            ['experimentId' => $experimentId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversionsPerDay(string $experimentId, string $eventType): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT variant_key, DATE(first_at) AS visit_day, COUNT(*) AS row_count
             FROM (
                 SELECT LOWER(HEX(variant_id)) AS variant_key, COALESCE(customer_id, visitor_id) AS unit, MIN(occurred_at) AS first_at
                 FROM rc_ab_event
                 WHERE experiment_id = :experimentId AND event_type = :eventType
                 GROUP BY variant_id, COALESCE(customer_id, visitor_id)
             ) AS first_conversion
             GROUP BY variant_key, visit_day',
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revenuePerDay(string $experimentId, string $eventType): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS variant_key, DATE(occurred_at) AS visit_day, COALESCE(SUM(event_value), 0) AS revenue
             FROM rc_ab_event
             WHERE experiment_id = :experimentId AND event_type = :eventType
             GROUP BY variant_id, visit_day',
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );
    }

    /**
     * Referenz auf den (Variante, Tag)-Punkt, bei Bedarf mit Nullwerten angelegt.
     *
     * @param array<string, array<string, array{date: string, assignments: int, conversions: int, revenue: float}>> $days
     *
     * @return array{date: string, assignments: int, conversions: int, revenue: float}
     */
    private function &point(array &$days, string $variantId, string $date): array
    {
        if (!isset($days[$variantId][$date])) {
            $days[$variantId][$date] = ['date' => $date, 'assignments' => 0, 'conversions' => 0, 'revenue' => 0.0];
        }

        return $days[$variantId][$date];
    }

    /**
     * @param array<string, array<string, array{date: string, assignments: int, conversions: int, revenue: float}>> $days
     *
     * @return array<string, list<array{date: string, assignments: int, conversions: int, revenue: float}>>
     */
    private function sortByDate(array $days): array
    {
        $series = [];
        foreach ($days as $variantId => $points) {
            ksort($points);
            $series[$variantId] = array_values($points);
        }

        return $series;
    }
}
