<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchAdapter;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ruft beim Rendern jeder Storefront-Seite die registrierten {@see FrontendSwitchAdapter} auf,
 * jeden mit dem Wert, der dem Besucher für seinen Schalter zugeordnet ist. Fremde Plugins werden
 * so über ihren Adapter geschaltet, ohne dass RcAbTesting sie kennt. Ist kein Adapter registriert,
 * tut der Abonnent nichts; eigene Plugins lesen den Wert selbst über `ab_switch`.
 */
final class FrontendSwitchDispatcher implements EventSubscriberInterface
{
    /** @var list<FrontendSwitchAdapter> */
    private readonly array $adapters;

    /**
     * @param iterable<FrontendSwitchAdapter> $adapters
     */
    public function __construct(
        private readonly FrontendSwitchResolver $resolver,
        iterable $adapters,
    ) {
        // Als Array, damit `onRender` mit einem billigen `=== []` prüfen kann, ob es überhaupt
        // Adapter gibt.
        $this->adapters = $adapters instanceof \Traversable
            ? iterator_to_array($adapters, false)
            : array_values($adapters);
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [StorefrontRenderEvent::class => 'onRender'];
    }

    public function onRender(StorefrontRenderEvent $event): void
    {
        // Ohne Adapter gar nicht erst auflösen: `all()` löst jedes laufende Schalter-Experiment
        // auf und legt für Besucher mit Einwilligung Zuordnungen an, bei jedem Seitenaufruf und
        // ohne dass jemand den Wert braucht.
        if ($this->adapters === []) {
            return;
        }

        $values = $this->resolver->all();
        if ($values === []) {
            return;
        }

        foreach ($this->adapters as $adapter) {
            $value = $values[$adapter->getSwitchKey()] ?? null;
            if ($value !== null) {
                $adapter->apply($value, $event);
            }
        }
    }
}
