<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;
use Ruhrcoder\RcAbTesting\Service\Stats\SampleSizeCalculator;

/**
 * Prüft die nötige Stichprobengröße je Variante gegen nachgerechnete Werte. Die Zahl steht im
 * Admin als Empfehlung, wie lange ein Test laufen muss; ist sie zu klein, wird ein Test zu
 * früh beendet und sein Ergebnis ist Zufall.
 */
final class SampleSizeCalculatorTest extends TestCase
{
    private SampleSizeCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SampleSizeCalculator(new NormalDistribution());
    }

    public function testKnownCase(): void
    {
        // Basisrate 5 %, plus 20 % relativ (also 6 %), Teststärke 80 %, Alpha 5 %, zwei Gruppen: rund 8 140
        // je Variante (Cohens h = 0,0439; 2 * ((1,960 + 0,842) / 0,0439)^2). Die übliche Faustzahl für diesen
        // Fall liegt bei gut 8 100. Rund 4 070 wäre die Zahl für einen Test gegen einen festen Wert,
        // also die halbe. Die Toleranz von 3 fängt Rundung auf.
        $n = $this->calculator->requiredSize(0.05, 0.20);

        self::assertEqualsWithDelta(8143, $n, 3);
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
        self::assertSame(0, $this->calculator->requiredSize(0.9, 0.2)); // 0,9 · 1,2 ergäbe eine Rate über 1
    }

    public function testMeanDifferenceKnownCase(): void
    {
        // Mittelwerte 2,00 und 2,60 (Differenz 0,6), Varianzen 36 und 45, Teststärke 80 %,
        // Alpha 5 %: n = (1,95996 + 0,84162)² · 81 / 0,36 ≈ 1766.
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
        self::assertSame(
            0,
            $this->calculator->requiredSizeForMeanDifference(
                2.6,
                2.0,
                36.0,
                45.0
            )
        ); // Treatment schlechter
        self::assertSame(0, $this->calculator->requiredSizeForMeanDifference(2.0, 2.6, 0.0, 0.0));   // keine Streuung
    }
}
