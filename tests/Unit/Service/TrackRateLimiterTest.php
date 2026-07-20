<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\TrackRateLimiter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TrackRateLimiterTest extends TestCase
{
    public function testAllowsUpToTheLimitThenBlocks(): void
    {
        $limiter = new TrackRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 60; ++$i) {
            self::assertFalse($limiter->tooManyRequests('client-a'), "Call {$i} sollte erlaubt sein.");
        }

        self::assertTrue($limiter->tooManyRequests('client-a'));
    }

    public function testClientsHaveSeparateBudgets(): void
    {
        $limiter = new TrackRateLimiter(new ArrayAdapter());
        for ($i = 0; $i < 60; ++$i) {
            $limiter->tooManyRequests('client-a');
        }

        // Ein anderer Client ist unberührt.
        self::assertFalse($limiter->tooManyRequests('client-b'));
    }
}
