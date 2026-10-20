/**
 * Reine Formatierung für die Auswertung, ohne Vue, ohne Shopware-Global und ohne eigenen Zustand. Der
 * Platzhalter für „kein Wert" ist ein Gedankenstrich (nicht „0"), damit fehlende Daten nicht als Messung
 * von null gelesen werden.
 */

const EMPTY = '–';

function isBlank(value) {
    return value === null || value === undefined;
}

export function formatRate(rate) {
    return isBlank(rate) ? EMPTY : `${(rate * 100).toFixed(2)} %`;
}

// Die Beträge kommen umgerechnet in die Standardwährung vom Server; ein festes „€“ stünde in einem Shop mit
// anderer Standardwährung über den falschen Summen. Der Euro wird als Zeichen geschrieben, jede andere Währung
// mit ihrem ISO-Code; ohne Code bleibt es beim Euro.
function currencySign(isoCode) {
    return typeof isoCode === 'string' && isoCode !== '' && isoCode !== 'EUR' ? isoCode : '€';
}

/** Beträge in der Standardwährung des Shops (`isoCode`, etwa `Shopware.Context.app.systemCurrencyISOCode`). */
export function formatCurrency(value, isoCode) {
    return isBlank(value) ? EMPTY : `${value.toFixed(2)} ${currencySign(isoCode)}`;
}

export function formatLift(lift) {
    return isBlank(lift) ? EMPTY : `${lift >= 0 ? '+' : ''}${(lift * 100).toFixed(1)} %`;
}

export function formatPValue(pValue) {
    return isBlank(pValue) ? EMPTY : pValue.toFixed(4);
}

/**
 * Veränderung zur Vorstufe in Prozentpunkten. Meist negativ; das Vorzeichen kommt
 * aus der Zahl, ein Plus wird nicht vorangestellt.
 */
export function formatPercentPoints(delta) {
    return `${(delta * 100).toFixed(1)} Pp.`;
}

/** ISO-Datum (YYYY-MM-DD) zu Tag.Monat für die X-Achse. */
export function shortDate(date) {
    const parts = String(date).split('-');

    return parts.length === 3 ? `${parts[2]}.${parts[1]}` : date;
}

export function formatMetricValue(value, format, isoCode) {
    if (isBlank(value)) {
        return EMPTY;
    }

    return format === 'rate' ? formatRate(value) : formatCurrency(value, isoCode);
}

/**
 * Achsenbeschriftung des Zeitverlaufs in der Einheit der Entscheidungs-Kennzahl.
 * Eine Nachkommastelle genügt — die Achse trägt Größenordnung, nicht Präzision.
 */
export function formatChartValue(value, meanDecision, isoCode) {
    if (isBlank(value)) {
        return EMPTY;
    }

    return meanDecision ? formatCurrency(value, isoCode) : `${(value * 100).toFixed(1)} %`;
}

/**
 * Konfidenzintervall der Variantenkennzahl: bei „Umsatz pro Besucher" in der Standardwährung,
 * bei der Conversion-Rate in Prozent (Einheit einmal am Ende des Intervalls).
 */
export function formatInterval(comparison, meanDecision, isoCode) {
    if (isBlank(comparison.ciLower) || isBlank(comparison.ciUpper)) {
        return EMPTY;
    }
    if (meanDecision) {
        return `${formatCurrency(comparison.ciLower, isoCode)} – ${formatCurrency(comparison.ciUpper, isoCode)}`;
    }

    return `${(comparison.ciLower * 100).toFixed(2)} – ${(comparison.ciUpper * 100).toFixed(2)} %`;
}

/**
 * Relative Veränderung einer Kennzahl der Variante gegenüber der Control.
 * null, wenn kein sinnvoller Bezug möglich ist (fehlende oder 0-Basis).
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
