<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\AbDeviceClass;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Dieselben Kennzahlen wie {@see ExperimentStatsAggregator}, zusätzlich aufgeteilt nach Gerät
 * oder Verkaufskanal. Das Segment hängt an der Zuordnung, nicht am Ereignis: So lässt sich der
 * Nenner je Segment zählen, und Conversions und Umsatz landen über den Join im selben Segment.
 * Wer auf dem Telefon zugeteilt wurde, zählt auch mit einem Kauf am Rechner zum Telefon-Segment.
 *
 * Gerechnet wird in SQL statt über geladene DAL-Entitäten, weil es nur wenige Segment-Varianten-
 * Paare gibt, aber sehr viele Zeilen dahinter.
 *
 * Conversions und Umsatz zählen wie in der Gesamtauswertung je Kunde, sonst je Besucher
 * (`COALESCE(customer_id, visitor_id)`). Ein Kunde, der auf zwei Geräten kauft, ergibt eine Conversion und
 * einen Umsatzwert, nicht zwei.
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
        $eventType = $experiment->getPrimaryMetricOrDefault();
        $experimentId = Uuid::fromHexToBytes($experiment->getId());

        $stats = [];
        foreach ($this->assignmentsBySegment($experimentId, $segmentExpression) as $row) {
            $entry = &$this->entry($stats, (string) $row['seg'], (string) $row['variant']);
            $entry['assignments'] = (int) $row['row_count'];
            unset($entry);
        }

        foreach ($this->conversionsBySegment($experimentId, $segmentExpression, $eventType) as $row) {
            $entry = &$this->entry($stats, (string) $row['seg'], (string) $row['variant']);
            // Mehr Conversions als Teilnehmer im Segment kann es nicht geben; die Begrenzung hält die Quote
            // auch dann bei höchstens 100 %, wenn ein Ereignis über die Kundenkennung zu mehreren Zuordnungen passt.
            $entry['conversions'] = \min((int) $row['row_count'], $entry['assignments']);
            unset($entry);
        }

        foreach ($this->revenueBySegment($experimentId, $segmentExpression, $eventType) as $row) {
            $entry = &$this->entry($stats, (string) $row['seg'], (string) $row['variant']);
            $entry['revenue'] = (float) $row['revenue_sum'];
            $entry['revenueSumSq'] = (float) $row['revenue_sum_sq'];
            unset($entry);
        }

        return $stats;
    }

    /**
     * SQL-Ausdruck für den Segmentwert, bezogen auf die Zuordnung `a`. Nur die zwei bekannten
     * Dimensionen sind erlaubt, weil der Ausdruck als Text in die Abfrage geht. Zuordnungen ohne
     * Gerät zählen als `unknown`, wie bei {@see \Ruhrcoder\RcAbTesting\Service\AbDeviceClass::UNKNOWN}.
     */
    private function segmentExpression(string $dimension): string
    {
        return match ($dimension) {
            self::DEVICE => \sprintf("COALESCE(a.device, '%s')", AbDeviceClass::UNKNOWN),
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
                'SELECT LOWER(HEX(a.variant_id)) AS variant, %s AS seg, COUNT(*) AS row_count
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
                'SELECT LOWER(HEX(m.variant_id)) AS variant, m.seg, COUNT(DISTINCT m.who) AS row_count
                 FROM (%s) AS m
                 GROUP BY m.variant_id, m.seg',
                $this->matchedEvents($segmentExpression),
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
                'SELECT variant, seg, COALESCE(SUM(visitor_revenue), 0) AS revenue_sum, COALESCE(SUM(visitor_revenue * visitor_revenue), 0) AS revenue_sum_sq
                 FROM (
                     SELECT LOWER(HEX(m.variant_id)) AS variant, m.seg, SUM(m.value) AS visitor_revenue
                     FROM (%s) AS m
                     GROUP BY m.variant_id, m.seg, m.who
                 ) AS per_visitor
                 GROUP BY variant, seg',
                $this->matchedEvents($segmentExpression),
            ),
            ['experimentId' => $experimentId, 'eventType' => $eventType],
        );
    }

    /**
     * Die Ereignisse der Zielmetrik mit der Zuordnung, zu der sie gehören: angemeldete Kunden über die Kundenkennung,
     * Gäste über die Besucherkennung.
     *
     * Zwei Teilabfragen statt einer Verknüpfung mit `OR`: Mit `OR` nutzt MySQL keinen der beiden Indizes und vergleicht
     * jedes Ereignis mit jeder Zuordnung. Bei 100.000 Besuchern dauerte die Auswertung so über eine Minute; getrennt
     * greift je Teil der Index auf (Experiment, Kunde) bzw. (Experiment, Besucher).
     */
    private function matchedEvents(string $segmentExpression): string
    {
        $part = 'SELECT a.variant_id, %1$s AS seg, COALESCE(e.customer_id, e.visitor_id) AS who, e.event_value AS value
                 FROM rc_ab_event e
                 JOIN rc_ab_assignment a ON a.experiment_id = e.experiment_id AND %2$s
                 WHERE e.experiment_id = :experimentId AND e.event_type = :eventType AND %3$s';

        return \sprintf($part, $segmentExpression, 'a.customer_id = e.customer_id', 'e.customer_id IS NOT NULL')
            . ' UNION ALL '
            . \sprintf($part, $segmentExpression, 'a.visitor_id = e.visitor_id', 'e.customer_id IS NULL');
    }

    /**
     * Referenz auf den Eintrag für Segment und Variante, bei Bedarf mit Nullwerten angelegt. So
     * muss keiner der drei Durchläufe wissen, ob ein anderer den Eintrag schon angelegt hat.
     *
     * @param array<string, array<string, array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}>> $stats
     *
     * @return array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}
     */
    private function &entry(array &$stats, string $segment, string $variantId): array
    {
        if (!isset($stats[$segment][$variantId])) {
            $stats[$segment][$variantId] = [
                'assignments' => 0,
                'conversions' => 0,
                'revenue' => 0.0,
                'revenueSumSq' => 0.0,
            ];
        }

        return $stats[$segment][$variantId];
    }
}
