import template from './rc-ab-testing-list.html.twig';

const { Component, Mixin, Data: { Criteria } } = Shopware;

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
                { property: 'name', label: this.$tc('rc-ab-testing.list.columnName'), routerLink: 'rc.ab.testing.detail', primary: true },
                { property: 'status', label: this.$tc('rc-ab-testing.list.columnStatus') },
                { property: 'testType', label: this.$tc('rc-ab-testing.list.columnTestType') },
                { property: 'trafficAllocationPct', label: this.$tc('rc-ab-testing.list.columnTraffic') },
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
                this.createNotificationError({ message: this.$tc('rc-ab-testing.list.loadError') });
            } finally {
                this.isLoading = false;
            }
        },

        async createExperiment() {
            const experiment = this.repository.create(Shopware.Context.api);
            // Pflichtfelder mit sinnvollen Defaults belegen, sonst scheitert das Speichern.
            experiment.name = this.$tc('rc-ab-testing.list.newExperimentName');
            experiment.technicalKey = `experiment-${Date.now()}`;
            experiment.status = 'draft';
            experiment.testType = 'twig';
            experiment.trafficAllocationPct = 100;
            experiment.targetSignificance = 0.95;

            try {
                await this.repository.save(experiment, Shopware.Context.api);
                this.$router.push({ name: 'rc.ab.testing.detail', params: { id: experiment.id } });
            } catch (error) {
                this.createNotificationError({ message: this.$tc('rc-ab-testing.list.createError') });
            }
        },
    },
});
