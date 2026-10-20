<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

/**
 * Erkennt Pfade der Verwaltung und der Schnittstellen am Pfad allein. Gebraucht wird das dort, wo der Route-Scope noch
 * nicht feststeht, etwa beim Schlüssel des HTTP-Caches, der vor dem Routing gebildet wird.
 */
final class NonStorefrontPath
{
    private const PREFIXES = ['/admin', '/api'];

    private function __construct()
    {
    }

    public static function matches(string $path): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
