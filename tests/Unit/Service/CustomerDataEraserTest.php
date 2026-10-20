<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\CustomerDataEraser;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft den Löschweg nach Art. 17 DSGVO. Löscht der Shop einen Kunden, ohne dass dieser Weg
 * greift, bleiben seine Zuordnungen und Ereignisse stehen, samt Kunden-, Bestell- und
 * Warenkorbkennung in den Zusatzangaben.
 */
#[CoversClass(CustomerDataEraser::class)]
final class CustomerDataEraserTest extends TestCase
{
    /**
     * Beide Tabellen tragen die Kundenkennung und müssen geleert werden. Die Reihenfolge ist
     * fachlich frei, weil keine Tabelle per Fremdschlüssel auf die andere zeigt; der Test hält
     * sie trotzdem fest.
     */
    public function testBothTablesAreClearedEventsFirst(): void
    {
        $tables = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$tables): int {
                preg_match('/FROM `([a-z_]+)`/', $sql, $matches);
                $tables[] = $matches[1] ?? '?';

                return 1;
            },
        );

        $deleted = (new CustomerDataEraser($connection))->eraseForCustomers([Uuid::randomHex()]);

        self::assertSame(['rc_ab_event', 'rc_ab_assignment'], $tables);
        self::assertSame(2, $deleted);
    }

    /**
     * Ohne gültige Kennung gäbe es nichts für `IN (…)`. Eine spätere Umformung der Abfrage
     * könnte dann ohne Bedingung laufen und die ganze Tabelle leeren; deshalb darf gar keine
     * Abfrage abgehen.
     */
    public function testNothingRunsWithoutUsableIds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(0, (new CustomerDataEraser($connection))->eraseForCustomers(['keine-uuid', '']));
    }

    /**
     * Ein Lösch-Ereignis kann gültige und ungültige Kennungen mischen. Scheiterten die gültigen
     * an den ungültigen, bliebe ein Löschverlangen unerfüllt, weil daneben Unsinn stand.
     */
    public function testValidIdsSurviveInvalidCompany(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturn(1);

        self::assertSame(2, (new CustomerDataEraser($connection))->eraseForCustomers(['unsinn', Uuid::randomHex()]));
    }
}
