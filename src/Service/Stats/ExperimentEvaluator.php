<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbDecisionMetric;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;

/**
 * Macht aus den Zähldaten je Variante eine Auswertung, auf die sich eine Entscheidung stützen
 * kann: je Herausforderer Lift, p-Wert, Konfidenzintervall und Signifikanz gegen die Control,
 * einen Gewinnervorschlag samt Gründen, ihn zurückzuhalten, und die Prüfung auf ungleiche
 * Gruppengrößen (Sample Ratio Mismatch). Die Klasse rechnet nur und liest nichts aus der
 * Datenbank; Gesamt- und Segmentauswertung rufen sie mit ihren jeweiligen Zahlen auf.
 */
final class ExperimentEvaluator
{
    /** Teststärke der Fallzahlen: 80 %, die übliche Wahl, für Rate wie für Umsatz. */
    private const POWER = 0.8;

    public const REASON_SAMPLE_TOO_SMALL = 'sample_too_small';
    public const REASON_MULTIPLE_COMPARISONS = 'multiple_comparisons';
    public const REASON_SAMPLE_RATIO_MISMATCH = 'sample_ratio_mismatch';

    /** Unter 100 Zuordnungen schwanken die Anteile so stark, dass die Prüfung nichts aussagt. */
    private const SRM_MIN_TOTAL = 100;

    /**
     * Entspricht zweiseitig p ≈ 0,001. Die Schwelle ist so streng, weil die Auswertung bei jedem
     * Aufruf neu rechnet; mit 0,05 schlüge die Prüfung bei häufigem Nachsehen auch ohne Fehler an.
     */
    private const SRM_Z_THRESHOLD = 3.29;

    public function __construct(
        private readonly StatisticsCalculator $statistics,
        private readonly SampleSizeCalculator $sampleSize,
    ) {
    }

    /**
     * @param array<string, array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float}> $stats
     *
     * @return array{
     *     controlKey: string|null,
     *     decisionMetric: string,
     *     confidenceLevel: float,
     *     minSampleSize: int,
     *     sampleRatioMismatch: bool|null,
     *     winnerKey: string|null,
     *     winnerWithheldReasons: list<string>,
     *     comparisons: list<array{id: string, variantKey: string, lift: float|null, pValue: float|null, ciLower: float|null, ciUpper: float|null, significant: bool, requiredSamplePerVariant: int}>
     * }
     */
    public function evaluate(AbExperimentEntity $experiment, array $stats): array
    {
        $variants = $experiment->getVariantList();
        $confidenceLevel = $experiment->getTargetSignificance();
        // Ohne gesetzte Entscheidungskennzahl gilt dieselbe Vorgabe wie für neue Experimente und
        // für die Spalte. Ein Rückfall auf die Conversion-Rate urteilte über eine andere Größe als
        // die angezeigte Vorgabe.
        $decisionMetric = $experiment->getDecisionMetric() ?? AbDecisionMetric::DEFAULT;
        $control = $experiment->getControlVariant();

        $comparisons = [];
        $winnerKey = null;
        $minSampleSize = $experiment->getMinSampleSize() ?? 0;
        $sampleRatioMismatch = $this->detectSampleRatioMismatch($variants, $stats);
        $withheldReasons = [];

        if ($control !== null) {
            $controlStats = $this->statsFor($stats, $control);
            [$comparisons, $winnerKey] = $this->compareWithControl(
                $variants,
                $control,
                $controlStats,
                $stats,
                $confidenceLevel,
                $decisionMetric
            );

            $withheldReasons = $this->reasonsToWithholdWinner(
                $controlStats,
                $stats,
                $variants,
                $control,
                $minSampleSize,
                $sampleRatioMismatch,
            );
        }

        // Bei einem zurückgehaltenen Gewinner stehen die Gründe daneben, damit die
        // Auswertungsseite zeigt, was noch fehlt, statt nur „kein Gewinner“.
        if ($withheldReasons !== []) {
            $winnerKey = null;
        }

        return [
            'controlKey' => $control?->getTechnicalKey(),
            'decisionMetric' => $decisionMetric,
            'confidenceLevel' => $confidenceLevel,
            'minSampleSize' => $minSampleSize,
            'sampleRatioMismatch' => $sampleRatioMismatch,
            'winnerKey' => $winnerKey,
            'winnerWithheldReasons' => $withheldReasons,
            'comparisons' => $comparisons,
        ];
    }

