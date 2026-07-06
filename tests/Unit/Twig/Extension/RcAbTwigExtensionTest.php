<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Twig\Extension;

use Ruhrcoder\RcAbTesting\Tests\Unit\Service\VariantResolverTestCase;
use Ruhrcoder\RcAbTesting\Twig\Extension\RcAbTwigExtension;

final class RcAbTwigExtensionTest extends VariantResolverTestCase
{
    public function testReturnsAssignedVariantKey(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository()));

        self::assertSame('a', $extension->getVariant(self::EXPERIMENT_KEY));
        self::assertTrue($extension->isInExperiment(self::EXPERIMENT_KEY));
    }

    public function testVariantConfigWholeAndField(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository()));

        self::assertSame(['color' => 'red'], $extension->getVariantConfig(self::EXPERIMENT_KEY));
        self::assertSame('red', $extension->getVariantConfig(self::EXPERIMENT_KEY, 'color'));
        self::assertNull($extension->getVariantConfig(self::EXPERIMENT_KEY, 'missing'));
    }

    public function testReturnsNullWhenNotParticipating(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository(), withRequest: false));

        self::assertNull($extension->getVariant(self::EXPERIMENT_KEY));
        self::assertFalse($extension->isInExperiment(self::EXPERIMENT_KEY));
        self::assertNull($extension->getVariantConfig(self::EXPERIMENT_KEY));
    }

    public function testRegistersTwigFunctionsAndTest(): void
    {
        $extension = new RcAbTwigExtension($this->resolver($this->assignmentRepository()));

        $functionNames = array_map(static fn ($function): string => $function->getName(), $extension->getFunctions());
        $testNames = array_map(static fn ($test): string => $test->getName(), $extension->getTests());

        self::assertContains('ab_variant', $functionNames);
        self::assertContains('ab_variant_config', $functionNames);
        self::assertContains('in_experiment', $testNames);
    }
}
