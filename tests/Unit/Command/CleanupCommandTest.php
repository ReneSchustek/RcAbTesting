<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Command\CleanupCommand;
use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Prüft das Konsolenkommando zur Anonymisierung. Eine Frist von null Tagen würde jede
 * Zeile bis zum aktuellen Augenblick erfassen und laufende Experimente mitten im Test
 * anonymisieren; deshalb lehnt das Kommando sie ab, bevor eine Abfrage läuft.
 */
final class CleanupCommandTest extends TestCase
{
    public function testAnonymizesOldRowsAndReportsCount(): void
    {
        $connection = $this->createMock(Connection::class);
        // Ereignisse und Zuordnungen melden je 5 Zeilen; die Ausgabe muss die Summe 10 nennen.
        $connection->method('executeStatement')->willReturn(5);

        $tester = new CommandTester(new CleanupCommand(new VisitorDataAnonymizer($connection, 'test-salt')));
        $tester->execute(['--older-than-days' => '30']);

        // Die ganze Aussage, nicht nur die Zahl: Eine „10“ stünde auch in jedem Datum der Ausgabe,
        // und der Test bestünde zufällig. Die Erfolgsbox bricht lange Zeilen um, daher ohne Zeilenumbrüche.
        $tester->assertCommandIsSuccessful();
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('10 Zeile(n) älter als 30 Tage anonymisiert', $display);
    }

    public function testRejectsNonPositiveThreshold(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $tester = new CommandTester(new CleanupCommand(new VisitorDataAnonymizer($connection, 'test-salt')));
        $exitCode = $tester->execute(['--older-than-days' => '0']);

        self::assertSame(Command::INVALID, $exitCode);
    }
}
