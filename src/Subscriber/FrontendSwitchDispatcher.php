<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Subscriber;

use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchAdapter;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ruft die registrierten {@see FrontendSwitchAdapter} zur Render-Zeit auf — je
 * Adapter mit dem aktiven Wert seines Schalters, sofern der Besucher einem Wert
 * zugeordnet ist. So werden Fremd-Plugins über ihren konkreten Adapter geschaltet,
 * ohne dass RcAbTesting sie kennt. Ohne registrierte Adapter (nur eigene Plugins,
 * die selbst lesen) ist der Subscriber ein No-Op.
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
        // Getaggte Iteratoren einmalig materialisieren, damit onRender die
        // Leerprüfung ohne Konsumieren eines Generators machen kann.
        $this->adapters = $adapters instanceof \Traversable ? iterator_to_array($adapters, false) : array_values($adapters);
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
        // Ohne registrierten Adapter ist der Dispatcher ein No-Op — dann NICHT
        // resolver->all() aufrufen: das würde auf jedem Storefront-Render die
        // Schalter-Varianten auflösen und eine Zuordnung schreiben (DAL-Write auf
        // dem Hot-Path), obwohl niemand den Wert konsumiert (Arbeitspaket AB45). Eigene
        // Plugins lesen den Wert bei Bedarf selbst über die Twig-Funktion.
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
