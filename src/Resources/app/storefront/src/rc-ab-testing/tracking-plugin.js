import { init, teardown } from './tracking.js';

/**
 * Baut den Shopware-Storefront-Plugin-Adapter um die framework-freie Tracking-
 * Logik. Bewusst als Factory statt direkter Klassendeklaration: `extends` würde
 * sonst schon beim Import ausgewertet und bräche, wenn `PluginBaseClass` (noch)
 * nicht verfügbar ist — genau der Fall, den der Fallback in `main.js` abfängt.
 * Die Basisklasse wird daher erst zur Aufrufzeit hereingereicht.
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
