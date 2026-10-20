<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

use Shopware\Storefront\Event\StorefrontRenderEvent;

/**
 * Wendet einen Schalter beim Rendern an, für fremde Plugins, die den Wert nicht selbst lesen.
 * Der Adapter setzt etwa einen Template-Parameter, den ein Twig-Override auswertet; das
 * Zielplugin bleibt unverändert. Jeder Adapter deckt genau einen Schalter ab.
 *
 * Adapter tragen den Service-Tag `rc_ab_testing.frontend_switch_adapter`.
 * {@see \Ruhrcoder\RcAbTesting\Subscriber\FrontendSwitchDispatcher} ruft `apply` nur, wenn für den
 * Besucher ein Wert gesetzt ist. Wer früher im Request eingreifen muss, abonniert selbst ein
 * Ereignis und fragt {@see FrontendSwitchResolver}.
 */
interface FrontendSwitchAdapter
{
    /** Schlüssel des Schalters, siehe {@see FrontendSwitch::getKey()}. */
    public function getSwitchKey(): string;

    public function apply(string $value, StorefrontRenderEvent $event): void;
}
