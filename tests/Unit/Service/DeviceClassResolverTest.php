<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\AbDeviceClass;
use Ruhrcoder\RcAbTesting\Service\DeviceClassResolver;

final class DeviceClassResolverTest extends TestCase
{
    private DeviceClassResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DeviceClassResolver();
    }

    #[DataProvider('provideUserAgents')]
    public function testResolvesDeviceClass(?string $userAgent, string $expected): void
    {
        self::assertSame($expected, $this->resolver->resolve($userAgent));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function provideUserAgents(): iterable
    {
        yield 'windows-chrome' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            AbDeviceClass::DESKTOP,
        ];
        yield 'macos-safari' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            AbDeviceClass::DESKTOP,
        ];
        yield 'iphone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            AbDeviceClass::MOBILE,
        ];
        yield 'android-phone' => [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
            AbDeviceClass::MOBILE,
        ];
        yield 'ipad' => [
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/604.1',
            AbDeviceClass::TABLET,
        ];
        yield 'android-tablet' => [
            // Android ohne „Mobile" = Tablet (Phones tragen zusaetzlich „Mobile").
            'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            AbDeviceClass::TABLET,
        ];
        yield 'null' => [null, AbDeviceClass::UNKNOWN];
        yield 'empty' => ['', AbDeviceClass::UNKNOWN];
        yield 'whitespace' => ['   ', AbDeviceClass::UNKNOWN];
    }
}
