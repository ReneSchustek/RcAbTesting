<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Stats;

/**
 * Standardnormalverteilung in reinem PHP (keine Extension-Abhängigkeit). `erf`
 * ist die Abramowitz-Stegun-Approximation 7.1.26 (max. Fehler ~1.5e-7), `cdf`
 * darauf aufgebaut. Bewusst eigene Klasse, damit die Mathematik unabhängig
 * vom Test-Service gegen die Standardnormaltabelle geprüft werden kann.
 */
final class NormalDistribution
{
    private const A1 = 0.254829592;
    private const A2 = -0.284496736;
    private const A3 = 1.421413741;
    private const A4 = -1.453152027;
    private const A5 = 1.061405429;
    private const P = 0.3275911;

    /**
     * Verteilungsfunktion der Standardnormalverteilung: P(Z <= z).
     */
    public function cdf(float $z): float
    {
        return 0.5 * (1.0 + $this->erf($z / \sqrt(2.0)));
    }

    /**
     * Gaußsche Fehlerfunktion. Ungerade Funktion — für negative Argumente
     * wird das Vorzeichen gespiegelt.
     */
    public function erf(float $x): float
    {
        if ($x < 0.0) {
            return -$this->erf(-$x);
        }

        $t = 1.0 / (1.0 + self::P * $x);
        $poly = ((((self::A5 * $t + self::A4) * $t + self::A3) * $t + self::A2) * $t + self::A1) * $t;

        return 1.0 - $poly * \exp(-$x * $x);
    }

    /**
     * Quantilsfunktion (inverse cdf) nach Acklam — z, sodass cdf(z) = p.
     * Genauigkeit ~1e-9 im Kernbereich; für p außerhalb (0,1) wird auf die
     * Grenzen geklemmt. Genutzt für z-Quantile in der Stichprobengrößen-Formel.
     */
    public function ppf(float $p): float
    {
        if ($p <= 0.0) {
            return -\INF;
        }
        if ($p >= 1.0) {
            return \INF;
        }

        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];

        $pLow = 0.02425;
        $pHigh = 1.0 - $pLow;

        if ($p < $pLow) {
            $q = \sqrt(-2.0 * \log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }

        if ($p <= $pHigh) {
            $q = $p - 0.5;
            $r = $q * $q;

            return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
        }

        $q = \sqrt(-2.0 * \log(1.0 - $p));

        return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
            / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
    }
}
