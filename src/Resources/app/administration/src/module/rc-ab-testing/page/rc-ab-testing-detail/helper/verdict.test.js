import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    buildRecommendations,
    buildVerdict,
    hasEnoughData,
    segmentVariantResult,
    significanceSnippet,
} from './verdict.js';

// Uebersetzer-Attrappe: gibt den Schluessel samt Parametern zurueck, damit die
// Tests die Regel pruefen und nicht die Snippets.
const translate = (key, params) => (params ? `${key}(${JSON.stringify(params)})` : key);
const formatLift = (lift) => (lift === null ? '–' : `${(lift * 100).toFixed(1)} %`);

const stats = [
    { technicalKey: 'control', isControl: true, assignments: 1000 },
    { technicalKey: 'variant-b', isControl: false, assignments: 1000 },
];

function comparison(overrides = {}) {
    return {
        variantKey: 'variant-b',
        significant: false,
        lift: 0.1,
        requiredSamplePerVariant: 800,
        ...overrides,
    };
}

test('hasEnoughData verlangt die Fallzahl auf BEIDEN Seiten', () => {
    const evaluation = { controlKey: 'control' };

    assert.equal(hasEnoughData(stats, evaluation, comparison()), true);
    assert.equal(hasEnoughData(stats, evaluation, comparison({ requiredSamplePerVariant: 1200 })), false);

    const thinControl = [{ technicalKey: 'control', isControl: true, assignments: 10 }, stats[1]];
    assert.equal(hasEnoughData(thinControl, evaluation, comparison()), false);
});

test('hasEnoughData ist ohne benoetigte Fallzahl niemals erfuellt', () => {
    assert.equal(hasEnoughData(stats, { controlKey: 'control' }, comparison({ requiredSamplePerVariant: 0 })), false);
    assert.equal(hasEnoughData(null, { controlKey: 'control' }, comparison()), false);
    assert.equal(hasEnoughData(stats, null, comparison()), false);
});

test('buildVerdict meldet den Gewinner mit Lift und Kennzahl', () => {
    const evaluation = {
        controlKey: 'control',
        winnerKey: 'variant-b',
        comparisons: [comparison({ significant: true, lift: 0.3 })],
    };

    const verdict = buildVerdict(evaluation, stats, { metricLabel: 'Umsatz/Besucher', translate, formatLift });

    assert.equal(verdict.variant, 'success');
    assert.match(verdict.text, /verdictWinner/);
    assert.match(verdict.text, /\+?30\.0 %/);
});

test('buildVerdict warnt bei signifikant SCHLECHTERER Variante statt Erfolg zu melden', () => {
    const evaluation = {
        controlKey: 'control',
        winnerKey: null,
        comparisons: [comparison({ significant: true, lift: -0.2 })],
    };

    const verdict = buildVerdict(evaluation, stats, { metricLabel: 'Rate', translate, formatLift });

    assert.equal(verdict.variant, 'warning');
    assert.match(verdict.text, /verdictWorse/);
});

test('buildVerdict verlangt Daten, bevor es „kein Unterschied" sagt', () => {
    const evaluation = { controlKey: 'control', comparisons: [comparison({ requiredSamplePerVariant: 5000 })] };

    const verdict = buildVerdict(evaluation, stats, { metricLabel: 'Rate', translate, formatLift });

    assert.equal(verdict.variant, 'info');
    assert.equal(verdict.text, 'rc-ab-testing.detail.verdictNotEnoughData');
});

test('buildVerdict meldet „kein Unterschied" bei ausreichender Fallzahl ohne Signifikanz', () => {
    const evaluation = { controlKey: 'control', comparisons: [comparison()] };

    const verdict = buildVerdict(evaluation, stats, { metricLabel: 'Rate', translate, formatLift });

    assert.equal(verdict.text, 'rc-ab-testing.detail.verdictNoDifference');
});

test('buildVerdict ohne Auswertung liefert null', () => {
    assert.equal(buildVerdict(null, stats, { metricLabel: '', translate, formatLift }), null);
});

test('buildRecommendations markiert die schlechtere Variante als Fehler', () => {
    const evaluation = { controlKey: 'control', comparisons: [comparison({ significant: true, lift: -0.2 })] };

    const [recommendation] = buildRecommendations(evaluation, stats, { translate });

    assert.equal(recommendation.variant, 'error');
    assert.match(recommendation.text, /recWorse/);
});

test('buildRecommendations nennt bei duenner Datenlage die fehlende Fallzahl', () => {
    const evaluation = { controlKey: 'control', comparisons: [comparison({ requiredSamplePerVariant: 5000 })] };

    const [recommendation] = buildRecommendations(evaluation, stats, { translate });

    assert.match(recommendation.text, /recNotEnoughData/);
    assert.match(recommendation.text, /"current":1000/);
    assert.match(recommendation.text, /"required":5000/);
});

test('buildRecommendations setzt ein Fragezeichen, wenn keine Fallzahl bekannt ist', () => {
    const evaluation = { controlKey: 'control', comparisons: [comparison({ requiredSamplePerVariant: 0 })] };

    const [recommendation] = buildRecommendations(evaluation, stats, { translate });

    assert.match(recommendation.text, /"required":"\?"/);
});

test('significanceSnippet unterscheidet Richtung statt nur „signifikant"', () => {
    assert.equal(significanceSnippet(comparison({ significant: false })), 'rc-ab-testing.detail.significantNo');
    assert.equal(significanceSnippet(comparison({ significant: true, lift: 0.2 })), 'rc-ab-testing.detail.significantBetter');
    assert.equal(significanceSnippet(comparison({ significant: true, lift: -0.2 })), 'rc-ab-testing.detail.significantWorse');
});

test('segmentVariantResult kennzeichnet die Control als Bezugsgroesse', () => {
    const result = segmentVariantResult({ isControl: true, technicalKey: 'control' }, null, 0);

    assert.deepEqual(result, { variant: 'info', snippet: 'rc-ab-testing.detail.segReference' });
});

test('segmentVariantResult meldet fehlenden Vergleich ohne Snippet', () => {
    const result = segmentVariantResult({ isControl: false, technicalKey: 'variant-b' }, { comparisons: [] }, 100);

    assert.deepEqual(result, { variant: 'info', snippet: null });
});

test('segmentVariantResult prueft die Fallzahl im Segment gegen Control und Variante', () => {
    const evaluation = { comparisons: [comparison({ requiredSamplePerVariant: 100 })] };
    const row = { isControl: false, technicalKey: 'variant-b', assignments: 100 };

    assert.equal(segmentVariantResult(row, evaluation, 100).snippet, 'rc-ab-testing.detail.segNoDifference');
    assert.equal(segmentVariantResult(row, evaluation, 50).snippet, 'rc-ab-testing.detail.segNotEnough');
    assert.equal(segmentVariantResult({ ...row, assignments: 50 }, evaluation, 100).snippet, 'rc-ab-testing.detail.segNotEnough');
});

test('segmentVariantResult faerbt die signifikant schlechtere Variante rot', () => {
    const evaluation = { comparisons: [comparison({ significant: true, lift: -0.3 })] };
    const row = { isControl: false, technicalKey: 'variant-b', assignments: 100 };

    assert.deepEqual(segmentVariantResult(row, evaluation, 100), {
        variant: 'error',
        snippet: 'rc-ab-testing.detail.significantWorse',
    });
});
