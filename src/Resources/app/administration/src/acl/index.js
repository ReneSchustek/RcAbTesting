/**
 * Rollen des A/B-Test-Moduls für die Rollenverwaltung, damit Nicht-Admins gezielt
 * freigeschaltet werden können. Aus dem Schlüssel `rc_ab_experiment` wird die Rolle
 * `rc_ab_experiment.viewer`, mit der die Modul-Routen abgesichert sind.
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
        // `rc_ab_experiment:update` deckt auch Start, Pause, Beenden und Archivieren ab,
        // weil die Aktions-Routen genau dieses Recht verlangen. Die Varianten-Rechte
        // braucht das Speichern, das die Varianten mitschreibt und entfernte löscht.
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
        // Ein neues Experiment öffnet sofort die Detailseite; ohne Bearbeiten-Recht
        // ließe es sich dort nicht weiter ausfüllen.
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
