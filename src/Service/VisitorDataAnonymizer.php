<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Doctrine\DBAL\Connection;

/**
 * Anonymisiert personenbeziehbare Felder abgelaufener A/B-Daten (DSGVO-
 * Speicherbegrenzung). Älter als die Aufbewahrungsfrist werden in
 * `rc_ab_assignment` und `rc_ab_event`:
 *
 * - `customer_id` auf NULL gesetzt (Personenbezug entfernt),
 * - `visitor_id` durch einen deterministischen SHA-256-Hash ersetzt — das bricht
 *   den Bezug zum ursprünglichen Cookie, erhält aber die Distinct-Zählung der
 *   Statistik (gleicher Besucher → gleicher Hash).
 *
 * Der `anonymized_at`-Marker macht den Lauf idempotent: einmal anonymisierte
 * Zeilen werden nicht erneut geschrieben.
 */
final class VisitorDataAnonymizer
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Anonymisiert alle Zeilen, deren maßgebliche Zeit vor `$threshold` liegt.
     *
     * @return int Anzahl anonymisierter Zeilen (beide Tabellen summiert)
     */
    public function anonymizeOlderThan(\DateTimeImmutable $threshold): int
    {
        $cutoff = $threshold->format('Y-m-d H:i:s.v');
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $affected = $this->anonymizeTable('rc_ab_assignment', 'last_seen_at', $cutoff, $now);
        $affected += $this->anonymizeTable('rc_ab_event', 'occurred_at', $cutoff, $now);

        return $affected;
    }

    private function anonymizeTable(string $table, string $timeColumn, string $cutoff, string $now): int
    {
        // SHA2(...,256) liefert 64 Hex-Zeichen — passt exakt in VARCHAR(64).
        $sql = \sprintf(
            'UPDATE `%s`
                SET `visitor_id` = SHA2(`visitor_id`, 256),
                    `customer_id` = NULL,
                    `anonymized_at` = :now
              WHERE `%s` < :cutoff
                AND `anonymized_at` IS NULL',
            $table,
            $timeColumn,
        );

        return (int) $this->connection->executeStatement($sql, [
            'now' => $now,
            'cutoff' => $cutoff,
        ]);
    }
}
