import { init, teardown } from './tracking.js';

/**
 * Baut den Shopware-Storefront-Plugin-Adapter um die framework-freie Tracking-
 * Logik. Eine Factory statt einer direkten Klassendeklaration, weil `extends` sonst
 * schon beim Import ausgewertet würde und bräche, wenn `PluginBaseClass` fehlt.
 * Genau diesen Fall fängt der Rückfall in `main.js` ab; die Basisklasse kommt
 * deshalb erst zur Aufrufzeit herein.
 *
 * @param {Function} PluginBaseClass Shopware-Basisklasse (window.PluginBaseClass)
 * @returns {Function} Plugin-Klasse mit init()/destroy()-Lifecycle
 */
export function createRcAbTrackingPlugin(PluginBaseClass) {
    return class RcAbTrackingPlugin extends PluginBaseClass {
        init() {
            init(window);
        }

        destroy() {
            teardown(window);
        }
    };
}
