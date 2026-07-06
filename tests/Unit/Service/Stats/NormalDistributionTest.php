<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;

final class NormalDistributionTest extends TestCase
{
    private NormalDistribution $normal;

    protected function setUp(): void
    {
        $this->normal = new NormalDistribution();
    }

    public function testErfIsZeroAtOrigin(): void
    {
        self::assertEqualsWithDelta(0.0, $this->normal->erf(0.0), 1e-9);
    }

    public function testCdfMatchesStandardNormalTable(): void
    {
        self::assertEqualsWithDelta(0.5, $this->normal->cdf(0.0), 1e-6);
        self::assertEqualsWithDelta(0.975, $this->normal->cdf(1.959964), 1e-3);
        self::assertEqualsWithDelta(0.025, $this->normal->cdf(-1.959964), 1e-3);
        self::assertEqualsWithDelta(0.995, $this->normal->cdf(2.575829), 1e-3);
    }

    public function testPpfMatchesKnownQuantiles(): void
    {
        self::assertEqualsWithDelta(0.0, $this->normal->ppf(0.5), 1e-6);
        self::assertEqualsWithDelta(1.959964, $this->normal->ppf(0.975), 1e-4);
        self::assertEqualsWithDelta(0.841621, $this->normal->ppf(0.8), 1e-4);
    }

    public function testPpfIsInverseOfCdf(): void
    {
        foreach ([0.1, 0.3, 0.65, 0.9] as $p) {
            self::assertEqualsWithDelta($p, $this->normal->cdf($this->normal->ppf($p)), 1e-3);
        }
    }

    public function testPpfLowerTailBranch(): void
    {
        // p < 0.02425 nutzt den unteren Rational-Approximations-Zweig.
        self::assertEqualsWithDelta(-3.090232, $this->normal->ppf(0.001), 1e-4);
        self::assertEqualsWithDelta(-2.575829, $this->normal->ppf(0.005), 1e-4);
    }

    public function testPpfUpperTailBranch(): void
    {
        // p > 0.97575 nutzt den oberen Rational-Approximations-Zweig.
        self::assertEqualsWithDelta(3.090232, $this->normal->ppf(0.999), 1e-4);
        self::assertEqualsWithDelta(2.575829, $this->normal->ppf(0.995), 1e-4);
    }

    public function testPpfIsInverseOfCdfInTails(): void
    {
        foreach ([0.001, 0.01, 0.99, 0.999] as $p) {
            self::assertEqualsWithDelta($p, $this->normal->cdf($this->normal->ppf($p)), 1e-3);
        }
    }

    public function testPpfClampsToInfinityOutsideUnitInterval(): void
    {
        self::assertSame(-\INF, $this->normal->ppf(0.0));
        self::assertSame(\INF, $this->normal->ppf(1.0));
        self::assertSame(-\INF, $this->normal->ppf(-0.5));
        self::assertSame(\INF, $this->normal->ppf(2.0));
    }
}
