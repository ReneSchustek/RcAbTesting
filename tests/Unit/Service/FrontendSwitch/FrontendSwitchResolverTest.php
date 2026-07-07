<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\FrontendSwitch;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;

final class FrontendSwitchResolverTest extends TestCase
{
    use FrontendSwitchHarness;

    public function testResolvesSwitchValueFromAssignedVariant(): void
    {
        $resolver = $this->switchResolver(['checkout_layout' => 'guided']);

        self::assertSame('guided', $resolver->resolve('checkout_layout'));
        self::assertSame(['checkout_layout' => 'guided'], $resolver->all());
    }

    public function testReturnsNullForUnknownSwitchKey(): void
    {
        $resolver = $this->switchResolver(['checkout_layout' => 'guided']);

        self::assertNull($resolver->resolve('nonexistent'));
    }

    public function testIgnoresNonSwitchTestTypes(): void
    {
        $resolver = $this->switchResolver(['checkout_layout' => 'guided'], AbExperimentTestType::TWIG);

        self::assertSame([], $resolver->all());
        self::assertNull($resolver->resolve('checkout_layout'));
    }

    public function testReturnsNullWithoutRequestContext(): void
    {
        $resolver = $this->switchResolver(['checkout_layout' => 'guided'], AbExperimentTestType::FRONTEND_SWITCH, false);

        self::assertSame([], $resolver->all());
        self::assertNull($resolver->resolve('checkout_layout'));
    }
}
