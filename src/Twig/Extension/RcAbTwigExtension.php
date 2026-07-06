<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Twig\Extension;

use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Stellt dem Storefront-Theme die A/B-Entscheidung zur Verfügung. Die eigentliche
 * Auflösung und Request-Memoization liegt im geteilten RequestVariantResolver,
 * den sich Twig und Plugin-Bridge teilen — diese Extension ist nur die dünne
 * Twig-Fassade darauf.
 */
final class RcAbTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestVariantResolver $variantResolver,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('ab_variant', $this->getVariant(...)),
            new TwigFunction('ab_variant_config', $this->getVariantConfig(...)),
        ];
    }

    /**
     * @return list<TwigTest>
     */
    public function getTests(): array
    {
        return [
            new TwigTest('in_experiment', $this->isInExperiment(...)),
        ];
    }

    /**
     * Technical-Key der zugewiesenen Variante oder null, wenn der Besucher nicht
     * teilnimmt (kein Experiment, keine Einwilligung, außerhalb der Allokation).
     */
    public function getVariant(string $experimentKey): ?string
    {
        return $this->variantResolver->resolve($experimentKey)?->getTechnicalKey();
    }

    /**
     * Variant-Config als Ganzes (configKey null) oder ein einzelnes Feld. Null,
     * wenn der Besucher nicht teilnimmt oder das Feld fehlt.
     */
    public function getVariantConfig(string $experimentKey, ?string $configKey = null): mixed
    {
        $config = $this->variantResolver->resolve($experimentKey)?->getConfig();
        if ($config === null) {
            return null;
        }

        if ($configKey === null) {
            return $config;
        }

        return $config[$configKey] ?? null;
    }

    public function isInExperiment(string $experimentKey): bool
    {
        return $this->variantResolver->resolve($experimentKey) !== null;
    }
}
