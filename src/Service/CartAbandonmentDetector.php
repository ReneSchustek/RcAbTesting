<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;

/**
 * Findet abgebrochene Warenkörbe rein aus den eigenen Funnel-Events: ein
 * Besucher mit `checkout.started` älter als die Frist, ohne späteres
 * `checkout.order_placed` und ohne ein bereits geschriebenes `cart.abandoned`
 * für genau diese Checkout-Sitzung. Das `cart.abandoned`-Event wird bei der
 * Erkennung mit `occurred_at = jetzt` geschrieben, liegt also stets nach dem
 * auslösenden `checkout.started`; deshalb darf der Ausschluss nur greifen, wenn
 * ein `cart.abandoned` >= diesem `checkout.started` existiert. Ohne diese
 * Zeitschranke würde ein einmaliger Abbruch einen Besucher lebenslang aus der
 * Zählung nehmen und spätere Abbrüche desselben Besuchers verschlucken.
 *
 * Die Erkennung ist eine reine Lese-Abfrage (kein Connection-Write auf
 * DAL-Tabellen); das Schreiben des Events übernimmt der Handler über die DAL.
 */
final class CartAbandonmentDetector
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{experimentId: string, variantId: string, visitorId: string}>
     */
    public function detect(\DateTimeImmutable $threshold): array
    {
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
