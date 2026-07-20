import template from './rc-ab-testing-stats-segments.html.twig';
import { formatCurrency, formatRate } from '../../helper/format.js';
import { segmentVariantResult } from '../../helper/verdict.js';

const { Component } = Shopware;

/**
 * Segmente-Tab: je Dimension (Geraet, Verkaufskanal) eine Tabelle mit dem
 * Ergebnis je Segment. Eine Dimension wird nur gezeigt, wenn sie mindestens zwei
 * Auspraegungen hat — eine einzige entspraeche dem Gesamtbild.
 */
Component.register('rc-ab-testing-stats-segments', {
    template,

    props: {
        segments: {
            type: Object,
            required: false,
            default: null,
        },
    },

    computed: {
        dimensions() {
            return [
                { key: 'device', title: this.$tc('rc-ab-testing.detail.segmentByDevice') },
                { key: 'salesChannel', title: this.$tc('rc-ab-testing.detail.segmentBySalesChannel') },
            ];
        },

        segmentGroups() {
            if (!this.segments) {
                return [];
            }

            return this.dimensions.reduce((groups, dimension) => {
                const raw = this.segments[dimension.key] || [];
                if (raw.length >= 2) {
                    groups.push({
                        key: dimension.key,
                        title: dimension.title,
                        segments: raw.map((segment) => this.buildSegmentView(dimension.key, segment)),
                    });
                }

                return groups;
            }, []);
        },

        hasSegmentData() {
            return this.segmentGroups.length > 0;
        },
    },

    methods: {
        /** Server-Segment zu einer render-fertigen Ansicht (Label, Werte, Ergebnis-Badge). */
        buildSegmentView(dimensionKey, segment) {
            const controlAssignments = (segment.variants.find((row) => row.isControl) || {}).assignments || 0;

            return {
                label: this.segmentLabel(dimensionKey, segment),
                size: segment.size,
                variants: segment.variants.map((row) => {
                    const result = segmentVariantResult(row, segment.evaluation, controlAssignments);

                    return {
                        technicalKey: row.technicalKey,
                        isControl: row.isControl,
                        assignments: row.assignments,
                        rate: formatRate(row.rate),
                        revenuePerVisitor: formatCurrency(row.revenuePerVisitor),
                        result: {
                            variant: result.variant,
                            text: result.snippet === null ? '–' : this.$tc(result.snippet),
                        },
                    };
                }),
            };
        },

        segmentLabel(dimensionKey, segment) {
            if (dimensionKey === 'salesChannel') {
                return segment.name || segment.segment;
            }
            const key = `rc-ab-testing.detail.device.${segment.segment}`;
            const translated = this.$tc(key);

            return translated === key ? segment.segment : translated;
        },
    },
});
