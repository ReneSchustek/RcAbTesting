<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Trägt abgebrochene Warenkörbe als `cart.abandoned` nach. Alle 15 Minuten: Bei der Vorgabefrist
 * von 30 Minuten erscheint ein Abbruch so höchstens eine Viertelstunde verspätet, was für eine
 * Auswertung über Tage keine Rolle spielt.
 */
final class CartAbandonmentTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_ab.cart_abandonment';
    }

    public static function getDefaultInterval(): int
    {
        return 900;
    }
}
