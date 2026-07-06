<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Consent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\Consent\DefaultConsentGate;
use Symfony\Component\HttpFoundation\Request;

final class DefaultConsentGateTest extends TestCase
{
    private DefaultConsentGate $gate;

    protected function setUp(): void
    {
        $this->gate = new DefaultConsentGate();
    }

    public function testAllowsWhenNoOptOutCookie(): void
    {
        self::assertTrue($this->gate->allowsTrackingCookie(new Request()));
    }

    #[DataProvider('optOutValues')]
    public function testDeniesOnOptOutCookie(string $value): void
    {
        $request = new Request();
        $request->cookies->set('rc_ab_opt_out', $value);

        self::assertFalse($this->gate->allowsTrackingCookie($request));
    }

    public function testIgnoresUnrelatedCookieValue(): void
    {
        $request = new Request();
        $request->cookies->set('rc_ab_opt_out', '0');

        self::assertTrue($this->gate->allowsTrackingCookie($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function optOutValues(): iterable
    {
        yield 'numerisch' => ['1'];
        yield 'bool' => ['true'];
        yield 'denied' => ['denied'];
        yield 'groß' => ['DENIED'];
    }
}
