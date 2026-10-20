import { init } from './rc-ab-testing/tracking.js';
import { createRcAbTrackingPlugin } from './rc-ab-testing/tracking-plugin.js';

// Einstieg der Storefront: bindet das Tracking bevorzugt über Shopwares
// `PluginManager` ein, damit es auch `destroy()` durchläuft. Fehlt der Manager,
// etwa außerhalb des Storefront-Bundles, greift der direkte `init()`.
// `page.viewed` zählt der Server, der Browser sendet es nie.
// Die Plugin-Klasse entsteht erst hier, damit der Import ohne verfügbare
// `PluginBaseClass` nicht bricht; sonst wäre der Rückfall unerreichbar.
if (typeof window !== 'undefined') {
    const manager = window.PluginManager;

    if (manager && typeof window.PluginBaseClass === 'function') {
        manager.register('RcAbTracking', createRcAbTrackingPlugin(window.PluginBaseClass));
    } else {
        init(window);
    }
}
