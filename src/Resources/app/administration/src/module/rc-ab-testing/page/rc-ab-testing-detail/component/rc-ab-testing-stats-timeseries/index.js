import template from './rc-ab-testing-stats-timeseries.html.twig';
import { buildTimeSeriesChart, hasTimeSeriesData } from '../../helper/chart-geometry.js';
import { formatChartValue, shortDate } from '../../helper/format.js';
import { isMeanDecision } from '../../helper/metric-catalog.js';

const { Component } = Shopware;

/**
 * Zeitverlauf-Tab: kumulativer Verlauf der Entscheidungs-Kennzahl je Variante als
 * Inline-SVG. Die Geometrie kommt aus `helper/chart-geometry.js` (getestet).
 *
 * Barrierefreiheit: die Linien tragen zusaetzlich zur Farbe ein Strichmuster und
 * eine Wert-Beschriftung am Ende; unter dem Chart liegt dieselbe Zahlenreihe als
 * Tabelle fuer Screenreader.
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
        isReady() {
            return hasTimeSeriesData(this.timeSeries);
        },

        chart() {
            return buildTimeSeriesChart(this.timeSeries, {
                meanDecision: isMeanDecision(this.decisionMetric),
                formatValue: (value) => formatChartValue(value, isMeanDecision(this.decisionMetric)),
                formatDate: shortDate,
            });
        },

        caption() {
            return this.$t('rc-ab-testing.detail.timeCaption', { metric: this.decisionMetricLabel });
        },

        /** Beschreibt den Chart-Inhalt fuer Screenreader (statt eines nackten `<svg>`). */
        chartDescription() {
            return this.$t('rc-ab-testing.detail.timeChartDescription', {
                metric: this.decisionMetricLabel,
                variants: this.chart.series.map((serie) => serie.key).join(', '),
            });
        },
    },
});
