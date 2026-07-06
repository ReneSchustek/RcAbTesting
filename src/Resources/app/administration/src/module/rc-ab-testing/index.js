import './page/rc-ab-testing-list';
import './page/rc-ab-testing-detail';
import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

const { Module } = Shopware;

Module.register('rc-ab-testing', {
    type: 'plugin',
    name: 'RcAbTesting',
    title: 'rc-ab-testing.general.mainMenuItemGeneral',
    description: 'rc-ab-testing.general.description',
    color: '#9AA8B5',
    icon: 'regular-rocket',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        list: {
            component: 'rc-ab-testing-list',
            path: 'list',
            meta: {
                privilege: 'rc_ab_experiment.viewer',
            },
        },
        detail: {
            component: 'rc-ab-testing-detail',
            path: 'detail/:id',
            meta: {
                privilege: 'rc_ab_experiment.viewer',
                parentPath: 'rc.ab.testing.list',
            },
            props: {
                default(route) {
                    return { experimentId: route.params.id };
                },
            },
        },
    },

    navigation: [{
        label: 'rc-ab-testing.general.mainMenuItemGeneral',
        color: '#9AA8B5',
        path: 'rc.ab.testing.list',
        icon: 'regular-rocket',
        parent: 'sw-marketing',
        position: 50,
    }],
});
