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
}
