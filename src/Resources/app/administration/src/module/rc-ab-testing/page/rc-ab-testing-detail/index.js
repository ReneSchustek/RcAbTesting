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

const { Component, Mixin, Data: { Criteria } } = Shopware;

/**
 * Detailseite eines Experiments: Editor (Stammdaten, Varianten) plus Auswertung.
 * Die vier Auswertungs-Tabs sind eigene Komponenten (`component/`), ihre
 * Ableitungen liegen als reine, getestete Funktionen in `helper/`.
 */
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
            isLoadingStats: false,
            // Aktiver Auswertungs-Tab (reiner Ansichtswechsel in der Karte).
            activeStatsTab: 'overview',
            // Progressive Disclosure: technische Felder (Schluessel, Signifikanz,
            // Targeting, Scheduling, Gewichte, Roh-JSON) erst auf Wunsch zeigen —
            // die Standardansicht bleibt fuer Nicht-Techniker verstaendlich.
            showAdvanced: false,
            // Registrierte Frontend-Schalter (AB30) + aktuell gewaehlter Schalter.
            frontendSwitches: [],
            selectedSwitchKey: null,
        };
    },

    computed: {
        // Shopware 6.7 stellt kein `httpClient`-Provide bereit: ein
        // `inject: ['httpClient']` liefert dort `undefined`, und jeder
        // `.post()/.get()`-Aufruf (Lifecycle, Auswertung) bricht still
        // client-seitig ab, ohne dass je ein Request rausgeht. Der init-Container
        // haelt die funktionierende Axios-Instanz (spricht bereits `/api` an).
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
                { value: 'frontend_switch', label: this.$tc('rc-ab-testing.detail.testTypeSwitch') },
                { value: 'twig', label: this.$tc('rc-ab-testing.detail.testTypeTwig') },
                { value: 'theme', label: this.$tc('rc-ab-testing.detail.testTypeTheme') },
                { value: 'feature_flag', label: this.$tc('rc-ab-testing.detail.testTypeFeatureFlag') },
            ];
        },

        // Erklaert den gewaehlten Test-Typ in Klartext (unter dem Auswahlfeld).
        testTypeHint() {
            const hints = {
                cms_page: 'rc-ab-testing.detail.testTypeCmsHint',
                frontend_switch: 'rc-ab-testing.detail.testTypeSwitchHint',
                twig: 'rc-ab-testing.detail.testTypeDevHint',
                theme: 'rc-ab-testing.detail.testTypeDevHint',
                feature_flag: 'rc-ab-testing.detail.testTypeDevHint',
            };
            const key = this.experiment ? hints[this.experiment.testType] : null;

            return key ? this.$tc(key) : '';
        },

        isSwitchTest() {
            return this.experiment ? this.experiment.testType === 'frontend_switch' : false;
        },

        // Auswahl der registrierten Schalter (Test-Typ „Frontend-Schalter").
        switchSelectOptions() {
            return this.frontendSwitches.map((entry) => ({ value: entry.key, label: this.$tc(entry.label) }));
        },

        // Erlaubte Werte des aktuell gewaehlten Schalters (je Variante waehlbar).
        switchValueOptions() {
            const active = this.frontendSwitches.find((entry) => entry.key === this.selectedSwitchKey);
            if (!active) {
                return [];
            }

            return active.options.map((option) => ({ value: option.value, label: this.$tc(option.label) }));
        },

        // Beim CMS-Seiten-Test waehlt der Betreuer je Variante eine CMS-Seite per
        // Dropdown statt Roh-JSON — die Config traegt dann nur `cmsPageId`.
        isCmsTest() {
            return this.experiment ? this.experiment.testType === 'cms_page' : false;
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

        decisionMetricLabel() {
            const option = this.decisionMetricOptions.find((entry) => entry.value === this.currentDecisionMetric);

            return option ? option.label : '';
        },

        statsTabs() {
            return [
                { key: 'overview', label: this.$tc('rc-ab-testing.detail.tabOverview') },
                { key: 'segments', label: this.$tc('rc-ab-testing.detail.tabSegments') },
                { key: 'time', label: this.$tc('rc-ab-testing.detail.tabTime') },
                { key: 'funnel', label: this.$tc('rc-ab-testing.detail.tabFunnel') },
            ];
        },

        // Ein-Satz-Verdikt aus der serverseitigen Auswertung, bezogen auf die
        // Entscheidungs-Kennzahl. Ampelfarbe ueber die sw-alert-Variante.
        verdict() {
            return buildVerdict(this.evaluation, this.stats, {
                metricLabel: this.decisionMetricLabel,
                translate: (key, params) => (params ? this.$t(key, params) : this.$tc(key)),
                formatLift,
            });
        },

        // Standardansicht zeigt nur Name, Ausgestaltung (Seite/Config) und Control;
        // technischer Schluessel und Gewicht erst im Experten-Modus (Gewichte
        // werden ohnehin automatisch 50:50 verteilt).
        variantColumns() {
            let configLabel = this.$tc('rc-ab-testing.detail.variantConfig');
            if (this.isCmsTest) {
                configLabel = this.$tc('rc-ab-testing.detail.variantPage');
            } else if (this.isSwitchTest) {
                configLabel = this.$tc('rc-ab-testing.detail.variantValue');
            }
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

        // Die Axios-Instanz des init-Containers spricht bereits /api an, transportiert
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
        this.loadFrontendSwitches();
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
                this.initSwitchSelection();
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

        // Laedt die registrierten Frontend-Schalter (AB30) fuer die Auswahl beim
        // Test-Typ „Frontend-Schalter". Fehlschlag ist unkritisch — dann bleibt die
        // Auswahl leer, die uebrige Seite funktioniert.
        async loadFrontendSwitches() {
            try {
                const response = await this.httpClient.get('/_action/rc-ab-testing/frontend-switches', { headers: this.apiHeaders });
                this.frontendSwitches = response.data.switches || [];
                this.initSwitchSelection();
            } catch (error) {
                this.frontendSwitches = [];
            }
        },

        // Waehlt den aktiven Schalter vor: aus einer vorhandenen Varianten-Config
        // abgeleitet (Bearbeiten) oder der erste registrierte Schalter (Neuanlage).
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
                    // ungueltiges JSON ignorieren
                }
            });
            this.selectedSwitchKey = derived || this.frontendSwitches[0].key;
        },

        setSelectedSwitch(key) {
            this.selectedSwitchKey = key;
            // Die gesetzten Werte gehoeren zum vorher gewaehlten Schalter — beim
            // Wechsel zuruecksetzen, damit keine fremden Config-Keys stehen bleiben.
            (this.experiment.variants || []).forEach((variant) => {
                this.setVariantConfigDraft(variant.id, '');
            });
        },

        // Schalter-Wert je Variante ueber den Config-Draft (analog CMS-Picker): die
        // Config traegt dann `{ <switchKey>: <value> }`.
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
            this.isLoadingStats = true;
            try {
                const response = await this.httpClient.get(`${this.actionBase}/stats`, { headers: this.apiHeaders });
                this.stats = response.data.variants;
                this.evaluation = response.data.evaluation;
                this.segments = response.data.segments || null;
                this.timeSeries = response.data.timeSeries || null;
                this.funnel = response.data.funnel || null;
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

        // Tastaturbedienung der Tab-Leiste nach ARIA-Muster: Pfeiltasten wechseln
        // den Tab, Pos1/Ende springen an den Rand. Ohne das waere die Leiste nur
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
