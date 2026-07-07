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

final class ExperimentEvaluatorTest extends TestCase
{
    private ExperimentEvaluator $evaluator;

    protected function setUp(): void
    {
        $normal = new NormalDistribution();
        $this->evaluator = new ExperimentEvaluator(new StatisticsCalculator($normal), new SampleSizeCalculator($normal));
    }

    public function testSignificantTreatmentBecomesWinner(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);

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

    public function testNoWinnerWhenNotSignificant(): void
    {
        $control = $this->variant('control', 50, true);
        $treatment = $this->variant('treatment', 50, false);
        $experiment = $this->experiment(0.95, [$control, $treatment]);

        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 100, 'conversions' => 10],
            $treatment->getId() => ['assignments' => 100, 'conversions' => 11],
        ]);

        self::assertNull($result['winnerKey']);
        self::assertFalse($result['comparisons'][0]['significant']);
    }

    public function testMultiVariantProducesComparisonPerTreatment(): void
    {
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

        // 50/50 erwartet, aber 900/100 beobachtet → klare Schieflage.
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

        // Gleich viele Conversions (100/100) — unter der Conversion-Rate gäbe es
        // KEINEN Gewinner. Die Variante macht aber pro Besucher mehr Umsatz.
        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 1000, 'conversions' => 100, 'revenue' => 2000.0, 'revenueSumSq' => 40000.0],
            $treatment->getId() => ['assignments' => 1000, 'conversions' => 100, 'revenue' => 2600.0, 'revenueSumSq' => 52000.0],
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

        // Variante konvertiert HÄUFIGER (150 vs. 100), macht pro Besucher aber
        // WENIGER Umsatz (kleinere Warenkörbe) — unter der Umsatz-Entscheidung
        // kein Gewinner, negativer Umsatz-Lift.
        $result = $this->evaluator->evaluate($experiment, [
            $control->getId() => ['assignments' => 1000, 'conversions' => 100, 'revenue' => 2600.0, 'revenueSumSq' => 52000.0],
            $treatment->getId() => ['assignments' => 1000, 'conversions' => 150, 'revenue' => 2000.0, 'revenueSumSq' => 30000.0],
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
