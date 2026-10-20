<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

use Symfony\Component\HttpFoundation\Request;

/**
 * Entscheidet, ob für den aktuellen Request der dauerhafte Besucher-Cookie gesetzt werden darf.
 * Als Schnittstelle, damit ein Adapter einen Einwilligungs-Manager wie ComanConsentManager
 * abfragen kann, ohne dass der Kern des Plugins von einem optionalen Plugin abhängt.
 */
interface ConsentGate
{
    /**
     * True, wenn der Besucher in Analyse- oder Marketing-Cookies eingewilligt hat.
     *
     * Bei false nimmt der Besucher gar nicht teil und sieht die Kontrollfassung. Ein Test ohne
     * Cookie ginge nicht: Die Besucher-Kennung wäre bei jedem Aufruf eine andere, die Zuteilung
     * würfelte jedes Mal neu und vermischte die Gruppen.
     */
    public function allowsTrackingCookie(Request $request): bool;
}
