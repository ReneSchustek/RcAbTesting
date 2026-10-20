<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Twig\Extension;

use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Twig-Fassade für die A/B-Entscheidung im Storefront-Template. Auflösung und Zwischenspeicher je
 * Request liegen im `RequestVariantResolver`, den sich Twig und die Plugin-Bridge teilen, damit
 * Template und fremdes Plugin für denselben Besucher dieselbe Variante sehen.
 *
 * Jeder Aufruf ordnet den Besucher bei Bedarf zu und schreibt die Zuordnung. Ein `ab_variant` in
 * einem Template, das nicht zum Test gehört, zieht deshalb Besucher in die Stichprobe, die die
 * Variante nie zu sehen bekommen.
 */
final class RcAbTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestVariantResolver $variantResolver,
        private readonly FrontendSwitchResolver $switchResolver,
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
            new TwigFunction('ab_switch', $this->getSwitch(...)),
        ];
    }

    /**
     * Aktiver Wert eines Frontend-Schalters für den Besucher, etwa `guided` für
     * `ab_switch('checkout_layout')`, oder null. Ein Template kommt so an die Entscheidung, ohne
     * das Experiment zu kennen.
     */
    public function getSwitch(string $switchKey): ?string
    {
        return $this->switchResolver->resolve($switchKey);
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
     * `technical_key` der zugeordneten Variante oder null, wenn der Besucher nicht teilnimmt (kein
     * laufendes Experiment, Targeting passt nicht, keine Einwilligung, außerhalb der Allokation).
     */
    public function getVariant(string $experimentKey): ?string
    {
        return $this->variantResolver->resolve($experimentKey)?->getTechnicalKey();
    }

    /**
     * Konfiguration der Variante als Ganzes (ohne `$configKey`) oder ein einzelnes Feld daraus.
     * Null, wenn der Besucher nicht teilnimmt oder das Feld fehlt.
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

    /**
     * Twig-Test `is in_experiment`: true, sobald der Besucher irgendeiner Variante zugeordnet ist,
     * auch der Kontrolle.
     */
    public function isInExperiment(string $experimentKey): bool
    {
        return $this->variantResolver->resolve($experimentKey) !== null;
    }
}
