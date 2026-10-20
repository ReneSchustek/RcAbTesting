<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Tests\Unit\Service\Stats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantCollection;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;
use Ruhrcoder\RcAbTesting\Service\Stats\ExperimentEvaluator;
use Ruhrcoder\RcAbTesting\Service\Stats\NormalDistribution;
use Ruhrcoder\RcAbTesting\Service\Stats\SampleSizeCalculator;
use Ruhrcoder\RcAbTesting\Service\Stats\StatisticsCalculator;

/**
 * Prüft, wann ein rechnerisch signifikanter Vorsprung trotzdem kein Gewinner ist.
 *
 * „Signifikant und größer“ allein reicht nicht. Die Zahlen werden bei jedem Öffnen der
 * Auswertungsseite neu gerechnet; bei kleinen Fallzahlen meldet die Seite früher oder später
 * einen Gewinner, der keiner ist, und der Betreiber entscheidet darauf. Die Rückhalteregeln
 * für Mindeststichprobe und Mehrfachvergleich greifen nur, wenn `minSampleSize` gesetzt ist.
 */
#[CoversClass(ExperimentEvaluator::class)]
final class WinnerWithheldTest extends TestCase
{
    /**
     * 10 % gegen 50 % bei je 40 Teilnehmern ist rechnerisch signifikant, liegt aber weit unter
     * der eingestellten Mindestzahl von 1000. Darunter gilt jeder Unterschied als Rauschen.
     */
    public function testNoWinnerBelowTheConfiguredMinimumSample(): void
    {
        $result = $this->evaluator()->evaluate(
            $this->experiment(minSampleSize: 1000),
            [
                'v-control' => ['assignments' => 40, 'conversions' => 4, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
                'v-b' => ['assignments' => 40, 'conversions' => 20, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
            ],
        );

        self::assertNull($result['winnerKey']);
        self::assertContains(ExperimentEvaluator::REASON_SAMPLE_TOO_SMALL, $result['winnerWithheldReasons']);
    }

    /**
     * Die Gegenprobe mit 1000 Teilnehmern je Gruppe bei einer Mindestzahl von 100: Eine Regel,
     * die nie einen Gewinner zulässt, machte die Auswertung nutzlos.
     */
    public function testAWinnerIsNamedWhenTheSampleIsLargeEnough(): void
    {
        $result = $this->evaluator()->evaluate(
            $this->experiment(minSampleSize: 100),
            [
                'v-control' => ['assignments' => 1000, 'conversions' => 100, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
                'v-b' => ['assignments' => 1000, 'conversions' => 200, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
            ],
        );

        self::assertSame('b', $result['winnerKey']);
        self::assertSame([], $result['winnerWithheldReasons']);
    }

    /**
     * Bei zwei Vergleichen zu je 5 % liegt die Wahrscheinlichkeit für mindestens einen
     * Fehlalarm bei knapp 10 %, und ausgewählt wird obendrein der größte Ausschlag. Statt still
     * strenger zu rechnen, benennt die Auswertung den Fall, damit der Betreiber weiß, dass hier
     * mehr nötig wäre.
     */
    public function testMultipleChallengersWithholdTheWinner(): void
    {
        $result = $this->evaluator()->evaluate(
            $this->experiment(minSampleSize: 100, extraVariant: true),
            [
                'v-control' => ['assignments' => 1000, 'conversions' => 100, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
                'v-b' => ['assignments' => 1000, 'conversions' => 200, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
                'v-c' => ['assignments' => 1000, 'conversions' => 120, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
            ],
        );

        self::assertNull($result['winnerKey']);
        self::assertContains(ExperimentEvaluator::REASON_MULTIPLE_COMPARISONS, $result['winnerWithheldReasons']);
    }

    /**
     * Drei Varianten ohne eingestellte Mindestzahl ergeben keinen Gewinner, mit dem Grund „Mehrfachvergleich“. Hinge
     * der Grund an der Mindestzahl, käme ohne sie der größte Ausschlag als Gewinner durch.
     */
    public function testMultipleChallengersWithholdTheWinnerWithoutMinimum(): void
    {
        $result = $this->evaluator()->evaluate(
            $this->experiment(minSampleSize: 0, extraVariant: true),
            [
                'v-control' => ['assignments' => 1000, 'conversions' => 100, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
                'v-b' => ['assignments' => 1000, 'conversions' => 200, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
                'v-c' => ['assignments' => 1000, 'conversions' => 120, 'revenue' => 0.0, 'revenueSumSq' => 0.0],
            ],
        );

        self::assertNull($result['winnerKey']);
        self::assertSame([ExperimentEvaluator::REASON_MULTIPLE_COMPARISONS], $result['winnerWithheldReasons']);
    }

    private function evaluator(): ExperimentEvaluator
    {
        $normal = new NormalDistribution();

        return new ExperimentEvaluator(new StatisticsCalculator($normal), new SampleSizeCalculator($normal));
    }

    private function experiment(int $minSampleSize, bool $extraVariant = false): AbExperimentEntity
    {
        $control = new AbVariantEntity();
        $control->setId('v-control');
        $control->setTechnicalKey('control');
        $control->setIsControl(true);
        $control->setWeight(50);

        $b = new AbVariantEntity();
        $b->setId('v-b');
        $b->setTechnicalKey('b');
        $b->setIsControl(false);
        $b->setWeight(50);

        $variants = [$control, $b];

        // Drei gleiche Gewichte ergeben gleiche Sollanteile; die Summe über 100 stört die
        // Auswertung nicht, sie rechnet mit Anteilen.
        if ($extraVariant) {
            $c = new AbVariantEntity();
            $c->setId('v-c');
            $c->setTechnicalKey('c');
            $c->setIsControl(false);
            $c->setWeight(50);
            $variants[] = $c;
        }

        $experiment = new AbExperimentEntity();
        $experiment->setId('exp-1');
        $experiment->setTechnicalKey('exp');
        $experiment->setTargetSignificance(0.95);
        // Ohne Angabe gilt der Umsatz je Besucher; diese Fälle prüfen die Conversion-Rate.
        $experiment->setDecisionMetric('conversion_rate');
        $experiment->setMinSampleSize($minSampleSize);
        $experiment->setVariants(new AbVariantCollection($variants));

        return $experiment;
    }
}
