<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Kernel\Subscriber;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\ExperimentRegistry;
use Ruhrcoder\RcAbTesting\Service\FrontendSwitch\FrontendSwitchResolver;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Ruhrcoder\RcAbTesting\Service\VisitorIdResolver;
use Ruhrcoder\RcAbTesting\Storefront\Subscriber\CmsPageVariantSubscriber;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Navigation\NavigationPage;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Führt `CmsPageVariantSubscriber` gegen den echten Kernel und die echte DAL aus. Ein
 * Fehler hier tauscht entweder die Erlebniswelt für die falschen Besucher oder bricht
 * die Seite, wenn die Zielseite fehlt; beides sieht jeder Kunde sofort.
 *
 * Geprüft wird an der Navigationsseite, weil alle drei Handler denselben Weg über
 * `resolveSwapPage()` gehen: Tausch für eine Nicht-Kontroll-Variante, kein Tausch ohne
 * Experiment oder für die Kontrolle, und kein Tausch ohne Ausnahme, wenn die Zielseite
 * nicht existiert.
 */
final class CmsPageVariantSubscriberKernelTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const CONFIG_KEY = 'cmsPageId';

    public function testSwapsToVariantPageForNonControlVisitor(): void
    {
        $baseCmsPageId = $this->createCmsPage();
        $targetCmsPageId = $this->createCmsPage();
        [$scId, $languageId] = $this->storefrontSalesChannel();

        $this->seedCmsExperiment($baseCmsPageId, $targetCmsPageId, 'visitor-swap', $scId, $languageId);
        $request = $this->putVisitorOnRequestStack('visitor-swap', $scId, $languageId);

        $page = $this->navigationPage($baseCmsPageId);
        $page->expects(self::once())->method('setCmsPage')
            ->with(self::callback(static fn (CmsPageEntity $p): bool => $p->getId() === $targetCmsPageId));

        // Geprüft wird über die Erwartung an `setCmsPage()`. Eine Prüfung, dass die Varianten-Id
        // nicht leer ist, könnte hier nie scheitern und fehlt deshalb.
        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
    }

    public function testDoesNotSwapWithoutRunningExperiment(): void
    {
        $baseCmsPageId = $this->createCmsPage();
        [$scId, $languageId] = $this->storefrontSalesChannel();
        $request = $this->putVisitorOnRequestStack('visitor-none', $scId, $languageId);

        $page = $this->navigationPage($baseCmsPageId);
        $page->expects(self::never())->method('setCmsPage');

        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
    }

    public function testDoesNotSwapForControlVisitor(): void
    {
        $baseCmsPageId = $this->createCmsPage();
        $targetCmsPageId = $this->createCmsPage();
        [$scId, $languageId] = $this->storefrontSalesChannel();
        // Die Kontrolle trägt die Basisseite als Ziel; der Subscriber erkennt das und lädt nichts nach.
        $this->seedCmsExperiment($baseCmsPageId, $targetCmsPageId, 'visitor-control', $scId, $languageId, true);
        $request = $this->putVisitorOnRequestStack('visitor-control', $scId, $languageId);

        $page = $this->navigationPage($baseCmsPageId);
        $page->expects(self::never())->method('setCmsPage');

        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
    }

    public function testFailSafeKeepsControlWhenTargetPageCannotBeLoaded(): void
    {
        $baseCmsPageId = $this->createCmsPage();
        // Eine Id ohne Seite dahinter: Der Loader liefert eine leere Menge, er wirft nicht.
        $missingTargetId = Uuid::randomHex();
        [$scId, $languageId] = $this->storefrontSalesChannel();
        $this->seedCmsExperiment($baseCmsPageId, $missingTargetId, 'visitor-fail', $scId, $languageId);
        $request = $this->putVisitorOnRequestStack('visitor-fail', $scId, $languageId);

        $page = $this->navigationPage($baseCmsPageId);
        // Die Kontrollseite bleibt stehen; eine Ausnahme ließe den Test scheitern.
        $page->expects(self::never())->method('setCmsPage');

        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
    }

    private function subscriber(): CmsPageVariantSubscriber
    {
        $subscriber = $this->service(CmsPageVariantSubscriber::class);
        self::assertInstanceOf(CmsPageVariantSubscriber::class, $subscriber);

        return $subscriber;
    }

    /**
     * Legt ein laufendes `cms_page`-Experiment an, dessen Kontrolle auf die Basisseite und
     * dessen Variante B auf die Zielseite zeigt, und ordnet den Besucher fest zu. Mit
     * `$assignToControl` landet er in der Kontrolle. Rückgabe ist immer die Id von Variante B.
     */
    private function seedCmsExperiment(
        string $baseCmsPageId,
        string $targetCmsPageId,
        string $visitorId,
        string $scId,
        string $languageId,
        bool $assignToControl = false,
    ): string {
        $context = Context::createDefaultContext();
        $experimentId = Uuid::randomHex();
        $controlId = Uuid::randomHex();
        $variantId = Uuid::randomHex();

        $this->repository('rc_ab_experiment.repository')->create([[
            'id' => $experimentId,
            'technicalKey' => 'cms-' . $experimentId,
            'name' => 'CMS-Test',
            'status' => AbExperimentStatus::RUNNING,
            'testType' => 'cms_page',
            'trafficAllocationPct' => 100,
            'targetSignificance' => 0.95,
            'variants' => [
['id' => $controlId,
    'technicalKey' => 'control',
    'name' => 'Control',
    'weight' => 50,
    'isControl' => true,
    'config' => [
        self::CONFIG_KEY => $baseCmsPageId,
    ],
],
['id' => $variantId,
    'technicalKey' => 'variant-b',
    'name' => 'Variante B',
    'weight' => 50,
    'isControl' => false,
    'config' => [
        self::CONFIG_KEY => $targetCmsPageId,
    ],
],
            ],
        ]], $context);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->repository('rc_ab_assignment.repository')->create([[
            'id' => Uuid::randomHex(),
            'experimentId' => $experimentId,
            'variantId' => $assignToControl ? $controlId : $variantId,
            'visitorId' => $visitorId,
            'salesChannelId' => $scId,
            'languageId' => $languageId,
            'assignedAt' => $now,
            'lastSeenAt' => $now,
        ]], $context);

        $this->service(ExperimentRegistry::class)->invalidate();

        return $variantId;
    }

    private function createCmsPage(): string
    {
        $id = Uuid::randomHex();
        $this->repository('cms_page.repository')->create([[
            'id' => $id,
            'name' => 'Seite ' . $id,
            'type' => 'page',
        ]], Context::createDefaultContext());

        return $id;
    }

    private function navigationEvent(NavigationPage $page, Request $request): NavigationPageLoadedEvent
    {
        $event = $this->createMock(NavigationPageLoadedEvent::class);
        $event->method('getPage')->willReturn($page);
        $event->method('getSalesChannelContext')->willReturn($this->currentContext);
        $event->method('getRequest')->willReturn($request);

        return $event;
    }

    private function navigationPage(string $cmsPageId): NavigationPage&\PHPUnit\Framework\MockObject\MockObject
    {
        $cmsPage = new CmsPageEntity();
        $cmsPage->setId($cmsPageId);

        $page = $this->createMock(NavigationPage::class);
        $page->method('getCmsPage')->willReturn($cmsPage);
        $page->method('getCategory')->willReturn(new CategoryEntity());

        return $page;
    }

    private SalesChannelContext $currentContext;

    private function putVisitorOnRequestStack(string $visitorId, string $scId, string $languageId): Request
    {
        $this->currentContext = $this->service(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), $scId, [SalesChannelContextService::LANGUAGE_ID => $languageId]);

        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, $visitorId);
        $request->attributes->set(VisitorIdResolver::PERSISTENT_ATTRIBUTE, true);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $this->currentContext);

        // Beide Resolver merken sich ihr Ergebnis je Anfrage. Ohne leeren Stapel und
        // Zurücksetzen sähe der Subscriber den Besucher des vorigen Tests.
        $stack = $this->service(RequestStack::class);
        while ($stack->getMainRequest() !== null) {
            $stack->pop();
        }
        $stack->push($request);
        $this->service(RequestVariantResolver::class)->reset();
        $this->service(FrontendSwitchResolver::class)->reset();

        return $request;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storefrontSalesChannel(): array
    {
        $connection = $this->service(Connection::class);
        // Die Konstante ist die feste Typ-Id des Storefront-Kanals in Shopware
        // (`Defaults::SALES_CHANNEL_TYPE_STOREFRONT`); ein Headless-Kanal hätte keine Erlebniswelten.
        $row = $connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(language_id)) AS languageId
             FROM sales_channel WHERE active = 1 AND type_id = UNHEX(:storefront) LIMIT 1',
            ['storefront' => '8a243080f92e4c719546314b577cf82b'],
        );
        \assert(\is_array($row) && \is_string($row['id']) && \is_string($row['languageId']));

        return [$row['id'], $row['languageId']];
    }

    /**
     * Ein DAL-Repository aus dem Container, mit Typprüfung statt Annahme.
     *
     * @return EntityRepository<covariant EntityCollection<Entity>>
     */
    private function repository(string $serviceId): EntityRepository
    {
        $repository = $this->getContainer()->get($serviceId);
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /**
     * Ein Dienst anhand seiner Klasse, geprüft geholt.
     *
     * @template TService of object
     *
     * @param class-string<TService> $className
     *
     * @return TService
     */
    private function service(string $className): object
    {
        $service = $this->getContainer()->get($className);
        self::assertInstanceOf($className, $service);

        return $service;
    }

}