    /**
     * Vergleicht jede Variante mit der Kontrolle. Gewinner wird nur, wer signifikant und besser ist als die Kontrolle
     * und alle bisher geprüften Herausforderer; ein signifikant schlechterer bleibt außen vor.
     *
     * @param list<AbVariantEntity>                                                                         $variants
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}                  $controlStats
     * @param array<string, array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float}> $stats
     *
     * @return array{0: list<array{id: string, variantKey: string, lift: float|null, pValue: float|null, ciLower: float|null, ciUpper: float|null, significant: bool, requiredSamplePerVariant: int}>, 1: string|null}
     */
    private function compareWithControl(
        array $variants,
        AbVariantEntity $control,
        array $controlStats,
        array $stats,
        float $confidenceLevel,
        string $decisionMetric,
    ): array {
        $comparisons = [];
        $winnerKey = null;
        $winnerValue = $this->metricValue($controlStats, $decisionMetric);

        foreach ($variants as $variant) {
            if ($variant->getId() === $control->getId()) {
                continue;
            }

            $variantStats = $this->statsFor($stats, $variant);
            $comparison = $this->compare($controlStats, $variantStats, $confidenceLevel, $decisionMetric);
            $comparisons[] = ['id' => $variant->getId(), 'variantKey' => $variant->getTechnicalKey()] + $comparison;

            $variantValue = $this->metricValue($variantStats, $decisionMetric);
            if ($comparison['significant'] && $variantValue > $winnerValue) {
                $winnerValue = $variantValue;
                $winnerKey = $variant->getTechnicalKey();
            }
        }

        return [$comparisons, $winnerKey];
    }

    /**
     * Warum ein rechnerisch signifikanter Vorsprung noch kein Gewinner ist.
     *
     * „Signifikant und größer“ allein reicht nicht. Die Zahlen werden bei jedem Aufruf neu
     * gerechnet; wer die Seite täglich öffnet, bekommt bei kleinen Fallzahlen früher oder später
     * einen Gewinner gemeldet, der keiner ist. Drei Gründe halten ihn zurück:
     *
     * 1. Zu wenige Teilnehmer. Unter der eingestellten Mindestzahl je Variante ist jeder
     *    Unterschied Rauschen.
     * 2. Ungleiche Gruppengrößen. Weichen sie stärker ab, als die Gewichte erwarten lassen,
     *    stimmt etwas an der Zuteilung nicht, und das Ergebnis trägt nicht, wie klein der p-Wert
     *    auch ist.
     * 3. Mehr als ein Herausforderer. Bei zwei Vergleichen zu je 5 % liegt die Wahrscheinlichkeit
     *    für mindestens einen Fehlalarm bei knapp 10 %, und ausgewählt wird obendrein der größte
     *    Ausschlag. Statt Alpha still zu korrigieren, wird der Fall benannt, damit der Betreiber
     *    weiß, dass hier strenger gerechnet werden müsste.
     *
     * Grund 1 wird nur geprüft, wenn eine Mindestzahl eingestellt ist (`$minSampleSize` größer 0).
     * Grund 3 gilt immer. Hinge auch er an der Mindestzahl, meldete ein Experiment mit drei
     * Varianten ohne eingestellte Mindestzahl den größten Ausschlag als Gewinner.
     *
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $controlStats
     * @param array<string, array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float}> $stats
     * @param iterable<AbVariantEntity> $variants
     *
     * @return list<string>
     */
    private function reasonsToWithholdWinner(
        array $controlStats,
        array $stats,
        iterable $variants,
        AbVariantEntity $control,
        int $minSampleSize,
        ?bool $sampleRatioMismatch,
    ): array {
        $reasons = [];

        $smallest = $controlStats['assignments'];
        $challengers = 0;

        foreach ($variants as $variant) {
            if ($variant->getId() === $control->getId()) {
                continue;
            }

            ++$challengers;
            $smallest = min($smallest, $this->statsFor($stats, $variant)['assignments']);
        }

        if ($minSampleSize > 0 && $smallest < $minSampleSize) {
            $reasons[] = self::REASON_SAMPLE_TOO_SMALL;
        }

        if ($challengers > 1) {
            $reasons[] = self::REASON_MULTIPLE_COMPARISONS;
        }

        // Null heißt „nicht ermittelbar“, etwa unter 100 Zuordnungen oder ohne Gewichte. Das hält
        // den Gewinner nicht zurück, ein festgestelltes Ungleichgewicht schon.
        if ($sampleRatioMismatch === true) {
            $reasons[] = self::REASON_SAMPLE_RATIO_MISMATCH;
        }

        return $reasons;
    }

