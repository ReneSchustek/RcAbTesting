<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbDecisionMetric;
use Ruhrcoder\RcAbTesting\Core\Content\AbExperiment\AbExperimentEntity;
use Ruhrcoder\RcAbTesting\Core\Content\AbVariant\AbVariantEntity;

/**
 * Verdichtet die reinen Zähldaten (Zuordnungen/Conversions je Variante) zu einer
 * entscheidbaren Auswertung: je Nicht-Control-Variante Lift, p-Value, Konfidenz-
 * intervall und Signifikanz gegen die Control (beim konfigurierten Niveau), eine
 * Gewinner-Empfehlung sowie ein Sample-Ratio-Mismatch-Check als Validitäts-
 * Warnung. Rein rechnend (keine I/O), damit ohne DAL testbar.
 */
final class ExperimentEvaluator
{
    private const SRM_MIN_TOTAL = 100;
    private const SRM_Z_THRESHOLD = 3.29; // zweiseitig p ~ 0,001

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
     *     comparisons: list<array{id: string, variantKey: string, lift: float|null, pValue: float|null, ciLower: float|null, ciUpper: float|null, significant: bool, requiredSamplePerVariant: int}>
     * }
     */
    public function evaluate(AbExperimentEntity $experiment, array $stats): array
    {
        $variants = $experiment->getVariantList();
        $confidenceLevel = $experiment->getTargetSignificance();
        // Ohne explizite Entscheidungs-Kennzahl (z. B. Alt-Experimente, reine
        // Unit-Test-Entities) auf die Conversion-Rate zurückfallen — die braucht
        // nur Zuordnungen/Conversions und ist damit immer berechenbar.
        $decisionMetric = $experiment->getDecisionMetric() ?? AbDecisionMetric::CONVERSION_RATE;
        $control = $experiment->getControlVariant();

        $comparisons = [];
        $winnerKey = null;

        if ($control !== null) {
            $controlStats = $this->statsFor($stats, $control);
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
        }

        return [
            'controlKey' => $control?->getTechnicalKey(),
            'decisionMetric' => $decisionMetric,
            'confidenceLevel' => $confidenceLevel,
            'minSampleSize' => $experiment->getMinSampleSize() ?? 0,
            'sampleRatioMismatch' => $this->detectSampleRatioMismatch($variants, $stats),
            'winnerKey' => $winnerKey,
            'comparisons' => $comparisons,
        ];
    }

    /**
     * Führt den zur Entscheidungs-Kennzahl passenden Signifikanztest: Proportionen-
     * z-Test für die Conversion-Rate, Mittelwert-Vergleich für den Umsatz je
     * Besucher. Liefert die für die Auswertungstabelle einheitlichen Felder.
     *
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $controlStats
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $variantStats
     *
     * @return array{lift: float|null, pValue: float|null, ciLower: float|null, ciUpper: float|null, significant: bool, requiredSamplePerVariant: int}
     */
    private function compare(array $controlStats, array $variantStats, float $confidenceLevel, string $decisionMetric): array
    {
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
     * Der je Entscheidungs-Kennzahl verglichene Wert einer Variante — Conversion-
     * Rate oder Umsatz je Besucher. Basis für die Gewinner-Bestimmung.
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
     * Benötigte Fallzahl je Variante für die Conversion-Rate, um den beobachteten
     * Lift beim konfigurierten Niveau mit 80 % Power zu bestätigen. 0 ohne
     * positiven Lift.
     *
     * @param array{assignments: int, conversions: int, revenue?: float, revenueSumSq?: float} $controlStats
     */
    private function requiredRateSample(array $controlStats, ?float $lift, float $confidenceLevel): int
    {
        if ($lift === null || $lift <= 0.0 || $controlStats['assignments'] <= 0) {
            return 0;
        }

        $baselineRate = $controlStats['conversions'] / $controlStats['assignments'];

        return $this->sampleSize->requiredSize($baselineRate, $lift, 0.8, 1.0 - $confidenceLevel);
    }

    /**
     * Benötigte Fallzahl je Variante für den Umsatz je Besucher, um die beobachtete
     * Mittelwert-Differenz zu bestätigen. Nutzt Mittelwert und Varianz aus den
     * aggregierten Summen (eine Ableitung im StatisticsCalculator).
     *
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $controlStats
     * @param array{assignments: int, conversions: int, revenue: float, revenueSumSq: float} $variantStats
     */
    private function requiredMeanSample(array $controlStats, array $variantStats, float $confidenceLevel): int
    {
        $control = $this->statistics->meanAndVariance($controlStats['revenue'], $controlStats['revenueSumSq'], $controlStats['assignments']);
        $variant = $this->statistics->meanAndVariance($variantStats['revenue'], $variantStats['revenueSumSq'], $variantStats['assignments']);

        return $this->sampleSize->requiredSizeForMeanDifference(
            $control['mean'],
            $variant['mean'],
            $control['variance'],
            $variant['variance'],
            0.8,
            1.0 - $confidenceLevel,
        );
    }

    /**
     * Sample-Ratio-Mismatch: weicht die beobachtete Verteilung signifikant von den
     * konfigurierten Gewichten ab, deutet das auf einen Tracking-/Bucketing-Fehler
     * hin. null, solange zu wenig Daten für eine Aussage vorliegen.
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
            $expectedShare = \max(0, $variant->getWeight()) / $weightSum;
            if ($expectedShare <= 0.0 || $expectedShare >= 1.0) {
                continue;
            }

            $observedShare = $this->statsFor($stats, $variant)['assignments'] / $total;
            $standardError = \sqrt($expectedShare * (1.0 - $expectedShare) / $total);
            if ($standardError > 0.0 && \abs($observedShare - $expectedShare) / $standardError > self::SRM_Z_THRESHOLD) {
                return true;
            }
        }

        return false;
    }


    /**
     * Normalisiert die (evtl. lückenhaften) Zähldaten einer Variante auf die volle
     * Form — fehlende Umsatz-Felder werden zu 0, damit die Aufrufer ohne Null-Prüfung
     * rechnen können.
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
