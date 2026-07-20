<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\FrontendSwitch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\CheckoutLayoutSwitch;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FreeShippingIndicatorSwitch;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitch;

/**
 * Pinnt den ausgelieferten Schalter-Katalog fest. Die Schlüssel und Werte sind ein
 * Kontrakt: Zielplugins lesen sie über `ab_variant_config`, und bestehende
 * Experimente tragen sie in ihrer Varianten-Config — eine Umbenennung würde
 * laufende Tests still auf den Fallback zurückfallen lassen.
 */
final class FrontendSwitchCatalogTest extends TestCase
{
    public function testCheckoutLayoutSwitchExposesItsTwoLayouts(): void
    {
        $switch = new CheckoutLayoutSwitch();

        self::assertSame('checkout_layout', $switch->getKey());
        self::assertSame('rc-ab-testing.switch.checkoutLayout.label', $switch->getLabel());
        self::assertSame(
            [
                ['value' => 'one_page', 'label' => 'rc-ab-testing.switch.checkoutLayout.onePage'],
                ['value' => 'guided', 'label' => 'rc-ab-testing.switch.checkoutLayout.guided'],
            ],
            $switch->getOptions(),
        );
    }

    public function testFreeShippingIndicatorSwitchExposesOnAndOff(): void
    {
        $switch = new FreeShippingIndicatorSwitch();

        self::assertSame('free_shipping_indicator', $switch->getKey());
        self::assertSame('rc-ab-testing.switch.freeShippingIndicator.label', $switch->getLabel());
        self::assertSame(
            [
                ['value' => 'on', 'label' => 'rc-ab-testing.switch.freeShippingIndicator.on'],
                ['value' => 'off', 'label' => 'rc-ab-testing.switch.freeShippingIndicator.off'],
            ],
            $switch->getOptions(),
        );
    }

    /**
     * @return iterable<string, array{FrontendSwitch}>
     */
    public static function switchProvider(): iterable
    {
        yield 'checkout_layout' => [new CheckoutLayoutSwitch()];
        yield 'free_shipping_indicator' => [new FreeShippingIndicatorSwitch()];
    }

    #[DataProvider('switchProvider')]
    public function testEverySwitchOffersAtLeastTwoDistinctValues(FrontendSwitch $switch): void
    {
        $values = array_column($switch->getOptions(), 'value');

        self::assertGreaterThanOrEqual(2, \count($values), 'Ein Schalter ohne Alternative kann nichts testen.');
        self::assertSame($values, array_unique($values), 'Doppelte Schalter-Werte wären mehrdeutig.');
        self::assertNotContains('', $values);
    }

    #[DataProvider('switchProvider')]
    public function testEverySwitchLabelsAllOptionsWithSnippetKeys(FrontendSwitch $switch): void
    {
        foreach ($switch->getOptions() as $option) {
            self::assertStringStartsWith('rc-ab-testing.switch.', $option['label']);
        }
    }
}
