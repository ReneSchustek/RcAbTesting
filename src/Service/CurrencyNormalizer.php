<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Rechnet einen Betrag aus der Währung des Kunden in die Standardwährung des Shops um. Shopware
 * speichert je Bestellung und Kontext den Faktor zur Standardwährung (Standardwährung = 1); geteilt
 * durch ihn ergibt sich der Betrag, den die Auswertung über alle Währungen hinweg summieren kann.
 */
final class CurrencyNormalizer
{
    private function __construct()
    {
    }

    /**
     * Ein Faktor von 0 oder darunter ist nicht umrechenbar; der Betrag bleibt dann, wie er ist, statt
     * durch null zu teilen.
     */
    public static function toDefaultCurrency(float $amount, float $currencyFactor): float
    {
        return $currencyFactor > 0.0 ? $amount / $currencyFactor : $amount;
    }
}
