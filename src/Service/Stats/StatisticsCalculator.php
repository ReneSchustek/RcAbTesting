<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

/**
 * Wertet einen A/B-Vergleich zweier Varianten aus: Konversionsraten mit Wald-
 * Konfidenzintervall, relativer Lift, z-Test für zwei Proportionen (gepoolte
 * Varianz) und zweiseitiger p-Value. Das Konfidenzniveau ist konfigurierbar
 * (Experiment-`target_significance`, Default 0,95); der kritische z-Wert und
 * Alpha leiten sich daraus ab. Degenerierte Eingaben (leere Stichprobe, keine
 * Streuung, Basisrate 0) liefern null statt einer Division durch null.
 */
final class StatisticsCalculator
{
    private const DEFAULT_CONFIDENCE = 0.95;

    public function __construct(
        private readonly NormalDistribution $normal,
    ) {
    }

    /**
     * @param float $confidenceLevel gewünschtes Konfidenzniveau in (0,1), z. B. 0,95 oder 0,99
     *
     * @return array{
     *     a: array{rate: float, ci_lower: float, ci_upper: float}|null,
     *     b: array{rate: float, ci_lower: float, ci_upper: float}|null,
     *     lift: float|null,
     *     z_score: float|null,
     *     p_value: float|null,
     *     alpha: float,
     *     significant: bool
     * }
     */
    public function calculate(int $aSamples, int $aConversions, int $bSamples, int $bConversions, float $confidenceLevel = self::DEFAULT_CONFIDENCE): array
    {
        // Unbrauchbare Niveaus (außerhalb (0,1)) auf den Default zurückfallen lassen,
        // statt ppf gegen ±INF laufen zu lassen.
        if ($confidenceLevel <= 0.0 || $confidenceLevel >= 1.0) {
            $confidenceLevel = self::DEFAULT_CONFIDENCE;
        }
        $alpha = 1.0 - $confidenceLevel;

        if ($aSamples <= 0 || $bSamples <= 0) {
            return ['a' => null, 'b' => null, 'lift' => null, 'z_score' => null, 'p_value' => null, 'alpha' => $alpha, 'significant' => false];
        }

        $zCritical = $this->normal->ppf(1.0 - $alpha / 2.0);
        $rateA = $aConversions / $aSamples;
        $rateB = $bConversions / $bSamples;

        [$zScore, $pValue] = $this->zTest($aSamples, $aConversions, $bSamples, $bConversions, $rateA, $rateB);

        return [
            'a' => $this->confidenceBlock($rateA, $aSamples, $zCritical),
            'b' => $this->confidenceBlock($rateB, $bSamples, $zCritical),
            'lift' => $rateA > 0.0 ? $rateB / $rateA - 1.0 : null,
            'z_score' => $zScore,
            'p_value' => $pValue,
            'alpha' => $alpha,
            'significant' => $pValue !== null && $pValue < $alpha,
        ];
    }

    /**
     * @return array{0: float|null, 1: float|null} z-Score und p-Value, beide null ohne Streuung
     */
    private function zTest(int $aSamples, int $aConversions, int $bSamples, int $bConversions, float $rateA, float $rateB): array
    {
        $pooled = ($aConversions + $bConversions) / ($aSamples + $bSamples);
        $standardError = \sqrt($pooled * (1.0 - $pooled) * (1.0 / $aSamples + 1.0 / $bSamples));
        if ($standardError <= 0.0) {
            return [null, null];
        }

        $zScore = ($rateB - $rateA) / $standardError;
        $pValue = 2.0 * (1.0 - $this->normal->cdf(\abs($zScore)));

        return [$zScore, $pValue];
    }

    /**
     * @return array{rate: float, ci_lower: float, ci_upper: float}
     */
    private function confidenceBlock(float $rate, int $samples, float $zCritical): array
    {
        $margin = $zCritical * \sqrt($rate * (1.0 - $rate) / $samples);

        return [
            'rate' => $rate,
            'ci_lower' => \max(0.0, $rate - $margin),
            'ci_upper' => \min(1.0, $rate + $margin),
        ];
    }
}
