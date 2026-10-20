<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CartAbandonmentDetector;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Führt die Abfrage von `CartAbandonmentDetector` gegen MySQL aus und prüft die
 * Zeitgrenze der beiden `NOT EXISTS`-Ausschlüsse. Ginge sie verloren, nähme ein einmal
 * gemeldeter Abbruch den Besucher dauerhaft aus der Zählung, und jeder spätere
 * Abbruch desselben Besuchers fehlte in der Auswertung.
 */
final class CartAbandonmentDetectorIntegrationTest extends IntegrationTestCase
{
    // Mittag, damit sich Ereignisse am Vormittag (älter als die Frist) und am
    // Nachmittag (zu jung) am selben Tag eindeutig trennen.
    private const THRESHOLD = '2026-07-05 12:00:00';

    public function testDetectsAbandonmentAndRespectsTimeBoundary(): void
    {
        $experimentId = Uuid::randomHex();
        $this->insertExperiment($experimentId, 'running');

        // (1) Klarer Abbruch: checkout.started vor der Frist, keine Bestellung, kein Abbruch-Event.
        $this->insertEvent($experimentId, 'plain', AbEventType::CHECKOUT_STARTED, '2026-07-05 10:00:00');

        // (2) Wiederkehrer: ein erster Durchgang mit Abbruch um 08:00/08:30, dann ein neues
        // `checkout.started` um 10:00 ohne Bestellung. Der alte Abbruch liegt vor dem zweiten
        // Start und darf ihn nicht ausschließen, der Besucher zählt also erneut.
        $this->insertEvent($experimentId, 'returner', AbEventType::CHECKOUT_STARTED, '2026-07-05 08:00:00');
        $this->insertEvent($experimentId, 'returner', AbEventType::CART_ABANDONED, '2026-07-05 08:30:00');
        $this->insertEvent($experimentId, 'returner', AbEventType::CHECKOUT_STARTED, '2026-07-05 10:00:00');

        // (3) Käufer: started + spätere Bestellung -> ausgeschlossen.
        $this->insertEvent($experimentId, 'buyer', AbEventType::CHECKOUT_STARTED, '2026-07-05 10:00:00');
        $this->insertEvent($experimentId, 'buyer', AbEventType::CHECKOUT_ORDER_PLACED, '2026-07-05 10:30:00');

        // (4) Bereits gemeldeter Abbruch (abandoned >= started) -> ausgeschlossen.
        $this->insertEvent($experimentId, 'already', AbEventType::CHECKOUT_STARTED, '2026-07-05 10:00:00');
        $this->insertEvent($experimentId, 'already', AbEventType::CART_ABANDONED, '2026-07-05 10:05:00');

        // (5) Zu jung: started nach der Frist -> ausgeschlossen.
        $this->insertEvent($experimentId, 'recent', AbEventType::CHECKOUT_STARTED, '2026-07-05 13:00:00');

        $detector = new CartAbandonmentDetector($this->connection);
        $rows = $detector->detect(new \DateTimeImmutable(self::THRESHOLD, new \DateTimeZone('UTC')));

        $visitors = array_column($rows, 'visitorId');
        sort($visitors);

        self::assertSame(['plain', 'returner'], $visitors);
    }

    public function testIgnoresNonRunningExperiments(): void
    {
        $experimentId = Uuid::randomHex();
        $this->insertExperiment($experimentId, 'paused');
        $this->insertEvent($experimentId, 'plain', AbEventType::CHECKOUT_STARTED, '2026-07-05 10:00:00');

        $detector = new CartAbandonmentDetector($this->connection);
        $rows = $detector->detect(new \DateTimeImmutable(self::THRESHOLD, new \DateTimeZone('UTC')));

        self::assertSame([], $rows);
    }
}
