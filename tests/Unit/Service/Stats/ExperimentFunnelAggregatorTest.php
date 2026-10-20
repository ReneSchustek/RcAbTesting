<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentFunnelAggregator;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft den Trichter von Seitenaufruf bis Kauf je Variante. Jede Stufe zählt alle Besucher,
 * die sie oder eine spätere erreicht haben; sonst entstünde bei Express-Käufen ohne
 * `checkout.started` ein Trichter, der nach unten breiter wird, und die Abbruchquote wäre negativ.
 */
final class ExperimentFunnelAggregatorTest extends TestCase
{
    public function testStagesAreCumulativeFromDeepestReachedStage(): void
    {
        // Die Abfrage liefert je Besucher die tiefste erreichte Stufe (Index 0 bis 3): 560 nur
        // Seite, 130 bis Warenkorb, 110 bis Checkout, 100 bis Kauf. Zusammen 900 von 1000
        // Zugeordneten; 100 haben nach der Zuordnung nichts ausgelöst.
        $aggregator = new ExperimentFunnelAggregator($this->connection(
            assignments: [['variant_key' => 'aa', 'row_count' => '1000']],
            depths: [
                ['variant_key' => 'aa', 'visit_day' => '0', 'row_count' => '560'],
                ['variant_key' => 'aa', 'visit_day' => '1', 'row_count' => '130'],
                ['variant_key' => 'aa', 'visit_day' => '2', 'row_count' => '110'],
                ['variant_key' => 'aa', 'visit_day' => '3', 'row_count' => '100'],
            ],
        ));

        $result = $aggregator->aggregate($this->experiment());

        self::assertSame(1000, $result['aa']['assignments']);
        self::assertSame(
            [
                'page.viewed' => 900,
                'product.added_to_cart' => 340,
                'checkout.started' => 210,
                'checkout.order_placed' => 100,
            ],
            $result['aa']['stages'],
        );
    }

    public function testDownstreamPurchaseCountsTowardEarlierStagesSoFunnelStaysMonotone(): void
    {
        // 100 Besucher kaufen per Express (Off-Canvas, PayPal Express) ohne ein einziges
        // `checkout.started`, dazu 200, die nur bis zum Warenkorb kommen. Zählte jede Stufe
        // nur ihr eigenes Ereignis, stünden 0 im Checkout gegen 100 Käufe. Weil der Kauf auch
        // für die Stufen davor zählt, fällt der Trichter durchgehend.
        $aggregator = new ExperimentFunnelAggregator($this->connection(
            assignments: [['variant_key' => 'aa', 'row_count' => '1000']],
            depths: [
                ['variant_key' => 'aa', 'visit_day' => '1', 'row_count' => '200'],
                ['variant_key' => 'aa', 'visit_day' => '3', 'row_count' => '100'],
            ],
        ));

        $stages = $aggregator->aggregate($this->experiment())['aa']['stages'];

        self::assertSame(
            [
                'page.viewed' => 300,
                'product.added_to_cart' => 300,
                'checkout.started' => 100,
                'checkout.order_placed' => 100,
            ],
            $stages,
        );
        $values = array_values($stages);
        for ($i = 1, $n = \count($values); $i < $n; $i++) {
            self::assertLessThanOrEqual(
                $values[$i - 1],
                $values[$i],
                'Funnel-Stufe darf die Vorstufe nicht übersteigen'
            );
        }
    }

    public function testStagesAreClampedToAssignments(): void
    {
        // 80 Besucher mit Ereignissen gegen 50 Zuordnungen, etwa durch Ereignisse ohne passende
        // Zuordnung. Ohne Begrenzung stünde die erste Stufe bei 160 %.
        $aggregator = new ExperimentFunnelAggregator($this->connection(
            assignments: [['variant_key' => 'aa', 'row_count' => '50']],
            depths: [['variant_key' => 'aa', 'visit_day' => '0', 'row_count' => '80']],
        ));

        $stages = $aggregator->aggregate($this->experiment())['aa']['stages'];

        self::assertSame(50, $stages['page.viewed'], 'Stufe auf Zuordnungszahl geklemmt');
    }

    /**
     * Die Administration ordnet die Stufenwerte nach ihrer Position zu. Fehlte eine Stufe oder
     * stünde sie an anderer Stelle, rutschten alle folgenden Balken um eins.
     */
    public function testMissingStagesDefaultToZeroInOrder(): void
    {
        $aggregator = new ExperimentFunnelAggregator($this->connection(
            assignments: [['variant_key' => 'aa', 'row_count' => '50']],
            depths: [['variant_key' => 'aa', 'visit_day' => '0', 'row_count' => '40']],
        ));

        $result = $aggregator->aggregate($this->experiment());

        self::assertSame(
            ['page.viewed' => 40, 'product.added_to_cart' => 0, 'checkout.started' => 0, 'checkout.order_placed' => 0],
            $result['aa']['stages'],
        );
        self::assertSame(ExperimentFunnelAggregator::STAGES, array_keys($result['aa']['stages']));
    }

    /**
     * @param list<array<string, string>> $assignments
     * @param list<array<string, string>> $depths
     */
    private function connection(array $assignments, array $depths): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'rc_ab_event') ? $depths : $assignments,
        );

        return $connection;
    }

    private function experiment(): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey('exp');
        $experiment->setName('exp');
        $experiment->setStatus('running');
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance(0.95);

        return $experiment;
    }
}
