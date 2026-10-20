<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentSegmentAggregator;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft gegen MySQL, wie der Segment-Aggregator Ereignisse ihrer Zuordnung zuordnet.
 * Ein angemeldeter Kunde, der auf einem Gerät eingeteilt wurde und auf einem zweiten
 * Gerät mit anderer `visitor_id` kauft, zählt im Segment seiner Zuordnung. Ein Join
 * nur über `visitor_id` verlöre diese Conversion, und die Segmentansicht wiche still
 * von der Gesamtauswertung ab, die über die Kundenkennung zählt.
 */
final class ExperimentSegmentAggregatorIntegrationTest extends IntegrationTestCase
{
    public function testCrossDeviceConversionCountsInAssignmentSegment(): void
    {
        $experimentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();
        $customerId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        // Die einzige Zuordnung des Kunden entstand auf dem Telefon.
        $this->insertAssignment(
            $experimentId,
            'visitor-mobile',
            '2026-01-01 00:00:00.000',
            $customerId,
            null,
            'mobile',
            $variantId
        );
        // Gekauft wird auf einem zweiten Gerät: andere `visitor_id`, derselbe Kunde.
        $this->insertEvent(
            $experimentId,
            'visitor-desktop',
            'checkout.order_placed',
            '2026-01-02 00:00:00.000',
            $customerId
        );

        $stats = $this->aggregate($experimentId);

        // Das Segment kommt aus der Zuordnung, nicht vom Gerät des Kaufs.
        self::assertSame(1, $stats['mobile'][$variantId]['conversions']);
        self::assertSame(1, $stats['mobile'][$variantId]['assignments']);
    }

    public function testAnonymousConversionCountsViaVisitorId(): void
    {
        $experimentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        // Ohne `customer_id` bleibt nur die `visitor_id` als Brücke zwischen Ereignis und Zuordnung.
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
        // Ohne `primaryMetric` zählt der Aggregator `checkout.order_placed`.

        return (new ExperimentSegmentAggregator(
            $this->connection
        ))->aggregate(
            $experiment,
            ExperimentSegmentAggregator::DEVICE
        );
    }
}
