<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Führt die Anonymisierung von `VisitorDataAnonymizer` gegen MySQL aus: gesalzener,
 * deterministischer Hash in beiden Tabellen, `customer_id` und `meta` geleert, junge
 * Zeilen unberührt, zweiter Lauf ohne Wirkung. Bräche eine dieser Eigenschaften,
 * blieben personenbezogene Daten über die Aufbewahrungsfrist hinaus stehen oder die
 * Statistik verlöre die Zuordnung zwischen Ereignis und Besucher.
 */
final class VisitorDataAnonymizerIntegrationTest extends IntegrationTestCase
{
    // Die Frist liegt zwischen OLD und RECENT, damit genau eine der beiden Zuordnungen fällig ist.
    private const THRESHOLD = '2026-07-05 12:00:00';
    private const OLD = '2026-07-01 00:00:00.000';
    private const RECENT = '2026-07-10 00:00:00.000';

    public function testAnonymizesOldRowsDeterministicallyAndIsIdempotent(): void
    {
        $experimentId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        // Derselbe Besucher in Zuordnung und Ereignis, beide vor der Frist. Das Ereignis
        // trägt personenbeziehbare Schlüssel in `meta`, die mit entfernt werden müssen.
        $this->insertAssignment($experimentId, 'visitor-old', self::OLD, Uuid::randomHex());
        $this->insertEvent($experimentId, 'visitor-old', 'checkout.order_placed', self::OLD, Uuid::randomHex(), [
            'customer_id' => Uuid::randomHex(),
            'order_id' => Uuid::randomHex(),
        ]);
        // Nach der Frist zuletzt gesehen, bleibt also unberührt.
        $this->insertAssignment($experimentId, 'visitor-recent', self::RECENT, Uuid::randomHex());

        $anonymizer = new VisitorDataAnonymizer($this->connection, 'test-salt');
        $affected = $anonymizer->anonymizeOlderThan(new \DateTimeImmutable(self::THRESHOLD, new \DateTimeZone('UTC')));

        // Eine alte Zuordnung und ein altes Ereignis.
        self::assertSame(2, $affected);

        // Ohne Salz ließe sich der Hash aus jedem bekannten Cookie-Wert nachrechnen; der
        // Bezug zur Person wäre wiederherstellbar und die Daten blieben personenbezogen.
        $expectedHash = hash('sha256', 'test-salt' . 'visitor-old');

        $oldAssignment = $this->connection->fetchAssociative(
            'SELECT `visitor_id`, `customer_id` IS NULL AS cust_null, `anonymized_at` FROM `rc_ab_assignment` WHERE `last_seen_at` = :t',
            ['t' => self::OLD],
        );
        self::assertIsArray($oldAssignment);
        self::assertSame($expectedHash, $oldAssignment['visitor_id']);
        self::assertSame(1, (int) $oldAssignment['cust_null']);
        self::assertNotNull($oldAssignment['anonymized_at']);

        // Derselbe Hash in der Ereignistabelle, sonst zählte die Statistik den Besucher doppelt.
        $oldEventVisitor = $this->connection->fetchOne(
            'SELECT `visitor_id` FROM `rc_ab_event` WHERE `occurred_at` = :t',
            ['t' => self::OLD],
        );
        self::assertSame($expectedHash, $oldEventVisitor);

        $oldEventMeta = $this->connection->fetchOne(
            'SELECT `meta` FROM `rc_ab_event` WHERE `occurred_at` = :t',
            ['t' => self::OLD],
        );
        self::assertNull($oldEventMeta);

        $recentVisitor = $this->connection->fetchOne(
            'SELECT `visitor_id` FROM `rc_ab_assignment` WHERE `last_seen_at` = :t',
            ['t' => self::RECENT],
        );
        self::assertSame('visitor-recent', $recentVisitor);

        // Ohne die Sperre über `anonymized_at` würde ein zweiter Lauf den Hash noch einmal
        // hashen und die Verbindung zu bereits anonymisierten Zeilen zerreißen.
        $secondRun = $anonymizer->anonymizeOlderThan(new \DateTimeImmutable(self::THRESHOLD, new \DateTimeZone('UTC')));
        self::assertSame(0, $secondRun);
    }

    /**
     * Ein Besucher hat eine abgelaufene Zuordnung in Experiment A und eine frische in B. Anonymisiert wird nur das
     * Ereignis in A; liefe der Join allein über `visitor_id`, träfe die alte Zuordnung auch die Ereignisse in B,
     * deren Zuordnung noch gilt.
     */
    public function testOnlyTheEventsOfTheExpiredAssignmentAreAnonymized(): void
    {
        $experimentA = Uuid::randomHex();
        $experimentB = Uuid::randomHex();
        $this->insertExperiment($experimentA);
        $this->insertExperiment($experimentB);

        $this->insertAssignment($experimentA, 'visitor-x', self::OLD);
        $this->insertAssignment($experimentB, 'visitor-x', self::RECENT);
        $this->insertEvent($experimentA, 'visitor-x', 'page.viewed', self::OLD);
        $this->insertEvent($experimentB, 'visitor-x', 'page.viewed', self::OLD);

        (new VisitorDataAnonymizer($this->connection, 'test-salt'))
            ->anonymizeOlderThan(new \DateTimeImmutable(self::THRESHOLD, new \DateTimeZone('UTC')));

        $visitorIn = fn (string $experimentId): mixed => $this->connection->fetchOne(
            'SELECT `visitor_id` FROM `rc_ab_event` WHERE `experiment_id` = :e',
            ['e' => Uuid::fromHexToBytes($experimentId)],
        );
        self::assertSame(hash('sha256', 'test-salt' . 'visitor-x'), $visitorIn($experimentA));
        self::assertSame('visitor-x', $visitorIn($experimentB));
    }
}
