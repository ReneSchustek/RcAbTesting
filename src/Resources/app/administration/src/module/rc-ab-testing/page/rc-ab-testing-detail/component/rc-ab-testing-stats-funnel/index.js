import template from './rc-ab-testing-stats-funnel.html.twig';
import { buildFunnelView, funnelStageSnippet, hasFunnelData } from '../../helper/funnel-view.js';
import { formatPercentPoints, formatRate } from '../../helper/format.js';

const { Component } = Shopware;

/**
 * Funnel-Tab: je Stufe der Anteil der Variante an ihren Zuordnungen und die
 * Veränderung zur vorherigen Stufe. So wird sichtbar, an welcher Stelle eine
 * Variante Besucher verliert.
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

            return snippet ? this.$t(snippet) : stageKey;
        },
    },
});
