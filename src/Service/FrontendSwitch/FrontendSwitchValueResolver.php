<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\FrontendSwitch;

/**
 * Die Schnittstelle, über die andere Plugins die Schalterwerte des aktuellen Besuchers lesen,
 * nach demselben Muster wie {@see \Ruhrcoder\RcAbTesting\Bridge\ActiveVariantQuery}. Gegen sie
 * statt gegen die `final`-Klasse {@see FrontendSwitchResolver} zu programmieren, hält die
 * Abhängigkeit dekorierbar und im Test ersetzbar, und Änderungen an der Implementierung brechen
 * die Aufrufer nicht.
 *
 * In `services.xml` ist sie ein öffentlicher Alias auf den Resolver. Aufrufer binden sie mit
 * `on-invalid="null"` ein, damit sie auch ohne installiertes RcAbTesting laufen.
 */
interface FrontendSwitchValueResolver
{
    /**
     * Null, wenn kein Schalter-Experiment läuft, der Besucher nicht teilnimmt oder seine Variante
     * den Schalter nicht setzt.
     */
    public function resolve(string $switchKey): ?string;

    /**
     * @return array<string, string> Schalter-Schlüssel => Wert
     */
    public function all(): array;
}
