/**
 * Rechenschritte der Detailseite ohne Vue und ohne Shopware-Global, damit sie in Node testbar sind.
 */

/**
 * Die führende Nicht-Control-Variante nach `metricKey`, bei zwei Varianten schlicht die Testvariante. Ein fehlender
 * Wert zählt als -Infinity, damit eine Variante ohne Daten nie als führend erscheint.
 */
export function leadingRow(stats, metricKey) {
    const others = stats.filter((row) => !row.isControl);
    if (!others.length) {
        return null;
    }

    return others.reduce((best, row) => {
        const bestValue = best[metricKey] ?? -Infinity;
        const rowValue = row[metricKey] ?? -Infinity;

        return rowValue > bestValue ? row : best;
    }, others[0]);
}

/**
 * Gewichte für `count` Varianten mit der Summe 100. Rest-Punkte (bei drei Varianten einer) gehen an die ersten
 * Varianten, damit die Summe genau 100 bleibt und die Startprüfung nicht anschlägt.
 */
export function evenWeights(count) {
    if (count <= 0) {
        return [];
    }

    const base = Math.floor(100 / count);
    const remainder = 100 - (base * count);

    return Array.from({ length: count }, (_, index) => base + (index < remainder ? 1 : 0));
}

/**
 * Der Text zu einem Fehler der Verwaltungs-API: die Übersetzung des Fehlercodes, sonst die Meldung des Servers, sonst
 * der Rückfalltext. `translate` ist `this.$t`; eine fehlende Übersetzung gibt den Schlüssel zurück.
 */
export function serverErrorMessage(error, translate, fallbackKey) {
    const data = error && error.response ? error.response.data : null;
    const code = data ? data.errorCode : null;
    if (code) {
        const key = `rc-ab-testing.detail.errors.${code}`;
        const translated = translate(key);
        if (translated !== key) {
            return translated;
        }
    }

    return (data && data.error) || translate(fallbackKey);
}
