<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

/**
 * Schätzt die nötige Stichprobengröße je Variante für einen geplanten Test.
 * Nutzt die varianzstabilisierende Arcus-Sinus-Transformation (Cohens h) statt
 * der naiven Normal-Approximation — robuster bei kleinen Raten. Wird vom
 * ExperimentEvaluator genutzt, um die zur Bestätigung eines Lifts nötige
 * Fallzahl auszuweisen.
 */
final class SampleSizeCalculator
{
    public function __construct(
        private readonly NormalDistribution $normal,
    ) {
    }

    /**
     * Benötigte Fallzahl je Variante, um einen relativen Mindesteffekt (mde)
     * mit gegebener Power und Signifikanz zu erkennen. 0, wenn die Eingaben
     * keinen sinnvollen Effekt beschreiben.
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

        return (int) \ceil((($zAlpha + $zBeta) / $effectSize) ** 2);
    }
}
