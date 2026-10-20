<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Service\AbEventType;
use Ruhrcoder\RcAbTesting\Service\CartAbandonmentDetector;

/**
 * Prüft, welche Parameter der Abbruch-Detektor an seine Abfrage bindet. Die Semantik des
 * SQL, vor allem die Zeitgrenze der Ausschlüsse, prüft erst
 * `CartAbandonmentDetectorIntegrationTest` gegen MySQL.
 */
final class CartAbandonmentDetectorTest extends TestCase
{
    public function testDetectPassesEventTypesAndThresholdAndRunningFilter(): void
    {
        $capturedParams = null;
        $capturedSql = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params) use (&$capturedParams, &$capturedSql): array {
                $capturedParams = $params;
                $capturedSql = $sql;

                return [['experimentId' => 'e1', 'variantId' => 'v1', 'visitorId' => 'vis1']];
            },
        );

        $detector = new CartAbandonmentDetector($connection);
        $rows = $detector->detect(new \DateTimeImmutable('2026-06-27 10:00:00', new \DateTimeZone('UTC')));

        self::assertSame([['experimentId' => 'e1', 'variantId' => 'v1', 'visitorId' => 'vis1']], $rows);
        self::assertIsArray($capturedParams);
        self::assertSame(AbEventType::CHECKOUT_STARTED, $capturedParams['started']);
        self::assertSame(AbEventType::CHECKOUT_ORDER_PLACED, $capturedParams['ordered']);
        self::assertSame(AbEventType::CART_ABANDONED, $capturedParams['abandoned']);
        self::assertSame(AbExperimentStatus::RUNNING, $capturedParams['running']);
        self::assertSame('2026-06-27 10:00:00', $capturedParams['threshold']);
        // Der Tabellenname belegt nur, dass die Experimenttabelle in der Abfrage vorkommt; ob
        // sie auf laufende Experimente filtert, zeigt der Parameter `running` oben.
        self::assertIsString($capturedSql);
        self::assertStringContainsString('rc_ab_experiment', $capturedSql);
    }
}
