<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Erster konkreter Frontend-Schalter: die Checkout-Darstellung — als eine Seite
 * vs. schrittweise geführt. Ein eigenes Rc-Checkout-Plugin liest den Wert über
 * `ab_variant_config('checkout_layout')` und rendert entsprechend; wo Kunden
 * abbrechen, zeigt die Funnel-Auswertung.
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
