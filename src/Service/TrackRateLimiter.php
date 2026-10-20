<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Drosselt den anonymen Track-Endpunkt mit einem Zähler je Absenderadresse im Objekt-Cache.
 *
 * Der Schlüssel darf nichts enthalten, was der Aufrufer selbst bestimmt. Die Besucher-Kennung
 * etwa steht in einem Cookie ohne `httpOnly` und wird ohne Cookie je Anfrage neu vergeben; wer
 * den Cookie weglässt, bekäme mit ihr bei jedem Aufruf einen frischen Zähler.
 *
 * Symfonys RateLimiter wäre für diesen Nebenpfad zu schwer: Gebraucht wird eine weiche Obergrenze
 * gegen Fluten, keine über Server hinweg exakte Zählung.
 */
final class TrackRateLimiter
{
    /**
     * Aufrufe je Adresse, bevor gedrosselt wird. Eine Seite löst nur wenige eigene Ereignisse aus;
     * 60 lassen auch mehreren Besuchern hinter einem gemeinsamen Anschluss Luft.
     */
    private const MAX_PER_WINDOW = 60;

    /**
     * Länge des Fensters in Sekunden, gemessen ab dem ersten Aufruf darin. Liefe es ab dem letzten
     * gezählten Aufruf, schöbe jeder Aufruf das Ende hinaus, und wer gleichmäßig unter dem Budget
     * bleibt, würde nach 60 Aufrufen dauerhaft gedrosselt.
     */
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Zählt den Aufruf und meldet true, wenn das Budget schon erschöpft war; der Aufrufer antwortet
     * dann mit 429. Gedrosselte Aufrufe zählen nicht mit.
     */
    public function tooManyRequests(string $clientKey): bool
    {
        // Ein leerer Schlüssel (keine ermittelbare Adresse) landet in einem gemeinsamen Topf.
        // Das ist strenger als nötig, aber sonst ließe sich die Drosselung durch Weglassen der
        // Adresse umgehen. Gehasht wird, weil PSR-6 den Doppelpunkt einer IPv6-Adresse im
        // Schlüssel verbietet und die Adresse so nicht im Klartext im Cache steht.
        $item = $this->cache->getItem('rc_ab_track_' . hash('sha256', $clientKey));
        $now = time();
        $stored = $item->isHit() ? $item->get() : null;
        $window = \is_array($stored) && isset($stored['count'], $stored['resetAt']) && $stored['resetAt'] > $now
            ? ['count' => (int) $stored['count'], 'resetAt' => (int) $stored['resetAt']]
            : ['count' => 0, 'resetAt' => $now + self::WINDOW_SECONDS];

        if ($window['count'] >= self::MAX_PER_WINDOW) {
            return true;
        }

        // Lesen und Schreiben sind nicht atomar, bei gleichzeitigen Anfragen zählt der Zähler zu
        // niedrig. Für eine weiche Obergrenze ist das hinnehmbar; ein exakter Zähler bräuchte eine
        // Sperre je Anfrage und erkaufte Genauigkeit mit Last auf genau dem Pfad, den er schützt.
        ++$window['count'];
        $item->set($window);
        $item->expiresAfter($window['resetAt'] - $now);
        $this->cache->save($item);

        return false;
    }
}
