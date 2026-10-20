<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;

/**
 * Prüft, wie der Stats-Aggregator die Zeilen seiner zwei Abfragen zu Kennzahlen je Variante
 * zusammensetzt. Die Zeilen sind vorgegeben; ob das SQL Teilnehmer und Umsätze richtig
 * zusammenfasst, prüft `ExperimentStatsAggregatorIntegrationTest` gegen MySQL.
 */
final class ExperimentStatsAggregatorTest extends TestCase
{
    private const VARIANT_ID = '0191a1b2c3d4e5f60718293a4b5c6d7e';
    private const CHALLENGER_ID = '0191aaaabbbbccccddddeeeeffff0000';
    private const EXPERIMENT_ID = '0191ffeeddccbbaa9988776655443322';

    public function testCountsAssignmentsAndDistinctConversions(): void
    {
        $aggregator = new ExperimentStatsAggregator(
            $this->connection(
                [['variant_key' => self::VARIANT_ID, 'row_count' => '100']],
                [$this->totalsRow(self::VARIANT_ID, conversions: 3, orders: 3, revenue: 3.0, revenueSumSq: 9.0)],
            ),
        );

        $stats = $aggregator->aggregate($this->experiment());

        self::assertSame(
            ['assignments' => 100, 'conversions' => 3, 'orders' => 3, 'revenue' => 3.0, 'revenueSumSq' => 9.0],
            $stats[self::VARIANT_ID],
        );
    }

    public function testAggregatesOrdersAndRevenuePerVariant(): void
    {
        // Bestellungen zählen Ereignisse (8), Conversions Teilnehmer (5). Unterschiedliche
        // Zahlen zeigen, dass der Aggregator die Spalten nicht vertauscht.
        $aggregator = new ExperimentStatsAggregator(
            $this->connection(
                [['variant_key' => self::VARIANT_ID, 'row_count' => '100']],
                [$this->totalsRow(
                    self::VARIANT_ID,
                    conversions: 5,
                    orders: 8,
                    revenue: 1234.5,
                    revenueSumSq: 98765.43
                )],
            ),
        );

        $stats = $aggregator->aggregate($this->experiment());

        self::assertSame(
            ['assignments' => 100, 'conversions' => 5, 'orders' => 8, 'revenue' => 1234.5, 'revenueSumSq' => 98765.43],
            $stats[self::VARIANT_ID],
        );
    }

    public function testConversionsAreClampedToAssignments(): void
    {
        // Mehr Conversions als Zuordnungen ergäben eine Rate über 100 %; begrenzt werden nur
        // die Conversions, Bestellungen und Umsatz bleiben unverändert.
        $aggregator = new ExperimentStatsAggregator(
            $this->connection(
                [['variant_key' => self::VARIANT_ID, 'row_count' => '2']],
                [$this->totalsRow(self::VARIANT_ID, conversions: 4, orders: 4, revenue: 4.0, revenueSumSq: 16.0)],
            ),
        );

        $stats = $aggregator->aggregate($this->experiment());

        self::assertSame(
            ['assignments' => 2, 'conversions' => 2, 'orders' => 4, 'revenue' => 4.0, 'revenueSumSq' => 16.0],
            $stats[self::VARIANT_ID],
        );
    }

    public function testVariantWithoutRowsFallsBackToZero(): void
    {
        // Eine Variante ohne Zuordnungen und ohne Events liefert keine SQL-Zeile.
        $aggregator = new ExperimentStatsAggregator($this->connection([], []));

        $stats = $aggregator->aggregate($this->experiment());

        self::assertSame(
            ['assignments' => 0, 'conversions' => 0, 'orders' => 0, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
            $stats[self::VARIANT_ID],
        );
    }

    public function testAggregatesAllVariantsFromTwoGroupedQueries(): void
    {
        // Unabhängig von der Variantenzahl laufen genau zwei Abfragen, eine für Zuordnungen und
        // eine für die Conversion-Kennzahlen. Je Variante eine eigene Abfrage ließe die Last mit
        // jeder zusätzlichen Variante wachsen.
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql): array {
                if (str_contains($sql, 'rc_ab_assignment')) {
                    return [
                        ['variant_key' => self::VARIANT_ID, 'row_count' => '600'],
                        ['variant_key' => self::CHALLENGER_ID, 'row_count' => '400'],
                    ];
                }

                return [
$this->totalsRow(
    self::VARIANT_ID,
    conversions: 60,
    orders: 66,
    revenue: 6000.0,
    revenueSumSq: 700000.0,
),
$this->totalsRow(
    self::CHALLENGER_ID,
    conversions: 52,
    orders: 55,
    revenue: 5200.0,
    revenueSumSq: 600000.0,
),
                ];
            });

        $aggregator = new ExperimentStatsAggregator($connection);

        $stats = $aggregator->aggregate($this->experiment(withChallenger: true));

        self::assertSame(600, $stats[self::VARIANT_ID]['assignments']);
        self::assertSame(60, $stats[self::VARIANT_ID]['conversions']);
        self::assertSame(400, $stats[self::CHALLENGER_ID]['assignments']);
        self::assertSame(52, $stats[self::CHALLENGER_ID]['conversions']);
        self::assertSame(5200.0, $stats[self::CHALLENGER_ID]['revenue']);
    }

    /**
     * @return array<string, string>
     */
    private function totalsRow(
        string $variantId,
        int $conversions,
        int $orders,
        float $revenue,
        float $revenueSumSq,
    ): array {
        // DBAL liefert numerische Spalten als Zeichenketten; der Aggregator wandelt sie um.
        return [
            'variant_key' => $variantId,
            'conversions' => (string) $conversions,
            'orders' => (string) $orders,
            'revenue' => (string) $revenue,
            'revenue_sum_sq' => (string) $revenueSumSq,
        ];
    }

    /**
     * @param list<array<string, string>> $assignmentRows
     * @param list<array<string, string>> $totalsRows
     */
    private function connection(array $assignmentRows, array $totalsRows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'rc_ab_assignment') ? $assignmentRows : $totalsRows,
        );

        return $connection;
    }

    private function experiment(bool $withChallenger = false): AbExperimentEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(self::VARIANT_ID);
        $variant->setTechnicalKey('a');
        $variant->setName('A');
        $variant->setWeight($withChallenger ? 50 : 100);
        $variant->setIsControl(true);

        $variants = [$variant];

        if ($withChallenger) {
            $challenger = new AbVariantEntity();
            $challenger->setId(self::CHALLENGER_ID);
            $challenger->setTechnicalKey('b');
            $challenger->setName('B');
            $challenger->setWeight(50);
            $challenger->setIsControl(false);
            $variants[] = $challenger;
        }

        $experiment = new AbExperimentEntity();
        $experiment->setId(self::EXPERIMENT_ID);
        $experiment->setTechnicalKey('checkout');
        $experiment->setName('Checkout');
        $experiment->setStatus('running');
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);
        $experiment->setVariants(new AbVariantCollection($variants));

        return $experiment;
    }
}
