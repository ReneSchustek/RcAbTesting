import template from './rc-ab-testing-detail.html.twig';
import './rc-ab-testing-detail.scss';
import './component/rc-ab-testing-help-label';
import './component/rc-ab-testing-stats-overview';
import './component/rc-ab-testing-stats-segments';
import './component/rc-ab-testing-stats-timeseries';
import './component/rc-ab-testing-stats-funnel';
import {
    DECISION_METRIC_CONVERSION,
    DECISION_METRIC_REVENUE_PER_VISITOR,
} from './helper/metric-catalog.js';
import { buildVerdict } from './helper/verdict.js';
import { formatLift } from './helper/format.js';
import { evenWeights, serverErrorMessage } from './helper/detail-logic.js';
import { MAX_LENGTH, slugify, uniqueKey } from './helper/technical-key.js';

const { Component, Mixin, Data: { Criteria } } = Shopware;

/**
 * Detailseite eines Experiments: Editor (Stammdaten, Varianten) plus Auswertung.
 * Die vier Auswertungs-Tabs sind eigene Komponenten (`component/`), ihre
 * Ableitungen liegen als reine, getestete Funktionen in `helper/`.
 */
Component.register('rc-ab-testing-detail', {
    template,

    inject: ['repositoryFactory', 'acl', 'rcAbTestingApiService'],

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
            isLoadingStats: false,
            // Aktiver Auswertungs-Tab (reiner Ansichtswechsel in der Karte).
            activeStatsTab: 'overview',
            // Technische Felder (Schlüssel, Zielsignifikanz, Verkaufskanal, Regel,
            // Zeitplan, Varianten-Schlüssel und Gewichte) erscheinen erst auf Wunsch,
            // damit die Standardansicht ohne technisches Vorwissen lesbar bleibt.
            showAdvanced: false,
            // Vom Server gemeldete Frontend-Schalter und der gewählte, dessen Werte
            // die Varianten tragen.
            frontendSwitches: [],
            selectedSwitchKey: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('rc_ab_experiment');
        },

        variantRepository() {
            return this.repositoryFactory.create('rc_ab_variant');
        },

        // No-Code-Variante (CMS-Seite) zuerst und empfohlen; die übrigen Typen
        // sind für Entwickler und im Alltag selten.
        testTypeOptions() {
            return [
                { value: 'cms_page', label: this.$t('rc-ab-testing.detail.testTypeCms') },
                { value: 'frontend_switch', label: this.$t('rc-ab-testing.detail.testTypeSwitch') },
                { value: 'twig', label: this.$t('rc-ab-testing.detail.testTypeTwig') },
                { value: 'theme', label: this.$t('rc-ab-testing.detail.testTypeTheme') },
                { value: 'feature_flag', label: this.$t('rc-ab-testing.detail.testTypeFeatureFlag') },
            ];
        },

        // Erklärt den gewählten Test-Typ in Klartext (unter dem Auswahlfeld).
        testTypeHint() {
            const hints = {
                cms_page: 'rc-ab-testing.detail.testTypeCmsHint',
                frontend_switch: 'rc-ab-testing.detail.testTypeSwitchHint',
                twig: 'rc-ab-testing.detail.testTypeDevHint',
                theme: 'rc-ab-testing.detail.testTypeDevHint',
                feature_flag: 'rc-ab-testing.detail.testTypeDevHint',
            };
            const key = this.experiment ? hints[this.experiment.testType] : null;

            return key ? this.$t(key) : '';
        },

        isSwitchTest() {
            return this.experiment ? this.experiment.testType === 'frontend_switch' : false;
        },

        // Auswahl der registrierten Schalter (Test-Typ „Frontend-Schalter").
        switchSelectOptions() {
            return this.frontendSwitches.map((entry) => ({ value: entry.key, label: this.$t(entry.label) }));
        },

        // Erlaubte Werte des aktuell gewählten Schalters (je Variante wählbar).
        switchValueOptions() {
            const active = this.frontendSwitches.find((entry) => entry.key === this.selectedSwitchKey);
            if (!active) {
                return [];
            }

            return active.options.map((option) => ({ value: option.value, label: this.$t(option.label) }));
        },

        // Beim CMS-Seiten-Test wählt der Betreuer je Variante eine CMS-Seite per
        // Dropdown statt Roh-JSON — die Config trägt dann nur `cmsPageId`.
        isCmsTest() {
            return this.experiment ? this.experiment.testType === 'cms_page' : false;
        },

        // Wählbare Entscheidungs-Kennzahlen — Umsatz je Besucher als Produkt-Default.
        decisionMetricOptions() {
            return [
                { value: DECISION_METRIC_REVENUE_PER_VISITOR, label: this.$t('rc-ab-testing.detail.decisionRevenuePerVisitor') },
                { value: DECISION_METRIC_CONVERSION, label: this.$t('rc-ab-testing.detail.decisionConversionRate') },
            ];
        },

        // Die tatsächlich zugrunde liegende Entscheidungs-Kennzahl: nach dem Laden
        // der Auswertung ist der Server maßgeblich, davor die Experiment-Auswahl.
        // Der Server rechnet mit dem gespeicherten Wert; eine geänderte Auswahl wirkt
        // also erst nach Speichern und erneutem Laden der Auswertung.
        currentDecisionMetric() {
            if (this.evaluation && this.evaluation.decisionMetric) {
                return this.evaluation.decisionMetric;
            }

            return (this.experiment && this.experiment.decisionMetric) || DECISION_METRIC_REVENUE_PER_VISITOR;
        },

        decisionMetricLabel() {
            const option = this.decisionMetricOptions.find((entry) => entry.value === this.currentDecisionMetric);

            return option ? option.label : '';
        },

        statsTabs() {
            return [
                { key: 'overview', label: this.$t('rc-ab-testing.detail.tabOverview') },
                { key: 'segments', label: this.$t('rc-ab-testing.detail.tabSegments') },
                { key: 'time', label: this.$t('rc-ab-testing.detail.tabTime') },
                { key: 'funnel', label: this.$t('rc-ab-testing.detail.tabFunnel') },
            ];
        },

        // Ein-Satz-Verdikt aus der serverseitigen Auswertung, bezogen auf die
        // Entscheidungs-Kennzahl. Ampelfarbe über die sw-alert-Variante.
        verdict() {
            return buildVerdict(this.evaluation, this.stats, {
                metricLabel: this.decisionMetricLabel,
                translate: (key, params) => (params ? this.$t(key, params) : this.$t(key)),
                formatLift,
            });
        },

        // Die Standardansicht zeigt nur Name, Ausgestaltung (Seite, Schalterwert oder
        // Config) und Control; technischer Schlüssel und Gewicht erst im Experten-Modus,
        // weil `distributeWeightsEvenly` die Gewichte ohnehin gleichmäßig verteilt.
        variantColumns() {
            let configLabel = this.$t('rc-ab-testing.detail.variantConfig');
            if (this.isCmsTest) {
                configLabel = this.$t('rc-ab-testing.detail.variantPage');
            } else if (this.isSwitchTest) {
                configLabel = this.$t('rc-ab-testing.detail.variantValue');
            }
            const name = { property: 'name', label: this.$t('rc-ab-testing.detail.variantName') };
            const config = { property: 'config', label: configLabel };
            const control = { property: 'isControl', label: this.$t('rc-ab-testing.detail.variantControl') };

            if (!this.showAdvanced) {
                return [name, config, control];
            }

            return [
                { property: 'technicalKey', label: this.$t('rc-ab-testing.detail.variantKey') },
                name,
                { property: 'weight', label: this.$t('rc-ab-testing.detail.variantWeight') },
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

        status() {
            return this.experiment ? this.experiment.status : null;
        },

        // Die Knöpfe spiegeln die Übergänge, die der Server erzwingt: Start nur aus
        // Entwurf oder Pause, Pause nur aus „läuft", Beenden aus „läuft" oder „pausiert",
        // Archivieren nur aus „beendet".
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
        this.loadFrontendSwitches();
    },

    methods: {
        async loadExperiment() {
            this.isLoading = true;
            const criteria = new Criteria();
            criteria.addAssociation('variants');

            try {
                this.experiment = await this.repository.get(this.experimentId, Shopware.Context.api, criteria);
                // Fehlt die Entscheidungs-Kennzahl im Datensatz, gilt dieselbe Vorgabe wie
                // in der Datenbankspalte, damit die Auswahl gefüllt ist und beim
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
                this.initSwitchSelection();
            } catch (error) {
                this.createNotificationError({ message: this.$t('rc-ab-testing.detail.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        addVariant() {
            const variant = this.variantRepository.create(Shopware.Context.api);
            variant.technicalKey = `variant-${this.experiment.variants.length + 1}`;
            variant.name = variant.technicalKey;
            variant.weight = 0;
            // Die erste Variante wird Control: Der Start verlangt genau eine.
            variant.isControl = this.experiment.variants.length === 0;
            this.variantConfigDrafts[variant.id] = '';
            this.experiment.variants.add(variant);
            // Jede neue Variante verteilt die Gewichte neu (zwei Varianten ergeben
            // 50:50), damit ohne Zutun ein ausgewogener Test entsteht. Eine andere
            // Aufteilung lässt sich danach im Experten-Modus frei eintragen.
            this.distributeWeightsEvenly();
        },

        // Verteilt die Varianten-Gewichte gleichmäßig auf die Summe 100 (`evenWeights`).
        distributeWeightsEvenly() {
            const weights = evenWeights(this.experiment.variants.length);
            this.experiment.variants.forEach((variant, index) => {
                variant.weight = weights[index];
            });
        },

        // Leitet fehlende technische Schlüssel aus dem Namen bzw. der Position ab,
        // damit die Standardansicht ohne das Schlüssel-Feld auskommt. Der Schlüssel des
        // Experiments wird gegen die vorhandenen eindeutig gemacht, sonst scheitert das Speichern.
        async ensureTechnicalKeys() {
            if (!this.experiment.technicalKey || !this.experiment.technicalKey.trim()) {
                const base = slugify(this.experiment.name) || `experiment-${Date.now()}`;
                this.experiment.technicalKey = uniqueKey(base, await this.takenTechnicalKeys(base));
            }
            this.experiment.variants.forEach((variant, index) => {
                if (!variant.technicalKey || !variant.technicalKey.trim()) {
                    variant.technicalKey = `variant-${index + 1}`;
                }
            });
        },

        async takenTechnicalKeys(base) {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.prefix('technicalKey', base.slice(0, MAX_LENGTH - 4)));
            criteria.addFilter(Criteria.not('AND', [Criteria.equals('id', this.experiment.id)]));
            const result = await this.repository.search(criteria, Shopware.Context.api);

            return result.map((experiment) => experiment.technicalKey);
        },

        setVariantConfigDraft(variantId, value) {
            this.variantConfigDrafts = { ...this.variantConfigDrafts, [variantId]: value };
        },

        // CMS-Seiten-Picker: liest/schreibt die cmsPageId über den vorhandenen
        // Config-Draft-Mechanismus (JSON), damit der Save-Pfad (applyVariantConfigDrafts)
        // unverändert bleibt — die Variante speichert dann `config = { cmsPageId }`.
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

        // Lädt die registrierten Frontend-Schalter für die Auswahl beim Test-Typ
        // „Frontend-Schalter". Ein Fehlschlag ist unkritisch: Die Auswahl bleibt
        // leer, die übrige Seite funktioniert.
        async loadFrontendSwitches() {
            try {
                const data = await this.rcAbTestingApiService.frontendSwitches();
                this.frontendSwitches = data.switches || [];
                this.initSwitchSelection();
            } catch (error) {
                this.frontendSwitches = [];
            }
        },

        // Wählt den aktiven Schalter vor: aus einer vorhandenen Varianten-Config
        // abgeleitet (Bearbeiten) oder der erste registrierte Schalter (Neuanlage).
        // Experiment und Schalterliste treffen unabhängig voneinander ein; deshalb
        // rufen beide Ladewege die Vorauswahl auf, und erst der zweite kommt durch.
        initSwitchSelection() {
            if (this.selectedSwitchKey || !this.frontendSwitches.length || !this.experiment) {
                return;
            }
            let derived = null;
            (this.experiment.variants || []).forEach((variant) => {
                const draft = this.variantConfigDrafts[variant.id];
                if (!draft) {
                    return;
                }
                try {
                    const parsed = JSON.parse(draft);
                    Object.keys(parsed || {}).forEach((key) => {
                        if (this.frontendSwitches.some((entry) => entry.key === key)) {
                            derived = key;
                        }
                    });
                } catch (error) {
                    // Ungültiges JSON trägt keinen Schalter. Gemeldet wird es erst beim
                    // Speichern durch `applyVariantConfigDrafts`.
                }
            });
            this.selectedSwitchKey = derived || this.frontendSwitches[0].key;
        },

        setSelectedSwitch(key) {
            this.selectedSwitchKey = key;
            // Die gesetzten Werte gehören zum vorher gewählten Schalter — beim
            // Wechsel zurücksetzen, damit keine fremden Config-Keys stehen bleiben.
            (this.experiment.variants || []).forEach((variant) => {
                this.setVariantConfigDraft(variant.id, '');
            });
        },

        // Schalter-Wert je Variante über den Config-Draft (analog CMS-Picker): die
        // Config trägt dann `{ <switchKey>: <value> }`.
        variantSwitchValue(variant) {
            const draft = (this.variantConfigDrafts[variant.id] || '').trim();
            if (draft === '' || !this.selectedSwitchKey) {
                return null;
            }
            try {
                const parsed = JSON.parse(draft);
                return parsed && parsed[this.selectedSwitchKey] ? parsed[this.selectedSwitchKey] : null;
            } catch (error) {
                return null;
            }
        },

        setVariantSwitchValue(variant, value) {
            this.setVariantConfigDraft(
                variant.id,
                value && this.selectedSwitchKey ? JSON.stringify({ [this.selectedSwitchKey]: value }) : '',
            );
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
            // Nach dem Entfernen die Gewichte wieder gleichmäßig auf 100 bringen.
            this.distributeWeightsEvenly();
        },

        async onSave() {
            // Technischer Schlüssel ist versteckt (Experten-Feld), aber Pflicht —
            // aus dem Namen ableiten, wenn leer.
            await this.ensureTechnicalKeys();

            if (!this.applyVariantConfigDrafts()) {
                this.createNotificationError({ message: this.$t('rc-ab-testing.detail.variantConfigInvalid') });
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
                this.createNotificationSuccess({ message: this.$t('rc-ab-testing.detail.saveSuccess') });
                await this.loadExperiment();
            } catch (error) {
                this.createNotificationError({ message: this.$t('rc-ab-testing.detail.saveError') });
            } finally {
                this.isSaving = false;
            }
        },

        async runLifecycle(action) {
            try {
                // Nur „Beenden" nimmt einen Gewinner an; der Server prüft, ob er zum
                // Experiment gehört.
                const body = action === 'end' && this.winnerVariantId
                    ? { winnerVariantId: this.winnerVariantId }
                    : {};
                await this.rcAbTestingApiService.transition(this.experimentId, action, body);
                this.createNotificationSuccess({ message: this.$t('rc-ab-testing.detail.lifecycleSuccess') });
                await this.loadExperiment();
            } catch (error) {
                this.createNotificationError({ message: this.serverError(error, 'rc-ab-testing.detail.lifecycleError') });
            }
        },

        async loadStats() {
            this.isLoadingStats = true;
            try {
                const data = await this.rcAbTestingApiService.stats(this.experimentId);
                this.stats = data.variants;
                this.evaluation = data.evaluation;
                this.segments = data.segments || null;
                this.timeSeries = data.timeSeries || null;
                this.funnel = data.funnel || null;
            } catch (error) {
                this.createNotificationError({ message: this.serverError(error, 'rc-ab-testing.detail.statsError') });
            } finally {
                this.isLoadingStats = false;
            }
        },

        // Übersetzt den stabilen Server-Fehlercode über die Snippets (de/en); fällt
        // ohne bekannten Code auf die deutsche Server-Meldung und zuletzt auf den
        // generischen Text zurück. So sieht ein en-GB-Admin kein hartes Deutsch.
        serverError(error, fallbackKey) {
            return serverErrorMessage(error, (key) => this.$t(key), fallbackKey);
        },

        setActiveStatsTab(key) {
            this.activeStatsTab = key;
        },

        // Tastaturbedienung der Tab-Leiste nach ARIA-Muster: Pfeiltasten wechseln
        // den Tab, Pos1/Ende springen an den Rand. Ohne das wäre die Leiste nur
        // per Maus bedienbar.
        onTabKeydown(event, index) {
            const keys = {
                ArrowRight: (index + 1) % this.statsTabs.length,
                ArrowLeft: (index - 1 + this.statsTabs.length) % this.statsTabs.length,
                Home: 0,
                End: this.statsTabs.length - 1,
            };
            const target = keys[event.key];
            if (target === undefined) {
                return;
            }

            event.preventDefault();
            const tab = this.statsTabs[target];
            this.setActiveStatsTab(tab.key);
            this.$nextTick(() => {
                const button = this.$refs[`tab-${tab.key}`];
                (Array.isArray(button) ? button[0] : button)?.focus();
            });
        },
    },
});
