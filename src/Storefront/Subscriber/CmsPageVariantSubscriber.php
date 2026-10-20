<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Storefront\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
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
 * Liefert je Variante eine andere Erlebniswelt aus, ohne Template-Code. Das greift, wenn die
 * gerenderte Seite die Kontrollseite eines laufenden CMS-Experiments ist und der Besucher einer
 * anderen Variante zugeordnet ist; Kategorie-, Produkt- und Landing-Seiten werden gleich behandelt.
 *
 * Über `resolve()` läuft der Besucher durch dasselbe Bucketing wie bei einer Twig-Variante:
 * Klebrige Zuordnung, Targeting, Einwilligung und Funnel-Ereignisse sind dieselben.
 *
 * Lässt sich die Alternativseite nicht laden, bleibt die Kontrollseite stehen. Ein A/B-Test darf
 * eine Seite nie unbenutzbar machen.
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

        $alternativePage = $this->resolveSwapPage(
            $page->getCmsPage(),
            $this->categoryDefinition,
            $category,
            $event->getSalesChannelContext(),
            $event->getRequest()
        );
        if ($alternativePage !== null) {
            $page->setCmsPage($alternativePage);
        }
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $page = $event->getPage();

        $alternativePage = $this->resolveSwapPage(
            $page->getCmsPage(),
            $this->productDefinition,
            $page->getProduct(),
            $event->getSalesChannelContext(),
            $event->getRequest()
        );
        if ($alternativePage !== null) {
            $page->setCmsPage($alternativePage);
        }
    }

    public function onLandingPageLoaded(LandingPageLoadedEvent $event): void
    {
        // Landing-Seiten tragen die CMS-Seite an der Entity, nicht am Page-Objekt; das
        // Storefront-Template rendert `page.landingPage.cmsPage`.
        $landingPage = $event->getPage()->getLandingPage();
        if ($landingPage === null) {
            return;
        }

        $alternativePage = $this->resolveSwapPage(
            $landingPage->getCmsPage(),
            $this->landingPageDefinition,
            $landingPage,
            $event->getSalesChannelContext(),
            $event->getRequest()
        );
        if ($alternativePage !== null) {
            $landingPage->setCmsPage($alternativePage);
        }
    }

    /**
     * Alternativseite für den Besucher oder null, wenn nichts getauscht wird: kein Experiment auf
     * dieser Seite, eine Variante ohne Zielseite, Besucher nicht teilnehmend, Kontrollvariante
     * oder Ladefehler.
     */
    private function resolveSwapPage(
        ?CmsPageEntity $current,
        EntityDefinition $definition,
        Entity $entity,
        SalesChannelContext $context,
        Request $request,
    ): ?CmsPageEntity {
        if ($current === null) {
            return null;
        }

        $baseCmsPageId = $current->getId();

        $experiment = $this->locator->findByBaseCmsPage($baseCmsPageId, $context->getContext());
        if ($experiment === null) {
            return null;
        }

        // Erst prüfen, ob der Test ausspielbar ist, dann zuteilen; die Zuteilung legt die
        // Zuordnung dauerhaft an. Fehlt einer Variante ihre Zielseite, etwa weil jemand sie im
        // laufenden Test aus der Variante genommen hat, sähen deren Teilnehmer die Kontrollseite
        // und zählten trotzdem als Variante, und ein echter Effekt verschwände im Mittel. Der
        // Wächter beim Start hilft dagegen nicht, er prüft nur einmal vor dem Lauf.
        if (!$this->isFullyConfigured($experiment)) {
            $this->logger->error('A/B-Experiment {experiment} hat eine Variante ohne Zielseite — es wird nicht ausgespielt, damit die Messung nicht verfälscht wird.', [
                'experiment' => $experiment->getTechnicalKey(),
            ]);

            return null;
        }

        $variant = $this->variantResolver->resolve($experiment->getTechnicalKey());
        if ($variant === null) {
            // Nicht teilnehmend (Targeting, Einwilligung, Allokation): Die Kontrollseite bleibt.
            return null;
        }

        $targetCmsPageId = $variant->getCmsPageId();
        if ($targetCmsPageId === null || $targetCmsPageId === $baseCmsPageId) {
            // Die Kontrollvariante trägt die Basisseite selbst, dann gibt es nichts zu tauschen.
            return null;
        }

        return $this->loadCmsPage($targetCmsPageId, $definition, $entity, $context, $request);
    }

    private function loadCmsPage(
        string $cmsPageId,
        EntityDefinition $definition,
        Entity $entity,
        SalesChannelContext $context,
        Request $request,
    ): ?CmsPageEntity {
        try {
            $resolverContext = new EntityResolverContext($context, $request, $definition, $entity);
            $pages = $this->cmsPageLoader->load($request, new Criteria([$cmsPageId]), $context, null, $resolverContext);
            $cmsPage = $pages->getEntities()->first();

            return $cmsPage instanceof CmsPageEntity ? $cmsPage : null;
        } catch (\Throwable $e) {
            // Fehlerstufe statt Warnung: Der Besucher ist schon einer Variante zugeordnet, sieht
            // aber die Kontrollseite. Er zählt in einer Gruppe, deren Fassung er nie gesehen hat,
            // und die Auswertung dieses Experiments ist ab hier nicht mehr belastbar.
            $this->logger->error('CMS-Varianten-Seite {cmsPageId} konnte nicht geladen werden — der Besucher sieht die Kontroll-Seite, zählt aber als Variante. Die Messung dieses Experiments ist dadurch verfälscht', [
                'cmsPageId' => $cmsPageId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Hat jede Variante außer der Kontrolle eine Zielseite?
     *
     * Ohne sie läuft der Test ins Leere, ohne dass es auffällt: Die Zuordnung entsteht, die
     * Seite bleibt die alte.
     */
    private function isFullyConfigured(AbExperimentEntity $experiment): bool
    {
        foreach ($experiment->getVariantList() as $variant) {
            if ($variant->isControl()) {
                continue;
            }

            if ($variant->getCmsPageId() === null) {
                return false;
            }
        }

        return true;
    }
}
