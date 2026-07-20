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
 *   Statistik (gleicher Besucher → gleicher Hash),
 * - bei `rc_ab_event` zusätzlich die `meta`-Spalte auf NULL — dort speichern die
 *   Subscriber personenbeziehbare Schlüssel (`customer_id`, `order_id`,
 *   `cart_token`), die sonst über die Aufbewahrungsfrist hinaus im Klartext
 *   verblieben (Arbeitspaket AB34). Die Aggregatoren lesen `meta` nicht, das Feld ist
 *   rein diagnostisch — sein Wegfall kostet keine Auswertung.
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
        // rc_ab_event trägt zusätzlich eine `meta`-Spalte mit Personenbezug.
        $affected += $this->anonymizeTable('rc_ab_event', 'occurred_at', $cutoff, $now, true);

        return $affected;
    }

    private function anonymizeTable(string $table, string $timeColumn, string $cutoff, string $now, bool $clearMeta = false): int
    {
        // Nur rc_ab_event hat `meta`- und `session_id`-Spalten mit möglichem
        // Personenbezug; dort mit-entfernen (session_id derzeit ungenutzt/leer,
        // aber der Anonymisierungspfad ist damit vollständig — AB49).
        $metaClause = $clearMeta ? ",\n                    `meta` = NULL,\n                    `session_id` = NULL" : '';
        // SHA2(...,256) liefert 64 Hex-Zeichen — passt exakt in VARCHAR(64).
        $sql = \sprintf(
            'UPDATE `%s`
                SET `visitor_id` = SHA2(`visitor_id`, 256),
                    `customer_id` = NULL,
                    `anonymized_at` = :now%s
              WHERE `%s` < :cutoff
                AND `anonymized_at` IS NULL',
            $table,
            $metaClause,
            $timeColumn,
        );

        return (int) $this->connection->executeStatement($sql, [
            'now' => $now,
            'cutoff' => $cutoff,
        ]);
    }
}
