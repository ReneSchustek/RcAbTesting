import test from 'node:test';
import assert from 'node:assert/strict';
import { evenWeights, leadingRow, serverErrorMessage } from './detail-logic.js';

test('leadingRow wählt unter den Testvarianten die mit dem höchsten Wert', () => {
    const stats = [
        { technicalKey: 'control', isControl: true, conversionRate: 0.9 },
        { technicalKey: 'a', isControl: false, conversionRate: 0.1 },
        { technicalKey: 'b', isControl: false, conversionRate: 0.2 },
    ];

    assert.equal(leadingRow(stats, 'conversionRate').technicalKey, 'b');
});

test('leadingRow lässt eine Variante ohne Wert nie führen', () => {
    const stats = [
        { technicalKey: 'a', isControl: false, conversionRate: null },
        { technicalKey: 'b', isControl: false, conversionRate: 0 },
    ];

    assert.equal(leadingRow(stats, 'conversionRate').technicalKey, 'b');
});

test('leadingRow liefert null, wenn es nur die Kontrolle gibt', () => {
    assert.equal(leadingRow([{ isControl: true, conversionRate: 0.5 }], 'conversionRate'), null);
});

test('evenWeights ergibt immer genau 100, der Rest geht an die ersten', () => {
    assert.deepEqual(evenWeights(3), [34, 33, 33]);
    assert.deepEqual(evenWeights(2), [50, 50]);
    assert.deepEqual(evenWeights(7).reduce((sum, weight) => sum + weight, 0), 100);
    assert.deepEqual(evenWeights(0), []);
});

test('serverErrorMessage nimmt die Übersetzung des Fehlercodes, sonst die Meldung, sonst den Rückfall', () => {
    const translate = (key) => ({
        'rc-ab-testing.detail.errors.INVALID_TRANSITION': 'Übergang nicht erlaubt',
        fallback: 'Rückfall',
    })[key] ?? key;

    assert.equal(
        serverErrorMessage({ response: { data: { errorCode: 'INVALID_TRANSITION' } } }, translate, 'fallback'),
        'Übergang nicht erlaubt',
    );
    assert.equal(
        serverErrorMessage({ response: { data: { errorCode: 'UNKNOWN', error: 'Meldung' } } }, translate, 'fallback'),
        'Meldung',
    );
    assert.equal(serverErrorMessage(new Error('Netz'), translate, 'fallback'), 'Rückfall');
});
