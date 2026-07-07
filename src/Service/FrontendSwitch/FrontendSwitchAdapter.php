<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

use Shopware\Storefront\Event\StorefrontRenderEvent;

/**
 * Wendet einen Frontend-Schalter zur Render-Zeit an — der Weg fuer **Fremd-Plugins**,
 * die den Wert nicht selbst lesen koennen: der Adapter setzt z. B. einen Template-
 * Parameter, den ein Twig-Override auswertet, ohne das Zielplugin zu aendern. Jeder
 * Adapter deckt genau EINEN Schalter ab (kein generischer Eingriff).
 *
 * Registrierung als getaggter Service `rc_ab_testing.frontend_switch_adapter`; der
 * {@see FrontendSwitchDispatcher} loest den aktiven Wert auf und ruft `apply` nur,
 * wenn der Besucher einem Schalter-Wert zugeordnet ist. Adapter mit anderem Timing
 * (z. B. frueh im Request) abonnieren stattdessen selbst ein Event und nutzen den
 * {@see FrontendSwitchResolver}.
 */
interface FrontendSwitchAdapter
{
    /** Schluessel des Schalters, den dieser Adapter anwendet (s. FrontendSwitch::getKey). */
    public function getSwitchKey(): string;

    public function apply(string $value, StorefrontRenderEvent $event): void;
}
