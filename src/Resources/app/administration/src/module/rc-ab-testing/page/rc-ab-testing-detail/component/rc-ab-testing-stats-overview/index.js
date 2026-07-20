import template from './rc-ab-testing-stats-overview.html.twig';
import { METRIC_CATALOG, orderByPrimary, primaryMetricKey, isMeanDecision } from '../../helper/metric-catalog.js';
import {
    formatCurrency,
    formatInterval,
    formatLift,
    formatMetricValue,
    formatPValue,
    formatRate,
    metricUplift,
    upliftClass,
} from '../../helper/format.js';
import { buildRecommendations, significanceSnippet } from '../../helper/verdict.js';

const { Component } = Shopware;

/**
 * Uebersichts-Tab der Auswertung: Metrik-Karten der fuehrenden Variante,
 * Scorecard, statistische Auswertung und Klartext-Empfehlung.
 *
 * Die Ableitungen liegen in `helper/` (getestet); hier bleibt nur die Darstellung.
 */
Component.register('rc-ab-testing-stats-overview', {
    template,

    props: {
        stats: {
            type: Array,
            required: true,
        },
        evaluation: {
            type: Object,
            required: false,
            default: null,
        },
        decisionMetric: {
            type: String,
            required: true,
        },
        variantOptions: {
            type: Array,
            required: true,
        },
        winnerVariantId: {
            type: String,
            required: false,
            default: null,
        },
    },

    emits: ['update:winnerVariantId'],

    computed: {
        meanDecision() {
            return isMeanDecision(this.decisionMetric);
        },

        primaryMetric() {
            return primaryMetricKey(this.decisionMetric);
        },

        controlRow() {
            return this.stats.find((row) => row.isControl) || null;
        },

        /**
         * Fuehrende Nicht-Control-Variante nach der Entscheidungs-Kennzahl — Basis
         * der Metrik-Karten (bei zwei Varianten schlicht die Testvariante).
         */
        leadingRow() {
            const others = this.stats.filter((row) => !row.isControl);
            if (!others.length) {
                return null;
            }
            const key = this.primaryMetric;

            return others.reduce((best, row) => {
                const bestValue = best[key] ?? -Infinity;
                const rowValue = row[key] ?? -Infinity;

                return rowValue > bestValue ? row : best;
            }, others[0]);
        },

        /**
         * Kennzahl-Karten: Wert der fuehrenden Variante je Kennzahl plus
         * Veraenderung zur Control. Entscheidungs-Kennzahl steht vorn.
         */
        metricCards() {
            const leading = this.leadingRow;
            if (!leading) {
                return [];
            }

            return orderByPrimary(METRIC_CATALOG, this.primaryMetric).map((metric) => {
                const uplift = metricUplift(this.controlRow, leading, metric.key);

                return {
                    key: metric.key,
                    label: this.$tc(metric.labelKey),
                    isPrimary: metric.key === this.primaryMetric,
                    value: formatMetricValue(leading[metric.key], metric.format),
                    uplift: uplift === null ? null : formatLift(uplift),
                    upliftClass: upliftClass(uplift),
                };
            });
        },

        /** Scorecard mit der Entscheidungs-Kennzahl vorn und als solche gekennzeichnet. */
        scorecardColumns() {
            const [primary, ...rest] = orderByPrimary(METRIC_CATALOG, this.primaryMetric);

            return [
                { property: 'technicalKey', label: this.$tc('rc-ab-testing.detail.variantKey') },
                { property: 'assignments', label: this.$tc('rc-ab-testing.detail.assignments') },
                { property: primary.key, label: `${this.$tc(primary.labelKey)} · ${this.$tc('rc-ab-testing.detail.decisionTag')}` },
                ...rest.map((metric) => ({ property: metric.key, label: this.$tc(metric.labelKey) })),
            ];
        },

        comparisonColumns() {
            return [
                { property: 'variantKey', label: this.$tc('rc-ab-testing.detail.variantKey') },
                { property: 'lift', label: this.$tc('rc-ab-testing.detail.lift') },
                { property: 'pValue', label: this.$tc('rc-ab-testing.detail.pValue') },
                { property: 'ci', label: this.$tc('rc-ab-testing.detail.confidenceInterval') },
                { property: 'significant', label: this.$tc('rc-ab-testing.detail.significance') },
                { property: 'requiredSamplePerVariant', label: this.$tc('rc-ab-testing.detail.requiredSample') },
            ];
        },

        recommendations() {
            return buildRecommendations(this.evaluation, this.stats, { translate: this.translate });
        },

        selectedWinner: {
            get() {
                return this.winnerVariantId;
            },
            set(value) {
                this.$emit('update:winnerVariantId', value);
            },
        },
    },

    methods: {
        translate(key, params) {
            return params ? this.$t(key, params) : this.$tc(key);
        },

        formatRate,
        formatCurrency,
        formatLift,
        formatPValue,

        formatInterval(comparison) {
            return formatInterval(comparison, this.meanDecision);
        },

        significanceLabel(comparison) {
            return this.$tc(significanceSnippet(comparison));
        },
    },
});
