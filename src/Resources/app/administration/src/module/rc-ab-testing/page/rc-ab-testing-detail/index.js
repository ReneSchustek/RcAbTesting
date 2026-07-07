import template from './rc-ab-testing-detail.html.twig';
import './rc-ab-testing-detail.scss';

const { Component, Mixin, Data: { Criteria } } = Shopware;

// Entscheidungs-Kennzahlen (Spiegel von AbDecisionMetric): nur Kennzahlen, die
// serverseitig sauber auf Signifikanz geprueft werden. Der durchschnittliche
// Bestellwert bleibt bewusst reine Anzeige-Kennzahl, keine Entscheidungsgrundlage.
const DECISION_METRIC_CONVERSION = 'conversion_rate';
const DECISION_METRIC_REVENUE_PER_VISITOR = 'revenue_per_visitor';

Component.register('rc-ab-testing-detail', {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        experimentId: {
            type: String,
            required: true,
        },
    },

    data() {
        return {
            experiment: null,
            stats: null,
            evaluation: null,
            segments: null,
            timeSeries: null,
            funnel: null,
            winnerVariantId: null,
            deletedVariantIds: [],
            variantConfigDrafts: {},
            isLoading: false,
            isSaving: false,
            // Aktiver Auswertungs-Tab (reiner Ansichtswechsel in der Karte).
            activeStatsTab: 'overview',
            // Progressive Disclosure: technische Felder (Schluessel, Signifikanz,
            // Targeting, Scheduling, Gewichte, Roh-JSON) erst auf Wunsch zeigen —
            // die Standardansicht bleibt fuer Nicht-Techniker verstaendlich.
            showAdvanced: false,
        };
    },

    computed: {
        // Der HTTP-Client wird bewusst NICHT injiziert: Shopware 6.7 stellt kein
        // `httpClient`-Provide bereit, ein `inject: ['httpClient']` liefert dort
        // `undefined` und jeder `.post()/.get()`-Aufruf (Lifecycle, Stats) wirft
        // still client-seitig, ohne dass je ein Request rausgeht. Der init-Container
        // hält die funktionierende Axios-Instanz (spricht bereits `/api` an).
        httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        repository() {
            return this.repositoryFactory.create('rc_ab_experiment');
        },

        variantRepository() {
            return this.repositoryFactory.create('rc_ab_variant');
        },

        // No-Code-Variante (CMS-Seite) zuerst und empfohlen; die uebrigen Typen
        // sind fuer Entwickler und im Alltag selten.
        testTypeOptions() {
            return [
                { value: 'cms_page', label: this.$tc('rc-ab-testing.detail.testTypeCms') },
                { value: 'twig', label: this.$tc('rc-ab-testing.detail.testTypeTwig') },
                { value: 'theme', label: this.$tc('rc-ab-testing.detail.testTypeTheme') },
                { value: 'feature_flag', label: this.$tc('rc-ab-testing.detail.testTypeFeatureFlag') },
            ];
        },

        // Erklaert den gewaehlten Test-Typ in Klartext (unter dem Auswahlfeld).
        testTypeHint() {
            const hints = {
                cms_page: 'rc-ab-testing.detail.testTypeCmsHint',
                twig: 'rc-ab-testing.detail.testTypeDevHint',
                theme: 'rc-ab-testing.detail.testTypeDevHint',
                feature_flag: 'rc-ab-testing.detail.testTypeDevHint',
            };
            const key = this.experiment ? hints[this.experiment.testType] : null;

            return key ? this.$tc(key) : '';
        },

        // Beim CMS-Seiten-Test waehlt der Betreuer je Variante eine CMS-Seite per
        // Dropdown statt Roh-JSON — die Config traegt dann nur `cmsPageId`.
        isCmsTest() {
            return this.experiment ? this.experiment.testType === 'cms_page' : false;
        },

        // Uebersetzt die Statistik je Variante in einen Klartext-Satz mit
        // Handlungsempfehlung — fuer Nicht-Techniker, die keine p-Values lesen.
        // Nutzt ausschliesslich die bereits geladenen stats/evaluation-Daten.
        plainRecommendations() {
            if (!this.evaluation || !this.stats) {
                return [];
            }

            const assignmentsByKey = {};
            this.stats.forEach((row) => { assignmentsByKey[row.technicalKey] = row.assignments; });

            return (this.evaluation.comparisons || []).map((comparison) => {
                const variantAssignments = assignmentsByKey[comparison.variantKey] || 0;
                const required = comparison.requiredSamplePerVariant || 0;
                const enoughData = this.enoughDataForComparison(comparison);

                if (comparison.significant) {
                    const worse = comparison.lift !== null && comparison.lift < 0;
                    return {
                        key: comparison.variantKey,
                        variant: worse ? 'error' : 'success',
                        text: this.$t(
                            worse ? 'rc-ab-testing.detail.recWorse' : 'rc-ab-testing.detail.recBetter',
                            { variant: comparison.variantKey },
                        ),
                    };
                }

                if (enoughData) {
                    return {
                        key: comparison.variantKey,
                        variant: 'info',
                        text: this.$t('rc-ab-testing.detail.recNoDifference', { variant: comparison.variantKey }),
                    };
                }

                return {
                    key: comparison.variantKey,
                    variant: 'info',
                    text: this.$t('rc-ab-testing.detail.recNotEnoughData', {
                        variant: comparison.variantKey,
                        current: variantAssignments,
                        required: required || '?',
                    }),
                };
            });
        },

        // Waehlbare Entscheidungs-Kennzahlen — Umsatz je Besucher als Produkt-Default.
        decisionMetricOptions() {
            return [
                { value: DECISION_METRIC_REVENUE_PER_VISITOR, label: this.$tc('rc-ab-testing.detail.decisionRevenuePerVisitor') },
                { value: DECISION_METRIC_CONVERSION, label: this.$tc('rc-ab-testing.detail.decisionConversionRate') },
            ];
        },

        // Die tatsaechlich zugrunde liegende Entscheidungs-Kennzahl: nach dem Laden
        // der Auswertung ist der Server maßgeblich, davor die Experiment-Auswahl.
        currentDecisionMetric() {
            if (this.evaluation && this.evaluation.decisionMetric) {
                return this.evaluation.decisionMetric;
            }

            return (this.experiment && this.experiment.decisionMetric) || DECISION_METRIC_REVENUE_PER_VISITOR;
        },

        isMeanDecision() {
            return this.currentDecisionMetric === DECISION_METRIC_REVENUE_PER_VISITOR;
        },

        decisionMetricLabel() {
            const option = this.decisionMetricOptions.find((entry) => entry.value === this.currentDecisionMetric);

            return option ? option.label : '';
        },

        // Scorecard-Spaltenschluessel der Entscheidungs-Kennzahl (fuer Hervorhebung
        // und Metrik-Karten).
        primaryMetricKey() {
            return this.isMeanDecision ? 'revenuePerVisitor' : 'rate';
        },

        // Detail-Tabs der Auswertung. Nur „Uebersicht" ist in AB32 gefuellt; die
        // uebrigen Tabs werden in AB31 (Zeitverlauf, Segmente, Funnel) bestueckt.
        statsTabs() {
            return [
                { key: 'overview', label: this.$tc('rc-ab-testing.detail.tabOverview'), available: true },
                { key: 'segments', label: this.$tc('rc-ab-testing.detail.tabSegments'), available: true },
                { key: 'time', label: this.$tc('rc-ab-testing.detail.tabTime'), available: true },
                { key: 'funnel', label: this.$tc('rc-ab-testing.detail.tabFunnel'), available: true },
            ];
        },

        // Segment-Gruppen (Gerät, Verkaufskanal) fuer den Segmente-Tab, gerendert aus
        // der Server-Antwort. Eine Dimension wird nur gezeigt, wenn sie mindestens
        // zwei Segmente hat — eine einzige Auspraegung entspraeche dem Gesamtbild.
        segmentGroups() {
            if (!this.segments) {
                return [];
            }
            const groups = [];
            [
                { key: 'device', title: this.$tc('rc-ab-testing.detail.segmentByDevice') },
                { key: 'salesChannel', title: this.$tc('rc-ab-testing.detail.segmentBySalesChannel') },
            ].forEach((dimension) => {
                const raw = this.segments[dimension.key] || [];
                if (raw.length < 2) {
                    return;
                }
                groups.push({
                    key: dimension.key,
                    title: dimension.title,
                    segments: raw.map((segment) => this.buildSegmentView(dimension.key, segment)),
                });
            });

            return groups;
        },

        hasSegmentData() {
            return this.segmentGroups.length > 0;
        },

        timeSeriesReady() {
            return !!this.timeSeries && this.timeSeries.some((serie) => serie.points.length > 0);
        },

        // Geometrie des Zeitverlauf-Charts (Inline-SVG): je Variante der kumulative
        // Verlauf der Entscheidungs-Kennzahl (Umsatz/Besucher oder Conversion-Rate).
        // Kumulativ, weil ein frueher Ausschlag bei wenigen Besuchern taeuscht und
        // sich der Wert erst mit der Zeit stabilisiert.
        timeSeriesChart() {
            if (!this.timeSeriesReady) {
                return null;
            }
            const width = 640;
            const height = 260;
            const padLeft = 56;
            const padRight = 16;
            const padTop = 14;
            const padBottom = 28;

            const dates = [...new Set(this.timeSeries.flatMap((serie) => serie.points.map((point) => point.date)))].sort();
            const dateIndex = {};
            dates.forEach((date, index) => { dateIndex[date] = index; });
            const xOf = (index) => (dates.length <= 1
                ? padLeft + (width - padLeft - padRight) / 2
                : padLeft + (width - padLeft - padRight) * (index / (dates.length - 1)));

            const isMean = this.isMeanDecision;
            const palette = ['#189eff', '#8b5cf6', '#e67e22', '#16a085'];
            let variantColorIndex = 0;
            const series = this.timeSeries.map((serie) => {
                let assignments = 0;
                let conversions = 0;
                let revenue = 0;
                const points = serie.points.map((point) => {
                    assignments += point.assignments;
                    conversions += point.conversions;
                    revenue += point.revenue;
                    const value = assignments > 0 ? (isMean ? revenue / assignments : conversions / assignments) : 0;
                    return { x: xOf(dateIndex[point.date]), value };
                });
                const color = serie.isControl ? '#8595a4' : palette[(variantColorIndex++) % palette.length];
                return { key: serie.technicalKey, isControl: serie.isControl, color, points };
            });

            const values = series.flatMap((serie) => serie.points.map((point) => point.value));
            let min = Math.min(...values);
            let max = Math.max(...values);
            if (min === max) {
                max = min + (min === 0 ? 1 : Math.abs(min) * 0.2);
            }
            const margin = (max - min) * 0.1;
            min = Math.max(0, min - margin);
            max += margin;
            const yOf = (value) => padTop + (height - padTop - padBottom) * (1 - (value - min) / (max - min));

            series.forEach((serie) => {
                serie.line = serie.points.map((point) => `${point.x.toFixed(1)},${yOf(point.value).toFixed(1)}`).join(' ');
                const last = serie.points[serie.points.length - 1];
                serie.end = { x: last.x.toFixed(1), y: yOf(last.value).toFixed(1) };
            });

            const yTicks = [];
            const steps = 4;
            for (let step = 0; step <= steps; step += 1) {
                const value = min + (max - min) * (step / steps);
                yTicks.push({ y: yOf(value).toFixed(1), x1: padLeft, x2: width - padRight, label: this.formatChartValue(value) });
            }

            const xTicks = [];
            const every = dates.length > 8 ? Math.ceil(dates.length / 6) : 1;
            dates.forEach((date, index) => {
                if (index % every === 0 || index === dates.length - 1) {
                    xTicks.push({ x: xOf(index).toFixed(1), label: this.shortDate(date) });
                }
            });

            return { width, height, bottom: height - padBottom, series, yTicks, xTicks };
        },

        funnelReady() {
            return !!this.funnel && this.funnel.variants.some((variant) => variant.assignments > 0);
        },

        // Render-fertiger Funnel: je Stufe die Varianten-Balken (Anteil an den
        // Zuordnungen als Basis) + Drop-off zur vorherigen Stufe.
        funnelView() {
            if (!this.funnelReady) {
                return null;
            }
            const palette = ['#189eff', '#8b5cf6', '#e67e22', '#16a085'];
            let variantColorIndex = 0;
            const variants = this.funnel.variants.map((variant) => ({
                key: variant.technicalKey,
                isControl: variant.isControl,
                assignments: variant.assignments,
                stages: variant.stages,
                color: variant.isControl ? '#8595a4' : palette[(variantColorIndex++) % palette.length],
            }));

            const stages = this.funnel.stages.map((stageKey, stageIndex) => ({
                key: stageKey,
                label: this.funnelStageLabel(stageKey),
                rows: variants.map((variant) => {
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
                }),
            }));

            return { variants, stages };
        },

        controlStatsRow() {
            return this.stats ? (this.stats.find((row) => row.isControl) || null) : null;
        },

        // Fuehrende Nicht-Control-Variante nach der Entscheidungs-Kennzahl — Basis
        // der Metrik-Karten (bei zwei Varianten schlicht die Testvariante).
        leadingStatsRow() {
            if (!this.stats) {
                return null;
            }
            const others = this.stats.filter((row) => !row.isControl);
            if (!others.length) {
                return null;
            }
            const key = this.primaryMetricKey;

            return others.reduce((best, row) => {
                const bestValue = best[key] === null || best[key] === undefined ? -Infinity : best[key];
                const rowValue = row[key] === null || row[key] === undefined ? -Infinity : row[key];

                return rowValue > bestValue ? row : best;
            }, others[0]);
        },

        // Kennzahl-Karten der Uebersicht: Wert der fuehrenden Variante je Kennzahl
        // plus Veraenderung zur Control. Entscheidungs-Kennzahl steht vorn und wird
        // hervorgehoben.
        metricCards() {
            const leading = this.leadingStatsRow;
            if (!leading) {
                return [];
            }
            const control = this.controlStatsRow;
            const catalog = [
                { key: 'rate', label: this.$tc('rc-ab-testing.detail.rate'), format: 'rate' },
                { key: 'revenuePerVisitor', label: this.$tc('rc-ab-testing.detail.revenuePerVisitor'), format: 'currency' },
                { key: 'aov', label: this.$tc('rc-ab-testing.detail.aov'), format: 'currency' },
                { key: 'revenue', label: this.$tc('rc-ab-testing.detail.revenue'), format: 'currency' },
            ];
            const primary = this.primaryMetricKey;
            const ordered = catalog.filter((metric) => metric.key === primary)
                .concat(catalog.filter((metric) => metric.key !== primary));

            return ordered.map((metric) => {
                const uplift = this.metricUplift(control, leading, metric.key);

                return {
                    key: metric.key,
                    label: metric.label,
                    isPrimary: metric.key === primary,
                    value: this.formatMetricValue(leading[metric.key], metric.format),
                    uplift: uplift === null ? null : this.formatLift(uplift),
                    upliftClass: this.upliftClass(uplift),
                };
            });
        },

        // Ein-Satz-Verdikt aus der serverseitigen Auswertung, bezogen auf die
        // Entscheidungs-Kennzahl. Ampelfarbe ueber die sw-alert-Variante.
        verdict() {
            if (!this.evaluation) {
                return null;
            }
            const metric = this.decisionMetricLabel;
            const comparisons = this.evaluation.comparisons || [];

            if (this.evaluation.winnerKey) {
                const winner = comparisons.find((entry) => entry.variantKey === this.evaluation.winnerKey);

                return {
                    variant: 'success',
                    text: this.$t('rc-ab-testing.detail.verdictWinner', {
                        variant: this.evaluation.winnerKey,
                        lift: this.formatLift(winner ? winner.lift : null),
                        metric,
                    }),
                };
            }

            const worse = comparisons.find((entry) => entry.significant && entry.lift !== null && entry.lift < 0);
            if (worse) {
                return {
                    variant: 'warning',
                    text: this.$t('rc-ab-testing.detail.verdictWorse', { variant: worse.variantKey, metric }),
                };
            }

            if (comparisons.length && comparisons.every((entry) => !this.enoughDataForComparison(entry))) {
                return { variant: 'info', text: this.$tc('rc-ab-testing.detail.verdictNotEnoughData') };
            }

            return { variant: 'info', text: this.$tc('rc-ab-testing.detail.verdictNoDifference') };
        },

        // Scorecard-Spalten mit der Entscheidungs-Kennzahl vorn und als solche
        // gekennzeichnet; die uebrigen Kennzahlen als Kontext dahinter.
        scorecardColumns() {
            const metricColumns = [
                { property: 'rate', label: this.$tc('rc-ab-testing.detail.rate') },
                { property: 'revenuePerVisitor', label: this.$tc('rc-ab-testing.detail.revenuePerVisitor') },
                { property: 'aov', label: this.$tc('rc-ab-testing.detail.aov') },
                { property: 'revenue', label: this.$tc('rc-ab-testing.detail.revenue') },
            ];
            const primary = this.primaryMetricKey;
            const primaryColumn = metricColumns.find((column) => column.property === primary);
            const rest = metricColumns.filter((column) => column.property !== primary);

            return [
                { property: 'technicalKey', label: this.$tc('rc-ab-testing.detail.variantKey') },
                { property: 'assignments', label: this.$tc('rc-ab-testing.detail.assignments') },
                { property: primaryColumn.property, label: `${primaryColumn.label} · ${this.$tc('rc-ab-testing.detail.decisionTag')}` },
                ...rest,
            ];
        },

        // Standardansicht zeigt nur Name, Ausgestaltung (Seite/Config) und Control;
        // technischer Schluessel und Gewicht erst im Experten-Modus (Gewichte
        // werden ohnehin automatisch 50:50 verteilt).
        variantColumns() {
            const configLabel = this.isCmsTest
                ? this.$tc('rc-ab-testing.detail.variantPage')
                : this.$tc('rc-ab-testing.detail.variantConfig');
            const name = { property: 'name', label: this.$tc('rc-ab-testing.detail.variantName') };
            const config = { property: 'config', label: configLabel };
            const control = { property: 'isControl', label: this.$tc('rc-ab-testing.detail.variantControl') };

            if (!this.showAdvanced) {
                return [name, config, control];
            }

            return [
                { property: 'technicalKey', label: this.$tc('rc-ab-testing.detail.variantKey') },
                name,
                { property: 'weight', label: this.$tc('rc-ab-testing.detail.variantWeight') },
                control,
                config,
            ];
        },

        // Schreibende Aktionen nur für Nutzer mit der editor-Rolle; Viewer sehen
        // die Buttons deaktiviert statt in einen serverseitigen 403 zu laufen.
        canEdit() {
            return this.acl.can('rc_ab_experiment.editor');
        },

        variantOptions() {
            if (!this.experiment || !this.experiment.variants) {
                return [];
            }

            return this.experiment.variants.map((variant) => ({
                value: variant.id,
                label: variant.technicalKey,
            }));
        },

        actionBase() {
            return `/_action/rc-ab-testing/experiment/${this.experimentId}`;
        },

        // Die injizierte httpClient-Instanz spricht bereits /api an, transportiert
        // aber keinen Auth-Header — den steuert der Login-Service bei.
        apiHeaders() {
            return {
                Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`,
                'Content-Type': 'application/json',
            };
        },

        status() {
            return this.experiment ? this.experiment.status : null;
        },

        // Die Lifecycle-Buttons spiegeln die serverseitig erlaubten Übergänge
        // (Start aus Entwurf/Pause, Pause nur aus laufend, Beenden aus laufend/
        // pausiert). Die API bleibt die Wahrheit; das ist reine UX-Vorab-Sperre.
        canStart() {
            return this.status === 'draft' || this.status === 'paused';
        },

        canPause() {
            return this.status === 'running';
        },

        canEnd() {
            return this.status === 'running' || this.status === 'paused';
        },

        canArchive() {
            return this.status === 'done';
        },
    },

    created() {
        this.loadExperiment();
    },

    methods: {
        async loadExperiment() {
            this.isLoading = true;
            const criteria = new Criteria();
            criteria.addAssociation('variants');

            try {
                this.experiment = await this.repository.get(this.experimentId, Shopware.Context.api, criteria);
                // Alt-Experimente ohne gespeicherte Entscheidungs-Kennzahl auf den
                // Produkt-Default setzen, damit die Auswahl gefuellt ist und beim
                // Speichern erhalten bleibt.
                if (this.experiment && !this.experiment.decisionMetric) {
                    this.experiment.decisionMetric = DECISION_METRIC_REVENUE_PER_VISITOR;
                }
                this.winnerVariantId = this.experiment ? this.experiment.winnerVariantId : null;
                this.deletedVariantIds = [];
                this.variantConfigDrafts = {};
                if (this.experiment && this.experiment.variants) {
                    this.experiment.variants.forEach((variant) => {
                        this.variantConfigDrafts[variant.id] = variant.config ? JSON.stringify(variant.config) : '';
                    });
                }
            } catch (error) {
                this.createNotificationError({ message: this.$tc('rc-ab-testing.detail.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        addVariant() {
            const variant = this.variantRepository.create(Shopware.Context.api);
            variant.technicalKey = `variant-${this.experiment.variants.length + 1}`;
            variant.name = variant.technicalKey;
            variant.weight = 0;
            variant.isControl = this.experiment.variants.length === 0;
            this.variantConfigDrafts[variant.id] = '';
            this.experiment.variants.add(variant);
            // Standard: Gewichte gleichmaessig verteilen (zwei Varianten => 50:50),
            // damit ohne manuelles Zutun ein ausgewogener Test entsteht. Wer eine
            // andere Aufteilung will, editiert die Gewichte danach frei.
            this.distributeWeightsEvenly();
        },

        // Verteilt die Varianten-Gewichte gleichmaessig auf Summe 100. Rest-Punkte
        // (z. B. 100/3) gehen an die ersten Varianten, damit die Summe exakt 100
        // bleibt und die Start-Integritaetspruefung nicht anschlaegt.
        distributeWeightsEvenly() {
            const variants = this.experiment.variants;
            const count = variants.length;
            if (count === 0) {
                return;
            }

            const base = Math.floor(100 / count);
            let remainder = 100 - (base * count);
            variants.forEach((variant) => {
                variant.weight = base + (remainder > 0 ? 1 : 0);
                if (remainder > 0) {
                    remainder -= 1;
                }
            });
        },

        // Leitet fehlende technische Schluessel aus dem Namen bzw. der Position ab,
        // damit die Standardansicht ohne das Schluessel-Feld auskommt.
        ensureTechnicalKeys() {
            if (!this.experiment.technicalKey || !this.experiment.technicalKey.trim()) {
                this.experiment.technicalKey = this.slugify(this.experiment.name) || `experiment-${Date.now()}`;
            }
            this.experiment.variants.forEach((variant, index) => {
                if (!variant.technicalKey || !variant.technicalKey.trim()) {
                    variant.technicalKey = `variant-${index + 1}`;
                }
            });
        },

        slugify(text) {
            return (text || '')
                .toString()
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        },

        setVariantConfigDraft(variantId, value) {
            this.variantConfigDrafts = { ...this.variantConfigDrafts, [variantId]: value };
        },

        // CMS-Seiten-Picker: liest/schreibt die cmsPageId ueber den vorhandenen
        // Config-Draft-Mechanismus (JSON), damit der Save-Pfad (applyVariantConfigDrafts)
        // unveraendert bleibt — die Variante speichert dann `config = { cmsPageId }`.
        variantCmsPageId(variant) {
            const draft = (this.variantConfigDrafts[variant.id] || '').trim();
            if (draft !== '') {
                try {
                    const parsed = JSON.parse(draft);
                    return parsed && parsed.cmsPageId ? parsed.cmsPageId : null;
                } catch (error) {
                    return null;
                }
            }

            return variant.config && variant.config.cmsPageId ? variant.config.cmsPageId : null;
        },

        setVariantCmsPageId(variant, cmsPageId) {
            this.setVariantConfigDraft(variant.id, cmsPageId ? JSON.stringify({ cmsPageId }) : '');
        },

        // Wendet die JSON-Config-Entwürfe auf die Varianten an. Liefert false und
        // meldet, sobald ein Entwurf kein gültiges JSON ist — der Save bricht dann ab.
        applyVariantConfigDrafts() {
            let valid = true;
            this.experiment.variants.forEach((variant) => {
                const draft = (this.variantConfigDrafts[variant.id] || '').trim();
                if (draft === '') {
                    variant.config = null;
                    return;
                }
                try {
                    variant.config = JSON.parse(draft);
                } catch (error) {
                    valid = false;
                }
            });

            return valid;
        },

        removeVariant(variant) {
            // Nur bereits persistierte Varianten müssen serverseitig gelöscht werden;
            // eine neu angelegte, noch nicht gespeicherte Variante existiert nur lokal.
            // Ein reines variants.remove() ist ein To-many-Upsert und würde die Zeile
            // sonst als Waise in der DB zurücklassen (Variante käme nach Reload wieder).
            if (!variant.isNew()) {
                this.deletedVariantIds.push(variant.id);
            }
            this.experiment.variants.remove(variant.id);
            // Nach dem Entfernen die Gewichte wieder gleichmaessig auf 100 bringen.
            this.distributeWeightsEvenly();
        },

        async onSave() {
            // Technischer Schluessel ist versteckt (Experten-Feld), aber Pflicht —
            // aus dem Namen ableiten, wenn leer.
            this.ensureTechnicalKeys();

            if (!this.applyVariantConfigDrafts()) {
                this.createNotificationError({ message: this.$tc('rc-ab-testing.detail.variantConfigInvalid') });
                return;
            }

            // Gewinner-Auswahl mitspeichern, damit sie auch nachträglich (Status
            // „beendet") über „Speichern" änderbar ist, nicht nur beim Beenden.
            this.experiment.winnerVariantId = this.winnerVariantId;

            this.isSaving = true;
            try {
                await this.repository.save(this.experiment, Shopware.Context.api);
                if (this.deletedVariantIds.length) {
                    await this.variantRepository.syncDeleted(this.deletedVariantIds, Shopware.Context.api);
                    this.deletedVariantIds = [];
                }
                this.createNotificationSuccess({ message: this.$tc('rc-ab-testing.detail.saveSuccess') });
                await this.loadExperiment();
            } catch (error) {
                this.createNotificationError({ message: this.$tc('rc-ab-testing.detail.saveError') });
            } finally {
                this.isSaving = false;
            }
        },

        async runLifecycle(action) {
            try {
                const body = action === 'end' && this.winnerVariantId
                    ? { winnerVariantId: this.winnerVariantId }
                    : {};
                await this.httpClient.post(`${this.actionBase}/${action}`, body, { headers: this.apiHeaders });
                this.createNotificationSuccess({ message: this.$tc('rc-ab-testing.detail.lifecycleSuccess') });
                await this.loadExperiment();
            } catch (error) {
                this.createNotificationError({ message: this.serverError(error, 'rc-ab-testing.detail.lifecycleError') });
            }
        },

        async loadStats() {
            try {
                const response = await this.httpClient.get(`${this.actionBase}/stats`, { headers: this.apiHeaders });
                this.stats = response.data.variants;
                this.evaluation = response.data.evaluation;
                this.segments = response.data.segments || null;
                this.timeSeries = response.data.timeSeries || null;
                this.funnel = response.data.funnel || null;
            } catch (error) {
                this.createNotificationError({ message: this.serverError(error, 'rc-ab-testing.detail.statsError') });
            }
        },

        // Übersetzt den stabilen Server-Fehlercode über die Snippets (de/en); fällt
        // ohne bekannten Code auf die deutsche Server-Meldung und zuletzt auf den
        // generischen Text zurück. So sieht ein en-GB-Admin kein hartes Deutsch.
        serverError(error, fallbackKey) {
            const data = error && error.response ? error.response.data : null;
            const code = data ? data.errorCode : null;
            if (code) {
                const key = `rc-ab-testing.detail.errors.${code}`;
                const translated = this.$tc(key);
                if (translated !== key) {
                    return translated;
                }
            }

            return (data && data.error) || this.$tc(fallbackKey);
        },

        setActiveStatsTab(key) {
            this.activeStatsTab = key;
        },

        // Ob fuer eine Vergleichszeile genug Daten fuer eine Aussage vorliegen —
        // beide Seiten erreichen die benoetigte Fallzahl. Von Verdikt und
        // Empfehlung gemeinsam genutzt.
        enoughDataForComparison(comparison) {
            if (!this.stats || !this.evaluation) {
                return false;
            }
            const assignmentsByKey = {};
            this.stats.forEach((row) => { assignmentsByKey[row.technicalKey] = row.assignments; });
            const controlAssignments = assignmentsByKey[this.evaluation.controlKey] || 0;
            const variantAssignments = assignmentsByKey[comparison.variantKey] || 0;
            const required = comparison.requiredSamplePerVariant || 0;

            return required > 0 && controlAssignments >= required && variantAssignments >= required;
        },

        // Relative Veraenderung einer Kennzahl der Variante gegenueber der Control.
        // null, wenn kein sinnvoller Bezug moeglich ist (fehlende oder 0-Basis).
        metricUplift(control, variant, key) {
            if (!control) {
                return null;
            }
            const base = control[key];
            const value = variant[key];
            if (base === null || base === undefined || value === null || value === undefined || base === 0) {
                return null;
            }

            return value / base - 1;
        },

        upliftClass(uplift) {
            if (uplift === null || uplift === undefined || uplift === 0) {
                return 'is--flat';
            }

            return uplift > 0 ? 'is--up' : 'is--down';
        },

        formatMetricValue(value, format) {
            if (value === null || value === undefined) {
                return '–';
            }

            return format === 'rate' ? this.formatRate(value) : this.formatCurrency(value);
        },

        // Achsenbeschriftung des Zeitverlaufs — in der Einheit der Entscheidungs-
        // Kennzahl (Euro bzw. Prozent).
        formatChartValue(value) {
            return this.isMeanDecision ? this.formatCurrency(value) : `${(value * 100).toFixed(1)} %`;
        },

        // ISO-Datum (YYYY-MM-DD) zu Tag.Monat fuer die X-Achse.
        shortDate(date) {
            const parts = date.split('-');
            return parts.length === 3 ? `${parts[2]}.${parts[1]}` : date;
        },

        // Sprechendes Label je Funnel-Stufe (Event-Typ -> Snippet).
        funnelStageLabel(stageKey) {
            const map = {
                'page.viewed': 'rc-ab-testing.detail.funnelStageViewed',
                'product.added_to_cart': 'rc-ab-testing.detail.funnelStageCart',
                'checkout.started': 'rc-ab-testing.detail.funnelStageCheckout',
                'checkout.order_placed': 'rc-ab-testing.detail.funnelStagePurchase',
            };

            return map[stageKey] ? this.$tc(map[stageKey]) : stageKey;
        },

        // Drop-off in Prozentpunkten (immer als Verlust dargestellt).
        formatPercentPoints(delta) {
            return `${(delta * 100).toFixed(1)} Pp.`;
        },

        // Wandelt ein Server-Segment in eine render-fertige Ansicht: sprechendes
        // Label + Varianten-Zeilen mit formatierten Werten und Ergebnis-Badge.
        buildSegmentView(dimensionKey, segment) {
            const controlAssignments = (segment.variants.find((row) => row.isControl) || {}).assignments || 0;

            return {
                label: this.segmentLabel(dimensionKey, segment),
                size: segment.size,
                variants: segment.variants.map((row) => ({
                    technicalKey: row.technicalKey,
                    isControl: row.isControl,
                    assignments: row.assignments,
                    rate: this.formatRate(row.rate),
                    revenuePerVisitor: this.formatCurrency(row.revenuePerVisitor),
                    result: this.segmentVariantResult(row, segment.evaluation, controlAssignments),
                })),
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

        // Kompaktes Ergebnis je Variante im Segment (Ampel), abgeleitet aus der
        // segment-eigenen Auswertung — dieselbe Logik wie im Gesamtbild.
        segmentVariantResult(row, evaluation, controlAssignments) {
            if (row.isControl) {
                return { variant: 'info', text: this.$tc('rc-ab-testing.detail.segReference') };
            }
            const comparison = ((evaluation && evaluation.comparisons) || []).find(
                (entry) => entry.variantKey === row.technicalKey,
            );
            if (!comparison) {
                return { variant: 'info', text: '–' };
            }
            if (comparison.significant) {
                const worse = comparison.lift !== null && comparison.lift < 0;
                return {
                    variant: worse ? 'error' : 'success',
                    text: this.$tc(worse ? 'rc-ab-testing.detail.significantWorse' : 'rc-ab-testing.detail.significantBetter'),
                };
            }
            const required = comparison.requiredSamplePerVariant || 0;
            const enough = required > 0 && controlAssignments >= required && row.assignments >= required;

            return {
                variant: 'info',
                text: this.$tc(enough ? 'rc-ab-testing.detail.segNoDifference' : 'rc-ab-testing.detail.segNotEnough'),
            };
        },

        formatRate(rate) {
            return rate === null ? '–' : `${(rate * 100).toFixed(2)} %`;
        },

        // Bewusst simples Euro-Format: die Shop-Waehrung liegt auf der
        // Auswertungs-Detailseite nicht direkt vor; fuer die Entscheider-Ansicht
        // reicht ein einheitlicher 2-Nachkommastellen-Betrag.
        formatCurrency(value) {
            if (value === null || value === undefined) {
                return '–';
            }

            return `${value.toFixed(2)} €`;
        },

        formatLift(lift) {
            if (lift === null || lift === undefined) {
                return '–';
            }

            return `${lift >= 0 ? '+' : ''}${(lift * 100).toFixed(1)} %`;
        },

        formatPValue(pValue) {
            return pValue === null || pValue === undefined ? '–' : pValue.toFixed(4);
        },

        // Konfidenzintervall der Variantenkennzahl — bei „Umsatz pro Besucher" in
        // Euro, bei der Conversion-Rate in Prozent.
        formatInterval(comparison) {
            if (comparison.ciLower === null || comparison.ciUpper === null) {
                return '–';
            }
            if (this.isMeanDecision) {
                return `${this.formatCurrency(comparison.ciLower)} – ${this.formatCurrency(comparison.ciUpper)}`;
            }

            return `${(comparison.ciLower * 100).toFixed(2)} – ${(comparison.ciUpper * 100).toFixed(2)} %`;
        },

        // Zeigt bei Signifikanz auch die Richtung an: ein zweiseitiger Test wird
        // auch für eine signifikant SCHLECHTERE Variante „signifikant" — ohne
        // Richtungshinweis läse sich das fälschlich als Erfolg.
        significanceLabel(comparison) {
            if (!comparison.significant) {
                return this.$tc('rc-ab-testing.detail.significantNo');
            }

            const worse = comparison.lift !== null && comparison.lift < 0;
            return this.$tc(worse
                ? 'rc-ab-testing.detail.significantWorse'
                : 'rc-ab-testing.detail.significantBetter');
        },
    },
});
