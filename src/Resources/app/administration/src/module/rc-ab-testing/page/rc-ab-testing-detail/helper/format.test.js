import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    formatChartValue,
    formatCurrency,
    formatInterval,
    formatLift,
    formatMetricValue,
    formatPValue,
    formatPercentPoints,
    formatRate,
    metricUplift,
    shortDate,
    upliftClass,
} from './format.js';

test('formatRate zeigt zwei Nachkommastellen in Prozent', () => {
    assert.equal(formatRate(0.1234), '12.34 %');
    assert.equal(formatRate(0), '0.00 %');
});

test('formatRate liefert fuer fehlende Werte einen Gedankenstrich, keine Null', () => {
    assert.equal(formatRate(null), '–');
    assert.equal(formatRate(undefined), '–');
});

test('formatCurrency haengt das Euro-Zeichen an', () => {
    assert.equal(formatCurrency(2.5), '2.50 €');
    assert.equal(formatCurrency(null), '–');
});

test('formatLift zeigt das Vorzeichen auch bei Zuwachs', () => {
    assert.equal(formatLift(0.302), '+30.2 %');
    assert.equal(formatLift(-0.1), '-10.0 %');
    assert.equal(formatLift(0), '+0.0 %');
    assert.equal(formatLift(null), '–');
});

test('formatPValue zeigt vier Nachkommastellen', () => {
    assert.equal(formatPValue(0.0354), '0.0354');
    assert.equal(formatPValue(null), '–');
});

test('formatPercentPoints beschriftet den Drop-off in Prozentpunkten', () => {
    assert.equal(formatPercentPoints(-0.073), '-7.3 Pp.');
});

test('shortDate kuerzt das ISO-Datum auf Tag.Monat', () => {
    assert.equal(shortDate('2026-07-09'), '09.07');
    assert.equal(shortDate('kaputt'), 'kaputt');
});

test('formatMetricValue waehlt das Format anhand des Kennzahl-Typs', () => {
    assert.equal(formatMetricValue(0.05, 'rate'), '5.00 %');
    assert.equal(formatMetricValue(12, 'currency'), '12.00 €');
    assert.equal(formatMetricValue(null, 'currency'), '–');
});

test('formatChartValue nutzt Euro bei der Mittelwert-Entscheidung, sonst Prozent mit einer Stelle', () => {
    assert.equal(formatChartValue(2.5, true), '2.50 €');
    assert.equal(formatChartValue(0.1234, false), '12.3 %');
});

test('formatInterval setzt das Prozentzeichen nur einmal ans Intervall-Ende', () => {
    const comparison = { ciLower: 0.1092, ciUpper: 0.1508 };

    assert.equal(formatInterval(comparison, false), '10.92 – 15.08 %');
    assert.equal(formatInterval({ ciLower: 2.18, ciUpper: 3.02 }, true), '2.18 € – 3.02 €');
});

test('formatInterval liefert einen Gedankenstrich, wenn eine Grenze fehlt', () => {
    assert.equal(formatInterval({ ciLower: null, ciUpper: 3 }, true), '–');
    assert.equal(formatInterval({ ciLower: 1, ciUpper: null }, false), '–');
});

test('metricUplift misst die Variante an der Control', () => {
    assert.equal(metricUplift({ rate: 0.1 }, { rate: 0.13 }, 'rate').toFixed(2), '0.30');
    assert.equal(metricUplift({ rate: 0.1 }, { rate: 0.05 }, 'rate').toFixed(2), '-0.50');
});

test('metricUplift verweigert die Aussage ohne belastbare Basis', () => {
    // Division durch die Null-Basis waere Infinity — als Kennzahl wertlos.
    assert.equal(metricUplift({ rate: 0 }, { rate: 0.13 }, 'rate'), null);
    assert.equal(metricUplift(null, { rate: 0.13 }, 'rate'), null);
    assert.equal(metricUplift({ rate: 0.1 }, { rate: null }, 'rate'), null);
    assert.equal(metricUplift({}, {}, 'rate'), null);
});

test('upliftClass unterscheidet Zuwachs, Verlust und Stillstand', () => {
    assert.equal(upliftClass(0.1), 'is--up');
    assert.equal(upliftClass(-0.1), 'is--down');
    assert.equal(upliftClass(0), 'is--flat');
    assert.equal(upliftClass(null), 'is--flat');
});
