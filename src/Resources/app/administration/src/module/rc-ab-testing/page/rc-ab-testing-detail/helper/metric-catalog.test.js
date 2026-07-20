import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    CONTROL_COLOR,
    CONTROL_DASH,
    DECISION_METRIC_CONVERSION,
    DECISION_METRIC_REVENUE_PER_VISITOR,
    METRIC_CATALOG,
    encodeVariants,
    isMeanDecision,
    orderByPrimary,
    primaryMetricKey,
    variantEncoding,
} from './metric-catalog.js';

test('primaryMetricKey bildet die Entscheidungs-Kennzahl auf die Scorecard-Spalte ab', () => {
    assert.equal(primaryMetricKey(DECISION_METRIC_REVENUE_PER_VISITOR), 'revenuePerVisitor');
    assert.equal(primaryMetricKey(DECISION_METRIC_CONVERSION), 'rate');
    // Unbekannte Kennzahl faellt auf die Conversion-Rate zurueck (Server-Fallback).
    assert.equal(primaryMetricKey(undefined), 'rate');
});

test('isMeanDecision gilt nur fuer den Umsatz je Besucher', () => {
    assert.equal(isMeanDecision(DECISION_METRIC_REVENUE_PER_VISITOR), true);
    assert.equal(isMeanDecision(DECISION_METRIC_CONVERSION), false);
});

test('orderByPrimary stellt die Entscheidungs-Kennzahl nach vorn', () => {
    const ordered = orderByPrimary(METRIC_CATALOG, 'revenuePerVisitor');

    assert.equal(ordered[0].key, 'revenuePerVisitor');
    assert.equal(ordered.length, METRIC_CATALOG.length);
    assert.deepEqual(ordered.slice(1).map((metric) => metric.key), ['rate', 'aov', 'revenue']);
});

test('orderByPrimary laesst die Reihenfolge unveraendert, wenn die Kennzahl fehlt', () => {
    const ordered = orderByPrimary(METRIC_CATALOG, 'gibtsnicht');

    assert.deepEqual(ordered.map((metric) => metric.key), METRIC_CATALOG.map((metric) => metric.key));
});

test('der Bestellwert steht im Katalog, ist aber keine Entscheidungs-Kennzahl', () => {
    // AOV bleibt bewusst reine Anzeige — sonst entstuende ein Verdikt ohne Test.
    assert.ok(METRIC_CATALOG.some((metric) => metric.key === 'aov'));
    assert.notEqual(primaryMetricKey(DECISION_METRIC_REVENUE_PER_VISITOR), 'aov');
    assert.notEqual(primaryMetricKey(DECISION_METRIC_CONVERSION), 'aov');
});

test('variantEncoding haelt die Control neutral und durchgezogen', () => {
    assert.deepEqual(variantEncoding(true, 0), { color: CONTROL_COLOR, dash: CONTROL_DASH });
});

test('encodeVariants vergibt Palettenplaetze nur an Testvarianten', () => {
    const encoded = encodeVariants([
        { technicalKey: 'a', isControl: false },
        { technicalKey: 'control', isControl: true },
        { technicalKey: 'b', isControl: false },
    ]);

    assert.equal(encoded[1].color, CONTROL_COLOR);
    // Die Control ueberspringt keinen Palettenplatz: a und b sind benachbart.
    assert.notEqual(encoded[0].color, encoded[2].color);
    assert.notEqual(encoded[0].dash, encoded[2].dash);
});

test('encodeVariants gibt jeder Testvariante ein eigenes Strichmuster', () => {
    const encoded = encodeVariants([
        { isControl: false }, { isControl: false }, { isControl: false }, { isControl: false },
    ]);
    const dashes = encoded.map((variant) => variant.dash);

    assert.equal(new Set(dashes).size, 4);
    assert.ok(dashes.every((dash) => dash !== CONTROL_DASH));
});

test('encodeVariants laesst die uebrigen Felder unangetastet', () => {
    const [encoded] = encodeVariants([{ technicalKey: 'a', isControl: false, assignments: 42 }]);

    assert.equal(encoded.technicalKey, 'a');
    assert.equal(encoded.assignments, 42);
});
