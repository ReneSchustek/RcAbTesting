import template from './rc-ab-testing-stats-timeseries.html.twig';
import { buildTimeSeriesChart, hasTimeSeriesData } from '../../helper/chart-geometry.js';
import { formatChartValue, shortDate } from '../../helper/format.js';
import { isMeanDecision } from '../../helper/metric-catalog.js';

const { Component } = Shopware;

/**
 * Zeitverlauf-Tab: kumulativer Verlauf der Entscheidungs-Kennzahl je Variante als
 * Inline-SVG. Die Geometrie kommt aus `helper/chart-geometry.js` (getestet).
 *
 * Farbe allein trägt die Zuordnung nicht: Jede Linie hat zusätzlich ein
 * Strichmuster, die Legende nennt Variante und Endwert, und unter dem Chart liegt
 * dieselbe Zahlenreihe als Tabelle für Screenreader.
 */
Component.register('rc-ab-testing-stats-timeseries', {
    template,

    props: {
        timeSeries: {
            type: Array,
            required: false,
            default: null,
        },
        decisionMetric: {
            type: String,
            required: true,
        },
        decisionMetricLabel: {
            type: String,
            required: true,
        },
    },

    computed: {
        // Die Beträge kommen in der Standardwährung des Shops.
        currencyIsoCode() {
            return Shopware.Context.app.systemCurrencyISOCode;
        },

        isReady() {
            return hasTimeSeriesData(this.timeSeries);
        },

        chart() {
            return buildTimeSeriesChart(this.timeSeries, {
                meanDecision: isMeanDecision(this.decisionMetric),
                formatValue: (value) => formatChartValue(value, isMeanDecision(this.decisionMetric), this.currencyIsoCode),
                formatDate: shortDate,
            });
        },

        caption() {
            return this.$t('rc-ab-testing.detail.timeCaption', { metric: this.decisionMetricLabel });
        },

        /** Beschreibt den Chart-Inhalt für Screenreader (statt eines nackten `<svg>`). */
        chartDescription() {
            return this.$t('rc-ab-testing.detail.timeChartDescription', {
                metric: this.decisionMetricLabel,
                variants: this.chart.series.map((serie) => serie.key).join(', '),
            });
        },
    },
});
