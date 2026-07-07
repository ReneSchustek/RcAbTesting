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
 * zugeordnet ist. So werden Fremd-Plugins ueber ihren konkreten Adapter geschaltet,
 * ohne dass RcAbTesting sie kennt. Ohne registrierte Adapter (nur eigene Plugins,
 * die selbst lesen) ist der Subscriber ein No-Op.
 */
final class FrontendSwitchDispatcher implements EventSubscriberInterface
{
    /**
     * @param iterable<FrontendSwitchAdapter> $adapters
     */
    public function __construct(
        private readonly FrontendSwitchResolver $resolver,
        private readonly iterable $adapters,
    ) {
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
