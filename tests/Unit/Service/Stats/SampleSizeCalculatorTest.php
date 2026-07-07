<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;
use Ruhrcoder\RcAbTesting\Service\Stats\SampleSizeCalculator;

final class SampleSizeCalculatorTest extends TestCase
{
    private SampleSizeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SampleSizeCalculator(new NormalDistribution());
    }

    public function testKnownCase(): void
    {
        // Basisrate 5%, +20% relativ (-> 6%), Power 80%, Alpha 5%:
        // Arcsine-Formel ergibt 4072 je Variante.
        $n = $this->calculator->requiredSize(0.05, 0.20);

        self::assertEqualsWithDelta(4072, $n, 3);
    }

    public function testSmallerEffectNeedsLargerSample(): void
    {
        $small = $this->calculator->requiredSize(0.05, 0.10);
        $large = $this->calculator->requiredSize(0.05, 0.20);

        self::assertGreaterThan($large, $small);
    }

    public function testDegenerateInputReturnsZero(): void
    {
        self::assertSame(0, $this->calculator->requiredSize(0.05, 0.0));
        self::assertSame(0, $this->calculator->requiredSize(0.0, 0.2));
        self::assertSame(0, $this->calculator->requiredSize(0.9, 0.2)); // treatment >= 1
    }

    public function testMeanDifferenceKnownCase(): void
    {
        // Mittelwerte 2,00 -> 2,60 (Differenz 0,6), Varianzen 36 + 45,
        // Power 80 %, Alpha 5 %: n = (1,95996+0,84162)^2 * 81 / 0,36 ~= 1766.
        $n = $this->calculator->requiredSizeForMeanDifference(2.0, 2.6, 36.0, 45.0);

        self::assertEqualsWithDelta(1766, $n, 5);
    }

    public function testMeanDifferenceSmallerEffectNeedsLargerSample(): void
    {
        $small = $this->calculator->requiredSizeForMeanDifference(2.0, 2.2, 36.0, 45.0);
        $large = $this->calculator->requiredSizeForMeanDifference(2.0, 2.6, 36.0, 45.0);

        self::assertGreaterThan($large, $small);
    }

    public function testMeanDifferenceDegenerateInputReturnsZero(): void
    {
        self::assertSame(0, $this->calculator->requiredSizeForMeanDifference(2.0, 2.0, 36.0, 45.0)); // keine Differenz
        self::assertSame(0, $this->calculator->requiredSizeForMeanDifference(2.6, 2.0, 36.0, 45.0)); // Treatment schlechter
        self::assertSame(0, $this->calculator->requiredSizeForMeanDifference(2.0, 2.6, 0.0, 0.0));   // keine Streuung
    }
}
