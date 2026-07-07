<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Aggregiert dieselben Kennzahlen wie der {@see ExperimentStatsAggregator}, aber
 * zusätzlich aufgeteilt nach einer Segment-Dimension der Zuordnung (Gerät oder
 * Verkaufskanal). Das Segment sitzt bewusst an der Zuordnung: so lässt sich der
 * Nenner (Zuordnungen) je Segment zählen, und Conversions/Umsatz werden über
 * einen Join auf die Zuordnung demselben Segment zugeschrieben — ein Besucher,
 * der auf Mobil gebucketet wurde, zählt auch bei Kauf zum Mobil-Segment.
 *
 * Aggregation bewusst per SQL (mit Join), nicht per DAL-Materialisierung: die
 * Zahl der (Segment × Variante)-Buckets ist klein, die zugrunde liegenden
 * Zeilen können viele sein.
 */
final class ExperimentSegmentAggregator
{
    public const DEVICE = 'device';
    public const SALES_CHANNEL = 'sales_channel';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, array<string, array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}>>
     *   Segment-Wert => Varianten-Id (hex) => Kennzahlen
     */
    public function aggregate(AbExperimentEntity $experiment, string $dimension): array
    {
        $segmentExpression = $this->segmentExpression($dimension);
        $eventType = $experiment->getPrimaryMetric() ?? AbEventType::CHECKOUT_ORDER_PLACED;
        $experimentId = Uuid::fromHexToBytes($experiment->getId());

        $stats = [];
        foreach ($this->assignmentsBySegment($experimentId, $segmentExpression) as $row) {
            $entry = &$this->entry($stats, (string) $row['seg'], (string) $row['variant']);
            $entry['assignments'] = (int) $row['c'];
            unset($entry);
        }

        foreach ($this->conversionsBySegment($experimentId, $segmentExpression, $eventType) as $row) {
            $entry = &$this->entry($stats, (string) $row['seg'], (string) $row['variant']);
            // Auf die Zuordnungszahl klemmen (Distinct-Conversions können nie über
            // die Teilnehmer je Segment hinausgehen).
            $entry['conversions'] = \min((int) $row['c'], $entry['assignments']);
            unset($entry);
        }

        foreach ($this->revenueBySegment($experimentId, $segmentExpression, $eventType) as $row) {
            $entry = &$this->entry($stats, (string) $row['seg'], (string) $row['variant']);
            $entry['revenue'] = (float) $row['s'];
            $entry['revenueSumSq'] = (float) $row['sq'];
            unset($entry);
        }

        return $stats;
    }

    /**
     * SQL-Ausdruck des Segment-Werts, immer auf die Zuordnung `a` bezogen. Strikt
     * whitelistet — der Wert fließt in SQL, darf also nicht frei kommen.
     */
    private function segmentExpression(string $dimension): string
    {
        return match ($dimension) {
            self::DEVICE => "COALESCE(a.device, 'unknown')",
            self::SALES_CHANNEL => 'LOWER(HEX(a.sales_channel_id))',
            default => throw new \InvalidArgumentException(\sprintf('Unbekannte Segment-Dimension "%s".', $dimension)),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentsBySegment(string $experimentId, string $segmentExpression): array
    {
        return $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT LOWER(HEX(a.variant_id)) AS variant, %s AS seg, COUNT(*) AS c
                 FROM rc_ab_assignment a
                 WHERE a.experiment_id = :experimentId
                 GROUP BY a.variant_id, seg',
                $segmentExpression,
            ),
            ['experimentId' => $experimentId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversionsBySegment(string $experimentId, string $segmentExpression, string $eventType): array
    {
        return $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT LOWER(HEX(a.variant_id)) AS variant, %s AS seg, COUNT(DISTINCT e.visitor_id) AS c
                 FROM rc_ab_event e
                 JOIN rc_ab_assignment a
                   ON a.experiment_id = e.experiment_id AND a.visitor_id = e.visitor_id
                 WHERE e.experiment_id = :experimentId AND e.event_type = :eventType
                 GROUP BY a.variant_id, seg',
                $segmentExpression,
            ),
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revenueBySegment(string $experimentId, string $segmentExpression, string $eventType): array
    {
        return $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT variant, seg, COALESCE(SUM(v), 0) AS s, COALESCE(SUM(v * v), 0) AS sq
                 FROM (
                     SELECT LOWER(HEX(a.variant_id)) AS variant, %s AS seg, SUM(e.event_value) AS v
                     FROM rc_ab_event e
                     JOIN rc_ab_assignment a
                       ON a.experiment_id = e.experiment_id AND a.visitor_id = e.visitor_id
                     WHERE e.experiment_id = :experimentId AND e.event_type = :eventType
                     GROUP BY a.variant_id, seg, e.visitor_id
                 ) AS per_visitor
                 GROUP BY variant, seg',
                $segmentExpression,
            ),
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );
    }

    /**
     * Referenz auf den (Segment, Variante)-Eintrag, bei Bedarf mit Nullwerten
     * angelegt — so bleiben die drei Aggregat-Durchläufe entkoppelt.
     *
     * @param array<string, array<string, array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}>> $stats
     *
     * @return array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}
     */
    private function &entry(array &$stats, string $segment, string $variantId): array
    {
        if (!isset($stats[$segment][$variantId])) {
            $stats[$segment][$variantId] = ['assignments' => 0, 'conversions' => 0, 'revenue' => 0.0, 'revenueSumSq' => 0.0];
        }

        return $stats[$segment][$variantId];
    }
}
