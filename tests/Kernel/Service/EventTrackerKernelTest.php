<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Kernel\Service;

use Ruhrcoder\RcAbTesting\Service\EventTracker;
use Ruhrcoder\RcAbTesting\Tests\Kernel\KernelTestBase;

/**
 * Führt die Zuordnungssuche von `EventTracker` gegen die echte DAL aus: Mit Kundenkennung
 * filtert sie nach `customerId`, sonst nach `visitorId`, und in beiden Fällen nur auf
 * laufende Experimente. Ein gemocktes Repository nimmt jedes Criteria-Objekt hin; erst
 * hier zeigt sich, ob die Filter wirklich greifen. Griffen sie nicht, landeten Ereignisse
 * in pausierten Experimenten oder bei fremden Besuchern.
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

        self::assertCount(1, $this->eventsForExperiment($runningId));
        self::assertCount(0, $this->eventsForExperiment($pausedId));
    }

    public function testTracksByCustomerIdEvenWhenTrackVisitorDiffers(): void
    {
        $customerId = $this->anyCustomerId();
        [$runningId, $variant] = $this->createExperiment($this->runningStatus());

        // Die Zuordnung des Kunden auf Gerät A und daneben eine anonyme Zuordnung, die nicht
        // getroffen werden darf.
        $this->createAssignment($runningId, $variant, 'device-a', $customerId);
        $this->createAssignment($runningId, $variant, 'anon-visitor');
        $this->invalidateRegistry();

        // Die `visitorId` passt zu keiner Zuordnung. Ein Ereignis entsteht nur, wenn der
        // Filter über `customerId` läuft.
        $this->tracker()->track('page.viewed', null, [], 'some-other-device', $this->context, $customerId);

        $events = $this->eventsForExperiment($runningId);
        self::assertCount(1, $events);
        self::assertSame($customerId, $events[0]['customerId']);
    }
}
