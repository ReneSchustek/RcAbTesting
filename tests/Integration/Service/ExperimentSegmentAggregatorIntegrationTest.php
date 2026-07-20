<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentSegmentAggregator;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft den Event→Zuordnung-Join des Segment-Aggregators gegen echtes MySQL —
 * insbesondere die Cross-Device-Attribution (Arbeitspaket AB35): ein auf einem Gerät
 * gebucketer, eingeloggter Kunde, der auf einem ZWEITEN Gerät (andere visitor_id)
 * kauft, muss weiterhin im Segment seiner kanonischen Zuordnung gezählt werden —
 * konsistent zum globalen Scorecard. Der frühere reine visitor_id-Join verlor
 * solche Conversions.
 */
final class ExperimentSegmentAggregatorIntegrationTest extends IntegrationTestCase
{
    public function testCrossDeviceConversionCountsInAssignmentSegment(): void
    {
        $experimentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();
        $customerId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        // Kunde auf „mobile" gebucketet (kanonische Zuordnung), Variante V.
        $this->insertAssignment($experimentId, 'visitor-mobile', '2026-01-01 00:00:00.000', $customerId, null, 'mobile', $variantId);
        // Conversion auf einem ZWEITEN Gerät: andere visitor_id, aber derselbe Kunde.
        $this->insertEvent($experimentId, 'visitor-desktop', 'checkout.order_placed', '2026-01-02 00:00:00.000', $customerId);

        $stats = $this->aggregate($experimentId);

        // Die Cross-Device-Conversion zählt im „mobile"-Segment (Zuordnungs-Segment).
        self::assertSame(1, $stats['mobile'][$variantId]['conversions']);
        self::assertSame(1, $stats['mobile'][$variantId]['assignments']);
    }

    public function testAnonymousConversionCountsViaVisitorId(): void
    {
        $experimentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        // Anonymer Besucher (keine customer_id): Zuordnung + Event teilen die visitor_id.
        $this->insertAssignment($experimentId, 'anon-1', '2026-01-01 00:00:00.000', null, null, 'desktop', $variantId);
        $this->insertEvent($experimentId, 'anon-1', 'checkout.order_placed', '2026-01-02 00:00:00.000');

        $stats = $this->aggregate($experimentId);

        self::assertSame(1, $stats['desktop'][$variantId]['conversions']);
    }

    /**
     * @return array<string, array<string, array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}>>
     */
    private function aggregate(string $experimentId): array
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId($experimentId);
        // primaryMetric bleibt null → Aggregator nutzt den checkout.order_placed-Fallback.

        return (new ExperimentSegmentAggregator($this->connection))->aggregate($experiment, ExperimentSegmentAggregator::DEVICE);
    }
}
