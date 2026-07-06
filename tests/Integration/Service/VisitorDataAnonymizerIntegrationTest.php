<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Führt die reale SHA2-/Idempotenz-SQL des VisitorDataAnonymizer gegen MySQL aus
 * — die DSGVO-Kern-Invarianten (deterministischer Hash, `customer_id` NULL,
 * `anonymized_at IS NULL`-Guard), die im Unit-Test nur String-geprüft waren.
 */
final class VisitorDataAnonymizerIntegrationTest extends IntegrationTestCase
{
    private const THRESHOLD = '2026-07-05 12:00:00';
    private const OLD = '2026-07-01 00:00:00.000';
    private const RECENT = '2026-07-10 00:00:00.000';

    public function testAnonymizesOldRowsDeterministicallyAndIsIdempotent(): void
    {
        $experimentId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        // Alte Zeilen (vor der Frist), gleicher Besucher in Assignment UND Event.
        $this->insertAssignment($experimentId, 'visitor-old', self::OLD, Uuid::randomHex());
        $this->insertEvent($experimentId, 'visitor-old', 'checkout.order_placed', self::OLD, Uuid::randomHex());
        // Junge Zeile (nach der Frist) — bleibt unberührt.
        $this->insertAssignment($experimentId, 'visitor-recent', self::RECENT, Uuid::randomHex());

        $anonymizer = new VisitorDataAnonymizer($this->connection);
        $affected = $anonymizer->anonymizeOlderThan(new \DateTimeImmutable(self::THRESHOLD, new \DateTimeZone('UTC')));

        // Eine alte Assignment- + eine alte Event-Zeile.
        self::assertSame(2, $affected);

        $expectedHash = hash('sha256', 'visitor-old');

        $oldAssignment = $this->connection->fetchAssociative(
            'SELECT `visitor_id`, `customer_id` IS NULL AS cust_null, `anonymized_at` FROM `rc_ab_assignment` WHERE `last_seen_at` = :t',
            ['t' => self::OLD],
        );
        self::assertIsArray($oldAssignment);
        self::assertSame($expectedHash, $oldAssignment['visitor_id']);
        self::assertSame(1, (int) $oldAssignment['cust_null']);
        self::assertNotNull($oldAssignment['anonymized_at']);

        // Determinismus: gleicher Besucher -> gleicher Hash in der Event-Tabelle.
        $oldEventVisitor = $this->connection->fetchOne(
            'SELECT `visitor_id` FROM `rc_ab_event` WHERE `occurred_at` = :t',
            ['t' => self::OLD],
        );
        self::assertSame($expectedHash, $oldEventVisitor);

        // Junge Zeile unberührt.
        $recentVisitor = $this->connection->fetchOne(
            'SELECT `visitor_id` FROM `rc_ab_assignment` WHERE `last_seen_at` = :t',
            ['t' => self::RECENT],
        );
        self::assertSame('visitor-recent', $recentVisitor);

        // Idempotenz: ein zweiter Lauf ändert nichts mehr (anonymized_at IS NULL-Guard).
        $secondRun = $anonymizer->anonymizeOlderThan(new \DateTimeImmutable(self::THRESHOLD, new \DateTimeZone('UTC')));
        self::assertSame(0, $secondRun);
    }
}
