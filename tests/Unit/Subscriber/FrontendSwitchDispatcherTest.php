<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use Doctrine\DBAL\Connection;
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

/**
 * Prüft, dass der Verteiler jedem Adapter genau den Wert seines Schalters reicht und sonst
 * nichts tut. Ein Adapter, der ohne aktiven Wert gerufen würde, schaltete Funktionen für
 * Besucher um, die gar nicht am Test teilnehmen.
 */
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
        // Die Variante setzt den Schalter nicht, also gibt es keinen aktiven Wert.
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
        // Ohne Adapter darf `onRender()` `all()` gar nicht erreichen, sonst legte jeder
        // Seitenaufruf Zuordnungen an, die niemand braucht. Der Anfragestapel trägt einen
        // Verkaufskanal, damit `all()` die Registry tatsächlich fragen würde; erst dann belegt
        // das `never()` am Repository, dass der Ausstieg vorher greift.
        $requestStack = $this->harnessRequestStack(true);

        $experimentRepository = $this->createMock(EntityRepository::class);
        $experimentRepository->expects(self::never())->method('search');

        $registry = new ExperimentRegistry($experimentRepository, new TagAwareAdapter(new ArrayAdapter()));
        $variantResolver = new RequestVariantResolver(
            new VariantAssigner(
                $registry,
                $this->createMock(EntityRepository::class),
                new VisitorBucketer(),
                new NullLogger(),
                $this->createMock(Connection::class)
            ),
            $requestStack,
            new DeviceClassResolver(),
        );
        $resolver = new FrontendSwitchResolver($registry, $variantResolver, $requestStack);

        (new FrontendSwitchDispatcher($resolver, []))->onRender($this->createMock(StorefrontRenderEvent::class));
    }
}

/**
 * Testdoppel, das sich die angewendeten Werte merkt.
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
