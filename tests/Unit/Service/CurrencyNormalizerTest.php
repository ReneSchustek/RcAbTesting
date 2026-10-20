<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\CurrencyNormalizer;

/**
 * Umsätze aus mehreren Währungen müssen vor dem Summieren in eine gebracht werden, sonst addiert die
 * Auswertung Beträge verschiedener Währungen.
 */
final class CurrencyNormalizerTest extends TestCase
{
    public function testConvertsIntoTheDefaultCurrency(): void
    {
        // 108 Franken bei einem Faktor von 1,08 sind 100 Euro.
        self::assertEqualsWithDelta(100.0, CurrencyNormalizer::toDefaultCurrency(108.0, 1.08), 0.0001);
    }

    public function testTheDefaultCurrencyStaysUnchanged(): void
    {
        self::assertSame(42.5, CurrencyNormalizer::toDefaultCurrency(42.5, 1.0));
    }

    public function testAnInvalidFactorLeavesTheAmountAlone(): void
    {
        self::assertSame(42.5, CurrencyNormalizer::toDefaultCurrency(42.5, 0.0));
    }
}
