<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcAbTesting\Service\CustomerDataEraserInterface;
use Ruhrcoder\RcAbTesting\Subscriber\CustomerDeletedSubscriber;
use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft den Anschluss des Löschwegs an das Löschen eines Kunden. Er hängt am Löschereignis der
 * DAL und greift damit auch bei Löschungen über die API oder ein anderes Plugin, ohne dass
 * jemand daran denken muss. Das Löschen selbst prüft
 * {@see \Ruhrcoder\RcAbTesting\Tests\Unit\Service\CustomerDataEraserTest}.
 */
#[CoversClass(CustomerDeletedSubscriber::class)]
final class CustomerDeletedSubscriberTest extends TestCase
{
    /**
     * Der Abonnent muss den Löschweg genau einmal anstoßen, mit den Kennungen aus dem Ereignis.
     */
    public function testTheDeletionIsPassedOn(): void
    {
        $id = Uuid::randomHex();

        $eraser = $this->createMock(CustomerDataEraserInterface::class);
        $eraser->expects(self::once())
            ->method('eraseForCustomers')
            ->with([$id])
            ->willReturn(2);

        (new CustomerDeletedSubscriber($eraser, new NullLogger()))
            ->onBeforeCustomerDelete($this->deletionEvent([$id]));
    }

    /**
     * Scheitert das Löschen der A/B-Daten, darf die Löschung des Kunden nicht mitscheitern.
     * Bliebe der ganze Kunde wegen dieser Nebenwirkung stehen, wäre das das größere
     * Datenschutzproblem als ein halb erledigtes Löschen.
     */
    public function testAFailureDoesNotBreakTheCustomerDeletion(): void
    {
        $eraser = $this->createMock(CustomerDataEraserInterface::class);
        $eraser->method('eraseForCustomers')->willThrowException(new \RuntimeException('weg'));

        // Der Test besteht, wenn der Aufruf ohne Ausnahme zurückkehrt; dafür ist diese Erwartung
        // in PHPUnit da. Eine Zusicherung auf `true` prüfte dagegen nichts.
        $this->expectNotToPerformAssertions();

        (new CustomerDeletedSubscriber($eraser, new NullLogger()))
            ->onBeforeCustomerDelete($this->deletionEvent([Uuid::randomHex()]));
    }

    /**
     * Eine Löschung ohne Kunden, etwa nur eines Produkts, stößt den Löschweg nicht an.
     */
    public function testOtherEntitiesAreIgnored(): void
    {
        $eraser = $this->createMock(CustomerDataEraserInterface::class);
        $eraser->expects(self::never())->method('eraseForCustomers');

        $event = $this->createMock(EntityDeleteEvent::class);
        $event->method('getIds')->willReturn([]);

        (new CustomerDeletedSubscriber($eraser, new NullLogger()))->onBeforeCustomerDelete($event);
    }

    /**
     * Der Abonnent hängt am Ereignis vor dem Löschen; danach hat `ON DELETE SET NULL` die Kundenkennung schon geleert.
     */
    public function testListensBeforeTheDeletion(): void
    {
        self::assertSame(
            [
                EntityDeleteEvent::class => 'onBeforeCustomerDelete',
            ],
            CustomerDeletedSubscriber::getSubscribedEvents()
        );
    }

    /**
     * @param list<string> $ids
     */
    private function deletionEvent(array $ids): EntityDeleteEvent
    {
        $event = $this->createMock(EntityDeleteEvent::class);
        $event->method('getIds')->willReturnCallback(
            static fn (string $entity): array => $entity === CustomerDefinition::ENTITY_NAME ? $ids : []
        );

        return $event;
    }
}
