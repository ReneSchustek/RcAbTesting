<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentEvaluator;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;
use Ruhrcoder\RcAbTesting\Service\Stats\SampleSizeCalculator;
use Ruhrcoder\RcAbTesting\Service\Stats\StatisticsCalculator;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft die Auswertung eines Experiments: Vergleich jeder Variante mit der Kontrolle,
 * Gewinnerwahl nach der eingestellten Entscheidungskennzahl und die Warnung bei schiefer
 * Verteilung der Besucher. Ein Fehler hier erklärt eine Variante zum Gewinner, die keiner
 * ist, und der Shop baut sie dauerhaft ein.
 *
 * Kein Fall setzt `minSampleSize`; die Regeln zum Zurückhalten des Gewinners prüft
 * `WinnerWithheldTest`.
 */
final class ExperimentEvaluatorTest extends TestCase
{
    private ExperimentEvaluator $evaluator;

    protected function setUp(): void
    {
        $normal = new NormalDistribution();
        $this->evaluator = new ExperimentEvaluator(
            new StatisticsCalculator($normal),
            new SampleSizeCalculator($normal)
        );
    }

    public function testSignificantTreatmentBecomesWinner(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);

        // Raten von 5,48 % und 6,44 %: ein Lift von 17,39 %, bei gut 5000 Besuchern je Gruppe
        // klar signifikant.
        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 5234, 'conversions' => 287],
            $treatment->getId() => ['assignments' => 5189, 'conversions' => 334],
        ]);

        self::assertSame('control', $result['controlKey']);
        self::assertSame('treatment', $result['winnerKey']);
        self::assertCount(1, $result['comparisons']);
        self::assertTrue($result['comparisons'][0]['significant']);
        self::assertEqualsWithDelta(0.1739, $result['comparisons'][0]['lift'], 1e-3);
        self::assertGreaterThan(0, $result['comparisons'][0]['requiredSamplePerVariant']);
    }

    /**
     * Ohne gesetzte Entscheidungskennzahl entscheidet der Evaluator nach Umsatz je Besucher, der Vorgabe. Fiele er
     * auf die Conversion-Rate zurück, entschiede er nach einer Kennzahl, die niemand gewählt hat.
     */
    public function testWithoutMetricTheDefaultDecides(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);
        $experiment->setDecisionMetric(null);

        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 100, 'conversions' => 10],
            $treatment->getId() => ['assignments' => 100, 'conversions' => 12],
        ]);

        self::assertSame('revenue_per_visitor', $result['decisionMetric']);
    }

    public function testNoWinnerWhenNotSignificant(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);

        // Eine Conversion Unterschied bei 100 Besuchern ist Rauschen.
        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 100, 'conversions' => 10],
            $treatment->getId() => ['assignments' => 100, 'conversions' => 11],
        ]);

        self::assertNull($result['winnerKey']);
        self::assertFalse($result['comparisons'][0]['significant']);
    }

    public function testMultiVariantProducesComparisonPerTreatment(): void
    {
        // 34 + 33 + 33, weil sich 100 nicht ohne Rest durch drei teilen lässt.
        $control = $this->variant('control', 34, true);
        $treatmentA = $this->variant('treatment-a', 33, false);
        $treatmentB = $this->variant('treatment-b', 33, false);
        $experiment = $this->experiment(0.95, [$control, $treatmentA, $treatmentB]);

        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 1000, 'conversions' => 100],
            $treatmentA->getId() => ['assignments' => 1000, 'conversions' => 110],
            $treatmentB->getId() => ['assignments' => 1000, 'conversions' => 120],
        ]);

        self::assertCount(2, $result['comparisons']);
        self::assertSame('treatment-a', $result['comparisons'][0]['variantKey']);
        self::assertSame('treatment-b', $result['comparisons'][1]['variantKey']);
    }

    public function testSampleRatioMismatchDetected(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);

        // 50/50 erwartet, 900/100 beobachtet: weit jenseits der Schwelle von z = 3,29.
        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 900, 'conversions' => 50],
            $treatment->getId() => ['assignments' => 100, 'conversions' => 5],
        ]);

        self::assertTrue($result['sampleRatioMismatch']);
    }

    public function testSampleRatioMismatchNullWhenTooFewData(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);

        // 40 Besucher insgesamt liegen unter der Mindestmenge von 100; darunter ist jede
        // Abweichung vom Soll-Verhältnis Zufall und `null` die ehrliche Antwort.
        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 20, 'conversions' => 2],
            $treatment->getId() => ['assignments' => 20, 'conversions' => 3],
        ]);

        self::assertNull($result['sampleRatioMismatch']);
    }

    public function testRevenuePerVisitorDecidesWinner(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);
        $experiment->setDecisionMetric('revenue_per_visitor');

        // Gleich viele Conversions (200 und 200), nach der Conversion-Rate gäbe es keinen
        // Gewinner. Die Variante bringt aber je Besucher 2,60 statt 2,00, also 30 % mehr Umsatz.
        // Jeder Käufer bestellt für 20 bzw. 26 €, die Quadratsummen sind also 200 * 20² und
        // 200 * 26². Das mögliche Minimum bei 100 Käufern läge für die Variante bei
        // 2 600² / 100 = 67 600; ein kleinerer Wert wäre als Testeingabe unmöglich.
        $result = $this->evaluator->evaluate($experiment, [
$control->getId() => [
    'assignments' => 2000,
    'conversions' => 200,
    'revenue' => 4000.0,
    'revenueSumSq' => 80000.0,
],
$treatment->getId() => [
    'assignments' => 2000,
    'conversions' => 200,
    'revenue' => 5200.0,
    'revenueSumSq' => 135200.0,
],
        ]);

        self::assertSame('revenue_per_visitor', $result['decisionMetric']);
        self::assertSame('treatment', $result['winnerKey']);
        self::assertTrue($result['comparisons'][0]['significant']);
        self::assertEqualsWithDelta(0.3, $result['comparisons'][0]['lift'], 1e-9);
        self::assertGreaterThan(0, $result['comparisons'][0]['requiredSamplePerVariant']);
    }

    public function testRevenueDecisionIgnoresConversionOnlyLift(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);
        $experiment->setDecisionMetric('revenue_per_visitor');

        // Die Variante konvertiert häufiger (150 statt 100), bringt je Besucher aber weniger
        // Umsatz, weil die Warenkörbe kleiner sind. Nach Umsatz entschieden gibt es keinen
        // Gewinner, der Lift ist negativ. 100 Käufer zu je 26 € ergeben die Quadratsumme 67 600.
        $result = $this->evaluator->evaluate($experiment, [
$control->getId() => [
    'assignments' => 1000,
    'conversions' => 100,
    'revenue' => 2600.0,
    'revenueSumSq' => 67600.0,
],
$treatment->getId() => [
    'assignments' => 1000,
    'conversions' => 150,
    'revenue' => 2000.0,
    'revenueSumSq' => 30000.0,
],
        ]);

        self::assertNull($result['winnerKey']);
        self::assertLessThan(0.0, $result['comparisons'][0]['lift']);
    }

    /**
     * @param list<AbVariantEntity> $variants
     */
    private function experiment(float $confidence, array $variants): AbExperimentEntity
    {
        $experiment = new AbExperimentEntity();
        $experiment->setId(Uuid::randomHex());
        $experiment->setTechnicalKey('exp');
        $experiment->setName('exp');
        $experiment->setTestType('twig');
        $experiment->setTrafficAllocationPct(100);
        $experiment->setTargetSignificance($confidence);
        // Ohne Angabe gilt der Umsatz je Besucher; diese Fälle prüfen die Conversion-Rate.
        $experiment->setDecisionMetric('conversion_rate');
        $experiment->setVariants(new AbVariantCollection($variants));

        return $experiment;
    }

    private function variant(string $key, int $weight, bool $isControl): AbVariantEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(Uuid::randomHex());
        $variant->setTechnicalKey($key);
        $variant->setName($key);
        $variant->setWeight($weight);
        $variant->setIsControl($isControl);

        return $variant;
    }
}
