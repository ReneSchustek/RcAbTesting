<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Schalter für den Versandkostenfrei-Hinweis von RcCheckout: anzeigen oder ausblenden. RcCheckout
 * liest den Wert über {@see FrontendSwitchResolver} und unterdrückt den Hinweis bei „off";
 * so lässt sich messen, was der Hinweis an Conversion und Umsatz bringt.
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
