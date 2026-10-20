<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;

/**
 * Prüft die Anweisungen der Anonymisierung an einer gemockten Verbindung: gesalzener Hash,
 * geleerte Kundenkennung, Sperre über `anonymized_at` und der richtige Stichtag. Fehlte eines
 * davon, blieben personenbezogene Daten über die Frist hinaus stehen. Ob MySQL die Anweisungen
 * so ausführt, prüft `VisitorDataAnonymizerIntegrationTest`.
 */
final class VisitorDataAnonymizerTest extends TestCase
{
    public function testAnonymizesBothTablesWithCutoffAndMarkerGuard(): void
    {
        $statements = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];

                return 3;
            },
        );

        $anonymizer = new VisitorDataAnonymizer($connection, 'test-salt');
        $threshold = new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC'));
        $affected = $anonymizer->anonymizeOlderThan($threshold);

        self::assertSame(6, $affected);
        self::assertCount(2, $statements);

        $tables = array_map(
            static fn (array $s): bool =>
    str_contains($s['sql'], 'rc_ab_assignment') ||
    str_contains($s['sql'], 'rc_ab_event'),
            $statements,
        );
        self::assertSame([true, true], $tables);

        foreach ($statements as $statement) {
            // Die Ereignis-Abfrage qualifiziert die Spalte (`e.`), die Zuordnungs-Abfrage nicht.
            self::assertMatchesRegularExpression('/SHA2\(CONCAT\(:salt, (e\.)?`visitor_id`\), 256\)/', $statement['sql']);
            self::assertMatchesRegularExpression('/(e\.)?`customer_id` = NULL/', $statement['sql']);
            self::assertMatchesRegularExpression('/(e\.)?`anonymized_at` IS NULL/', $statement['sql']);
            self::assertSame('2026-03-01 00:00:00.000', $statement['params']['cutoff']);
        }

        // Nur die Ereignistabelle hat eine `meta`-Spalte mit Personenbezug; die
        // Zuordnungstabelle hat keine, und eine Anweisung darauf scheitert an MySQL.
        $eventSql = $this->statementFor($statements, 'rc_ab_event');
        $assignmentSql = $this->statementFor($statements, 'rc_ab_assignment');
        self::assertStringContainsString('`meta` = NULL', $eventSql);
        self::assertStringNotContainsString('`meta`', $assignmentSql);
    }

    /**
     * @param list<array{sql: string, params: array<string, mixed>}> $statements
     */
    private function statementFor(array $statements, string $table): string
    {
        // Gesucht wird das Ziel des `UPDATE`, nicht das bloße Vorkommen des Namens. Die
        // Ereignisabfrage verbindet die Zuordnungstabelle mit; ein einfaches `str_contains`
        // lieferte für beide Tabellen dieselbe Abfrage, und der Test prüfte zweimal dasselbe.
        foreach ($statements as $statement) {
            if (str_contains($statement['sql'], sprintf('UPDATE `%s`', $table))) {
                return $statement['sql'];
            }
        }

        self::fail(\sprintf('Kein UPDATE-Statement für %s gefunden.', $table));
    }
}
