import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MAX_LENGTH, slugify, uniqueKey } from './technical-key.js';

test('slugify behält nur Kleinbuchstaben, Ziffern und Bindestriche', () => {
    assert.equal(slugify('  Checkout Test 2027! '), 'checkout-test-2027');
    assert.equal(slugify(''), '');
});

test('slugify kürzt auf die Spaltenlänge ohne Bindestrich am Ende', () => {
    const key = slugify(`${'a'.repeat(63)} b`);
    assert.ok(key.length <= MAX_LENGTH);
    assert.equal(key, 'a'.repeat(63));
});

test('uniqueKey lässt einen freien Schlüssel stehen', () => {
    assert.equal(uniqueKey('checkout', ['andere']), 'checkout');
});

test('uniqueKey hängt eine Zahl an, bis der Schlüssel frei ist', () => {
    assert.equal(uniqueKey('checkout', ['checkout', 'checkout-2']), 'checkout-3');
});

test('uniqueKey bleibt mit Zusatz in der Spaltenlänge', () => {
    const base = 'b'.repeat(MAX_LENGTH);
    const key = uniqueKey(base, [base]);
    assert.equal(key.length, MAX_LENGTH);
    assert.ok(key.endsWith('-2'));
});