    /**
     * Der zur Entscheidungskennzahl passende Test: z-Test zweier Anteile für die Conversion-Rate,
     * Mittelwertvergleich für den Umsatz je Besucher. Beide Wege liefern dieselben Felder für die
     * Auswertungstabelle; Intervall und Lift beziehen sich auf die Variante.
     *
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $controlStats
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $variantStats
     *
     * @return array{lift: float|null, pValue: float|null, ciLower: float|null, ciUpper: float|null, significant: bool, requiredSamplePerVariant: int}
     */
    private function compare(
        array $controlStats,
        array $variantStats,
        float $confidenceLevel,
        string $decisionMetric,
    ): array {
        if (AbDecisionMetric::isMeanBased($decisionMetric)) {
            $result = $this->statistics->compareMeans(
                $controlStats['assignments'],
                $controlStats['revenue'],
                $controlStats['revenueSumSq'],
                $variantStats['assignments'],
                $variantStats['revenue'],
                $variantStats['revenueSumSq'],
                $confidenceLevel,
            );
            $requiredSample = $this->requiredMeanSample($controlStats, $variantStats, $confidenceLevel);
        } else {
            $result = $this->statistics->calculate(
                $controlStats['assignments'],
                $controlStats['conversions'],
                $variantStats['assignments'],
                $variantStats['conversions'],
                $confidenceLevel,
            );
            $requiredSample = $this->requiredRateSample($controlStats, $result['lift'], $confidenceLevel);
        }

        return [
            'lift' => $result['lift'],
            'pValue' => $result['p_value'],
            'ciLower' => $result['b']['ci_lower'] ?? null,
            'ciUpper' => $result['b']['ci_upper'] ?? null,
            'significant' => $result['significant'],
            'requiredSamplePerVariant' => $requiredSample,
        ];
    }

    /**
     * Conversion-Rate oder Umsatz je Teilnehmer, je nach Entscheidungskennzahl; Maßstab für den
     * Gewinner. 0 ohne Teilnehmer.
     *
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $variantStats
     */
    private function metricValue(array $variantStats, string $decisionMetric): float
    {
        if ($variantStats['assignments'] <= 0) {
            return 0.0;
        }

        if (AbDecisionMetric::isMeanBased($decisionMetric)) {
            return $variantStats['revenue'] / $variantStats['assignments'];
        }

        return $variantStats['conversions'] / $variantStats['assignments'];
    }

    /**
     * Fallzahl je Variante, um den beobachteten Lift der Conversion-Rate beim eingestellten
     * Niveau zu bestätigen. 80 % Power ist die übliche Vorgabe für A/B-Tests; jeder fünfte echte
     * Effekt dieser Größe bliebe damit unentdeckt. 0 ohne positiven Lift.
     *
     * @param array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float} $controlStats
     */
    private function requiredRateSample(array $controlStats, ?float $lift, float $confidenceLevel): int
    {
        if ($lift === null || $lift <= 0.0 || $controlStats['assignments'] <= 0) {
            return 0;
        }

        $baselineRate = $controlStats['conversions'] / $controlStats['assignments'];

        return $this->sampleSize->requiredSize($baselineRate, $lift, self::POWER, 1.0 - $confidenceLevel);
    }

