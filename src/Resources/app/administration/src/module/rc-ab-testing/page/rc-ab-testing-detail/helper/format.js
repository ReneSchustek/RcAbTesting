/**
 * Reine Formatierung fuer die Auswertung — kein Vue, kein Shopware-Global. Der
 * Platzhalter fuer „kein Wert" ist bewusst ein Gedankenstrich (nicht „0"), damit
 * fehlende Daten nicht als Messung von null gelesen werden.
 */

const EMPTY = '–';

function isBlank(value) {
    return value === null || value === undefined;
}

export function formatRate(rate) {
    return isBlank(rate) ? EMPTY : `${(rate * 100).toFixed(2)} %`;
}

/**
 * Bewusst simples Euro-Format: die Shop-Waehrung liegt auf der Auswertungs-
 * Detailseite nicht direkt vor; fuer die Entscheider-Ansicht reicht ein
 * einheitlicher Betrag mit zwei Nachkommastellen.
 */
export function formatCurrency(value) {
    return isBlank(value) ? EMPTY : `${value.toFixed(2)} €`;
}

export function formatLift(lift) {
    return isBlank(lift) ? EMPTY : `${lift >= 0 ? '+' : ''}${(lift * 100).toFixed(1)} %`;
}

export function formatPValue(pValue) {
    return isBlank(pValue) ? EMPTY : pValue.toFixed(4);
}

/** Drop-off in Prozentpunkten (immer als Verlust dargestellt). */
export function formatPercentPoints(delta) {
    return `${(delta * 100).toFixed(1)} Pp.`;
}

/** ISO-Datum (YYYY-MM-DD) zu Tag.Monat fuer die X-Achse. */
export function shortDate(date) {
    const parts = String(date).split('-');

    return parts.length === 3 ? `${parts[2]}.${parts[1]}` : date;
}

export function formatMetricValue(value, format) {
    if (isBlank(value)) {
        return EMPTY;
    }

    return format === 'rate' ? formatRate(value) : formatCurrency(value);
}

/**
 * Achsenbeschriftung des Zeitverlaufs in der Einheit der Entscheidungs-Kennzahl.
 * Eine Nachkommastelle genuegt — die Achse traegt Groessenordnung, nicht Praezision.
 */
export function formatChartValue(value, meanDecision) {
    if (isBlank(value)) {
        return EMPTY;
    }

    return meanDecision ? formatCurrency(value) : `${(value * 100).toFixed(1)} %`;
}

/**
 * Konfidenzintervall der Variantenkennzahl — bei „Umsatz pro Besucher" in Euro,
 * bei der Conversion-Rate in Prozent (Einheit einmal am Ende des Intervalls).
 */
export function formatInterval(comparison, meanDecision) {
    if (isBlank(comparison.ciLower) || isBlank(comparison.ciUpper)) {
        return EMPTY;
    }
    if (meanDecision) {
        return `${formatCurrency(comparison.ciLower)} – ${formatCurrency(comparison.ciUpper)}`;
    }

    return `${(comparison.ciLower * 100).toFixed(2)} – ${(comparison.ciUpper * 100).toFixed(2)} %`;
}

/**
 * Relative Veraenderung einer Kennzahl der Variante gegenueber der Control.
 * null, wenn kein sinnvoller Bezug moeglich ist (fehlende oder 0-Basis).
 */
export function metricUplift(control, variant, key) {
    if (!control || !variant) {
        return null;
    }
    const base = control[key];
    const value = variant[key];
    if (isBlank(base) || isBlank(value) || base === 0) {
        return null;
    }

    return value / base - 1;
}

export function upliftClass(uplift) {
    if (isBlank(uplift) || uplift === 0) {
        return 'is--flat';
    }

    return uplift > 0 ? 'is--up' : 'is--down';
}
