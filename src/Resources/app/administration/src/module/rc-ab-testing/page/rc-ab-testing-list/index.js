import template from './rc-ab-testing-list.html.twig';

const { Component, Mixin, Data: { Criteria } } = Shopware;

/**
 * Übersicht aller Experimente. „Anlegen" speichert sofort einen Entwurf und springt
 * dann in die Detailseite, weil diese das Experiment über seine ID aus der
 * Datenbank lädt.
 */
Component.register('rc-ab-testing-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            experiments: null,
            isLoading: false,
            sortBy: 'name',
            sortDirection: 'ASC',
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('rc_ab_experiment');
        },

        columns() {
            return [
                { property: 'name', label: this.$t('rc-ab-testing.list.columnName'), routerLink: 'rc.ab.testing.detail', primary: true },
                { property: 'status', label: this.$t('rc-ab-testing.list.columnStatus') },
                { property: 'testType', label: this.$t('rc-ab-testing.list.columnTestType') },
                { property: 'trafficAllocationPct', label: this.$t('rc-ab-testing.list.columnTraffic') },
            ];
        },
    },

    methods: {
        async getList() {
            this.isLoading = true;
            const criteria = new Criteria(this.page, this.limit);
            criteria.addAssociation('variants');
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            try {
                this.experiments = await this.repository.search(criteria, Shopware.Context.api);
                this.total = this.experiments.total;
            } catch (error) {
                this.createNotificationError({ message: this.$t('rc-ab-testing.list.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        async createExperiment() {
            const experiment = this.repository.create(Shopware.Context.api);
            // Alle Pflichtfelder vorbelegen, sonst scheitert das Speichern. Der Zeitstempel
            // hält die Schlüssel auseinander, weil `technical_key` eindeutig sein muss;
            // 100 % Traffic und 95 % Zielsignifikanz sind die üblichen Startwerte.
            experiment.name = this.$t('rc-ab-testing.list.newExperimentName');
            experiment.technicalKey = `experiment-${Date.now()}`;
            experiment.status = 'draft';
            // Die empfohlene Art ohne Template-Code, wie in der Auswahl der Detailseite zuerst.
            // `twig` liefert ohne Entwickler nichts aus und taugt deshalb nicht als Vorbelegung.
            experiment.testType = 'cms_page';
            experiment.trafficAllocationPct = 100;
            experiment.targetSignificance = 0.95;

            try {
                await this.repository.save(experiment, Shopware.Context.api);
                this.$router.push({ name: 'rc.ab.testing.detail', params: { id: experiment.id } });
            } catch (error) {
                this.createNotificationError({ message: this.$t('rc-ab-testing.list.createError') });
            }
        },
    },
});
