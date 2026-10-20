/**
 * Eine Quelle der Wahrheit für Kennzahlen und Varianten-Kodierung der
 * Auswertung. Scorecard, Metrik-Karten, Zeitverlauf und Funnel lesen alle von hier;
 * eigene Listen je Ansicht müssten bei jeder Änderung an vier Stellen nachgezogen
 * werden.
 */

// Entscheidungs-Kennzahlen (Spiegel von AbDecisionMetric): nur Kennzahlen, die
// serverseitig sauber auf Signifikanz geprüft werden. Der durchschnittliche
// Bestellwert wird nur angezeigt; für ihn gibt es keinen passenden Test.
export const DECISION_METRIC_CONVERSION = 'conversion_rate';
export const DECISION_METRIC_REVENUE_PER_VISITOR = 'revenue_per_visitor';

/** Alle in Scorecard und Metrik-Karten gezeigten Kennzahlen, in Basis-Reihenfolge. */
export const METRIC_CATALOG = [
    { key: 'rate', labelKey: 'rc-ab-testing.detail.rate', format: 'rate' },
    { key: 'revenuePerVisitor', labelKey: 'rc-ab-testing.detail.revenuePerVisitor', format: 'currency' },
    { key: 'aov', labelKey: 'rc-ab-testing.detail.aov', format: 'currency' },
    { key: 'revenue', labelKey: 'rc-ab-testing.detail.revenue', format: 'currency' },
];

/** Snippet je Funnel-Stufe (Event-Typ des Servers → Klartext). */
export const FUNNEL_STAGE_SNIPPETS = {
    'page.viewed': 'rc-ab-testing.detail.funnelStageViewed',
    'product.added_to_cart': 'rc-ab-testing.detail.funnelStageCart',
    'checkout.started': 'rc-ab-testing.detail.funnelStageCheckout',
    'checkout.order_placed': 'rc-ab-testing.detail.funnelStagePurchase',
};

/** Die Control bleibt neutral-grau, damit die Testvarianten sich davon abheben. */
export const CONTROL_COLOR = '#8595a4';

/**
 * Alle Farben erreichen gegen Weiß mindestens 3:1, die Anforderung an
 * bedeutungstragende Grafik (WCAG 1.4.11); die Control-Farbe liegt bei 3,1:1.
 * Hellere Töne wie das Shopware-Blau #189eff (2,85:1) scheiden deshalb aus.
 */
const VARIANT_PALETTE = ['#0d6ebf', '#6d28d9', '#b45309', '#0f766e'];

/**
 * Strichmuster je Testvariante (SVG `stroke-dasharray`). Barrierefreiheit (BFSG):
 * Farbe darf nicht der einzige Träger der Information sein — wer Farben nicht
 * unterscheiden kann, erkennt die Linie am Muster.
 */
const VARIANT_DASHES = ['6 4', '2 3', '10 3 2 3', '1 4'];

/** Durchgezogen: die Control ist die Bezugslinie. */
export const CONTROL_DASH = 'none';

/**
 * Scorecard-Spalte, auf der die Entscheidung beruht. Nur diese Kennzahl wird
 * serverseitig auf Signifikanz geprüft.
 */
export function primaryMetricKey(decisionMetric) {
    return decisionMetric === DECISION_METRIC_REVENUE_PER_VISITOR ? 'revenuePerVisitor' : 'rate';
}

export function isMeanDecision(decisionMetric) {
    return decisionMetric === DECISION_METRIC_REVENUE_PER_VISITOR;
}

/** Entscheidungs-Kennzahl nach vorn, übrige Kennzahlen in Basis-Reihenfolge dahinter. */
export function orderByPrimary(catalog, primaryKey) {
    return [
        ...catalog.filter((metric) => metric.key === primaryKey),
        ...catalog.filter((metric) => metric.key !== primaryKey),
    ];
}

/**
 * Farbe und Strichmuster einer Variante. `variantIndex` zählt nur die
 * Testvarianten (die Control hat keinen Palettenplatz).
 */
export function variantEncoding(isControl, variantIndex) {
    if (isControl) {
        return { color: CONTROL_COLOR, dash: CONTROL_DASH };
    }

    return {
        color: VARIANT_PALETTE[variantIndex % VARIANT_PALETTE.length],
        dash: VARIANT_DASHES[variantIndex % VARIANT_DASHES.length],
    };
}

/**
 * Reichert eine Varianten-Liste (Server-Reihenfolge) um Farbe und Strichmuster an.
 * Die Control zählt nicht mit, damit die erste Testvariante immer dieselbe Farbe
 * bekommt — unabhängig davon, an welcher Stelle die Control steht.
 */
export function encodeVariants(variants) {
    let variantIndex = 0;

    return variants.map((variant) => {
        const encoding = variantEncoding(variant.isControl, variantIndex);
        if (!variant.isControl) {
            variantIndex += 1;
        }

        return { ...variant, ...encoding };
    });
}
