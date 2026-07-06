import { init } from './rc-ab-testing/tracking.js';
import { createRcAbTrackingPlugin } from './rc-ab-testing/tracking-plugin.js';

// Storefront-Entry: bindet das Tracking bevorzugt über den Shopware-
// PluginManager ein (Lifecycle inkl. destroy()). Steht der Manager nicht zur
// Verfügung (z.B. außerhalb des Storefront-Bundles), fällt es auf den
// direkten init() zurück. page.viewed bleibt serverseitig — nie clientseitig.
// Die Plugin-Klasse wird erst hier erzeugt, damit der Import ohne verfügbare
// PluginBaseClass nicht bricht (sonst wäre der Fallback unerreichbar).
if (typeof window !== 'undefined') {
    const manager = window.PluginManager;

    if (manager && typeof window.PluginBaseClass === 'function') {
        manager.register('RcAbTracking', createRcAbTrackingPlugin(window.PluginBaseClass));
    } else {
        init(window);
    }
}
