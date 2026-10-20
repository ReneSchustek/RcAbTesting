<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Zulässige Ereignistypen für `rc_ab_event.event_type`. Neben der festen Liste gibt es den
 * offenen Raum `custom.*`, den Shop-Skripte frei belegen. Ein Enum könnte diesen offenen Teil
 * nicht abbilden; als Zeichenketten-Konstanten (wie {@see
 * \Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus}) gelten feste und freie Typen gleich.
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
     * Gültig für das Speichern: ein Typ aus `KNOWN` oder ein `custom.`-Typ mit nicht leerem Rest.
     */
    public static function isValid(string $eventType): bool
    {
        if (\in_array($eventType, self::KNOWN, true)) {
            return true;
        }

        return self::isClientTrackable($eventType);
    }

    /**
     * Darf über den anonymen Track-Endpunkt aus dem Browser kommen: nur `custom.`-Typen.
     *
     * Die Typen aus `KNOWN` schreiben ausschließlich die Subscriber auf dem Server. Ließe der
     * öffentliche Endpunkt sie zu, könnte jeder Teilnehmer Bestellungen samt Umsatz (`value`)
     * melden und die Auswertung nach Belieben verschieben.
     */
    public static function isClientTrackable(string $eventType): bool
    {
        return \str_starts_with($eventType, self::CUSTOM_PREFIX)
            && \strlen($eventType) > \strlen(self::CUSTOM_PREFIX);
    }
}
