import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildFunnelView, funnelStageSnippet, hasFunnelData } from './funnel-view.js';
import { CONTROL_COLOR } from './metric-catalog.js';

const stageLabel = (key) => `L:${key}`;

const funnel = {
    stages: ['page.viewed', 'product.added_to_cart', 'checkout.order_placed'],
    variants: [
        { technicalKey: 'control', isControl: true, assignments: 200, stages: [200, 60, 20] },
        { technicalKey: 'variant-b', isControl: false, assignments: 100, stages: [100, 41, 15] },
    ],
};

test('hasFunnelData verlangt mindestens eine Variante mit Zuordnungen', () => {
    assert.equal(hasFunnelData(null), false);
    assert.equal(hasFunnelData({ variants: [] }), false);
    assert.equal(hasFunnelData({ variants: [{ assignments: 0 }] }), false);
    assert.equal(hasFunnelData({ variants: [{ assignments: 1 }] }), true);
});

test('funnelStageSnippet uebersetzt bekannte Stufen und meldet unbekannte', () => {
    assert.equal(funnelStageSnippet('page.viewed'), 'rc-ab-testing.detail.funnelStageViewed');
    assert.equal(funnelStageSnippet('etwas.anderes'), null);
});

test('buildFunnelView liefert ohne Daten null', () => {
    assert.equal(buildFunnelView({ variants: [{ assignments: 0 }] }, { stageLabel }), null);
});

test('buildFunnelView bezieht jede Stufe auf die Zuordnungen der Variante', () => {
    const view = buildFunnelView(funnel, { stageLabel });
    const [viewed, cart] = view.stages;

    assert.equal(viewed.label, 'L:page.viewed');
    // Basis sind die Zuordnungen, nicht die vorherige Stufe — sonst waeren die
    // Varianten untereinander nicht vergleichbar.
    assert.equal(cart.rows[0].share, 0.3);
    assert.equal(cart.rows[1].share, 0.41);
});

test('buildFunnelView weist den Drop-off gegenueber der vorherigen Stufe aus', () => {
    const view = buildFunnelView(funnel, { stageLabel });

    assert.equal(view.stages[0].rows[0].drop, null, 'Die erste Stufe hat keinen Vorgaenger.');
    assert.equal(view.stages[1].rows[0].drop.toFixed(2), '-0.70');
    assert.equal(view.stages[1].rows[1].drop.toFixed(2), '-0.59');
});

test('buildFunnelView faerbt die Control neutral und die Testvariante aus der Palette', () => {
    const view = buildFunnelView(funnel, { stageLabel });

    assert.equal(view.variants[0].color, CONTROL_COLOR);
    assert.notEqual(view.variants[1].color, CONTROL_COLOR);
    assert.equal(view.stages[0].rows[1].color, view.variants[1].color);
});

test('buildFunnelView vertraegt fehlende Stufenwerte und leere Zuordnungen', () => {
    const sparse = {
        stages: ['page.viewed', 'checkout.order_placed'],
        variants: [
            { technicalKey: 'control', isControl: true, assignments: 10, stages: [10] },
            { technicalKey: 'variant-b', isControl: false, assignments: 0, stages: [] },
        ],
    };

    const view = buildFunnelView(sparse, { stageLabel });

    assert.equal(view.stages[1].rows[0].count, 0);
    assert.equal(view.stages[1].rows[1].share, 0, 'Ohne Zuordnungen keine Division.');
});
