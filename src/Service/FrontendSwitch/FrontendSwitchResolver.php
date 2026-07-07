<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentTestType;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ermittelt den aktiven Wert eines Frontend-Schalters fuer den aktuellen Besucher:
 * ueber alle laufenden Experimente vom Typ „frontend_switch" wird die zugewiesene
 * Variante aufgeloest und ihr Schalter-Wert aus der Config gelesen. Zentraler
 * Zugriffspunkt fuer BEIDE Wege — eigene Plugins/Templates ueber die Twig-Funktion
 * `ab_switch`, Fremd-Plugin-Adapter (Subscriber) ueber diesen Service. Kein
 * Eingriff ins Zielplugin: der Wert wird nur bereitgestellt.
 *
 * Pro Request memoized; `kernel.reset` leert das Memo im Worker-Betrieb.
 */
final class FrontendSwitchResolver implements ResetInterface
{
    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
        private readonly RequestVariantResolver $variantResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Aktiver Wert des Schalters oder null (kein laufendes Schalter-Experiment,
     * Besucher nimmt nicht teil, oder Schalter in der Variante nicht gesetzt).
     */
    public function resolve(string $switchKey): ?string
    {
        if (\array_key_exists($switchKey, $this->memo)) {
            return $this->memo[$switchKey];
        }

        return $this->memo[$switchKey] = $this->all()[$switchKey] ?? null;
    }

    /**
     * Alle aktiven Schalter-Werte des aktuellen Besuchers (Schluessel => Wert).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $context = $this->salesChannelContext();
        if ($context === null) {
            return [];
        }

        $values = [];
        foreach ($this->experimentRegistry->getRunning($context->getContext()) as $experiment) {
            if ($experiment->getTestType() !== AbExperimentTestType::FRONTEND_SWITCH) {
                continue;
            }

            $config = $this->variantResolver->resolve($experiment->getTechnicalKey())?->getConfig();
            if (!\is_array($config)) {
                continue;
            }

            foreach ($config as $key => $value) {
                if (\is_string($key) && \is_string($value)) {
                    $values[$key] = $value;
                }
            }
        }

        return $values;
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    private function salesChannelContext(): ?SalesChannelContext
    {
        $context = $this->requestStack->getMainRequest()?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        return $context instanceof SalesChannelContext ? $context : null;
    }
}
