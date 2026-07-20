import { encodeVariants, FUNNEL_STAGE_SNIPPETS } from './metric-catalog.js';

/**
 * Render-fertiger Funnel: je Stufe die Varianten-Balken (Anteil an den Zuordnungen
 * als Basis) + Drop-off zur vorherigen Stufe. Bezugsgroesse ist immer die
 * Zuordnungszahl der Variante, nicht die vorherige Stufe — so bleiben die Balken
 * zwischen Varianten vergleichbar.
 */

export function hasFunnelData(funnel) {
    return !!funnel && funnel.variants.some((variant) => variant.assignments > 0);
}

/** Snippet-Schluessel einer Funnel-Stufe; unbekannte Stufe faellt auf ihren Rohwert zurueck. */
export function funnelStageSnippet(stageKey) {
    return FUNNEL_STAGE_SNIPPETS[stageKey] || null;
}

/**
 * @param {object} funnel Server-Antwort: { stages: string[], variants: [{ technicalKey, isControl, assignments, stages: number[] }] }
 * @param {{ stageLabel: Function }} options
 */
export function buildFunnelView(funnel, { stageLabel }) {
    if (!hasFunnelData(funnel)) {
        return null;
    }

    const variants = encodeVariants(funnel.variants).map((variant) => ({
        key: variant.technicalKey,
        isControl: variant.isControl,
        assignments: variant.assignments,
        stages: variant.stages,
        color: variant.color,
    }));

    const stages = funnel.stages.map((stageKey, stageIndex) => ({
        key: stageKey,
        label: stageLabel(stageKey),
        rows: variants.map((variant) => stageRow(variant, stageIndex)),
    }));

    return { variants, stages };
}

function stageRow(variant, stageIndex) {
    const count = variant.stages[stageIndex] || 0;
    const share = variant.assignments > 0 ? count / variant.assignments : 0;
    const previousShare = stageIndex > 0 && variant.assignments > 0
        ? (variant.stages[stageIndex - 1] || 0) / variant.assignments
        : null;

    return {
        key: variant.key,
        isControl: variant.isControl,
        color: variant.color,
        count,
        share,
        drop: previousShare === null ? null : share - previousShare,
    };
}
