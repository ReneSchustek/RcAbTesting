<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchAdapter;
use Ruhrcoder\RcAbTesting\Subscriber\FrontendSwitchDispatcher;
use Ruhrcoder\RcAbTesting\Tests\Unit\Service\FrontendSwitch\FrontendSwitchHarness;
use Shopware\Storefront\Event\StorefrontRenderEvent;

final class FrontendSwitchDispatcherTest extends TestCase
{
    use FrontendSwitchHarness;

    public function testSubscribesToStorefrontRender(): void
    {
        self::assertArrayHasKey(StorefrontRenderEvent::class, FrontendSwitchDispatcher::getSubscribedEvents());
    }

    public function testCallsMatchingAdapterWithActiveValue(): void
    {
        $adapter = new RecordingSwitchAdapter('checkout_layout');
        $dispatcher = new FrontendSwitchDispatcher($this->switchResolver(['checkout_layout' => 'guided']), [$adapter]);

        $dispatcher->onRender($this->createMock(StorefrontRenderEvent::class));

        self::assertSame(['guided'], $adapter->applied);
    }

    public function testDoesNotCallAdapterWhenSwitchInactive(): void
    {
        $adapter = new RecordingSwitchAdapter('checkout_layout');
        // Variante ohne Schalter-Wert -> kein aktiver Wert -> Adapter wird nicht gerufen.
        $dispatcher = new FrontendSwitchDispatcher($this->switchResolver([]), [$adapter]);

        $dispatcher->onRender($this->createMock(StorefrontRenderEvent::class));

        self::assertSame([], $adapter->applied);
    }

    public function testDoesNotCallAdapterForDifferentKey(): void
    {
        $adapter = new RecordingSwitchAdapter('other_switch');
        $dispatcher = new FrontendSwitchDispatcher($this->switchResolver(['checkout_layout' => 'guided']), [$adapter]);

        $dispatcher->onRender($this->createMock(StorefrontRenderEvent::class));

        self::assertSame([], $adapter->applied);
    }
}

/**
 * Test-Double: merkt sich die angewendeten Werte.
 */
final class RecordingSwitchAdapter implements FrontendSwitchAdapter
{
    /** @var list<string> */
    public array $applied = [];

    public function __construct(private readonly string $switchKey)
    {
    }

    public function getSwitchKey(): string
    {
        return $this->switchKey;
    }

    public function apply(string $value, StorefrontRenderEvent $event): void
    {
        $this->applied[] = $value;
    }
}
