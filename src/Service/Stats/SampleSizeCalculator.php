<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

/**
 * Schätzt, wie viele Teilnehmer je Variante nötig sind, um einen beobachteten Unterschied zu
 * bestätigen. {@see ExperimentEvaluator} weist die Zahl neben jedem Vergleich aus.
 *
 * Für Raten dient die varianzstabilisierende Arcus-Sinus-Transformation (Cohens h) statt der
 * einfachen Normalnäherung, weil sie bei kleinen Conversion-Raten weniger danebenliegt.
 */
final class SampleSizeCalculator
{
    public function __construct(
        private readonly NormalDistribution $normal,
    ) {
    }

    /**
     * Fallzahl je Variante, um einen relativen Mindesteffekt `$mdeRelative` (0,1 für zehn
     * Prozent) mit der gegebenen Power zweiseitig auf dem Niveau `$alpha` zu erkennen. 0, wenn
     * die Eingaben keinen Effekt beschreiben oder die Zielrate 100 % erreicht.
     *
     * Gerechnet wird n = 2 * ((z_alpha + z_beta) / h)^2 mit h als Differenz der transformierten Raten.
     * Der Faktor 2 steht da, weil zwei Gruppen verglichen werden: Jede transformierte Rate hat die
     * Varianz 1/n, ihre Differenz also 2/n. Ohne ihn wäre das Ergebnis die Fallzahl eines Tests gegen
     * einen festen Wert und damit halb so groß wie nötig.
     */
    public function requiredSize(float $baselineRate, float $mdeRelative, float $power = 0.8, float $alpha = 0.05): int
    {
        $treatmentRate = $baselineRate * (1.0 + $mdeRelative);
        if ($baselineRate <= 0.0 || $treatmentRate >= 1.0 || $mdeRelative <= 0.0) {
            return 0;
        }

        $effectSize = 2.0 * \asin(\sqrt($treatmentRate)) - 2.0 * \asin(\sqrt($baselineRate));
        if ($effectSize === 0.0) {
            return 0;
        }

        $zAlpha = $this->normal->ppf(1.0 - $alpha / 2.0);
        $zBeta = $this->normal->ppf($power);

        return (int) \ceil(2.0 * (($zAlpha + $zBeta) / $effectSize) ** 2);
    }

    /**
     * Fallzahl je Variante, um eine beobachtete Differenz zweier Mittelwerte einer stetigen
     * Größe, etwa Umsatz je Besucher, zu bestätigen. Klassische Formel für zwei Gruppen:
     * n = (z_alpha + z_beta)^2 * (var_a + var_b) / diff^2. 0 ohne positive Differenz oder ohne
     * Streuung.
     */
    public function requiredSizeForMeanDifference(
        float $meanControl,
        float $meanTreatment,
        float $varianceControl,
        float $varianceTreatment,
        float $power = 0.8,
        float $alpha = 0.05,
    ): int {
        $difference = $meanTreatment - $meanControl;
        $varianceSum = $varianceControl + $varianceTreatment;
        if ($difference <= 0.0 || $varianceSum <= 0.0) {
            return 0;
        }

        $zAlpha = $this->normal->ppf(1.0 - $alpha / 2.0);
        $zBeta = $this->normal->ppf($power);

        return (int) \ceil((($zAlpha + $zBeta) ** 2) * $varianceSum / ($difference ** 2));
    }
}
