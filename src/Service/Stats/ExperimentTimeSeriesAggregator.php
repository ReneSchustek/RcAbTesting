<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Liefert je Variante die täglichen Zuwächse (neue Zuordnungen, neue
 * konvertierende Besucher, Umsatz) für den Zeitverlauf. Bewusst nur die Deltas
 * je Tag — die Admin-Ansicht bildet daraus den kumulativen Verlauf, damit sich
 * ein frühes Zufallssignal von einem stabilen Trend unterscheiden lässt. Tage
 * ohne Ereignis fehlen; die Ansicht summiert lückenlos über die vorhandenen
 * Punkte. Aggregation per SQL (Tages-Buckets sind wenige, Zeilen viele).
 *
 * Eine Conversion zählt am Tag der **ersten** Bestellung des Besuchers, damit die
 * kumulative Summe den distinct-Besuchern entspricht (kein Doppelzählen bei
 * mehreren Bestellungen).
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
            $this->point($days, (string) $row['v'], (string) $row['d'])['assignments'] = (int) $row['c'];
        }
        foreach ($this->conversionsPerDay($experimentId, $eventType) as $row) {
            $this->point($days, (string) $row['v'], (string) $row['d'])['conversions'] = (int) $row['c'];
        }
        foreach ($this->revenuePerDay($experimentId, $eventType) as $row) {
            $this->point($days, (string) $row['v'], (string) $row['d'])['revenue'] = (float) $row['r'];
        }

        return $this->sortByDate($days);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentsPerDay(string $experimentId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS v, DATE(assigned_at) AS d, COUNT(*) AS c
             FROM rc_ab_assignment
             WHERE experiment_id = :experimentId
             GROUP BY variant_id, d',
            ['experimentId' => $experimentId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversionsPerDay(string $experimentId, string $eventType): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT v, DATE(first_at) AS d, COUNT(*) AS c
             FROM (
                 SELECT LOWER(HEX(variant_id)) AS v, visitor_id, MIN(occurred_at) AS first_at
                 FROM rc_ab_event
                 WHERE experiment_id = :experimentId AND event_type = :eventType
                 GROUP BY variant_id, visitor_id
             ) AS first_conversion
             GROUP BY v, d',
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revenuePerDay(string $experimentId, string $eventType): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS v, DATE(occurred_at) AS d, COALESCE(SUM(event_value), 0) AS r
             FROM rc_ab_event
             WHERE experiment_id = :experimentId AND event_type = :eventType
             GROUP BY variant_id, d',
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
