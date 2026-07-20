<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Service\Cms\CmsExperimentLocator;
use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Cms\CmsPageEntity;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\SalesChannel\SalesChannelCmsPageLoaderInterface;
use Shopware\Core\Content\LandingPage\LandingPageDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\LandingPage\LandingPageLoadedEvent;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * No-Code-CMS-Auslieferung: liefert je nach zugewiesener Variante eine andere
 * CMS-Seite (Erlebniswelt) aus. Greift genau dann, wenn die aktuell gerenderte
 * Seite die Control-Seite eines laufenden CMS-Experiments ist und der Besucher
 * einer Nicht-Control-Variante zugeordnet wurde.
 *
 * Der `resolve()`-Aufruf hängt den Besucher ins bestehende Bucketing/Tracking
 * ein (Sticky, Targeting, Consent, Funnel-Events) — die CMS-Auslieferung nutzt
 * also dieselbe Zuordnungs- und Auswertungsbasis wie die Twig-Variante.
 *
 * Fail-safe: kann die Alternativseite nicht geladen werden, bleibt die
 * Control-Seite stehen — ein A/B-Test darf die Seite nie brechen.
 */
final class CmsPageVariantSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CmsExperimentLocator $locator,
        private readonly RequestVariantResolver $variantResolver,
        private readonly SalesChannelCmsPageLoaderInterface $cmsPageLoader,
        private readonly CategoryDefinition $categoryDefinition,
        private readonly LandingPageDefinition $landingPageDefinition,
        private readonly ProductDefinition $productDefinition,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded',
            LandingPageLoadedEvent::class => 'onLandingPageLoaded',
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $category = $page->getCategory();
        if ($category === null) {
            return;
        }

        $alt = $this->resolveSwapPage($page->getCmsPage(), $this->categoryDefinition, $category, $event->getSalesChannelContext(), $event->getRequest());
        if ($alt !== null) {
            $page->setCmsPage($alt);
        }
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $page = $event->getPage();

        $alt = $this->resolveSwapPage($page->getCmsPage(), $this->productDefinition, $page->getProduct(), $event->getSalesChannelContext(), $event->getRequest());
        if ($alt !== null) {
            $page->setCmsPage($alt);
        }
    }

    public function onLandingPageLoaded(LandingPageLoadedEvent $event): void
    {
        // Landing-Seiten tragen die CMS-Seite an der LandingPage-Entity, nicht am
        // Page-Objekt (das Storefront-Template rendert `page.landingPage.cmsPage`).
        $landingPage = $event->getPage()->getLandingPage();
        if ($landingPage === null) {
            return;
        }

        $alt = $this->resolveSwapPage($landingPage->getCmsPage(), $this->landingPageDefinition, $landingPage, $event->getSalesChannelContext(), $event->getRequest());
        if ($alt !== null) {
            $landingPage->setCmsPage($alt);
        }
    }

    /**
     * Entscheidet, ob die aktuelle Seite getauscht werden muss, und laedt ggf.
     * die Alternativseite. Null = kein Tausch (kein Experiment, nicht teilnehmend,
     * Control-Variante oder Ladefehler).
     */
    private function resolveSwapPage(?CmsPageEntity $current, EntityDefinition $definition, Entity $entity, SalesChannelContext $context, Request $request): ?CmsPageEntity
    {
        if ($current === null) {
            return null;
        }

        $baseCmsPageId = $current->getId();

        $experiment = $this->locator->findByBaseCmsPage($baseCmsPageId, $context->getContext());
        if ($experiment === null) {
            return null;
        }

        $variant = $this->variantResolver->resolve($experiment->getTechnicalKey());
        if ($variant === null) {
            // Nicht teilnehmend (Targeting/Consent/Allokation) — Control-Seite bleibt.
            return null;
        }

        $targetCmsPageId = $this->locator->cmsPageIdOf($variant);
        if ($targetCmsPageId === null || $targetCmsPageId === $baseCmsPageId) {
            // Control-Variante oder fehlende Config — nichts tauschen.
            return null;
        }

        return $this->loadCmsPage($targetCmsPageId, $definition, $entity, $context, $request);
    }

    private function loadCmsPage(string $cmsPageId, EntityDefinition $definition, Entity $entity, SalesChannelContext $context, Request $request): ?CmsPageEntity
    {
        try {
            $resolverContext = new EntityResolverContext($context, $request, $definition, $entity);
            $pages = $this->cmsPageLoader->load($request, new Criteria([$cmsPageId]), $context, null, $resolverContext);
            $cmsPage = $pages->getEntities()->first();

            return $cmsPage instanceof CmsPageEntity ? $cmsPage : null;
        } catch (\Throwable $e) {
            $this->logger->warning('CMS-Varianten-Seite {cmsPageId} konnte nicht geladen werden: {error}', [
                'cmsPageId' => $cmsPageId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
