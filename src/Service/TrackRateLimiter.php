<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Schlanke, self-contained Drosselung des anonymen Track-Endpunkts (Arbeitspaket AB41):
 * ein fester Zähler je Client-Schlüssel (Besucher + IP) im gleitenden
 * Zeitfenster über den Shopware-Objekt-Cache. Bewusst keine framework-
 * RateLimiter-Konfiguration — der Nebenpfad-Endpunkt braucht nur eine weiche
 * Obergrenze gegen Flooding, keine verteilt-präzise Ratenkontrolle.
 */
final class TrackRateLimiter
{
    /** Erlaubte Track-Calls je Client im Fenster. */
    private const MAX_PER_WINDOW = 60;

    /** Fensterlänge in Sekunden. */
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Zählt einen Track-Versuch des Clients und meldet, ob dessen Fenster-Budget
     * bereits erschöpft ist (dann sollte der Aufrufer 429 antworten).
     */
    public function tooManyRequests(string $clientKey): bool
    {
        $item = $this->cache->getItem('rc_ab_track_' . hash('sha256', $clientKey));
        $count = $item->isHit() ? (int) $item->get() : 0;

        if ($count >= self::MAX_PER_WINDOW) {
            return true;
        }

        $item->set($count + 1);
        $item->expiresAfter(self::WINDOW_SECONDS);
        $this->cache->save($item);

        return false;
    }
}
