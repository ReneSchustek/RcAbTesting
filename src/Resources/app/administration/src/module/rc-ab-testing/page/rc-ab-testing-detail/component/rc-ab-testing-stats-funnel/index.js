import template from './rc-ab-testing-stats-funnel.html.twig';
import { buildFunnelView, funnelStageSnippet, hasFunnelData } from '../../helper/funnel-view.js';
import { formatPercentPoints, formatRate } from '../../helper/format.js';

const { Component } = Shopware;

/**
 * Funnel-Tab: je Stufe der Anteil der Variante an ihren Zuordnungen plus der
 * Drop-off zur vorherigen Stufe — so wird sichtbar, WO eine Variante Besucher
 * verliert.
 */
Component.register('rc-ab-testing-stats-funnel', {
    template,

    props: {
        funnel: {
            type: Object,
            required: false,
            default: null,
        },
    },

    computed: {
        isReady() {
            return hasFunnelData(this.funnel);
        },

        funnelView() {
            return buildFunnelView(this.funnel, { stageLabel: this.stageLabel });
        },
    },

    methods: {
        formatRate,
        formatPercentPoints,

        stageLabel(stageKey) {
            const snippet = funnelStageSnippet(stageKey);

            return snippet ? this.$tc(snippet) : stageKey;
        },
    },
});
