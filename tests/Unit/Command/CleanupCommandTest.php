<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Command\CleanupCommand;
use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CleanupCommandTest extends TestCase
{
    public function testAnonymizesOldRowsAndReportsCount(): void
    {
        $connection = $this->createMock(Connection::class);
        // Beide Tabellen je 5 anonymisierte Zeilen.
        $connection->method('executeStatement')->willReturn(5);

        $tester = new CommandTester(new CleanupCommand(new VisitorDataAnonymizer($connection)));
        $tester->execute(['--older-than-days' => '30']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('10', $tester->getDisplay());
        self::assertStringContainsString('30 Tage', $tester->getDisplay());
    }

    public function testRejectsNonPositiveThreshold(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $tester = new CommandTester(new CleanupCommand(new VisitorDataAnonymizer($connection)));
        $exitCode = $tester->execute(['--older-than-days' => '0']);

        self::assertSame(Command::INVALID, $exitCode);
    }
}
