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
     * Vergleicht die Mittelwerte einer stetigen Größe (z. B. Umsatz je Besucher)
     * zwischen Control (a) und Variante (b). Anders als {@see calculate} prüft dies
     * keine Proportion, sondern die Differenz zweier Mittelwerte per Welch-z-Test
     * (großzahlige Normal-Approximation, für A/B-Stichproben mit tausenden
     * Besuchern angemessen). Eingaben sind je Seite Fallzahl, Summe und Quadrat-
     * summe der Einzelwerte — daraus folgen Mittelwert und Stichproben-Varianz,
     * ohne die Einzelwerte materialisieren zu müssen. Degenerierte Eingaben
     * (Fallzahl < 2, keine Streuung) liefern null statt einer Division durch null.
     *
     * @param float $sumA   Summe der Einzelwerte in Control
     * @param float $sumSqA Summe der quadrierten Einzelwerte in Control
     *
     * @return array{
     *     a: array{mean: float, ci_lower: float, ci_upper: float}|null,
     *     b: array{mean: float, ci_lower: float, ci_upper: float}|null,
     *     lift: float|null,
     *     z_score: float|null,
     *     p_value: float|null,
     *     alpha: float,
     *     significant: bool
     * }
     */
    public function compareMeans(int $aSamples, float $sumA, float $sumSqA, int $bSamples, float $sumB, float $sumSqB, float $confidenceLevel = self::DEFAULT_CONFIDENCE): array
    {
        if ($confidenceLevel <= 0.0 || $confidenceLevel >= 1.0) {
            $confidenceLevel = self::DEFAULT_CONFIDENCE;
        }
        $alpha = 1.0 - $confidenceLevel;

        // Varianz braucht mindestens zwei Werte je Seite.
        if ($aSamples < 2 || $bSamples < 2) {
            return ['a' => null, 'b' => null, 'lift' => null, 'z_score' => null, 'p_value' => null, 'alpha' => $alpha, 'significant' => false];
        }

        $statsA = $this->meanAndVariance($sumA, $sumSqA, $aSamples);
        $statsB = $this->meanAndVariance($sumB, $sumSqB, $bSamples);
        $meanA = $statsA['mean'];
        $meanB = $statsB['mean'];
        $varianceA = $statsA['variance'];
        $varianceB = $statsB['variance'];

        $standardError = \sqrt($varianceA / $aSamples + $varianceB / $bSamples);
        if ($standardError <= 0.0) {
            return ['a' => null, 'b' => null, 'lift' => null, 'z_score' => null, 'p_value' => null, 'alpha' => $alpha, 'significant' => false];
        }

        $zScore = ($meanB - $meanA) / $standardError;
        // Auf [0,1] klemmen: die erf-Approximation der cdf kann bei sehr grossen
        // |z| minimal ueber 1 liefern, sodass 2*(1-cdf) leicht negativ wuerde (AB49).
        $pValue = max(0.0, min(1.0, 2.0 * (1.0 - $this->normal->cdf(\abs($zScore)))));
        $zCritical = $this->normal->ppf(1.0 - $alpha / 2.0);

        return [
            'a' => $this->meanConfidenceBlock($meanA, $varianceA, $aSamples, $zCritical),
            'b' => $this->meanConfidenceBlock($meanB, $varianceB, $bSamples, $zCritical),
            'lift' => $meanA > 0.0 ? $meanB / $meanA - 1.0 : null,
            'z_score' => $zScore,
            'p_value' => $pValue,
            'alpha' => $alpha,
            'significant' => $pValue < $alpha,
        ];
    }

    /**
     * Mittelwert und Stichproben-Varianz aus Fallzahl, Summe und Quadratsumme —
     * ohne die Einzelwerte materialisieren zu müssen. Einzige Quelle dieser
     * Ableitung (auch der Evaluator nutzt sie für die nötige Fallzahl). Die Varianz
     * kann durch Gleitkomma-Rundung minimal negativ werden (praktisch konstante
     * Werte) — auf 0 geklemmt.
     *
     * @return array{mean: float, variance: float}
     */
    public function meanAndVariance(float $sum, float $sumSquares, int $samples): array
    {
        if ($samples <= 0) {
            return ['mean' => 0.0, 'variance' => 0.0];
        }

        $mean = $sum / $samples;
        if ($samples < 2) {
            return ['mean' => $mean, 'variance' => 0.0];
        }

        $variance = ($sumSquares - $sum * $sum / $samples) / ($samples - 1);

        return ['mean' => $mean, 'variance' => \max(0.0, $variance)];
    }

    /**
     * @return array{mean: float, ci_lower: float, ci_upper: float}
     */
    private function meanConfidenceBlock(float $mean, float $variance, int $samples, float $zCritical): array
    {
        $margin = $zCritical * \sqrt($variance / $samples);

        return [
            'mean' => $mean,
            'ci_lower' => $mean - $margin,
            'ci_upper' => $mean + $margin,
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
        // Auf [0,1] geklemmt (cdf-Approximation, siehe compareMeans, AB49).
        $pValue = max(0.0, min(1.0, 2.0 * (1.0 - $this->normal->cdf(\abs($zScore)))));

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
