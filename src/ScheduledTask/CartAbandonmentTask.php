<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Periodischer Task, der abgebrochene Warenkörbe als `cart.abandoned`-Events
 * nachträgt. Standard-Intervall 15 Minuten — feiner braucht es nicht, weil die
 * Abbruch-Frist ohnehin in Minuten zählt.
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
