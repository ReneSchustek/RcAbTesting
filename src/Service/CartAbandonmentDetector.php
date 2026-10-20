<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;

/**
 * Findet abgebrochene Warenkörbe allein aus den eigenen Ereignissen: ein `checkout.started`
 * älter als die Frist, ohne späteres `checkout.order_placed` und ohne ein `cart.abandoned`,
 * das schon zu diesem Checkout geschrieben wurde.
 *
 * `cart.abandoned` entsteht mit dem Zeitpunkt der Erkennung und liegt damit immer nach dem
 * auslösenden Checkout. Deshalb schließt nur ein Abbruch-Ereignis aus, das nicht vor diesem
 * Checkout liegt. Ohne die Zeitschranke nähme ein einziger Abbruch den Besucher für immer aus
 * der Zählung, und jeder spätere Abbruch ginge verloren.
 *
 * Die Klasse liest nur. Das Ereignis schreibt `CartAbandonmentTaskHandler` über die DAL, damit
 * Schreibereignisse und Indexer der Tabelle nicht umgangen werden.
 */
final class CartAbandonmentDetector
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Nur laufende Experimente: Ein Abbruch in einem beendeten Experiment änderte dessen
     * abgeschlossene Zahlen im Nachhinein.
     *
     * @param \DateTimeImmutable $threshold in UTC, wie `occurred_at` gespeichert ist
     *
     * @return list<array{experimentId: string, variantId: string, visitorId: string}>
     */
    public function detect(\DateTimeImmutable $threshold): array
    {
        // Das GROUP BY fasst mehrere offene Checkouts desselben Besuchers zu einer Zeile zusammen,
        // sodass ein Lauf je Besucher, Experiment und Variante höchstens einen Abbruch meldet.
        $sql = '
            SELECT LOWER(HEX(s.experiment_id)) AS experimentId,
                   LOWER(HEX(s.variant_id))     AS variantId,
                   s.visitor_id                 AS visitorId
            FROM rc_ab_event s
            WHERE s.event_type = :started
              AND s.occurred_at < :threshold
              AND EXISTS (
                  SELECT 1 FROM rc_ab_experiment e
                  WHERE e.id = s.experiment_id
                    AND e.status = :running
              )
              AND NOT EXISTS (
                  SELECT 1 FROM rc_ab_event o
                  WHERE o.experiment_id = s.experiment_id
                    AND o.visitor_id = s.visitor_id
                    AND o.event_type = :ordered
                    AND o.occurred_at >= s.occurred_at
              )
              AND NOT EXISTS (
                  SELECT 1 FROM rc_ab_event a
                  WHERE a.experiment_id = s.experiment_id
                    AND a.visitor_id = s.visitor_id
                    AND a.event_type = :abandoned
                    AND a.occurred_at >= s.occurred_at
              )
            GROUP BY s.experiment_id, s.variant_id, s.visitor_id
        ';

        /** @var list<array{experimentId: string, variantId: string, visitorId: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [
            'started' => AbEventType::CHECKOUT_STARTED,
            'ordered' => AbEventType::CHECKOUT_ORDER_PLACED,
            'abandoned' => AbEventType::CART_ABANDONED,
            'running' => AbExperimentStatus::RUNNING,
            'threshold' => $threshold->format('Y-m-d H:i:s'),
        ]);

        return $rows;
    }
}
