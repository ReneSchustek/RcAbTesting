<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Schalter für die Darstellung des Checkouts: alles auf einer Seite oder schrittweise geführt.
 * Ein Checkout-Plugin liest den Wert über `ab_switch('checkout_layout')` und rendert danach; an
 * welcher Stufe Kunden abspringen, zeigt die Funnel-Auswertung.
 */
final class CheckoutLayoutSwitch implements FrontendSwitch
{
    public const KEY = 'checkout_layout';
    public const ONE_PAGE = 'one_page';
    public const GUIDED = 'guided';

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'rc-ab-testing.switch.checkoutLayout.label';
    }

    public function getOptions(): array
    {
        return [
            ['value' => self::ONE_PAGE, 'label' => 'rc-ab-testing.switch.checkoutLayout.onePage'],
            ['value' => self::GUIDED, 'label' => 'rc-ab-testing.switch.checkoutLayout.guided'],
        ];
    }
}
