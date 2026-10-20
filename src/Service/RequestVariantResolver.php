<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Die Variante des aktuellen Besuchers je Experiment, einmal pro Request bestimmt. Twig-Funktionen,
 * Bridge, CMS-Seiten-Tausch und Frontend-Schalter fragen alle hier, damit sie dieselbe Variante
 * sehen und die Datenbank nur einmal je Experiment getroffen wird.
 *
 * `reset()` leert den Zustand zwischen Requests; im Worker-Betrieb sähe der nächste Besucher
 * sonst die Variante des vorigen.
 */
final class RequestVariantResolver implements ResetInterface, ExperimentTouchState
{
    /** @var array<string, AbVariantEntity|null> Ergebnis je Experiment-Schlüssel, null für „nimmt nicht teil" */
    private array $memo = [];

    /**
     * Ob eine Seite dieses Requests ein laufendes, hier anwendbares Experiment berührt hat, ob der
     * Besucher teilnimmt oder nicht.
     *
     * Der Zustand liegt im Service statt in einem Request-Attribut, weil `CacheVarySubscriber` ihn
     * bei `BeforeSendResponseEvent` liest. Bei aktivem HTTP-Cache ist das Request-Objekt dort ein
     * anderes als beim Seitenaufbau, ein Attribut wäre verloren; der Service bleibt derselbe.
     */
    private bool $experimentTouched = false;

    public function __construct(
        private readonly VariantAssigner $variantAssigner,
        private readonly RequestStack $requestStack,
        private readonly DeviceClassResolver $deviceClassResolver,
    ) {
    }

    /**
     * Null, wenn das Experiment nicht läuft, nicht passt oder der Besucher nicht teilnimmt; der
     * Aufrufer zeigt dann die Kontrollfassung.
     */
    public function resolve(string $experimentKey): ?AbVariantEntity
    {
        if (\array_key_exists($experimentKey, $this->memo)) {
            return $this->memo[$experimentKey];
        }

        return $this->memo[$experimentKey] = $this->assign($experimentKey);
    }

    public function hasTouchedExperiment(): bool
    {
        return $this->experimentTouched;
    }

    public function reset(): void
    {
        $this->memo = [];
        $this->experimentTouched = false;
    }

    private function assign(string $experimentKey): ?AbVariantEntity
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return null;
        }

        $visitorId = $request->attributes->get(VisitorIdResolver::REQUEST_ATTRIBUTE);
        $salesChannelContext = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!\is_string($visitorId) || !$salesChannelContext instanceof SalesChannelContext) {
            return null;
        }

        // Die Markierung kommt vor der Teilnahmeprüfung, denn sie gilt auch für Besucher, die
        // nicht teilnehmen. Cachte der Proxy die Kontrollseite eines ausgeschlossenen Besuchers,
        // bekämen danach auch Teilnehmer der Variante diese Seite.
        if ($this->variantAssigner->appliesTo($experimentKey, $salesChannelContext)) {
            $this->experimentTouched = true;
        }

        // Ohne dauerhafte Kennung keine Teilnahme. Eine Kennung, die den Request nicht überlebt,
        // würfelte bei jedem Aufruf neu, welche Variante der Besucher sieht, und vermischte die
        // Gruppen. Wer ohne Einwilligung keinen Cookie bekommt, sieht die Kontrollfassung und
        // wird nicht gezählt.
        if ($request->attributes->get(VisitorIdResolver::PERSISTENT_ATTRIBUTE) !== true) {
            return null;
        }

        // Die Geräteklasse wird hier am HTTP-Rand bestimmt, weil nur hier der User-Agent
        // vorliegt; die Zuordnung speichert sie für die Segment-Auswertung.
        $device = $this->deviceClassResolver->resolve($request->headers->get('User-Agent'));

        return $this->variantAssigner->assign($experimentKey, $visitorId, $salesChannelContext, $device);
    }
}
