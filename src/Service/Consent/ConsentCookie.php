<?php

declare(strict_types=1);

namespace Ruhrcoder\RcAbTesting\Service\Consent;

/**
 * Name und Wert des Cookies, mit dem der Besucher im Cookie-Banner der Storefront einwilligt.
 *
 * Das Plugin liest ihn nur. Geschrieben wird er von Shopware: Wählt der Besucher den Eintrag an,
 * setzt Shopware den Marker, wählt er ihn ab, entfernt Shopware ihn wieder.
 *
 * Der Marker ist von der Besucher-Kennung getrennt. Er sagt nur, ob eingewilligt wurde; die
 * Kennung vergibt der Server erst danach.
 *
 * Name und Wert müssen an drei Orten übereinstimmen: Banner-Eintrag, Einwilligungs-Tor und
 * Dokumentation. Liefen sie auseinander, würde ohne Einwilligung getrackt oder gar nicht, und
 * beides fiele erst spät auf.
 */
final class ConsentCookie
{
    public const NAME = 'rc-ab-testing';

    public const GRANTED_VALUE = '1';

    private function __construct()
    {
    }
}
