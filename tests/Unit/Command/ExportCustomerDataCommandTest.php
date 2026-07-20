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

final class ExportCustomerDataCommandTest extends TestCase
{
    public function testExportsAssignmentsAndEventsAsJson(): void
    {
        $tester = new CommandTester(new ExportCustomerDataCommand(
            $this->assignmentRepository($this->assignment()),
            $this->eventRepository($this->event()),
        ));

        $tester->execute(['--customer' => 'cust-1']);

        $tester->assertCommandIsSuccessful();
        $data = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($data);
        self::assertSame('cust-1', $data['customerId']);
        self::assertCount(1, $data['assignments']);
        self::assertCount(1, $data['events']);
        self::assertSame('exp-1', $data['assignments'][0]['experimentId']);
        // Art.-15-Vollstaendigkeit: Zuordnungskontext (Verkaufskanal, Sprache) gehoert in den Export.
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

    private function assignment(): AbAssignmentEntity
    {
        $assignment = new AbAssignmentEntity();
        $assignment->setId(Uuid::randomHex());
        $assignment->setExperimentId('exp-1');
        $assignment->setVariantId('var-1');
        $assignment->setVisitorId('visitor-1');
        $assignment->setCustomerId('cust-1');
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
        $event->setCustomerId('cust-1');
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
