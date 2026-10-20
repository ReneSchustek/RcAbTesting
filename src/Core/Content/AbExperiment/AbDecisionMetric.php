<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Core\Content\AbExperiment;

/**
 * Zulässige Werte für `rc_ab_experiment.decision_metric`, die Kennzahl, die das Urteil
 * („ausrollen oder nicht“) trägt. Sie ist von `primary_metric` getrennt: Die Primär-Metrik sagt,
 * welches Ereignis als Conversion zählt, die Entscheidungs-Kennzahl, welche Größe statistisch
 * geprüft wird.
 *
 * Zugelassen sind nur Kennzahlen mit sauberem Test: die Conversion-Rate über einen z-Test für
 * zwei Proportionen, der Umsatz pro Besucher über einen Mittelwertvergleich mit Varianz. Der
 * durchschnittliche Bestellwert fehlt, weil seine Einheit die Bestellung ist und nicht der
 * Besucher; er bräuchte einen anderen Test und bleibt eine Kontextzahl in der Anzeige.
 *
 * Zeichenketten-Konstanten statt Enum: Die Werte laufen als Zeichenketten durch die Datenschicht, die
 * Verwaltungs-API und die Vorlagen; ein Enum müsste an jeder dieser Grenzen in seinen Wert und zurück verwandelt
 * werden.
 */
final class AbDecisionMetric
{
    /** Anteil der Besucher mit Conversion, geprüft mit dem Proportionen-Test. */
    public const CONVERSION_RATE = 'conversion_rate';

    /** Umsatz je zugeordnetem Besucher, geprüft mit dem Mittelwerttest; erfasst Conversion und Bestellwert zugleich. */
    public const REVENUE_PER_VISITOR = 'revenue_per_visitor';

    /**
     * Vorgabe für neue Experimente und Default der Spalte: Für einen Shop ist der Umsatz pro
     * Besucher die aussagekräftigste Einzelgröße. Auch der `ExperimentEvaluator` nimmt sie, wenn
     * kein Wert gesetzt ist.
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
     * Ob die Kennzahl eine stetige Größe ist (Mittelwertvergleich) statt eines Anteils. Der
     * Evaluator wählt danach den Signifikanztest.
     */
    public static function isMeanBased(string $metric): bool
    {
        return $metric === self::REVENUE_PER_VISITOR;
    }
}
