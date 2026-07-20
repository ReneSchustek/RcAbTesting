<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Frontend-Schalter für den Versandkostenfrei-Hinweis (RcCheckout): anzeigen vs.
 * ausblenden. RcCheckout liest den Wert über den {@see FrontendSwitchResolver}
 * und unterdrückt seinen Indikator bei „off" — so lässt sich die Wirkung des
 * Hinweises auf Conversion/Umsatz A/B-testen.
 */
final class FreeShippingIndicatorSwitch implements FrontendSwitch
{
    public const KEY = 'free_shipping_indicator';
    public const ON = 'on';
    public const OFF = 'off';

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'rc-ab-testing.switch.freeShippingIndicator.label';
    }

    public function getOptions(): array
    {
        return [
            ['value' => self::ON, 'label' => 'rc-ab-testing.switch.freeShippingIndicator.on'],
            ['value' => self::OFF, 'label' => 'rc-ab-testing.switch.freeShippingIndicator.off'],
        ];
    }
}
