<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Bridge;

/**
 * Schmale, stabile Schnittstelle, über die andere Plugins die Variante des aktuellen Besuchers
 * abfragen können, ohne von den Interna dieses Plugins abzuhängen. Ein Verbraucher bindet sie mit
 * `on-invalid="null"` ein: Fehlt RcAbTesting, bekommt er null und läuft unverändert weiter.
 *
 * Jede Änderung an diesen Signaturen bricht die Verbraucher und gehört deshalb in ein
 * Major-Release.
 */
interface ActiveVariantQuery
{
    /**
     * True nur, wenn der Besucher am Experiment teilnimmt und genau dieser Variante zugeordnet ist.
     */
    public function isActiveVariant(string $experimentKey, string $variantKey): bool;

    /**
     * `technical_key` der zugeordneten Variante; null, wenn der Besucher nicht teilnimmt oder das
     * Experiment nicht läuft.
     */
    public function getActiveVariant(string $experimentKey): ?string;
}
