<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Bridge;

use Ruhrcoder\RcAbTesting\Service\RequestVariantResolver;

/**
 * Stützt sich auf denselben `RequestVariantResolver` wie die Twig-Funktionen. Ein fremdes Plugin
 * sieht dadurch dieselbe Variante wie das Template, und weil der Resolver je Request und
 * Experiment zwischenspeichert, kostet eine wiederholte Abfrage keinen weiteren Datenbankzugriff.
 *
 * Wie in Twig legt die erste Abfrage die Zuordnung an, wenn es noch keine gibt.
 */
final class RequestActiveVariantQuery implements ActiveVariantQuery
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
