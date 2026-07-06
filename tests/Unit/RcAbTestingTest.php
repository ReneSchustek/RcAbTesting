<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\RcAbTesting;
use Shopware\Core\Framework\Plugin;

/**
 * Smoke-Test für den Plugin-Bootstrapper. Verhindert, dass Plugin-Klasse
 * versehentlich ihre Plugin-Basis verliert oder nicht final ist.
 */
final class RcAbTestingTest extends TestCase
{
    public function testPluginExtendsShopwarePlugin(): void
    {
        $reflection = new \ReflectionClass(RcAbTesting::class);

        self::assertTrue($reflection->isSubclassOf(Plugin::class), 'RcAbTesting muss von Shopware\\Core\\Framework\\Plugin erben.');
    }

    public function testPluginClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(RcAbTesting::class);

        self::assertTrue($reflection->isFinal(), 'RcAbTesting-Plugin-Klasse muss final sein (Ruhrcoder-Konvention).');
    }
}
