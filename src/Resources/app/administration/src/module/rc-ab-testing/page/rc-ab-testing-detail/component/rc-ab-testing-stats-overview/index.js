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
import { leadingRow } from '../../helper/detail-logic.js';
import { buildRecommendations, significanceSnippet } from '../../helper/verdict.js';

const { Component } = Shopware;

/**
 * Übersichts-Tab der Auswertung: Metrik-Karten der führenden Variante,
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
        // Die Beträge kommen in der Standardwährung des Shops.
        currencyIsoCode() {
            return Shopware.Context.app.systemCurrencyISOCode;
        },

        meanDecision() {
            return isMeanDecision(this.decisionMetric);
        },

        primaryMetric() {
            return primaryMetricKey(this.decisionMetric);
        },

        controlRow() {
            return this.stats.find((row) => row.isControl) || null;
        },

        // Führende Nicht-Control-Variante nach der Entscheidungs-Kennzahl, Basis der Metrik-Karten.
        leadingRow() {
            return leadingRow(this.stats, this.primaryMetric);
        },

        /**
         * Kennzahl-Karten: Wert der führenden Variante je Kennzahl plus
         * Veränderung zur Control. Entscheidungs-Kennzahl steht vorn.
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
                    label: this.$t(metric.labelKey),
                    isPrimary: metric.key === this.primaryMetric,
                    value: formatMetricValue(leading[metric.key], metric.format, this.currencyIsoCode),
                    uplift: uplift === null ? null : formatLift(uplift),
                    upliftClass: upliftClass(uplift),
                };
            });
        },

        /** Scorecard mit der Entscheidungs-Kennzahl vorn und als solche gekennzeichnet. */
        scorecardColumns() {
            const [primary, ...rest] = orderByPrimary(METRIC_CATALOG, this.primaryMetric);

            return [
                { property: 'technicalKey', label: this.$t('rc-ab-testing.detail.variantKey') },
                { property: 'assignments', label: this.$t('rc-ab-testing.detail.assignments') },
                { property: primary.key, label: `${this.$t(primary.labelKey)} · ${this.$t('rc-ab-testing.detail.decisionTag')}` },
                ...rest.map((metric) => ({ property: metric.key, label: this.$t(metric.labelKey) })),
            ];
        },

        comparisonColumns() {
            return [
                { property: 'variantKey', label: this.$t('rc-ab-testing.detail.variantKey') },
                { property: 'lift', label: this.$t('rc-ab-testing.detail.lift') },
                { property: 'pValue', label: this.$t('rc-ab-testing.detail.pValue') },
                { property: 'ci', label: this.$t('rc-ab-testing.detail.confidenceInterval') },
                { property: 'significant', label: this.$t('rc-ab-testing.detail.significance') },
                { property: 'requiredSamplePerVariant', label: this.$t('rc-ab-testing.detail.requiredSample') },
            ];
        },

        recommendations() {
            return buildRecommendations(this.evaluation, this.stats, { translate: this.translate });
        },

        /**
         * Die Gewinner-Auswahl gehört der Detailseite (`v-model:winner-variant-id`),
         * die sie beim Speichern und beim Beenden mitschickt.
         */
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
            return params ? this.$t(key, params) : this.$t(key);
        },

        formatRate,
        formatLift,
        formatPValue,

        formatCurrency(value) {
            return formatCurrency(value, this.currencyIsoCode);
        },

        formatInterval(comparison) {
            return formatInterval(comparison, this.meanDecision, this.currencyIsoCode);
        },

        significanceLabel(comparison) {
            return this.$t(significanceSnippet(comparison));
        },
    },
});
