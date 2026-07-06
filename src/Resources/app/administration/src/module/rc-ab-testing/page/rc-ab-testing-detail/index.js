import template from './rc-ab-testing-detail.html.twig';

const { Component, Mixin, Data: { Criteria } } = Shopware;

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
            winnerVariantId: null,
            deletedVariantIds: [],
            variantConfigDrafts: {},
            isLoading: false,
            isSaving: false,
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

        testTypeOptions() {
            return [
                { value: 'twig', label: this.$tc('rc-ab-testing.detail.testTypeTwig') },
                { value: 'theme', label: this.$tc('rc-ab-testing.detail.testTypeTheme') },
                { value: 'feature_flag', label: this.$tc('rc-ab-testing.detail.testTypeFeatureFlag') },
            ];
        },

        variantColumns() {
            return [
                { property: 'technicalKey', label: this.$tc('rc-ab-testing.detail.variantKey') },
                { property: 'name', label: this.$tc('rc-ab-testing.detail.variantName') },
                { property: 'weight', label: this.$tc('rc-ab-testing.detail.variantWeight') },
                { property: 'isControl', label: this.$tc('rc-ab-testing.detail.variantControl') },
                { property: 'config', label: this.$tc('rc-ab-testing.detail.variantConfig') },
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
        },

        setVariantConfigDraft(variantId, value) {
            this.variantConfigDrafts = { ...this.variantConfigDrafts, [variantId]: value };
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
        },

        async onSave() {
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

        formatRate(rate) {
            return rate === null ? '–' : `${(rate * 100).toFixed(2)} %`;
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

        formatInterval(comparison) {
            if (comparison.ciLower === null || comparison.ciUpper === null) {
                return '–';
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
