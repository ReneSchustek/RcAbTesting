<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\DeviceClassResolver;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchAdapter;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Ruhrcoder\RcAbTesting\Service\VariantAssigner;
use Ruhrcoder\RcAbTesting\Service\VisitorBucketer;
use Ruhrcoder\RcAbTesting\Subscriber\FrontendSwitchDispatcher;
use Ruhrcoder\RcAbTesting\Tests\Unit\Service\FrontendSwitch\FrontendSwitchHarness;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

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

    public function testDoesNotConsultResolverWhenNoAdaptersRegistered(): void
    {
        // Ohne Adapter darf onRender resolver->all() gar nicht erreichen — sonst
        // würde auf jedem Storefront-Render eine Zuordnung geschrieben (Hot-Path-
        // Schreib-Seiteneffekt, Arbeitspaket AB45). RequestStack MIT Context, damit all()
        // die Registry überhaupt anfragen WÜRDE; die never()-Erwartung beweist,
        // dass der Guard vorher greift.
        $requestStack = $this->harnessRequestStack(true);

        $experimentRepository = $this->createMock(EntityRepository::class);
        $experimentRepository->expects(self::never())->method('search');

        $registry = new ExperimentRegistry($experimentRepository, new TagAwareAdapter(new ArrayAdapter()));
        $variantResolver = new RequestVariantResolver(
            new VariantAssigner($registry, $this->createMock(EntityRepository::class), new VisitorBucketer(), new NullLogger()),
            $requestStack,
            new DeviceClassResolver(),
        );
        $resolver = new FrontendSwitchResolver($registry, $variantResolver, $requestStack);

        (new FrontendSwitchDispatcher($resolver, []))->onRender($this->createMock(StorefrontRenderEvent::class));
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
