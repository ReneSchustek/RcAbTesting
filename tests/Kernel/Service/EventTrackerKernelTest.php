<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Kernel\Service;

use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Tests\Kernel\KernelTestBase;

/**
 * Führt den EventTracker-Zuordnungsfilter gegen die echte DAL aus — den Criteria-
 * Switch (customerId vs. visitorId) und den Running-`EqualsAnyFilter`, die im
 * Unit-Test vom Mock ignoriert wurden (Review-Befund N3/AB24). Verifiziert, dass
 * Events nur für die richtigen Zuordnungen laufender Experimente geschrieben werden.
 */
final class EventTrackerKernelTest extends KernelTestBase
{
    private function tracker(): EventTracker
    {
        $tracker = $this->getContainer()->get(EventTracker::class);
        self::assertInstanceOf(EventTracker::class, $tracker);

        return $tracker;
    }

    public function testTracksOnlyVisitorAssignmentsOfRunningExperiments(): void
    {
        [$runningId, $runningVariant] = $this->createExperiment($this->runningStatus());
        [$pausedId, $pausedVariant] = $this->createExperiment('paused');

        // Derselbe Besucher ist beiden Experimenten zugeordnet.
        $this->createAssignment($runningId, $runningVariant, 'visitor-1');
        $this->createAssignment($pausedId, $pausedVariant, 'visitor-1');
        $this->invalidateRegistry();

        $this->tracker()->track('page.viewed', null, [], 'visitor-1', $this->context);

        // Nur das laufende Experiment bekommt ein Event; das pausierte bleibt außen vor.
        self::assertCount(1, $this->eventsForExperiment($runningId));
        self::assertCount(0, $this->eventsForExperiment($pausedId));
    }

    public function testTracksByCustomerIdEvenWhenTrackVisitorDiffers(): void
    {
        $customerId = $this->anyCustomerId();
        [$runningId, $variant] = $this->createExperiment($this->runningStatus());

        // Zuordnung des Kunden (Gerät A) + eine reine Besucher-Zuordnung ohne Kunde.
        $this->createAssignment($runningId, $variant, 'device-a', $customerId);
        $this->createAssignment($runningId, $variant, 'anon-visitor');
        $this->invalidateRegistry();

        // Track mit einer FREMDEN visitorId, aber der customerId: der Filter muss nach
        // customerId greifen (nicht nach visitorId) -> genau die Kunden-Zuordnung zählt.
        $this->tracker()->track('page.viewed', null, [], 'some-other-device', $this->context, $customerId);

        $events = $this->eventsForExperiment($runningId);
        self::assertCount(1, $events);
        self::assertSame($customerId, $events[0]['customerId']);
    }
}
