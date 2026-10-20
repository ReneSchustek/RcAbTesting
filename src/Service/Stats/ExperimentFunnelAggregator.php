<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zählt je Variante die Besucher, die jede Funnel-Stufe erreicht haben. Bezugsgröße (100 %) sind
 * die Zuordnungen der Variante; als Anteil daran zeigt jede Stufe, wo eine Variante Besucher
 * verliert.
 *
 * Die Stufen steigen nie an. Je Besucher zählt die tiefste erreichte Stufe, und jede Stufe zählt
 * alle Besucher, die mindestens bis dorthin kamen. Wer über einen Express-Checkout oder den
 * Offcanvas-Warenkorb ohne eigenes `checkout.started` kauft, zählt so auch in den Stufen davor.
 * Keine spätere Stufe kann eine frühere übersteigen, und Express-Käufe gehen nicht verloren.
 *
 * Gezählt wird je Kunde, sonst je Besucher (`COALESCE(customer_id, visitor_id)`), in derselben Einheit
 * wie die Gesamtauswertung. Ein angemeldeter Kunde mit Ereignissen von zwei Geräten zählt einmal; je Gerät
 * gezählt, stünde er doppelt im Trichter.
 */
final class ExperimentFunnelAggregator
{
    /**
     * Die vier Kernschritte des Kauftrichters in ihrer Reihenfolge: Seite angesehen, Warenkorb,
     * Checkout, Kauf. Die Position in der Liste ist zugleich die Tiefe der Stufe.
     *
     * @var list<string>
     */
    public const STAGES = [
        AbEventType::PAGE_VIEWED,
        AbEventType::PRODUCT_ADDED_TO_CART,
        AbEventType::CHECKOUT_STARTED,
        AbEventType::CHECKOUT_ORDER_PLACED,
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, array{assignments: int, stages: array<string, int>}>
     *   Varianten-Id (hex) => Zuordnungen + monotone Besucherzahl je Stufe
     */
    public function aggregate(AbExperimentEntity $experiment): array
    {
        $experimentId = Uuid::fromHexToBytes($experiment->getId());

        $assignments = [];
        foreach ($this->assignmentsPerVariant($experimentId) as $row) {
            $assignments[(string) $row['variant_key']] = (int) $row['row_count'];
        }

        $depthsPerVariant = [];
        foreach ($this->deepestStagePerVariant($experimentId) as $row) {
            $depthsPerVariant[(string) $row['variant_key']][(int) $row['visit_day']] = (int) $row['row_count'];
        }

        $variantIds = array_unique([...array_keys($assignments), ...array_keys($depthsPerVariant)]);

        $result = [];
        foreach ($variantIds as $variantId) {
            $variantAssignments = $assignments[$variantId] ?? 0;
            $result[$variantId] = [
                'assignments' => $variantAssignments,
                'stages' => $this->cumulativeStages($depthsPerVariant[$variantId] ?? [], $variantAssignments),
            ];
        }

        return $result;
    }

    /**
     * Macht aus der Verteilung der tiefsten Stufe die Zahl je Stufe und begrenzt sie auf die
     * Zuordnungen, damit kein Anteil über 100 % steigt. Ohne Zuordnungen bleibt die Zahl
     * unbegrenzt.
     *
     * @param array<int, int> $depths Tiefste-Stufen-Index => Anzahl Besucher
     *
     * @return array<string, int>
     */
    private function cumulativeStages(array $depths, int $assignments): array
    {
        $stages = [];
        foreach (self::STAGES as $index => $stage) {
            $reached = 0;
            foreach ($depths as $depth => $visitors) {
                if ($depth >= $index) {
                    $reached += $visitors;
                }
            }

            $stages[$stage] = $assignments > 0 ? min($reached, $assignments) : $reached;
        }

        return $stages;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentsPerVariant(string $experimentId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS variant_key, COUNT(*) AS row_count
             FROM rc_ab_assignment
             WHERE experiment_id = :experimentId
             GROUP BY variant_id',
            ['experimentId' => $experimentId],
        );
    }

    /**
     * Wie viele Besucher je Variante welche Stufe als tiefste erreicht haben (Index in `STAGES`).
     * Das CASE übersetzt den Ereignistyp in die Tiefe, das innere MAX nimmt je Besucher die
     * tiefste.
     *
     * @return list<array<string, mixed>>
     */
    private function deepestStagePerVariant(string $experimentId): array
    {
        $cases = '';
        $params = ['experimentId' => $experimentId, 'stages' => self::STAGES];
        foreach (self::STAGES as $index => $stage) {
            // `$index` ist eine Ganzzahl aus der eigenen Konstante und darf in den SQL-Text; der
            // Ereignistyp geht als Parameter.
            $cases .= sprintf(' WHEN :stage%d THEN %d', $index, $index);
            $params['stage' . $index] = $stage;
        }

        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS variant_key, deepest AS visit_day, COUNT(*) AS row_count
             FROM (
                 SELECT variant_id, COALESCE(customer_id, visitor_id) AS unit, MAX(CASE event_type' . $cases . ' END) AS deepest
                 FROM rc_ab_event
                 WHERE experiment_id = :experimentId AND event_type IN (:stages)
                 GROUP BY variant_id, COALESCE(customer_id, visitor_id)
             ) t
             GROUP BY variant_id, deepest',
            $params,
            ['stages' => ArrayParameterType::STRING],
        );
    }
}
