/**
 * Ableitung von Verdikt, Klartext-Empfehlung und Segment-Ergebnis aus der
 * serverseitigen Auswertung. Kein Vue: die Übersetzung kommt als Funktion
 * `translate(key, params)` herein, damit die Regeln testbar bleiben.
 *
 * Gemeinsame Prämisse aller drei: „signifikant" heißt nicht „besser". Der Test
 * ist zweiseitig, eine signifikant schlechtere Variante muss als Warnung
 * erscheinen, nie als Erfolg.
 */

/** @returns {Record<string, number>} technischer Schlüssel → Zuordnungen */
export function assignmentsByKey(stats) {
    const map = {};
    (stats || []).forEach((row) => { map[row.technicalKey] = row.assignments; });

    return map;
}

function isWorse(comparison) {
    return comparison.significant && comparison.lift !== null && comparison.lift < 0;
}

/**
 * Ob für eine Vergleichszeile genug Daten für eine Aussage vorliegen — beide
 * Seiten (Control und Variante) erreichen die benötigte Fallzahl.
 */
export function hasEnoughData(stats, evaluation, comparison) {
    if (!stats || !evaluation) {
        return false;
    }
    const assignments = assignmentsByKey(stats);

    return reachesSampleSize(
        comparison.requiredSamplePerVariant,
        assignments[evaluation.controlKey] || 0,
        assignments[comparison.variantKey] || 0,
    );
}

/**
 * Ob Kontrolle und Variante die benötigte Fallzahl erreichen. 0 heißt, der Server konnte keine Fallzahl berechnen,
 * etwa ohne positiven Lift; das zählt als „noch zu wenig Daten".
 */
function reachesSampleSize(requiredSamplePerVariant, controlAssignments, variantAssignments) {
    const required = requiredSamplePerVariant || 0;

    return required > 0 && controlAssignments >= required && variantAssignments >= required;
}

/**
 * Ein-Satz-Verdikt, bezogen auf die Entscheidungs-Kennzahl. Ampelfarbe über die
 * sw-alert-Variante. Hält der Server den Gewinner zurück (zu kleine Fallzahl,
 * ungleiche Verteilung), ist `winnerKey` leer und das Verdikt fällt auf die
 * übrigen Sätze zurück.
 */
export function buildVerdict(evaluation, stats, { metricLabel, translate, formatLift }) {
    if (!evaluation) {
        return null;
    }
    const comparisons = evaluation.comparisons || [];

    if (evaluation.winnerKey) {
        const winner = comparisons.find((entry) => entry.variantKey === evaluation.winnerKey);

        return {
            variant: 'success',
            text: translate('rc-ab-testing.detail.verdictWinner', {
                variant: evaluation.winnerKey,
                lift: formatLift(winner ? winner.lift : null),
                metric: metricLabel,
            }),
        };
    }

    const worse = comparisons.find(isWorse);
    if (worse) {
        return {
            variant: 'warning',
            text: translate('rc-ab-testing.detail.verdictWorse', { variant: worse.variantKey, metric: metricLabel }),
        };
    }

    if (comparisons.length && comparisons.every((entry) => !hasEnoughData(stats, evaluation, entry))) {
        return { variant: 'info', text: translate('rc-ab-testing.detail.verdictNotEnoughData') };
    }

    return { variant: 'info', text: translate('rc-ab-testing.detail.verdictNoDifference') };
}

/**
 * Übersetzt die Statistik je Variante in einen Klartext-Satz mit Handlungs-
 * empfehlung — für Nicht-Techniker, die keine p-Values lesen.
 */
export function buildRecommendations(evaluation, stats, { translate }) {
    if (!evaluation || !stats) {
        return [];
    }
    const assignments = assignmentsByKey(stats);

    return (evaluation.comparisons || []).map((comparison) => {
        if (comparison.significant) {
            const worse = isWorse(comparison);

            return {
                key: comparison.variantKey,
                variant: worse ? 'error' : 'success',
                text: translate(
                    worse ? 'rc-ab-testing.detail.recWorse' : 'rc-ab-testing.detail.recBetter',
                    { variant: comparison.variantKey },
                ),
            };
        }

        if (hasEnoughData(stats, evaluation, comparison)) {
            return {
                key: comparison.variantKey,
                variant: 'info',
                text: translate('rc-ab-testing.detail.recNoDifference', { variant: comparison.variantKey }),
            };
        }

        return {
            key: comparison.variantKey,
            variant: 'info',
            text: translate('rc-ab-testing.detail.recNotEnoughData', {
                variant: comparison.variantKey,
                current: assignments[comparison.variantKey] || 0,
                required: comparison.requiredSamplePerVariant || '?',
            }),
        };
    });
}

/**
 * Zeigt bei Signifikanz auch die Richtung an — ohne Richtungshinweis läse sich
 * „signifikant" fälschlich als Erfolg.
 */
export function significanceSnippet(comparison) {
    if (!comparison.significant) {
        return 'rc-ab-testing.detail.significantNo';
    }

    return isWorse(comparison)
        ? 'rc-ab-testing.detail.significantWorse'
        : 'rc-ab-testing.detail.significantBetter';
}

/**
 * Kompaktes Ergebnis je Variante im Segment (Ampel), abgeleitet aus der
 * segment-eigenen Auswertung — dieselbe Logik wie im Gesamtbild.
 */
export function segmentVariantResult(row, evaluation, controlAssignments) {
    if (row.isControl) {
        return { variant: 'info', snippet: 'rc-ab-testing.detail.segReference' };
    }
    const comparison = ((evaluation && evaluation.comparisons) || []).find(
        (entry) => entry.variantKey === row.technicalKey,
    );
    if (!comparison) {
        return { variant: 'info', snippet: null };
    }
    if (comparison.significant) {
        const worse = isWorse(comparison);

        return {
            variant: worse ? 'error' : 'success',
            snippet: worse ? 'rc-ab-testing.detail.significantWorse' : 'rc-ab-testing.detail.significantBetter',
        };
    }

    const enough = reachesSampleSize(comparison.requiredSamplePerVariant, controlAssignments, row.assignments);

    return {
        variant: 'info',
        snippet: enough ? 'rc-ab-testing.detail.segNoDifference' : 'rc-ab-testing.detail.segNotEnough',
    };
}
