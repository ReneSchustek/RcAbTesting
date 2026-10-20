/**
 * Storefront-Brücke für Custom-Event-Tracking. Ohne Framework und mit
 * injizierbaren Abhängigkeiten (fetch, Cookie-Zugriff), damit die Logik in Node
 * ohne DOM testbar ist. Ohne Besucher-Cookie (keine Einwilligung) ist `track`
 * ein No-op. Der Server setzt den Cookie ohne `HttpOnly`, damit dieses Skript ihn
 * lesen kann.
 */

const TRACK_URL = '/rc-ab-testing/track';
const VISITOR_COOKIE = 'rc_ab_visitor_id';

/**
 * Liest den Besucher-Cookie aus einem `document.cookie`-String. Gibt null
 * zurück, wenn der Cookie fehlt oder fehlerhaft kodiert ist. Ungefangen wirft
 * `decodeURIComponent` dann, und der Fehler risse das Skript der Seite mit.
 */
export function readVisitorCookie(cookieString) {
    if (typeof cookieString !== 'string' || cookieString.length === 0) {
        return null;
    }

    const match = cookieString
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(`${VISITOR_COOKIE}=`));

    if (!match) {
        return null;
    }

    const value = match.slice(VISITOR_COOKIE.length + 1);

    if (value.length === 0) {
        return null;
    }

    try {
        return decodeURIComponent(value);
    } catch {
        return null;
    }
}

/**
 * Baut die fetch-Anfrage für ein Track-Event: JSON-Body, als XHR markiert, weil
 * die Route als XHR-Route freigegeben ist.
 */
export function buildTrackRequest(eventType, value = null, meta = {}) {
    return {
        url: TRACK_URL,
        options: {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ eventType, value, meta }),
            credentials: 'same-origin',
        },
    };
}

/**
 * Erzeugt einen Tracker mit injizierten Abhängigkeiten. `track` liefert ein
 * Promise<boolean>: true, sobald der Server geantwortet hat, gleich mit welchem
 * Status; false bei fehlendem Cookie oder Netzwerkfehler. Ob das Ereignis zählt,
 * entscheidet der Server, der aus dem Browser nur `custom.*` annimmt.
 */
export function createTracker({ fetchFn, getCookie }) {
    function track(eventType, value = null, meta = {}) {
        const visitorId = readVisitorCookie(getCookie());
        if (!visitorId) {
            return Promise.resolve(false);
        }

        const { url, options } = buildTrackRequest(eventType, value, meta);

        try {
            return fetchFn(url, options)
                .then(() => true)
                .catch(() => false);
        } catch (error) {
            // Synchroner Wurf, etwa bei fehlendem fetch in alten Browsern: Tracking
            // ist ein Nebenpfad und darf die Seite nie stören.
            return Promise.resolve(false);
        }
    }

    return { track };
}

/**
 * Verdrahtet den Tracker mit dem Browser: legt `window.RcAbTesting.track` für
 * Custom-Events an. `page.viewed` sendet der Browser nicht, das zählt der
 * `PageViewedSubscriber` auf dem Server; ein zusätzlicher Aufruf aus dem Browser
 * würde jeden Seitenaufruf doppelt zählen.
 */
export function init(win) {
    const tracker = createTracker({
        fetchFn: (url, options) => win.fetch(url, options),
        getCookie: () => win.document.cookie,
    });

    win.RcAbTesting = { track: tracker.track };

    return tracker;
}

/**
 * Macht `init` rückgängig: entfernt die globale Track-API wieder. Dient dem
 * `destroy()`-Pfad des Storefront-Plugins, damit bei SPA-artigen Re-Inits keine
 * verwaisten Referenzen zurückbleiben.
 */
export function teardown(win) {
    if (win && Object.prototype.hasOwnProperty.call(win, 'RcAbTesting')) {
        delete win.RcAbTesting;
    }
}
