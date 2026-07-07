<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

/**
 * Zulässige Werte für `rc_ab_experiment.decision_metric` — die Kennzahl, die das
 * Verdikt („ausrollen ja/nein") treibt. Bewusst getrennt von `primary_metric`
 * (welches Event als Conversion zählt): die Entscheidungs-Kennzahl legt fest,
 * WELCHE Grösse statistisch geprüft wird, nicht welches Event zählt.
 *
 * Nur Kennzahlen, die sich statistisch sauber prüfen lassen, sind erlaubt:
 * die Conversion-Rate über einen Proportionen-z-Test, der Umsatz pro Besucher
 * über einen Mittelwert-Vergleich mit Varianz. Der durchschnittliche Bestellwert
 * (AOV) ist bewusst NICHT als Entscheidungs-Kennzahl zugelassen — seine
 * Analyseeinheit ist die Bestellung, nicht der Besucher, was einen Mischtest
 * erzwingen würde; AOV bleibt reine Anzeige-Kontextzahl.
 */
final class AbDecisionMetric
{
    /** Anteil konvertierender Besucher — Proportionen-Test. */
    public const CONVERSION_RATE = 'conversion_rate';

    /** Umsatz je zugeordnetem Besucher — Mittelwert-Test (erfasst Conversion UND Bestellwert). */
    public const REVENUE_PER_VISITOR = 'revenue_per_visitor';

    /**
     * Produkt-Default für neue Experimente (auch DB-Spalten-Default): der Umsatz
     * pro Besucher ist für einen Shop die aussagekräftigste Einzelgrösse.
     */
    public const DEFAULT = self::REVENUE_PER_VISITOR;

    /** @var list<string> */
    public const VALUES = [
        self::CONVERSION_RATE,
        self::REVENUE_PER_VISITOR,
    ];

    private function __construct()
    {
    }

    /**
     * Ob die Kennzahl eine stetige Grösse ist (Mittelwert-Vergleich) statt einer
     * Proportion. Steuert im Evaluator die Wahl des Signifikanztests.
     */
    public static function isMeanBased(string $metric): bool
    {
        return $metric === self::REVENUE_PER_VISITOR;
    }
}
