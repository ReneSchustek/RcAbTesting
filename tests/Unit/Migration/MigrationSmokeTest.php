<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569600CreateAbExperiment;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569601CreateAbVariant;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569602CreateAbAssignment;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569603CreateAbEvent;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569604AddAnonymizedAt;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569605AddCustomerUnique;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569606AddExperimentKeyUnique;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569607AddScheduling;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569608AddDecisionMetric;
use Ruhrcoder\RcAbTesting\Migration\Migration1747569609AddAssignmentDevice;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Smoke-Tests: jede Migration läuft ohne Exception durch (Mock-Connection,
 * kein realer DB-Zugriff). Timestamps sind gegen versehentliche Reihenfolge-
 * Wechsel gepinnt — FK-Reihenfolge experiment → variant → assignment → event
 * darf nie brechen.
 */
final class MigrationSmokeTest extends TestCase
{
    /** @return iterable<string, array{MigrationStep, int, string}> */
    public static function provideMigrations(): iterable
    {
        yield 'experiment' => [new Migration1747569600CreateAbExperiment(), 1747569600, 'rc_ab_experiment'];
        yield 'variant' => [new Migration1747569601CreateAbVariant(), 1747569601, 'rc_ab_variant'];
        yield 'assignment' => [new Migration1747569602CreateAbAssignment(), 1747569602, 'rc_ab_assignment'];
        yield 'event' => [new Migration1747569603CreateAbEvent(), 1747569603, 'rc_ab_event'];
    }

    #[DataProvider('provideMigrations')]
    public function testCreationTimestampIsPinned(MigrationStep $migration, int $expectedTimestamp): void
    {
        self::assertSame($expectedTimestamp, $migration->getCreationTimestamp());
    }

    #[DataProvider('provideMigrations')]
    public function testUpdateCallsExecuteStatementOnce(MigrationStep $migration, int $_timestamp, string $tableName): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(self::stringContains('CREATE TABLE IF NOT EXISTS `' . $tableName . '`'));

        $migration->update($connection);
    }

    #[DataProvider('provideMigrations')]
    public function testUpdateDestructiveIsNoOp(MigrationStep $migration): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $migration->updateDestructive($connection);
    }

    public function testAddAnonymizedAtTimestampIsPinned(): void
    {
        self::assertSame(1747569604, (new Migration1747569604AddAnonymizedAt())->getCreationTimestamp());
    }

    public function testAddAnonymizedAtAltersBothTablesWhenColumnMissing(): void
    {
        $altered = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false); // Spalte fehlt noch
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$altered): int {
                $altered[] = $sql;

                return 0;
            },
        );

        (new Migration1747569604AddAnonymizedAt())->update($connection);

        self::assertCount(2, $altered);
        self::assertStringContainsString('ALTER TABLE `rc_ab_assignment` ADD COLUMN `anonymized_at`', $altered[0]);
        self::assertStringContainsString('ALTER TABLE `rc_ab_event` ADD COLUMN `anonymized_at`', $altered[1]);
    }

    public function testAddAnonymizedAtSkipsWhenColumnExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('anonymized_at'); // Spalte vorhanden
        $connection->expects(self::never())->method('executeStatement');

        (new Migration1747569604AddAnonymizedAt())->update($connection);
    }

    public function testAddCustomerUniqueTimestampIsPinned(): void
    {
        self::assertSame(1747569605, (new Migration1747569605AddCustomerUnique())->getCreationTimestamp());
    }

    public function testAddCustomerUniqueDedupesAndAddsUniqueWhenMissing(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false); // Index fehlt noch
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        (new Migration1747569605AddCustomerUnique())->update($connection);

        self::assertCount(2, $statements);
        self::assertStringContainsString('DELETE', $statements[0]);
        self::assertStringContainsString('ADD UNIQUE KEY `uniq.rc_ab_assignment.experiment_customer`', $statements[1]);
    }

    public function testAddCustomerUniqueSkipsAlterWhenAlreadyPresent(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('1'); // Index bereits vorhanden
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        (new Migration1747569605AddCustomerUnique())->update($connection);

        // Nur das Dedup-DELETE, kein ALTER (UNIQUE existiert schon).
        self::assertCount(1, $statements);
        self::assertStringContainsString('DELETE', $statements[0]);
    }

    public function testAddExperimentKeyUniqueTimestampIsPinned(): void
    {
        self::assertSame(1747569606, (new Migration1747569606AddExperimentKeyUnique())->getCreationTimestamp());
    }

    public function testAddExperimentKeyUniqueDedupesAndAddsUniqueWhenMissing(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        (new Migration1747569606AddExperimentKeyUnique())->update($connection);

        self::assertCount(2, $statements);
        self::assertStringContainsString('DELETE', $statements[0]);
        self::assertStringContainsString('ADD UNIQUE KEY `uniq.rc_ab_experiment.technical_key`', $statements[1]);
    }

    public function testAddSchedulingTimestampIsPinned(): void
    {
        self::assertSame(1747569607, (new Migration1747569607AddScheduling())->getCreationTimestamp());
    }

    public function testAddSchedulingAddsBothColumnsWhenMissing(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        (new Migration1747569607AddScheduling())->update($connection);

        self::assertCount(2, $statements);
        self::assertStringContainsString('`scheduled_start_at`', $statements[0]);
        self::assertStringContainsString('`scheduled_end_at`', $statements[1]);
    }

    public function testAddSchedulingSkipsExistingColumns(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('1');
        $connection->expects(self::never())->method('executeStatement');

        (new Migration1747569607AddScheduling())->update($connection);
    }

    public function testAddDecisionMetricTimestampIsPinned(): void
    {
        self::assertSame(1747569608, (new Migration1747569608AddDecisionMetric())->getCreationTimestamp());
    }

    public function testAddDecisionMetricAddsColumnWhenMissing(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false); // Spalte fehlt noch
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        (new Migration1747569608AddDecisionMetric())->update($connection);

        self::assertCount(1, $statements);
        self::assertStringContainsString('ADD COLUMN `decision_metric`', $statements[0]);
    }

    public function testAddDecisionMetricSkipsWhenColumnExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('decision_metric'); // Spalte vorhanden
        $connection->expects(self::never())->method('executeStatement');

        (new Migration1747569608AddDecisionMetric())->update($connection);
    }

    public function testAddAssignmentDeviceTimestampIsPinned(): void
    {
        self::assertSame(1747569609, (new Migration1747569609AddAssignmentDevice())->getCreationTimestamp());
    }

    public function testAddAssignmentDeviceAddsColumnWhenMissing(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false); // Spalte fehlt noch
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            },
        );

        (new Migration1747569609AddAssignmentDevice())->update($connection);

        self::assertCount(1, $statements);
        self::assertStringContainsString('ADD COLUMN `device`', $statements[0]);
    }

    public function testAddAssignmentDeviceSkipsWhenColumnExists(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn('device'); // Spalte vorhanden
        $connection->expects(self::never())->method('executeStatement');

        (new Migration1747569609AddAssignmentDevice())->update($connection);
    }
}
