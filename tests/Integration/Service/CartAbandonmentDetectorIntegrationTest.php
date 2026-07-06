<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CartAbandonmentDetector;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Führt die reale NOT-EXISTS-Zeitgrenzen-SQL des CartAbandonmentDetector gegen
 * MySQL aus — genau die Kern-Invariante, die im Unit-Test nur per String-Match
 * geprüft war (Review-Befund N3/AB24).
 */
final class CartAbandonmentDetectorIntegrationTest extends IntegrationTestCase
{
    private const THRESHOLD = '2026-07-05 12:00:00';

    public function testDetectsAbandonmentAndRespectsTimeBoundary(): void
    {
        $experimentId = Uuid::randomHex();
        $this->insertExperiment($experimentId, 'running');

        // (1) Klarer Abbruch: checkout.started vor der Frist, keine Bestellung, kein Abbruch-Event.
        $this->insertEvent($experimentId, 'plain', AbEventType::CHECKOUT_STARTED, '2026-07-05 10:00:00');

        // (2) Wiederkehrer (Zeitgrenze): erster Zyklus started+abandoned (08:00/08:30), dann
        // ein NEUES started um 10:00 ohne Folge-Order. Der alte Abbruch (08:30) liegt VOR dem
        // zweiten started und darf es daher NICHT ausschließen -> Wiederkehrer wird erkannt.
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
