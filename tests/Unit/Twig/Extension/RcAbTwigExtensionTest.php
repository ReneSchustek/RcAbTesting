<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Twig\Extension;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Tests\Unit\Service\FrontendSwitch\FrontendSwitchHarness;
use Ruhrcoder\RcAbTesting\Tests\Unit\Service\VariantResolverTestCase;
use Ruhrcoder\RcAbTesting\Twig\Extension\RcAbTwigExtension;

final class RcAbTwigExtensionTest extends VariantResolverTestCase
{
    use FrontendSwitchHarness;

    public function testReturnsAssignedVariantKey(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository()), $this->inactiveSwitchResolver());

        self::assertSame('a', $extension->getVariant(self::EXPERIMENT_KEY));
        self::assertTrue($extension->isInExperiment(self::EXPERIMENT_KEY));
    }

    public function testVariantConfigWholeAndField(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository()), $this->inactiveSwitchResolver());

        self::assertSame(['color' => 'red'], $extension->getVariantConfig(self::EXPERIMENT_KEY));
        self::assertSame('red', $extension->getVariantConfig(self::EXPERIMENT_KEY, 'color'));
        self::assertNull($extension->getVariantConfig(self::EXPERIMENT_KEY, 'missing'));
    }

    public function testReturnsNullWhenNotParticipating(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository(), withRequest: false), $this->inactiveSwitchResolver());

        self::assertNull($extension->getVariant(self::EXPERIMENT_KEY));
        self::assertFalse($extension->isInExperiment(self::EXPERIMENT_KEY));
        self::assertNull($extension->getVariantConfig(self::EXPERIMENT_KEY));
    }

    public function testAbSwitchResolvesActiveValue(): void
    {
        $extension = new RcAbTwigExtension(
            $this->resolver($this->assignmentRepository()),
            $this->switchResolver(['checkout_layout' => 'guided']),
        );

        self::assertSame('guided', $extension->getSwitch('checkout_layout'));
        self::assertNull($extension->getSwitch('unknown'));
    }

    public function testRegistersTwigFunctionsAndTest(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository()), $this->inactiveSwitchResolver());

        $functionNames = array_map(static fn ($function): string => $function->getName(), $extension->getFunctions());
        $testNames = array_map(static fn ($test): string => $test->getName(), $extension->getTests());

        self::assertContains('ab_variant', $functionNames);
        self::assertContains('ab_variant_config', $functionNames);
        self::assertContains('ab_switch', $functionNames);
        self::assertContains('in_experiment', $testNames);
    }

    private function inactiveSwitchResolver(): \Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver
    {
        return $this->switchResolver([], AbExperimentTestType::FRONTEND_SWITCH, false);
    }
}
