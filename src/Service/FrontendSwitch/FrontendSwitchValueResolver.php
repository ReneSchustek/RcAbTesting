<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Stabiler Cross-Plugin-Kontrakt zum Lesen der aktiven Frontend-Schalter-Werte des
 * aktuellen Besuchers — analog zur Bridge {@see \Ruhrcoder\RcAbTesting\Bridge\ActiveVariantQuery}.
 * Fremd-Plugins (z. B. RcCheckout) sollen gegen dieses Interface programmieren statt
 * gegen die konkrete `final`-Implementierung {@see FrontendSwitchResolver}, damit ihre
 * Abhängigkeit dekorierbar/mockbar bleibt und Signatur-Änderungen der Implementierung
 * kein harter Bruch sind (Arbeitspaket AB46). In services.xml als public Alias auf den Resolver
 * registriert; per `on-invalid="null"` optional injizierbar (RcAbTesting nicht Pflicht).
 */
interface FrontendSwitchValueResolver
{
    /**
     * Aktiver Wert des Schalters für den aktuellen Besucher oder null (kein
     * laufendes Schalter-Experiment, Besucher nimmt nicht teil, oder Schalter in
     * der zugewiesenen Variante nicht gesetzt).
     */
    public function resolve(string $switchKey): ?string;

    /**
     * Alle aktiven Schalter-Werte des aktuellen Besuchers (Schlüssel => Wert).
     *
     * @return array<string, string>
     */
    public function all(): array;
}
