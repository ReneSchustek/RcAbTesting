import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildTimeSeriesChart, hasTimeSeriesData } from './chart-geometry.js';
import { CONTROL_COLOR, CONTROL_DASH } from './metric-catalog.js';

const options = {
    meanDecision: true,
    formatValue: (value) => value.toFixed(2),
    formatDate: (date) => date.slice(-2),
};

function point(date, assignments, conversions, revenue) {
    return { date, assignments, conversions, revenue };
}

const twoDays = [
    {
        technicalKey: 'control',
        isControl: true,
        points: [point('2026-01-01', 100, 10, 200), point('2026-01-02', 100, 10, 200)],
    },
    {
        technicalKey: 'variant-b',
        isControl: false,
        points: [point('2026-01-01', 100, 20, 400), point('2026-01-02', 100, 20, 400)],
    },
];

test('hasTimeSeriesData erkennt leere und fehlende Reihen', () => {
    assert.equal(hasTimeSeriesData(null), false);
    assert.equal(hasTimeSeriesData([]), false);
    assert.equal(hasTimeSeriesData([{ points: [] }]), false);
    assert.equal(hasTimeSeriesData([{ points: [point('2026-01-01', 1, 0, 0)] }]), true);
});

test('buildTimeSeriesChart liefert ohne Daten null', () => {
    assert.equal(buildTimeSeriesChart([], options), null);
});

test('buildTimeSeriesChart kumuliert den Wert ueber die Tage', () => {
    const chart = buildTimeSeriesChart(twoDays, options);
    const [control, variant] = chart.series;

    // Umsatz je Besucher, kumulativ: Tag 1 = 200/100, Tag 2 = 400/200 — stabil bei 2.
    assert.deepEqual(control.points.map((entry) => entry.value), [2, 2]);
    assert.deepEqual(variant.points.map((entry) => entry.value), [4, 4]);
});

test('buildTimeSeriesChart rechnet bei der Raten-Entscheidung mit Conversions statt Umsatz', () => {
    const chart = buildTimeSeriesChart(twoDays, { ...options, meanDecision: false });

    assert.deepEqual(chart.series[0].points.map((entry) => entry.value), [0.1, 0.1]);
    assert.deepEqual(chart.series[1].points.map((entry) => entry.value), [0.2, 0.2]);
});

test('buildTimeSeriesChart kodiert Varianten zusaetzlich nicht-farblich (Strichmuster)', () => {
    const chart = buildTimeSeriesChart(twoDays, options);
    const [control, variant] = chart.series;

    assert.equal(control.color, CONTROL_COLOR);
    assert.equal(control.dash, CONTROL_DASH);
    assert.notEqual(variant.color, CONTROL_COLOR);
    assert.notEqual(variant.dash, CONTROL_DASH);
    assert.ok(variant.dash.length > 0);
});

test('buildTimeSeriesChart beschriftet das Linienende mit dem Wert', () => {
    const chart = buildTimeSeriesChart(twoDays, options);

    assert.equal(chart.series[1].endLabel, '4.00');
});

test('buildTimeSeriesChart baut eine Datentabelle als Alternative zum SVG', () => {
    const chart = buildTimeSeriesChart(twoDays, options);

    assert.deepEqual(chart.table.columns, ['control', 'variant-b']);
    assert.equal(chart.table.rows.length, 2);
    assert.equal(chart.table.rows[0].label, '01');
    assert.deepEqual(chart.table.rows[0].values, [
        { key: 'control', value: '2.00' },
        { key: 'variant-b', value: '4.00' },
    ]);
});

test('buildTimeSeriesChart haelt eine flache Linie in der Skala', () => {
    const flat = [{ technicalKey: 'control', isControl: true, points: [point('2026-01-01', 100, 10, 200), point('2026-01-02', 100, 10, 200)] }];

    const chart = buildTimeSeriesChart(flat, options);

    // Alle Y-Werte endlich — bei min === max waere die Skala sonst durch 0 geteilt.
    chart.series[0].points.forEach((entry) => assert.ok(Number.isFinite(entry.value)));
    chart.yTicks.forEach((tick) => assert.ok(Number.isFinite(Number(tick.y))));
});

test('buildTimeSeriesChart zentriert einen einzelnen Messtag', () => {
    const single = [{ technicalKey: 'control', isControl: true, points: [point('2026-01-01', 100, 10, 200)] }];

    const chart = buildTimeSeriesChart(single, options);

    assert.equal(chart.xTicks.length, 1);
    assert.ok(Number.isFinite(chart.series[0].points[0].x));
});

test('buildTimeSeriesChart duennt die X-Beschriftung bei vielen Tagen aus', () => {
    const points = Array.from({ length: 20 }, (unused, index) => point(`2026-01-${String(index + 1).padStart(2, '0')}`, 10, 1, 20));
    const chart = buildTimeSeriesChart([{ technicalKey: 'control', isControl: true, points }], options);

    assert.ok(chart.xTicks.length < 20);
    // Der letzte Tag bleibt immer beschriftet.
    assert.equal(chart.xTicks[chart.xTicks.length - 1].label, '20');
});

test('buildTimeSeriesChart liefert fuenf Y-Ticks und eine Grundlinie', () => {
    const chart = buildTimeSeriesChart(twoDays, options);

    assert.equal(chart.yTicks.length, 5);
    assert.equal(chart.bottom, chart.height - 28);
});
