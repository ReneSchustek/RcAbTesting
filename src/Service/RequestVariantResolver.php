<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Löst die Variante des aktuellen Besuchers für ein Experiment auf und
 * memoized sie pro Request je Experiment. Gemeinsame Basis für die Twig-
 * Integration und die Plugin-Bridge, damit beide identisch bucketen und nicht
 * doppelt die Datenbank treffen. `reset()` (kernel.reset) leert das Memo
 * zwischen Requests im Worker-Modus, damit keine Zuordnung durchsickert.
 */
final class RequestVariantResolver implements ResetInterface
{
    /**
     * Request-Attribut, das markiert, dass der Besucher in diesem Request
     * tatsächlich einem Experiment zugeordnet wurde. Der CacheVarySubscriber
     * deaktiviert daraufhin gezielt den HTTP-Cache nur für diese Seiten.
     */
    public const IN_EXPERIMENT_ATTRIBUTE = 'rc_ab_in_experiment';

    /** @var array<string, AbVariantEntity|null> */
    private array $memo = [];

    public function __construct(
        private readonly VariantAssigner $variantAssigner,
        private readonly RequestStack $requestStack,
        private readonly DeviceClassResolver $deviceClassResolver,
    ) {
    }

    public function resolve(string $experimentKey): ?AbVariantEntity
    {
        if (\array_key_exists($experimentKey, $this->memo)) {
            return $this->memo[$experimentKey];
        }

        return $this->memo[$experimentKey] = $this->assign($experimentKey);
    }

    public function reset(): void
    {
        $this->memo = [];
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

        // Nur persistieren, wenn die Besucher-ID aus einem echten Cookie stammt.
        $persist = $request->attributes->get(VisitorIdResolver::PERSISTENT_ATTRIBUTE) === true;
        // Geräteklasse am HTTP-Rand aus dem User-Agent ableiten und mitgeben — die
        // Zuordnung haelt sie fuer die Segment-Auswertung fest.
        $device = $this->deviceClassResolver->resolve($request->headers->get('User-Agent'));
        $variant = $this->variantAssigner->assign($experimentKey, $visitorId, $salesChannelContext, $persist, $device);
        if ($variant !== null) {
            $request->attributes->set(self::IN_EXPERIMENT_ATTRIBUTE, true);
        }

        return $variant;
    }
}
