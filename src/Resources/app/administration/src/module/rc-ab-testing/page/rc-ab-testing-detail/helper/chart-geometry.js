import { encodeVariants } from './metric-catalog.js';

/**
 * Geometrie des Zeitverlauf-Charts (Inline-SVG): je Variante der kumulative
 * Verlauf der Entscheidungs-Kennzahl. Kumulativ, weil ein frueher Ausschlag bei
 * wenigen Besuchern taeuscht und sich der Wert erst mit der Zeit stabilisiert.
 *
 * Rein rechnende Funktion — die Komponente reicht nur Formatierer herein.
 */

const WIDTH = 640;
const HEIGHT = 260;
const PAD_LEFT = 56;
const PAD_RIGHT = 16;
const PAD_TOP = 14;
const PAD_BOTTOM = 28;
const Y_TICK_STEPS = 4;

/** Ohne wenigstens einen Messpunkt gibt es nichts zu zeichnen. */
export function hasTimeSeriesData(timeSeries) {
    return Array.isArray(timeSeries) && timeSeries.some((serie) => serie.points.length > 0);
}

/**
 * @param {Array} timeSeries Server-Reihen: [{ technicalKey, isControl, points: [{ date, assignments, conversions, revenue }] }]
 * @param {{ meanDecision: boolean, formatValue: Function, formatDate: Function }} options
 * @returns {object|null} Render-fertige Geometrie oder null ohne Daten.
 */
export function buildTimeSeriesChart(timeSeries, { meanDecision, formatValue, formatDate }) {
    if (!hasTimeSeriesData(timeSeries)) {
        return null;
    }

    const dates = [...new Set(timeSeries.flatMap((serie) => serie.points.map((point) => point.date)))].sort();
    const xOf = xScale(dates.length);
    const series = encodeVariants(timeSeries).map((serie) => ({
        key: serie.technicalKey,
        isControl: serie.isControl,
        color: serie.color,
        dash: serie.dash,
        points: cumulativePoints(serie.points, dates, xOf, meanDecision),
    }));

    const { min, max } = valueRange(series);
    const yOf = (value) => PAD_TOP + (HEIGHT - PAD_TOP - PAD_BOTTOM) * (1 - (value - min) / (max - min));

    series.forEach((serie) => {
        serie.line = serie.points.map((point) => `${point.x.toFixed(1)},${yOf(point.value).toFixed(1)}`).join(' ');
        const last = serie.points[serie.points.length - 1];
        serie.end = { x: last.x.toFixed(1), y: yOf(last.value).toFixed(1) };
        serie.endLabel = formatValue(last.value);
    });

    return {
        width: WIDTH,
        height: HEIGHT,
        bottom: HEIGHT - PAD_BOTTOM,
        series,
        yTicks: buildYTicks(min, max, yOf, formatValue),
        xTicks: buildXTicks(dates, xOf, formatDate),
        // Barrierefreie Alternative zum SVG: dieselben Zahlen als Tabelle.
        table: buildDataTable(dates, series, formatDate, formatValue),
    };
}

function xScale(dateCount) {
    if (dateCount <= 1) {
        const center = PAD_LEFT + (WIDTH - PAD_LEFT - PAD_RIGHT) / 2;

        return () => center;
    }

    return (index) => PAD_LEFT + (WIDTH - PAD_LEFT - PAD_RIGHT) * (index / (dateCount - 1));
}

/**
 * Kumulierter Wert je Tag: Zuordnungen, Conversions und Umsatz laufen mit, der
 * ausgewiesene Wert ist der Quotient bis zu diesem Tag.
 */
function cumulativePoints(points, dates, xOf, meanDecision) {
    let assignments = 0;
    let conversions = 0;
    let revenue = 0;

    return points.map((point) => {
        assignments += point.assignments;
        conversions += point.conversions;
        revenue += point.revenue;
        const numerator = meanDecision ? revenue : conversions;

        return {
            x: xOf(dates.indexOf(point.date)),
            date: point.date,
            value: assignments > 0 ? numerator / assignments : 0,
        };
    });
}

/**
 * Wertebereich der Y-Achse mit 10 % Luft. Eine flache Linie (alle Werte gleich)
 * bekommt kuenstlich Hoehe, sonst waere die Skala nicht definiert.
 */
function valueRange(series) {
    const values = series.flatMap((serie) => serie.points.map((point) => point.value));
    let min = Math.min(...values);
    let max = Math.max(...values);
    if (min === max) {
        max = min + (min === 0 ? 1 : Math.abs(min) * 0.2);
    }
    const margin = (max - min) * 0.1;

    return { min: Math.max(0, min - margin), max: max + margin };
}

function buildYTicks(min, max, yOf, formatValue) {
    const ticks = [];
    for (let step = 0; step <= Y_TICK_STEPS; step += 1) {
        const value = min + (max - min) * (step / Y_TICK_STEPS);
        ticks.push({
            y: yOf(value).toFixed(1),
            x1: PAD_LEFT,
            x2: WIDTH - PAD_RIGHT,
            label: formatValue(value),
        });
    }

    return ticks;
}

/** Bei vielen Tagen nur jede n-te Beschriftung, damit die Achse lesbar bleibt. */
function buildXTicks(dates, xOf, formatDate) {
    const every = dates.length > 8 ? Math.ceil(dates.length / 6) : 1;

    return dates.reduce((ticks, date, index) => {
        if (index % every === 0 || index === dates.length - 1) {
            ticks.push({ x: xOf(index).toFixed(1), label: formatDate(date) });
        }

        return ticks;
    }, []);
}

/**
 * Datentabelle hinter dem Chart (visuell versteckt): je Tag eine Zeile, je
 * Variante eine Spalte. Screenreader lesen die Zahlen, statt an einem `<svg>`
 * haengenzubleiben.
 */
function buildDataTable(dates, series, formatDate, formatValue) {
    const rows = dates.map((date) => ({
        key: date,
        label: formatDate(date),
        values: series.map((serie) => {
            const point = serie.points.find((entry) => entry.date === date);

            return { key: serie.key, value: point ? formatValue(point.value) : '–' };
        }),
    }));

    return { columns: series.map((serie) => serie.key), rows };
}
