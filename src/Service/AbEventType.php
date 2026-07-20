<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Whitelist zulässiger Funnel-Event-Typen. Bewusst kein Enum, damit das
 * `custom.*`-Präfix (offene Storefront-JS-API) ohne Sonderfall in einem
 * einheitlichen Wert-Konzept lebt — analog zu AbExperimentStatus.
 */
final class AbEventType
{
    public const PAGE_VIEWED = 'page.viewed';
    public const PRODUCT_ADDED_TO_CART = 'product.added_to_cart';
    public const PRODUCT_REMOVED_FROM_CART = 'product.removed_from_cart';
    public const CHECKOUT_STARTED = 'checkout.started';
    public const CHECKOUT_CONFIRM_VIEWED = 'checkout.confirm_viewed';
    public const CHECKOUT_ORDER_PLACED = 'checkout.order_placed';
    public const CART_ABANDONED = 'cart.abandoned';
    public const CUSTOMER_REGISTERED = 'customer.registered';
    public const CUSTOMER_LOGGED_IN = 'customer.logged_in';

    public const CUSTOM_PREFIX = 'custom.';

    /** @var list<string> */
    public const KNOWN = [
        self::PAGE_VIEWED,
        self::PRODUCT_ADDED_TO_CART,
        self::PRODUCT_REMOVED_FROM_CART,
        self::CHECKOUT_STARTED,
        self::CHECKOUT_CONFIRM_VIEWED,
        self::CHECKOUT_ORDER_PLACED,
        self::CART_ABANDONED,
        self::CUSTOMER_REGISTERED,
        self::CUSTOMER_LOGGED_IN,
    ];

    private function __construct()
    {
    }

    /**
     * Ein Event-Typ ist gültig, wenn er auf der Whitelist steht oder mit dem
     * `custom.`-Präfix beginnt und dahinter mindestens ein Zeichen trägt.
     */
    public static function isValid(string $eventType): bool
    {
        if (\in_array($eventType, self::KNOWN, true)) {
            return true;
        }

        return self::isClientTrackable($eventType);
    }

    /**
     * Ein Event-Typ darf aus dem Browser (anonymer Track-Endpunkt) gesendet
     * werden, wenn er das `custom.`-Präfix trägt und dahinter mindestens ein
     * Zeichen hat. Die Funnel-/Order-Typen der KNOWN-Liste entstehen dagegen
     * ausschließlich serverseitig in den Subscribern — sie am öffentlichen
     * Endpunkt zuzulassen erlaubte einem teilnehmenden Besucher, Conversions und
     * Umsatz (`value`) zu fälschen und damit die Auswertung zu manipulieren
     * (siehe Arbeitspaket AB33).
     */
    public static function isClientTrackable(string $eventType): bool
    {
        return \str_starts_with($eventType, self::CUSTOM_PREFIX)
            && \strlen($eventType) > \strlen(self::CUSTOM_PREFIX);
    }
}
