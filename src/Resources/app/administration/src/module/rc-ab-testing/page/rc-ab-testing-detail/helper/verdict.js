/**
 * Ableitung von Verdikt, Klartext-Empfehlung und Segment-Ergebnis aus der
 * serverseitigen Auswertung. Kein Vue: die Uebersetzung kommt als Funktion
 * `translate(key, params)` herein, damit die Regeln testbar bleiben.
 *
 * Gemeinsame Praemisse aller drei: „signifikant" heisst nicht „besser". Der Test
 * ist zweiseitig — eine signifikant SCHLECHTERE Variante muss als Warnung
 * erscheinen, nie als Erfolg.
 */

/** @returns {Record<string, number>} technischer Schluessel → Zuordnungen */
export function assignmentsByKey(stats) {
    const map = {};
    (stats || []).forEach((row) => { map[row.technicalKey] = row.assignments; });

    return map;
}

function isWorse(comparison) {
    return comparison.significant && comparison.lift !== null && comparison.lift < 0;
}

/**
 * Ob fuer eine Vergleichszeile genug Daten fuer eine Aussage vorliegen — beide
 * Seiten (Control und Variante) erreichen die benoetigte Fallzahl.
 */
export function hasEnoughData(stats, evaluation, comparison) {
    if (!stats || !evaluation) {
        return false;
    }
    const assignments = assignmentsByKey(stats);
    const required = comparison.requiredSamplePerVariant || 0;

    return required > 0
        && (assignments[evaluation.controlKey] || 0) >= required
        && (assignments[comparison.variantKey] || 0) >= required;
}

/**
 * Ein-Satz-Verdikt, bezogen auf die Entscheidungs-Kennzahl. Ampelfarbe ueber die
 * sw-alert-Variante.
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
 * Uebersetzt die Statistik je Variante in einen Klartext-Satz mit Handlungs-
 * empfehlung — fuer Nicht-Techniker, die keine p-Values lesen.
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
 * Zeigt bei Signifikanz auch die Richtung an — ohne Richtungshinweis laese sich
 * „signifikant" faelschlich als Erfolg.
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

    const required = comparison.requiredSamplePerVariant || 0;
    const enough = required > 0 && controlAssignments >= required && row.assignments >= required;

    return {
        variant: 'info',
        snippet: enough ? 'rc-ab-testing.detail.segNoDifference' : 'rc-ab-testing.detail.segNotEnough',
    };
}
