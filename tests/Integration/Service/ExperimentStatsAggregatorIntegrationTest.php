<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Führt die zwei gruppierten Abfragen des Stats-Aggregators gegen echtes MySQL aus
 * (Arbeitspaket AB49). Der Unit-Test stubbt die Zeilen — hier wird die SQL-Semantik selbst
 * geprüft: Conversions sind **distinkte Besucher**, Bestellungen zählen Events, und
 * Umsatz/Quadratsumme werden **je Besucher gefaltet**, bevor über die Variante
 * summiert wird. Genau diese Faltung ist die Varianz-Basis des Mittelwert-Tests —
 * eine Summe über Einzel-Events würde sie still verfälschen.
 */
final class ExperimentStatsAggregatorIntegrationTest extends IntegrationTestCase
{
    public function testConversionsAreDistinctVisitorsWhileOrdersCountEvents(): void
    {
        $experimentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        $this->insertAssignment($experimentId, 'visitor-1', '2026-01-01 00:00:00.000', null, null, 'desktop', $variantId);
        $this->insertAssignment($experimentId, 'visitor-2', '2026-01-01 00:00:00.000', null, null, 'desktop', $variantId);

        // visitor-1 kauft zweimal (10 + 30), visitor-2 einmal (20).
        $this->order($experimentId, 'visitor-1', $variantId, 10.0);
        $this->order($experimentId, 'visitor-1', $variantId, 30.0);
        $this->order($experimentId, 'visitor-2', $variantId, 20.0);

        $stats = $this->aggregate($experimentId, [$variantId]);

        self::assertSame(2, $stats[$variantId]['assignments']);
        self::assertSame(2, $stats[$variantId]['conversions'], 'Zwei distinkte Besucher, nicht drei Events.');
        self::assertSame(3, $stats[$variantId]['orders'], 'Bestellungen zählen Events.');
        self::assertSame(60.0, $stats[$variantId]['revenue']);
        // Faltung je Besucher: 40² + 20² = 2000. Ohne Faltung waeren es 10²+30²+20² = 1400.
        self::assertSame(2000.0, $stats[$variantId]['revenueSumSq']);
    }

    public function testEachVariantGetsItsOwnTotals(): void
    {
        $experimentId = Uuid::randomHex();
        $control = Uuid::randomHex();
        $challenger = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        $this->insertAssignment($experimentId, 'c-1', '2026-01-01 00:00:00.000', null, null, 'desktop', $control);
        $this->insertAssignment($experimentId, 'b-1', '2026-01-01 00:00:00.000', null, null, 'desktop', $challenger);
        $this->insertAssignment($experimentId, 'b-2', '2026-01-01 00:00:00.000', null, null, 'desktop', $challenger);

        $this->order($experimentId, 'c-1', $control, 100.0);
        $this->order($experimentId, 'b-1', $challenger, 250.0);

        $stats = $this->aggregate($experimentId, [$control, $challenger]);

        self::assertSame(1, $stats[$control]['assignments']);
        self::assertSame(100.0, $stats[$control]['revenue']);
        self::assertSame(2, $stats[$challenger]['assignments']);
        self::assertSame(1, $stats[$challenger]['conversions']);
        self::assertSame(250.0, $stats[$challenger]['revenue']);
    }

    public function testVariantWithoutEventsReportsZeroWithoutLosingAssignments(): void
    {
        $experimentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        $this->insertAssignment($experimentId, 'visitor-1', '2026-01-01 00:00:00.000', null, null, 'desktop', $variantId);

        $stats = $this->aggregate($experimentId, [$variantId]);

        self::assertSame(1, $stats[$variantId]['assignments']);
        self::assertSame(0, $stats[$variantId]['conversions']);
        self::assertSame(0, $stats[$variantId]['orders']);
        self::assertSame(0.0, $stats[$variantId]['revenue']);
        self::assertSame(0.0, $stats[$variantId]['revenueSumSq']);
    }

    public function testConversionsAreClampedToAssignments(): void
    {
        // Kaufte ein Besucher ohne Zuordnung (Datenlage nach Anonymisierung), darf
        // die Rate nicht über 100 % laufen.
        $experimentId = Uuid::randomHex();
        $variantId = Uuid::randomHex();
        $this->insertExperiment($experimentId);

        $this->order($experimentId, 'geist-1', $variantId, 5.0);

        $stats = $this->aggregate($experimentId, [$variantId]);

        self::assertSame(0, $stats[$variantId]['assignments']);
        self::assertSame(0, $stats[$variantId]['conversions']);
    }

    private function order(string $experimentId, string $visitorId, string $variantId, float $value): void
    {
        $this->insertEvent(
            $experimentId,
            $visitorId,
            'checkout.order_placed',
            '2026-01-02 00:00:00.000',
            variantIdHex: $variantId,
            eventValue: $value,
        );
    }

    /**
     * @param list<string> $variantIds
     *
     * @return array<string, array{assignments: int, conversions: int, orders: int, revenue: float, revenueSumSq: float}>
     */
    private function aggregate(string $experimentId, array $variantIds): array
    {
        $variants = [];
        foreach ($variantIds as $index => $variantId) {
            $variant = new AbVariantEntity();
            $variant->setId($variantId);
            $variant->setTechnicalKey('v' . $index);
            $variant->setName('V' . $index);
            $variant->setWeight(50);
            $variant->setIsControl($index === 0);
            $variants[] = $variant;
        }

        $experiment = new AbExperimentEntity();
        $experiment->setId($experimentId);
        // primaryMetric bleibt null → Aggregator nutzt den checkout.order_placed-Fallback.
        $experiment->setVariants(new AbVariantCollection($variants));

        return (new ExperimentStatsAggregator($this->connection))->aggregate($experiment);
    }
}
