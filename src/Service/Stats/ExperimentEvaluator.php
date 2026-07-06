<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

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
     * @param array<string, array{assignments: int, conversions: int}> $stats
     *
     * @return array{
     *     controlKey: string|null,
     *     confidenceLevel: float,
     *     minSampleSize: int,
     *     sampleRatioMismatch: bool|null,
     *     winnerKey: string|null,
     *     comparisons: list<array{id: string, variantKey: string, lift: float|null, pValue: float|null, ciLower: float|null, ciUpper: float|null, significant: bool, requiredSamplePerVariant: int}>
     * }
     */
    public function evaluate(AbExperimentEntity $experiment, array $stats): array
    {
        $variants = $this->variants($experiment);
        $confidenceLevel = $experiment->getTargetSignificance();
        $control = $this->findControl($variants);

        $comparisons = [];
        $winnerKey = null;
        $winnerRate = $control !== null ? $this->rate($stats, $control) : 0.0;

        if ($control !== null) {
            $controlStats = $this->statsFor($stats, $control);

            foreach ($variants as $variant) {
                if ($variant->getId() === $control->getId()) {
                    continue;
                }

                $variantStats = $this->statsFor($stats, $variant);
                $result = $this->statistics->calculate(
                    $controlStats['assignments'],
                    $controlStats['conversions'],
                    $variantStats['assignments'],
                    $variantStats['conversions'],
                    $confidenceLevel,
                );

                $comparisons[] = [
                    'id' => $variant->getId(),
                    'variantKey' => $variant->getTechnicalKey(),
                    'lift' => $result['lift'],
                    'pValue' => $result['p_value'],
                    'ciLower' => $result['b']['ci_lower'] ?? null,
                    'ciUpper' => $result['b']['ci_upper'] ?? null,
                    'significant' => $result['significant'],
                    'requiredSamplePerVariant' => $this->requiredSample($controlStats, $result['lift'], $confidenceLevel),
                ];

                if ($result['significant'] && $this->rate($stats, $variant) > $winnerRate) {
                    $winnerRate = $this->rate($stats, $variant);
                    $winnerKey = $variant->getTechnicalKey();
                }
            }
        }

        return [
            'controlKey' => $control?->getTechnicalKey(),
            'confidenceLevel' => $confidenceLevel,
            'minSampleSize' => $experiment->getMinSampleSize() ?? 0,
            'sampleRatioMismatch' => $this->detectSampleRatioMismatch($variants, $stats),
            'winnerKey' => $winnerKey,
            'comparisons' => $comparisons,
        ];
    }

    /**
     * Benötigte Fallzahl je Variante, um den aktuell beobachteten Lift beim
     * konfigurierten Niveau mit 80 % Power zu bestätigen. 0, wenn (noch) kein
     * positiver Lift vorliegt.
     *
     * @param array{assignments: int, conversions: int} $controlStats
     */
    private function requiredSample(array $controlStats, ?float $lift, float $confidenceLevel): int
    {
        if ($lift === null || $lift <= 0.0 || $controlStats['assignments'] <= 0) {
            return 0;
        }

        $baselineRate = $controlStats['conversions'] / $controlStats['assignments'];

        return $this->sampleSize->requiredSize($baselineRate, $lift, 0.8, 1.0 - $confidenceLevel);
    }

    /**
     * Sample-Ratio-Mismatch: weicht die beobachtete Verteilung signifikant von den
     * konfigurierten Gewichten ab, deutet das auf einen Tracking-/Bucketing-Fehler
     * hin. null, solange zu wenig Daten für eine Aussage vorliegen.
     *
     * @param list<AbVariantEntity>                                     $variants
     * @param array<string, array{assignments: int, conversions: int}>  $stats
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
     * @param list<AbVariantEntity> $variants
     */
    private function findControl(array $variants): ?AbVariantEntity
    {
        foreach ($variants as $variant) {
            if ($variant->isControl()) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{assignments: int, conversions: int}> $stats
     */
    private function rate(array $stats, AbVariantEntity $variant): float
    {
        $variantStats = $this->statsFor($stats, $variant);

        return $variantStats['assignments'] > 0 ? $variantStats['conversions'] / $variantStats['assignments'] : 0.0;
    }

    /**
     * @param array<string, array{assignments: int, conversions: int}> $stats
     *
     * @return array{assignments: int, conversions: int}
     */
    private function statsFor(array $stats, AbVariantEntity $variant): array
    {
        return $stats[$variant->getId()] ?? ['assignments' => 0, 'conversions' => 0];
    }

    /**
     * @return list<AbVariantEntity>
     */
    private function variants(AbExperimentEntity $experiment): array
    {
        $variants = $experiment->getVariants();

        return $variants === null ? [] : array_values($variants->getElements());
    }
}