    /**
     * Fallzahl je Variante, um die beobachtete Differenz im Umsatz je Teilnehmer zu bestätigen,
     * mit derselben Power von 80 % wie bei der Rate. Mittelwert und Varianz kommen aus
     * {@see StatisticsCalculator::meanAndVariance()}, damit die Ableitung nur an einer Stelle steht.
     *
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $controlStats
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $variantStats
     */
    private function requiredMeanSample(array $controlStats, array $variantStats, float $confidenceLevel): int
    {
        $control = $this->statistics->meanAndVariance(
            $controlStats['revenue'],
            $controlStats['revenueSumSq'],
            $controlStats['assignments']
        );
        $variant = $this->statistics->meanAndVariance(
            $variantStats['revenue'],
            $variantStats['revenueSumSq'],
            $variantStats['assignments']
        );

        return $this->sampleSize->requiredSizeForMeanDifference(
            $control['mean'],
            $variant['mean'],
            $control['variance'],
            $variant['variance'],
            self::POWER,
            1.0 - $confidenceLevel,
        );
    }

    /**
     * Sample Ratio Mismatch: Weicht die Verteilung der Zuordnungen deutlich von den Gewichten ab,
     * stimmt etwas an Zuteilung oder Zählung nicht. Geprüft wird jede Variante einzeln mit einem
     * z-Test ihres Anteils; eine Abweichung genügt für true. Null unter `SRM_MIN_TOTAL`
     * Zuordnungen oder ohne positive Gewichte.
     *
     * @param list<AbVariantEntity>                                                                          $variants
     * @param array<string, array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float}> $stats
     */
    private function detectSampleRatioMismatch(array $variants, array $stats): ?bool
    {
        $total = 0;
        $weightSum = 0;
        foreach ($variants as $variant) {
            $total += $this->statsFor($stats, $variant)['assignments'];
            $weightSum += \max(0, $variant->getWeight());
        }

        if ($total < self::SRM_MIN_TOTAL || $weightSum <= 0) {
            return null;
        }

        foreach ($variants as $variant) {
            // Bei einem erwarteten Anteil von 0 oder 1 ist der Standardfehler null, der Test
            // also nicht definiert.
            $expectedShare = \max(0, $variant->getWeight()) / $weightSum;
            if ($expectedShare <= 0.0 || $expectedShare >= 1.0) {
                continue;
            }

            $observedShare = $this->statsFor($stats, $variant)['assignments'] / $total;
            $standardError = \sqrt($expectedShare * (1.0 - $expectedShare) / $total);
            if ($standardError > 0.0 &&
                \abs($observedShare - $expectedShare) / $standardError > self::SRM_Z_THRESHOLD) {
                return true;
            }
        }

        return false;
    }


    /**
     * Die Zähldaten einer Variante in voller Form. Fehlt die Variante oder ein Umsatzfeld, steht
     * dort 0, damit die Aufrufer ohne Prüfung auf fehlende Schlüssel rechnen können.
     *
     * @param array<string, array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float}> $stats
     *
     * @return array{assignments: int, conversions: int, revenue: float, revenueSumSq: float}
     */
    private function statsFor(array $stats, AbVariantEntity $variant): array
    {
        $variantStats = $stats[$variant->getId()] ?? [];

        return [
            'assignments' => $variantStats['assignments'] ?? 0,
            'conversions' => $variantStats['conversions'] ?? 0,
            'revenue' => $variantStats['revenue'] ?? 0.0,
            'revenueSumSq' => $variantStats['revenueSumSq'] ?? 0.0,
        ];
    }

}
