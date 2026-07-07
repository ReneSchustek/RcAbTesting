<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zaehlt je Variante die Besucher, die jede Funnel-Stufe erreicht haben — als
 * distinct Besucher je Stufen-Event. Bezugsgroesse (100 %) sind die Zuordnungen
 * der Variante (die Teilnehmer); jede Stufe wird als Anteil daran ausgewiesen, so
 * wird sichtbar, WO eine Variante Besucher verliert.
 *
 * Stufen bewusst fix und in Reihenfolge: Seite angesehen → Warenkorb → Checkout
 * → Kauf. Aggregation per SQL (wenige Stufen × Varianten, viele Event-Zeilen).
 */
final class ExperimentFunnelAggregator
{
    /**
     * Funnel-Stufen in Reihenfolge. Bewusste Auswahl aus den getrackten Events —
     * die vier Kern-Schritte des Kauftrichters.
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
     *   Varianten-Id (hex) => Zuordnungen + distinct Besucher je Stufen-Event
     */
    public function aggregate(AbExperimentEntity $experiment): array
    {
        $experimentId = Uuid::fromHexToBytes($experiment->getId());

        $result = [];
        foreach ($this->assignmentsPerVariant($experimentId) as $row) {
            $result[(string) $row['v']] = ['assignments' => (int) $row['c'], 'stages' => $this->emptyStages()];
        }

        foreach ($this->stageVisitorsPerVariant($experimentId) as $row) {
            $variantId = (string) $row['v'];
            if (!isset($result[$variantId])) {
                $result[$variantId] = ['assignments' => 0, 'stages' => $this->emptyStages()];
            }
            $result[$variantId]['stages'][(string) $row['t']] = (int) $row['c'];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStages(): array
    {
        return array_fill_keys(self::STAGES, 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentsPerVariant(string $experimentId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS v, COUNT(*) AS c
             FROM rc_ab_assignment
             WHERE experiment_id = :experimentId
             GROUP BY variant_id',
            ['experimentId' => $experimentId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stageVisitorsPerVariant(string $experimentId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(variant_id)) AS v, event_type AS t, COUNT(DISTINCT visitor_id) AS c
             FROM rc_ab_event
             WHERE experiment_id = :experimentId AND event_type IN (:stages)
             GROUP BY variant_id, event_type',
            ['experimentId' => $experimentId, 'stages' => self::STAGES],
            ['stages' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }
}
