<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Command\ExportCustomerDataCommand;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbAssignment\AbAssignmentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbEvent\AbEventEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prüft den Datenexport für ein Auskunftsersuchen nach Art. 15 DSGVO. Fehlt im Export eine
 * gespeicherte Angabe, ist die Auskunft unvollständig und damit rechtlich mangelhaft.
 */
final class ExportCustomerDataCommandTest extends TestCase
{
    private const CUSTOMER_ID = '0190a1b2c3d4e5f60718293a4b5c6d7e';

    public function testExportsAssignmentsAndEventsAsJson(): void
    {
        $tester = new CommandTester(new ExportCustomerDataCommand(
            $this->assignmentRepository($this->assignment()),
            $this->eventRepository($this->event()),
        ));

        $tester->execute(['--customer' => self::CUSTOMER_ID]);

        $tester->assertCommandIsSuccessful();
        $data = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($data);
        self::assertSame(self::CUSTOMER_ID, $data['customerId']);
        self::assertCount(1, $data['assignments']);
        self::assertCount(1, $data['events']);
        self::assertSame('exp-1', $data['assignments'][0]['experimentId']);
        // Verkaufskanal und Sprache sind gespeicherte Angaben zur Person und gehören deshalb mit hinein.
        self::assertSame('sc-1', $data['assignments'][0]['salesChannelId']);
        self::assertSame('lang-1', $data['assignments'][0]['languageId']);
        self::assertSame('checkout.order_placed', $data['events'][0]['eventType']);
    }

    public function testFailsWithoutCustomerOption(): void
    {
        $tester = new CommandTester(new ExportCustomerDataCommand(
            $this->assignmentRepository(),
            $this->eventRepository(),
        ));

        $tester->execute([]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    /**
     * Eine Kennung, die keine ist, liefe sonst als leere Auskunft durch, und niemand bemerkte den Tippfehler.
     */
    public function testFailsForAnInvalidCustomerId(): void
    {
        $tester = new CommandTester(new ExportCustomerDataCommand(
            $this->assignmentRepository(),
            $this->eventRepository(),
        ));

        $tester->execute(['--customer' => 'kunde-1']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    private function assignment(): AbAssignmentEntity
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId('exp-1');
        $assignment->setVariantId('var-1');
        $assignment->setVisitorId('visitor-1');
        $assignment->setCustomerId(self::CUSTOMER_ID);
        $assignment->setSalesChannelId('sc-1');
        $assignment->setLanguageId('lang-1');
        $assignment->setAssignedAt(new \DateTimeImmutable('2026-07-01T10:00:00+00:00'));
        $assignment->setLastSeenAt(new \DateTimeImmutable('2026-07-02T10:00:00+00:00'));

        return $assignment;
    }

    private function event(): AbEventEntity
    {
        $event = new AbEventEntity();
        $event->setId(Uuid::randomHex());
        $event->setExperimentId('exp-1');
        $event->setVariantId('var-1');
        $event->setVisitorId('visitor-1');
        $event->setCustomerId(self::CUSTOMER_ID);
        $event->setEventType('checkout.order_placed');
        $event->setEventValue(49.9);
        $event->setMeta(['order' => 'abc']);
        $event->setOccurredAt(new \DateTimeImmutable('2026-07-02T10:05:00+00:00'));

        return $event;
    }

    /**
     * @return EntityRepository<AbAssignmentCollection>
     */
    private function assignmentRepository(AbAssignmentEntity ...$assignments): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbAssignmentCollection($assignments));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }

    /**
     * @return EntityRepository<AbEventCollection>
     */
    private function eventRepository(AbEventEntity ...$events): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn(new AbEventCollection($events));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($result);

        return $repository;
    }
}
