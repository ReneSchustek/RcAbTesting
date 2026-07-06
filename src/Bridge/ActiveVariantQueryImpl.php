<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Bridge;

use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;

/**
 * Bridge-Implementierung auf Basis des geteilten RequestVariantResolver. Die
 * Zuordnung ist dadurch identisch zur Twig-Integration und pro Request memoized
 * — ein Subscriber-Guard kostet keinen zusätzlichen Datenbank-Treffer.
 */
final class ActiveVariantQueryImpl implements ActiveVariantQuery
{
    public function __construct(
        private readonly RequestVariantResolver $variantResolver,
    ) {
    }

    public function isActiveVariant(string $experimentKey, string $variantKey): bool
    {
        return $this->getActiveVariant($experimentKey) === $variantKey;
    }

    public function getActiveVariant(string $experimentKey): ?string
    {
        return $this->variantResolver->resolve($experimentKey)?->getTechnicalKey();
    }
}
