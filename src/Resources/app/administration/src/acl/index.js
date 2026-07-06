/* RcAbTesting – ACL-Privilegien
 *
 * Registriert die Rollen viewer/editor/creator/deleter für das A/B-Test-Modul,
 * damit sie in der Rollenverwaltung erscheinen und Nicht-Admins gezielt
 * freigeschaltet werden können. Der Schlüssel `rc_ab_experiment` passt zu den
 * `rc_ab_experiment.viewer`-Privilegien der Modul-Routen.
 */
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'marketing',
    key: 'rc_ab_experiment',
    roles: {
        viewer: {
            privileges: [
                'rc_ab_experiment:read',
                'rc_ab_variant:read',
                'rc_ab_assignment:read',
                'rc_ab_event:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'rc_ab_experiment:update',
                'rc_ab_variant:create',
                'rc_ab_variant:update',
                'rc_ab_variant:delete',
            ],
            dependencies: [
                'rc_ab_experiment.viewer',
            ],
        },
        creator: {
            privileges: [
                'rc_ab_experiment:create',
            ],
            dependencies: [
                'rc_ab_experiment.viewer',
                'rc_ab_experiment.editor',
            ],
        },
        deleter: {
            privileges: [
                'rc_ab_experiment:delete',
            ],
            dependencies: [
                'rc_ab_experiment.viewer',
            ],
        },
    },
});
