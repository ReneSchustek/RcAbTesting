<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\FrontendSwitch;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\CheckoutLayoutSwitch;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchRegistry;

final class FrontendSwitchRegistryTest extends TestCase
{
    public function testCollectsSwitchesAndFindsByKey(): void
    {
        $checkout = new CheckoutLayoutSwitch();
        $registry = new FrontendSwitchRegistry([$checkout]);

        self::assertSame([$checkout], $registry->all());
        self::assertSame($checkout, $registry->get(CheckoutLayoutSwitch::KEY));
        self::assertNull($registry->get('unknown'));
    }

    public function testAcceptsTraversableSwitches(): void
    {
        $checkout = new CheckoutLayoutSwitch();
        $registry = new FrontendSwitchRegistry((static function () use ($checkout): \Generator {
            yield $checkout;
        })());

        self::assertSame([$checkout], $registry->all());
    }

    public function testCheckoutSwitchExposesValues(): void
    {
        $switch = new CheckoutLayoutSwitch();

        self::assertSame('checkout_layout', $switch->getKey());
        self::assertSame(
            ['one_page', 'guided'],
            array_column($switch->getOptions(), 'value'),
        );
    }
}
