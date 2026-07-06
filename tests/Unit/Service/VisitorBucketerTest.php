<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\VisitorBucketer;
use Shopware\Core\Framework\Uuid\Uuid;

final class VisitorBucketerTest extends TestCase
{
    private VisitorBucketer $bucketer;

    protected function setUp(): void
    {
        $this->bucketer = new VisitorBucketer();
    }

    public function testBucketIsDeterministic(): void
    {
        $first = $this->bucketer->bucketFor('visitor-1', 'checkout-test');

        for ($i = 0; $i < 1000; ++$i) {
            self::assertSame($first, $this->bucketer->bucketFor('visitor-1', 'checkout-test'));
        }
    }

    public function testBucketStaysInRange(): void
    {
        for ($i = 0; $i < 5000; ++$i) {
            $bucket = $this->bucketer->bucketFor('visitor-' . $i, 'experiment');
            self::assertGreaterThanOrEqual(0, $bucket);
            self::assertLessThan(100, $bucket);
        }
    }

    public function testDifferentExperimentKeyYieldsIndependentBucket(): void
    {
        // Verschiedene Schlüssel dürfen nicht denselben Bucket erzwingen,
        // sonst korrelierten parallele Tests für denselben Besucher.
        $differs = false;
        for ($i = 0; $i < 50; ++$i) {
            $visitor = 'visitor-' . $i;
            if ($this->bucketer->bucketFor($visitor, 'a') !== $this->bucketer->bucketFor($visitor, 'b')) {
                $differs = true;
                break;
            }
        }

        self::assertTrue($differs, 'Bucket muss vom Experiment-Schlüssel abhängen.');
    }

    public function testWeightDistributionIsWithinTolerance(): void
    {
        $variants = [$this->makeVariant('a', 50), $this->makeVariant('b', 50)];
        $counts = ['a' => 0, 'b' => 0];

        $total = 10000;
        for ($i = 0; $i < $total; ++$i) {
            $bucket = $this->bucketer->bucketFor(Uuid::randomHex(), 'dist-test');
            $variant = $this->bucketer->pick($bucket, $variants);
            self::assertNotNull($variant);
            ++$counts[$variant->getTechnicalKey()];
        }

        // 50/50-Soll mit ±3% Toleranz.
        self::assertEqualsWithDelta(0.5, $counts['a'] / $total, 0.03);
        self::assertEqualsWithDelta(0.5, $counts['b'] / $total, 0.03);
    }

    public function testPickHonoursCumulativeWeights(): void
    {
        $variants = [$this->makeVariant('a', 50), $this->makeVariant('b', 50)];

        self::assertSame('a', $this->requirePick(0, $variants));
        self::assertSame('a', $this->requirePick(49, $variants));
        self::assertSame('b', $this->requirePick(50, $variants));
        self::assertSame('b', $this->requirePick(99, $variants));
    }

    public function testPickReturnsNullWhenBucketExceedsWeightSum(): void
    {
        $variants = [$this->makeVariant('a', 30), $this->makeVariant('b', 30)];

        self::assertNull($this->bucketer->pick(70, $variants));
    }

    public function testParticipationRespectsTrafficAllocation(): void
    {
        self::assertTrue($this->bucketer->participates('visitor-1', 'exp', 100));
        self::assertFalse($this->bucketer->participates('visitor-1', 'exp', 0));

        $participating = 0;
        $total = 10000;
        for ($i = 0; $i < $total; ++$i) {
            if ($this->bucketer->participates(Uuid::randomHex(), 'alloc-test', 50)) {
                ++$participating;
            }
        }

        self::assertEqualsWithDelta(0.5, $participating / $total, 0.03);
    }

    /**
     * @param list<AbVariantEntity> $variants
     */
    private function requirePick(int $bucket, array $variants): string
    {
        $variant = $this->bucketer->pick($bucket, $variants);
        self::assertNotNull($variant);

        return $variant->getTechnicalKey();
    }

    private function makeVariant(string $technicalKey, int $weight): AbVariantEntity
    {
        $variant = new AbVariantEntity();
        $variant->setId(Uuid::randomHex());
        $variant->setTechnicalKey($technicalKey);
        $variant->setWeight($weight);
        $variant->setName($technicalKey);
        $variant->setIsControl($technicalKey === 'a');

        return $variant;
    }
}
