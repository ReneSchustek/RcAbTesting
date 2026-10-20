<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\TrackRateLimiter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Prüft die Drosselung des anonymen Tracking-Endpunkts. Ohne sie könnte ein einzelner
 * Absender die Ereignistabelle fluten; teilten sich Absender ein Budget, sperrte einer alle
 * anderen hinter demselben Server aus.
 */
final class TrackRateLimiterTest extends TestCase
{
    public function testAllowsUpToTheLimitThenBlocks(): void
    {
        // 60 ist die Obergrenze je Fenster in `TrackRateLimiter`; der 61. Aufruf wird gedrosselt.
        $limiter = new TrackRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 60; ++$i) {
            self::assertFalse($limiter->tooManyRequests('client-a'), "Call {$i} sollte erlaubt sein.");
        }

        self::assertTrue($limiter->tooManyRequests('client-a'));
    }

    /**
     * Beim zweiten Aufruf bleibt das Ende des Fensters stehen. Schöbe jeder Aufruf es hinaus, füllte sich das Budget
     * bei stetigen Aufrufen nie wieder auf.
     */
    public function testTheWindowEndDoesNotMove(): void
    {
        $cache = new ArrayAdapter();
        $limiter = new TrackRateLimiter($cache);
        $key = 'rc_ab_track_' . hash('sha256', 'client-a');

        $limiter->tooManyRequests('client-a');
        $first = $cache->getItem($key)->get();
        $limiter->tooManyRequests('client-a');
        $second = $cache->getItem($key)->get();

        self::assertSame(2, $second['count']);
        self::assertSame($first['resetAt'], $second['resetAt']);
    }

    public function testAnExpiredWindowStartsAfresh(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('rc_ab_track_' . hash('sha256', 'client-a'));
        $item->set(['count' => 60, 'resetAt' => time() - 1]);
        $cache->save($item);

        self::assertFalse((new TrackRateLimiter($cache))->tooManyRequests('client-a'));
    }

    public function testClientsHaveSeparateBudgets(): void
    {
        $limiter = new TrackRateLimiter(new ArrayAdapter());
        for ($i = 0; $i < 60; ++$i) {
            $limiter->tooManyRequests('client-a');
        }

        self::assertFalse($limiter->tooManyRequests('client-b'));
    }
}
