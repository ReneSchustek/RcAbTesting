import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readVisitorCookie, buildTrackRequest, createTracker, init, teardown } from './tracking.js';

test('readVisitorCookie liefert die ID aus dem Cookie-String', () => {
    assert.equal(readVisitorCookie('foo=1; rc_ab_visitor_id=abc123; bar=2'), 'abc123');
});

test('readVisitorCookie liefert null ohne Cookie', () => {
    assert.equal(readVisitorCookie('foo=1; bar=2'), null);
    assert.equal(readVisitorCookie(''), null);
    assert.equal(readVisitorCookie(undefined), null);
});

test('buildTrackRequest baut JSON-Payload mit XHR-Header', () => {
    const { url, options } = buildTrackRequest('custom.cta_clicked', 2.5, { cta: 'header' });

    assert.equal(url, '/rc-ab-testing/track');
    assert.equal(options.method, 'POST');
    assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.deepEqual(JSON.parse(options.body), {
        eventType: 'custom.cta_clicked',
        value: 2.5,
        meta: { cta: 'header' },
    });
});

test('track sendet bei vorhandenem Cookie', async () => {
    const calls = [];
    const tracker = createTracker({
        fetchFn: (url, options) => {
            calls.push({ url, options });
            return Promise.resolve({ ok: true });
        },
        getCookie: () => 'rc_ab_visitor_id=visitor-1',
    });

    const result = await tracker.track('page.viewed');

    assert.equal(result, true);
    assert.equal(calls.length, 1);
    assert.equal(JSON.parse(calls[0].options.body).eventType, 'page.viewed');
});

test('track ist No-op ohne Cookie', async () => {
    let fetched = false;
    const tracker = createTracker({
        fetchFn: () => {
            fetched = true;
            return Promise.resolve({ ok: true });
        },
        getCookie: () => '',
    });

    const result = await tracker.track('page.viewed');

    assert.equal(result, false);
    assert.equal(fetched, false);
});

test('track schluckt Netzwerkfehler', async () => {
    const tracker = createTracker({
        fetchFn: () => Promise.reject(new Error('offline')),
        getCookie: () => 'rc_ab_visitor_id=visitor-1',
    });

    assert.equal(await tracker.track('page.viewed'), false);
});

test('track wirft nicht bei synchron fehlendem fetch', async () => {
    const tracker = createTracker({
        fetchFn: () => { throw new TypeError('fetch is not a function'); },
        getCookie: () => 'rc_ab_visitor_id=visitor-1',
    });

    assert.equal(await tracker.track('page.viewed'), false);
});

test('init legt window.RcAbTesting an, teardown räumt es ab', () => {
    const win = { fetch: () => Promise.resolve({ ok: true }), document: { cookie: '' } };

    init(win);
    assert.equal(typeof win.RcAbTesting.track, 'function');

    teardown(win);
    assert.equal(Object.prototype.hasOwnProperty.call(win, 'RcAbTesting'), false);
});
