<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Integration\Service;

use Ruhrcoder\RcAbTesting\Benchmarks\Support\ExperimentSeeder;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentStatus;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\CartAbandonmentDetector;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentFunnelAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentSegmentAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentStatsAggregator;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentTimeSeriesAggregator;
use Ruhrcoder\RcAbTesting\Service\VisitorDataAnonymizer;
use Ruhrcoder\RcAbTesting\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Grobe Geschwindigkeitswächter im gewöhnlichen Testlauf. Sie messen nicht, sie halten fest, was
 * nicht schlechter werden darf: Auswertung, Abbruch-Erkennung und Anonymisierung über einen
 * Bestand von 20.000 Besuchern im laufenden Experiment und 80.000 in vier Altexperimenten, derselbe
 * Aufbau wie im Messprojekt unter `benchmarks/`.
 *
 * Die Grenze liegt mit 3 Sekunden je Schritt beim Zehnfachen des langsamsten Werts, den das
 * Messprojekt für 100.000 Besucher abgelegt hat (Trichter, rund 0,3 Sekunden). Sie schlägt nicht bei
 * Schwankungen an, wohl aber, wenn eine Abfrage ihren Index verliert oder in eine Schleife rutscht.
 * Die genauen Zahlen und ihre Reihe stehen in `benchmarks/results.csv`.
 */
final class PerformanceGuardIntegrationTest extends IntegrationTestCase
{
    private const VISITORS = 20_000;

    /** Auswertungen sieht die Verwaltung beim Öffnen eines Experiments; mehr als drei Sekunden fallen dort auf. */
    private const EVALUATION_LIMIT_SECONDS = 3.0;

    /**
     * Abbruch-Erkennung und Anonymisierung laufen als geplante Aufgaben im Hintergrund. Gemessen liegen sie bei bis zu
     * sieben Sekunden; die Grenze fängt eine Verschlechterung wie die der Segmentauswertung (Minuten statt Sekunden).
     */
    private const MAINTENANCE_LIMIT_SECONDS = 30.0;

    public function testEvaluationAndMaintenanceStayWithinTheirLimits(): void
    {
        $experiment = $this->experiment((new ExperimentSeeder($this->connection))->seed(self::VISITORS));

        $this->assertWithinLimit(
            'Kennzahlen',
            self::EVALUATION_LIMIT_SECONDS,
            fn (): array => (new ExperimentStatsAggregator(
                $this->connection
            ))->aggregate($experiment)
        );
        $this->assertWithinLimit('Segmente', self::EVALUATION_LIMIT_SECONDS, fn (): array => (new ExperimentSegmentAggregator($this->connection))
            ->aggregate($experiment, ExperimentSegmentAggregator::DEVICE));
        $this->assertWithinLimit(
            'Zeitverlauf',
            self::EVALUATION_LIMIT_SECONDS,
            fn (): array => (new ExperimentTimeSeriesAggregator(
                $this->connection
            ))->aggregate($experiment)
        );
        $this->assertWithinLimit(
            'Trichter',
            self::EVALUATION_LIMIT_SECONDS,
            fn (): array => (new ExperimentFunnelAggregator(
                $this->connection
            ))->aggregate($experiment)
        );
        $this->assertWithinLimit('Abbruch-Erkennung', self::MAINTENANCE_LIMIT_SECONDS, fn (): array => (new CartAbandonmentDetector($this->connection))
            ->detect(new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC'))));
        $this->assertWithinLimit('Anonymisierung', self::MAINTENANCE_LIMIT_SECONDS, fn (): array => [
            (new VisitorDataAnonymizer($this->connection, 'test-salt'))
                ->anonymizeOlderThan(new \DateTimeImmutable('-90 days', new \DateTimeZone('UTC'))),
        ]);
    }

    /**
     * Ein leeres Ergebnis käme schnell zurück und bestünde jede Grenze; es zählt deshalb als Fehler.
     *
     * @param \Closure(): array<mixed> $step
     */
    private function assertWithinLimit(string $name, float $limitSeconds, \Closure $step): void
    {
        $start = hrtime(true);
        $result = $step();
        $seconds = (hrtime(true) - $start) / 1e9;

        self::assertNotSame([], $result, $name . ' lief über keine Zeile.');
        self::assertNotSame([0], $result, $name . ' traf keine Zeile.');
        self::assertLessThan($limitSeconds, $seconds, \sprintf('%s brauchte %.2f s.', $name, $seconds));
    }

    private function experiment(string $experimentId): AbExperimentEntity
    {
        $variants = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, technical_key, is_control FROM rc_ab_variant WHERE experiment_id = :id',
            ['id' => Uuid::fromHexToBytes($experimentId)],
        );
        foreach ($rows as $row) {
            $variant = new AbVariantEntity();
            $variant->setId((string) $row['id']);
            $variant->setTechnicalKey((string) $row['technical_key']);
            $variant->setName((string) $row['technical_key']);
            $variant->setWeight(50);
            $variant->setIsControl((int) $row['is_control'] === 1);
            $variants[] = $variant;
        }

        $experiment = new AbExperimentEntity();
        $experiment->setId($experimentId);
        $experiment->setTechnicalKey('performance-guard');
        $experiment->setStatus(AbExperimentStatus::RUNNING);
        $experiment->setVariants(new AbVariantCollection($variants));

        return $experiment;
    }
}
