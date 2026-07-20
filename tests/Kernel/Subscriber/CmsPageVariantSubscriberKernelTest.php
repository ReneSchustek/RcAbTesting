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
 * Führt den CmsPageVariantSubscriber gegen den echten Kernel + die echte DAL aus
 * (Arbeitspaket AB36): der Subscriber war zuvor komplett ungetestet, inkl. des
 * sicherheitsrelevanten Fail-safe „ein A/B-Test darf die Seite nie brechen".
 *
 * Verifiziert am Beispiel der Navigation-Seite (die drei Handler teilen sich
 * resolveSwapPage): Tausch bei Nicht-Control-Variante, KEIN Tausch bei fehlendem
 * Experiment / Control-Variante, und der Fail-safe bei nicht ladbarer Zielseite
 * (Control-Seite bleibt, kein Throw).
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

        $variantB = $this->seedCmsExperiment($baseCmsPageId, $targetCmsPageId, 'visitor-swap', $scId, $languageId);
        $this->putVisitorOnRequestStack('visitor-swap', $scId, $languageId, $request);

        $page = $this->navigationPage($baseCmsPageId);
        // Erwartung: getauscht auf die Zielseite der Variante.
        $page->expects(self::once())->method('setCmsPage')
            ->with(self::callback(static fn (CmsPageEntity $p): bool => $p->getId() === $targetCmsPageId));

        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
        self::assertNotSame('', $variantB);
    }

    public function testDoesNotSwapWithoutRunningExperiment(): void
    {
        $baseCmsPageId = $this->createCmsPage();
        [$scId, $languageId] = $this->storefrontSalesChannel();
        $this->putVisitorOnRequestStack('visitor-none', $scId, $languageId, $request);

        $page = $this->navigationPage($baseCmsPageId);
        $page->expects(self::never())->method('setCmsPage');

        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
    }

    public function testDoesNotSwapForControlVisitor(): void
    {
        $baseCmsPageId = $this->createCmsPage();
        $targetCmsPageId = $this->createCmsPage();
        [$scId, $languageId] = $this->storefrontSalesChannel();
        // Besucher der Control-Variante zugeordnet (Basis-Seite) — kein Tausch.
        $this->seedCmsExperiment($baseCmsPageId, $targetCmsPageId, 'visitor-control', $scId, $languageId, true);
        $this->putVisitorOnRequestStack('visitor-control', $scId, $languageId, $request);

        $page = $this->navigationPage($baseCmsPageId);
        $page->expects(self::never())->method('setCmsPage');

        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
    }

    public function testFailSafeKeepsControlWhenTargetPageCannotBeLoaded(): void
    {
        $baseCmsPageId = $this->createCmsPage();
        $missingTargetId = Uuid::randomHex(); // existiert nicht -> Loader liefert nichts
        [$scId, $languageId] = $this->storefrontSalesChannel();
        $this->seedCmsExperiment($baseCmsPageId, $missingTargetId, 'visitor-fail', $scId, $languageId);
        $this->putVisitorOnRequestStack('visitor-fail', $scId, $languageId, $request);

        $page = $this->navigationPage($baseCmsPageId);
        // Fail-safe: Zielseite nicht ladbar -> Control-Seite bleibt, KEIN Tausch, kein Throw.
        $page->expects(self::never())->method('setCmsPage');

        $this->subscriber()->onNavigationPageLoaded($this->navigationEvent($page, $request));
    }

    private function subscriber(): CmsPageVariantSubscriber
    {
        $subscriber = $this->getContainer()->get(CmsPageVariantSubscriber::class);
        self::assertInstanceOf(CmsPageVariantSubscriber::class, $subscriber);

        return $subscriber;
    }

    /**
     * Legt ein laufendes cms_page-Experiment an (Control = Basis-Seite,
     * Variante-B = Zielseite) und ordnet den Besucher zu. Gibt die Variante-B-Id
     * zurück. Bei $assignToControl wird stattdessen der Control zugeordnet.
     */
    private function seedCmsExperiment(string $baseCmsPageId, string $targetCmsPageId, string $visitorId, string $scId, string $languageId, bool $assignToControl = false): string
    {
        $context = Context::createDefaultContext();
        $experimentId = Uuid::randomHex();
        $controlId = Uuid::randomHex();
        $variantId = Uuid::randomHex();

        $this->getContainer()->get('rc_ab_experiment.repository')->create([[
            'id' => $experimentId,
            'technicalKey' => 'cms-' . $experimentId,
            'name' => 'AB36 CMS-Test',
            'status' => AbExperimentStatus::RUNNING,
            'testType' => 'cms_page',
            'trafficAllocationPct' => 100,
            'targetSignificance' => 0.95,
            'variants' => [
                ['id' => $controlId, 'technicalKey' => 'control', 'name' => 'Control', 'weight' => 50, 'isControl' => true, 'config' => [self::CONFIG_KEY => $baseCmsPageId]],
                ['id' => $variantId, 'technicalKey' => 'variant-b', 'name' => 'Variante B', 'weight' => 50, 'isControl' => false, 'config' => [self::CONFIG_KEY => $targetCmsPageId]],
            ],
        ]], $context);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->getContainer()->get('rc_ab_assignment.repository')->create([[
            'id' => Uuid::randomHex(),
            'experimentId' => $experimentId,
            'variantId' => $assignToControl ? $controlId : $variantId,
            'visitorId' => $visitorId,
            'salesChannelId' => $scId,
            'languageId' => $languageId,
            'assignedAt' => $now,
            'lastSeenAt' => $now,
        ]], $context);

        $this->getContainer()->get(ExperimentRegistry::class)->invalidate();

        return $variantId;
    }

    private function createCmsPage(): string
    {
        $id = Uuid::randomHex();
        $this->getContainer()->get('cms_page.repository')->create([[
            'id' => $id,
            'name' => 'AB36 Seite ' . $id,
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

    private function putVisitorOnRequestStack(string $visitorId, string $scId, string $languageId, ?Request &$request): void
    {
        $this->currentContext = $this->getContainer()
            ->get(SalesChannelContextFactory::class)
            ->create(Uuid::randomHex(), $scId, [SalesChannelContextService::LANGUAGE_ID => $languageId]);

        $request = new Request();
        $request->attributes->set(VisitorIdResolver::REQUEST_ATTRIBUTE, $visitorId);
        $request->attributes->set(VisitorIdResolver::PERSISTENT_ATTRIBUTE, true);
        $request->attributes->set(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT, $this->currentContext);

        $stack = $this->getContainer()->get(RequestStack::class);
        while ($stack->getMainRequest() !== null) {
            $stack->pop();
        }
        $stack->push($request);
        $this->getContainer()->get(RequestVariantResolver::class)->reset();
        $this->getContainer()->get(FrontendSwitchResolver::class)->reset();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storefrontSalesChannel(): array
    {
        $connection = $this->getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $row = $connection->fetchAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(language_id)) AS languageId
             FROM sales_channel WHERE active = 1 AND type_id = UNHEX(:storefront) LIMIT 1',
            ['storefront' => '8a243080f92e4c719546314b577cf82b'],
        );
        \assert(\is_array($row) && \is_string($row['id']) && \is_string($row['languageId']));

        return [$row['id'], $row['languageId']];
    }
}
