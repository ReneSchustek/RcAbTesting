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
 * Der aktive Wert eines Frontend-Schalters für den aktuellen Besucher. Für jedes laufende
 * Experiment vom Typ `frontend_switch` wird die Variante des Besuchers bestimmt und deren
 * Konfiguration gelesen. Templates kommen über die Twig-Funktion `ab_switch` hierher, Adapter und
 * fremde Plugins direkt; beide sehen so denselben Wert.
 *
 * Das Bestimmen der Variante teilt einen neuen Besucher zu und schreibt die Zuordnung. Aufrufer
 * fragen deshalb nur, wenn sie den Wert auch verwenden.
 */
final class FrontendSwitchResolver implements FrontendSwitchValueResolver, ResetInterface
{
    /** @var array<string, string|null> Wert je Schalter für diesen Request; `reset()` leert ihn zwischen Requests. */
    private array $memo = [];

    public function __construct(
        private readonly ExperimentRegistry $experimentRegistry,
        private readonly RequestVariantResolver $variantResolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Null, wenn kein Schalter-Experiment läuft, der Besucher nicht teilnimmt oder seine Variante
     * den Schalter nicht setzt.
     */
    public function resolve(string $switchKey): ?string
    {
        if (\array_key_exists($switchKey, $this->memo)) {
            return $this->memo[$switchKey];
        }

        return $this->memo[$switchKey] = $this->all()[$switchKey] ?? null;
    }

    /**
     * Setzen zwei laufende Experimente denselben Schalter, gilt der Wert des späteren in der
     * Reihenfolge der Registry.
     *
     * @return array<string, string> Schalter-Schlüssel => Wert
     */
    public function all(): array
    {
        // Ohne Sales-Channel-Kontext (Konsole, Admin-API) gibt es keinen Storefront-Besucher.
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

            // Schalterwerte sind immer Zeichenketten; andere Einträge der Konfiguration gehören
            // nicht zu einem Schalter und bleiben draußen. PHP macht aus rein numerischen Schlüsseln Zahlen, deshalb
            // prüft die Schleife auch den Schlüssel.
            /** @var array<array-key, mixed> $config */
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
        $context = $this->requestStack->getMainRequest()
            ?->attributes->get(
                PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT
            );

        return $context instanceof SalesChannelContext ? $context : null;
    }
}
