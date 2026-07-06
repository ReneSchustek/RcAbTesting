/**
 * Storefront-Brücke für Custom-Event-Tracking. Bewusst framework-frei und mit
 * injizierbaren Abhängigkeiten (fetch, Cookie-Zugriff), damit die Logik in Node
 * ohne DOM testbar ist. Ohne Besucher-Cookie (Consent abgelehnt) ist `track`
 * ein No-op.
 */

const TRACK_URL = '/rc-ab-testing/track';
const VISITOR_COOKIE = 'rc_ab_visitor_id';

/**
 * Liest den Besucher-Cookie aus einem `document.cookie`-String. Gibt null
 * zurück, wenn der Cookie fehlt.
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

    return value.length > 0 ? decodeURIComponent(value) : null;
}

/**
 * Baut die fetch-Anfrage für ein Track-Event. JSON-Body, als XHR markiert.
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
 * Promise<boolean> — true bei erfolgreichem Versand, false bei fehlendem Cookie
 * oder Netzwerkfehler (Tracking ist Nebenpfad, darf nie werfen).
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
            // Synchroner Wurf (z.B. fehlendes fetch in alten Browsern): Tracking
            // ist Nebenpfad und darf nie werfen — als No-op behandeln.
            return Promise.resolve(false);
        }
    }

    return { track };
}

/**
 * Verdrahtet den Tracker mit dem Browser: legt `window.RcAbTesting.track` für
 * Custom-Events an. `page.viewed` wird bewusst NICHT clientseitig gefeuert — das
 * übernimmt der PageViewedSubscriber serverseitig; ein zusätzlicher Client-Hit
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
