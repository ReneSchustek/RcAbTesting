<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Doctrine\DBAL\Connection;

/**
 * Anonymisiert A/B-Daten, deren Aufbewahrungsfrist abgelaufen ist (Speicherbegrenzung nach
 * DSGVO). In `rc_ab_assignment` und `rc_ab_event` wird `customer_id` geleert und `visitor_id`
 * durch einen gesalzenen SHA-256-Hash ersetzt. Der Hash trennt die Zeile vom Cookie, lässt aber
 * die Zählung verschiedener Besucher intakt, weil derselbe Besucher denselben Hash bekommt. In
 * `rc_ab_event` fallen zusätzlich `meta` und `session_id` weg; `meta` trägt Kunden-, Bestell-
 * und Warenkorb-Kennungen, wird von keiner Auswertung gelesen und kostet beim Löschen nichts.
 *
 * `anonymized_at` macht den Lauf wiederholbar: Einmal anonymisierte Zeilen werden nicht erneut
 * gehasht.
 *
 * Das Salz ist Pflicht. Ohne Salz wäre der Hash nur ein Pseudonym: Wer einen Cookie-Wert kennt,
 * und dafür genügt der eigene Browser, hasht ihn und findet die Zeilen wieder. Was sich einer
 * Person wieder zuordnen lässt, bleibt personenbezogen. Als Salz dient das Anwendungsgeheimnis
 * (`kernel.secret`); es steht in der Umgebung, nicht in der Datenbank, und ein Datenbankauszug
 * allein reicht deshalb nicht, um den Bezug herzustellen.
 */
final class VisitorDataAnonymizer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $salt,
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

        // Beide Tabellen richten sich nach derselben Uhr, der letzten Aktivität des Besuchers
        // (`last_seen_at` der Zuordnung). Liefe jede nach ihrer eigenen, bekämen bei einem
        // Experiment, das länger läuft als die Frist, alte Ereignisse eines aktiven Besuchers
        // die gehashte Kennung, während seine Zuordnung die klare behielte. Die
        // Segment-Auswertung verlöre dann seine Conversions, die Gesamtansicht zählte sie weiter.
        //
        // Die Ereignisse laufen zuerst, weil sie über die Kennung der Zuordnung gefunden werden;
        // nach deren Anonymisierung gäbe es diesen Bezug nicht mehr.
        $affected = $this->anonymizeEvents($cutoff, $now);
        $affected += $this->anonymizeAssignments($cutoff, $now);

        return $affected;
    }

    /**
     * Anonymisiert Ereignisse nach der letzten Aktivität ihres Besuchers.
     *
     * Ereignisse ohne passende Zuordnung, etwa weil die Zuordnung gelöscht wurde, richten sich nach
     * ihrem eigenen Zeitpunkt; sonst blieben sie für immer stehen.
     *
     * Der Join verbindet über Besucher und Experiment, also mit genau der Zuordnung, zu der das Ereignis
     * gehört (eindeutiger Schlüssel). Verbände er nur über `visitor_id`, genügte bei einem Besucher mit
     * mehreren Zuordnungen eine abgelaufene, und auch die Ereignisse eines laufenden Experiments würden
     * zu früh anonymisiert.
     */
    private function anonymizeEvents(string $cutoff, string $now): int
    {
        $sql = 'UPDATE `rc_ab_event` e
                   LEFT JOIN `rc_ab_assignment` a
                          ON a.`visitor_id` = e.`visitor_id`
                         AND a.`experiment_id` = e.`experiment_id`
                         AND a.`anonymized_at` IS NULL
                    SET e.`visitor_id` = SHA2(CONCAT(:salt, e.`visitor_id`), 256),
                        e.`customer_id` = NULL,
                        e.`meta` = NULL,
                        e.`session_id` = NULL,
                        e.`anonymized_at` = :now
                  WHERE e.`anonymized_at` IS NULL
                    AND (
                          (a.`visitor_id` IS NOT NULL AND a.`last_seen_at` < :cutoff)
                       OR (a.`visitor_id` IS NULL AND e.`occurred_at` < :cutoff)
                    )';

        return (int) $this->connection->executeStatement($sql, [
            'now' => $now,
            'cutoff' => $cutoff,
            'salt' => $this->salt,
        ]);
    }

    /**
     * Anonymisiert die Zuordnungen, deren letzte Aktivität vor dem Stichtag liegt.
     */
    private function anonymizeAssignments(string $cutoff, string $now): int
    {
        // SHA2(…, 256) liefert 64 Hexadezimalzeichen und füllt `visitor_id` VARCHAR(64) genau.
        $sql = 'UPDATE `rc_ab_assignment`
                   SET `visitor_id` = SHA2(CONCAT(:salt, `visitor_id`), 256),
                       `customer_id` = NULL,
                       `anonymized_at` = :now
                 WHERE `last_seen_at` < :cutoff
                   AND `anonymized_at` IS NULL';

        return (int) $this->connection->executeStatement($sql, [
            'now' => $now,
            'cutoff' => $cutoff,
            'salt' => $this->salt,
        ]);
    }
}
