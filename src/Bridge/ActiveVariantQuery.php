<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Bridge;

/**
 * Schmale, stabile Schnittstelle, über die fremde Plugins (z.B. RcCheckout,
 * RcCheckoutEnhancer) die aktive Twig-Variante des Besuchers abfragen, ohne eine
 * harte Abhängigkeit auf die RcAbTesting-Interna aufzubauen. Fehlt RcAbTesting,
 * injizieren die Zielplugins per `on-invalid="null"` einfach null und laufen
 * unverändert weiter. Änderungen an dieser Signatur sind ein Major-Break.
 */
interface ActiveVariantQuery
{
    /**
     * True, wenn der aktuelle Besucher im Experiment genau der angegebenen
     * Variante zugeordnet ist. False bei anderer Variante oder Nicht-Teilnahme.
     */
    public function isActiveVariant(string $experimentKey, string $variantKey): bool;

    /**
     * Technical-Key der zugeordneten Variante oder null, wenn der Besucher nicht
     * am Experiment teilnimmt.
     */
    public function getActiveVariant(string $experimentKey): ?string;
}
