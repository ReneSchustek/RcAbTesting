<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;
use Ruhrcoder\RcAbTesting\Service\Stats\StatisticsCalculator;

final class StatisticsCalculatorTest extends TestCase
{
    private StatisticsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new StatisticsCalculator(new NormalDistribution());
    }

    public function testEqualRatesAreNotSignificant(): void
    {
        $result = $this->calculator->calculate(1000, 50, 1000, 50);

        self::assertEqualsWithDelta(0.0, $result['z_score'], 1e-9);
        self::assertEqualsWithDelta(1.0, $result['p_value'], 1e-6);
        self::assertFalse($result['significant']);
        self::assertEqualsWithDelta(0.0, $result['lift'], 1e-9);
    }

    public function testSignificantDifference(): void
    {
        // Gepoolter Zwei-Proportionen-z-Test: z = (rateB - rateA) / SE.
        // 287/5234 vs 334/5189 -> z ~= 2.056, p ~= 0.0398 (gegen die
        // Standard-pooled-z-Test-Formel nachgerechnet).
        $result = $this->calculator->calculate(5234, 287, 5189, 334);

        self::assertNotNull($result['z_score']);
        self::assertNotNull($result['p_value']);
        self::assertEqualsWithDelta(2.056, $result['z_score'], 5e-3);
        self::assertEqualsWithDelta(0.0398, $result['p_value'], 2e-3);
        self::assertTrue($result['significant']);
        self::assertEqualsWithDelta(0.1739, $result['lift'], 1e-3);
    }

    public function testConfidenceIntervalBracketsRate(): void
    {
        $result = $this->calculator->calculate(5234, 287, 5189, 334);

        self::assertNotNull($result['a']);
        self::assertGreaterThanOrEqual($result['a']['rate'], $result['a']['ci_upper']);
        self::assertLessThanOrEqual($result['a']['rate'], $result['a']['ci_lower']);
        self::assertGreaterThanOrEqual(0.0, $result['a']['ci_lower']);
        self::assertLessThanOrEqual(1.0, $result['a']['ci_upper']);
    }

    public function testHigherConfidenceLevelFlipsSignificance(): void
    {
        // p ~= 0.0398: bei 95 % (Alpha 0,05) signifikant, bei 99 % (Alpha 0,01) nicht mehr.
        $at95 = $this->calculator->calculate(5234, 287, 5189, 334, 0.95);
        $at99 = $this->calculator->calculate(5234, 287, 5189, 334, 0.99);

        self::assertTrue($at95['significant']);
        self::assertFalse($at99['significant']);
        self::assertEqualsWithDelta(0.05, $at95['alpha'], 1e-9);
        self::assertEqualsWithDelta(0.01, $at99['alpha'], 1e-9);
    }

    public function testHigherConfidenceLevelWidensInterval(): void
    {
        $at95 = $this->calculator->calculate(5234, 287, 5189, 334, 0.95);
        $at99 = $this->calculator->calculate(5234, 287, 5189, 334, 0.99);

        self::assertNotNull($at95['a']);
        self::assertNotNull($at99['a']);
        $width95 = $at95['a']['ci_upper'] - $at95['a']['ci_lower'];
        $width99 = $at99['a']['ci_upper'] - $at99['a']['ci_lower'];
        self::assertGreaterThan($width95, $width99);
    }

    public function testInvalidConfidenceLevelFallsBackToDefault(): void
    {
        foreach ([0.0, 1.0, 1.5, -0.2] as $invalid) {
            $result = $this->calculator->calculate(1000, 50, 1000, 60, $invalid);
            self::assertEqualsWithDelta(0.05, $result['alpha'], 1e-9, \sprintf('Niveau %.1f', $invalid));
        }
    }

    public function testEmptySampleReturnsNulls(): void
    {
        $result = $this->calculator->calculate(0, 0, 100, 5);

        self::assertNull($result['a']);
        self::assertNull($result['b']);
        self::assertNull($result['lift']);
        self::assertNull($result['z_score']);
        self::assertNull($result['p_value']);
        self::assertFalse($result['significant']);
    }

    public function testNoVarianceYieldsNullZScore(): void
    {
        $result = $this->calculator->calculate(100, 0, 100, 0);

        self::assertNull($result['z_score']);
        self::assertNull($result['p_value']);
        self::assertNull($result['lift']);
        self::assertFalse($result['significant']);
        self::assertNotNull($result['a']);
        self::assertEqualsWithDelta(0.0, $result['a']['rate'], 1e-9);
    }
}
